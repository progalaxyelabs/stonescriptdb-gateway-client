<?php

namespace StoneScriptDB;

use Exception;

/**
 * Exception thrown by GatewayClient when requests fail.
 *
 * PRIVACY CONTRACT. PostgreSQL error text (message, DETAIL, HINT, CONTEXT, and
 * custom `RAISE EXCEPTION` text) routinely echoes data values back
 * (`Key (email)=(a@b.c) already exists`). That text is customer data, so this
 * exception NEVER places it in:
 *   - getMessage()            (identifier-only, see below)
 *   - getPrevious() chains    (nothing raw is ever attached)
 *   - __toString() / uncaught-exception output
 *   - print_r() / var_dump() / serialize() of the exception
 *
 * What a developer can rely on for diagnosis, all allow-listed identifiers:
 * the gateway error code, HTTP status, database, function name, SQLSTATE,
 * schema/table/column/constraint names and a correlation id.
 *
 * The raw cause is kept in memory only and is reachable solely through the
 * explicit, debug-only {@see getRawCauseForDebug()}. Never log its result.
 *
 * @package StoneScriptDB
 */
class GatewayException extends Exception
{
    /** An identifier as PostgreSQL would accept it unquoted (<= 63 bytes). */
    private const IDENT = '/^[A-Za-z_][A-Za-z0-9_$]{0,62}$/';
    private const SQLSTATE = '/^[0-9A-Z]{5}$/';
    private const CORRELATION = '/^[A-Za-z0-9_-]{1,64}$/';

    /** @var array<string,string> allow-listed, identifier-only diagnostics */
    private array $safe = [];

    /** Raw gateway/PostgreSQL cause text. Debug-only. Never in message/chain/string/dump. */
    private ?string $rawCause = null;

    /**
     * Create a new gateway exception.
     *
     * @param string $message The exception message. Callers must pass identifier-only text.
     * @param int $code The HTTP status code (if applicable)
     * @param Exception|null $previous Previous exception
     * @param array|null $errorBody The gateway error response body (optional). Only allow-listed
     *        identifier fields are retained; `cause` is held privately for opt-in debugging.
     */
    public function __construct(string $message = "", int $code = 0, ?Exception $previous = null, ?array $errorBody = null)
    {
        parent::__construct($message, $code, $previous);
        if ($errorBody !== null) {
            $this->absorb($errorBody);
        }
    }

    /**
     * Keep only allow-listed, shape-validated identifier fields from a gateway error body.
     */
    private function absorb(array $body): void
    {
        $cause = $body['cause'] ?? null;
        if (is_string($cause) && $cause !== '') {
            $this->rawCause = $cause;
        }

        $this->keep('error', $body['error'] ?? null, self::IDENT);
        $this->keep('database', $body['database'] ?? null, self::IDENT);
        $this->keep('function', $body['function'] ?? null, self::IDENT);
        $this->keep('sqlstate', $body['sqlstate'] ?? null, self::SQLSTATE);
        $this->keep('schema', $body['schema'] ?? null, self::IDENT);
        $this->keep('table', $body['table'] ?? null, self::IDENT);
        $this->keep('column', $body['column'] ?? null, self::IDENT);
        $this->keep('constraint', $body['constraint'] ?? null, self::IDENT);
        $this->keep('correlation_id', $body['correlation_id'] ?? $body['request_id'] ?? null, self::CORRELATION);

        // Older gateways send only the free-text cause. Lift object identifiers out of it with
        // narrow patterns; every captured group is itself validated as an identifier, and the
        // surrounding text (where values live) is discarded.
        if ($this->rawCause !== null) {
            $patterns = [
                'constraint' => '/constraint "([^"\s]+)"/',
                'column' => '/(?:null value in column|\bCOLUMN:) "?([A-Za-z_][A-Za-z0-9_$]*)"?/',
                'table' => '/\bTABLE: ([A-Za-z_][A-Za-z0-9_$]*)/',
                'schema' => '/\bSCHEMA: ([A-Za-z_][A-Za-z0-9_$]*)/',
            ];
            foreach ($patterns as $field => $re) {
                if (!isset($this->safe[$field]) && preg_match($re, $this->rawCause, $m) === 1) {
                    $this->keep($field, $m[1], self::IDENT);
                }
            }
        }
    }

    private function keep(string $field, mixed $value, string $shape): void
    {
        if (is_string($value) && preg_match($shape, $value) === 1) {
            $this->safe[$field] = $value;
        }
    }

    /**
     * Identifier-only diagnostics (error, database, function, sqlstate, schema, table, column,
     * constraint, correlation_id). Safe to log.
     *
     * @return array<string,string>
     */
    public function getSafeContext(): array
    {
        return $this->safe;
    }

    /** SQLSTATE if the gateway supplied one, else null. Safe to log. */
    public function getSqlState(): ?string
    {
        return $this->safe['sqlstate'] ?? null;
    }

    /** Violated constraint name, if known. Safe to log. */
    public function getConstraint(): ?string
    {
        return $this->safe['constraint'] ?? null;
    }

    /** Offending column name, if known. Safe to log. */
    public function getColumn(): ?string
    {
        return $this->safe['column'] ?? null;
    }

    /** Correlation id tying this exception to gateway logs. Safe to log. */
    public function getCorrelationId(): ?string
    {
        return $this->safe['correlation_id'] ?? null;
    }

    /**
     * Whether a raw cause is held (without exposing it).
     */
    public function hasRawCause(): bool
    {
        return $this->rawCause !== null;
    }

    /**
     * DEBUG-ONLY. The raw PostgreSQL / gateway cause text, which may contain customer data
     * (DETAIL, HINT, values echoed in RAISE EXCEPTION text). Use for in-process branching or
     * interactive debugging only. NEVER write the result to logs, stdout, stderr, responses or
     * exception messages.
     */
    public function getRawCauseForDebug(): ?string
    {
        return $this->rawCause;
    }

    /**
     * @deprecated Since 3.1.2: use {@see getRawCauseForDebug()}. Returns the RAW cause (may contain
     *             customer data). Retained only for backward compatibility; never log the result.
     */
    public function getCause(): ?string
    {
        return $this->rawCause;
    }

    /**
     * Get the database name from the gateway error.
     *
     * @return string|null The database name if present
     */
    public function getDatabase(): ?string
    {
        return $this->safe['database'] ?? null;
    }

    /**
     * Get the error code from the gateway response.
     *
     * @return string|null The gateway error code (e.g., 'query_failed')
     */
    public function getGatewayError(): ?string
    {
        return $this->safe['error'] ?? null;
    }

    /**
     * Build the identifier-only suffix appended to exception messages.
     *
     * @param array<string,string> $ctx
     */
    public static function formatSafeSuffix(array $ctx): string
    {
        $parts = [];
        foreach (['database', 'function', 'sqlstate', 'schema', 'table', 'column', 'constraint', 'correlation_id'] as $k) {
            if (isset($ctx[$k])) {
                $parts[] = ($k === 'correlation_id' ? 'ref' : $k) . '=' . $ctx[$k];
            }
        }
        return $parts === [] ? '' : ' [' . implode(' ', $parts) . ']';
    }

    /**
     * Value-free string form: class, message, location and a trace WITHOUT argument values.
     * Overridden so uncaught-exception output and `(string) $e` can never carry raw database text.
     */
    public function __toString(): string
    {
        $out = static::class . ': ' . $this->getMessage() . ' in ' . $this->getFile() . ':' . $this->getLine();
        $i = 0;
        foreach ($this->getTrace() as $f) {
            $fn = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '');
            $out .= "\n#" . $i++ . ' ' . ($f['file'] ?? '[internal]') . '(' . ($f['line'] ?? 0) . '): ' . $fn . '()';
        }
        return $out;
    }

    /** Keeps the raw cause out of print_r()/var_dump(). */
    public function __debugInfo(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'safe' => $this->safe,
            'hasRawCause' => $this->rawCause !== null,
        ];
    }

    /** Keeps the raw cause out of serialize(). */
    public function __serialize(): array
    {
        return ['message' => $this->getMessage(), 'code' => $this->getCode(), 'safe' => $this->safe];
    }

    public function __unserialize(array $data): void
    {
        $this->message = (string) ($data['message'] ?? '');
        $this->code = (int) ($data['code'] ?? 0);
        $this->safe = is_array($data['safe'] ?? null) ? $data['safe'] : [];
    }
}
