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
 * schema/table/column/constraint names, an application error code and a
 * correlation id.
 *
 * The raw cause is NOT stored on the exception object (so var_export,
 * (array) casts and reflection-based dumpers cannot reach it); it lives in a
 * weak registry and is reachable solely through the explicit, debug-only
 * {@see getRawCauseForDebug()}. Never log its result.
 *
 * APPLICATION ERROR CODE CONTRACT. A PL/pgSQL function signals a business
 * error with `RAISE EXCEPTION '<code>'` or `RAISE EXCEPTION '<code>: <free text>'`.
 * Only the code (a token at the very start of the PostgreSQL primary message,
 * `[A-Za-z][A-Za-z0-9_.-]{0,63}` followed by `:` or end of line, or a
 * bracketed tag such as `[ER123]` / `[public:code:status]`) is surfaced, via
 * {@see getAppErrorCode()} and `code=<token>` in the message. Free text,
 * DETAIL, HINT and CONTEXT are never surfaced. A sentence-style RAISE
 * ("Insufficient stock for item") yields no code: use a code.
 *
 * @package StoneScriptDB
 */
class GatewayException extends Exception
{
    /** An identifier as PostgreSQL would accept it unquoted (<= 63 bytes). */
    private const IDENT = '/^[A-Za-z_][A-Za-z0-9_$]{0,62}$/';
    private const SQLSTATE = '/^[0-9A-Z]{5}$/';
    private const CORRELATION = '/^[A-Za-z0-9_-]{1,64}$/';
    private const APP_CODE = '/^(?:\[(?:ER[A-Za-z0-9_]{1,30}|public(?::[a-z][a-z0-9_]{0,40})?(?::[1-4][0-9]{2})?)\]|[A-Za-z][A-Za-z0-9_.-]{0,63}:?)$/';

    /** SQLSTATE classes PostgreSQL itself raises. P0001 (raise_exception) is deliberately NOT a system class here. */
    private const SYSTEM_CLASSES = [
        '00', '01', '02', '03', '08', '09', '0A', '0B', '0F', '0L', '0P', '0Z', '20', '21', '22', '23', '24', '25', '26', '27',
        '28', '2B', '2D', '2F', '34', '38', '39', '3B', '3D', '3F', '40', '42', '44', '53', '54', '55', '57', '58', '72',
        'F0', 'HV', 'XX',
    ];

    /** @var array<string,string> allow-listed, identifier-only diagnostics */
    private array $safe = [];

    /** @var \WeakMap<GatewayException,string>|null raw causes, kept OFF the exception object */
    private static ?\WeakMap $rawCauses = null;

    /**
     * Create a new gateway exception.
     *
     * @param string $message The exception message. Callers must pass identifier-only text.
     * @param int $code The HTTP status code (if applicable)
     * @param Exception|null $previous Previous exception
     * @param array|null $errorBody The gateway error response body (optional). Only allow-listed
     *        identifier fields are retained; `cause` is held in a weak registry for opt-in debugging.
     */
    public function __construct(string $message = "", int $code = 0, ?Exception $previous = null, ?array $errorBody = null)
    {
        parent::__construct($message, $code, $previous);
        if ($errorBody !== null) {
            $this->absorb($errorBody);
        }
    }

    private function absorb(array $body): void
    {
        $cause = $body['cause'] ?? null;
        $cause = is_string($cause) && $cause !== '' ? $cause : null;
        if ($cause !== null) {
            self::$rawCauses ??= new \WeakMap();
            self::$rawCauses[$this] = $cause;
        }

        // Structured fields from the gateway win. A present-but-invalid value is marked, not silently dropped.
        $this->keep('error', $body, 'error', self::IDENT);
        $this->keep('database', $body, 'database', self::IDENT);
        $this->keep('function', $body, 'function', self::IDENT);
        $this->keep('sqlstate', $body, 'sqlstate', self::SQLSTATE);
        $this->keep('schema', $body, 'schema', self::IDENT);
        $this->keep('table', $body, 'table', self::IDENT);
        $this->keep('column', $body, 'column', self::IDENT);
        $this->keep('constraint', $body, 'constraint', self::IDENT);
        $this->keep('app_code', $body, 'app_code', self::APP_CODE);
        $this->keep('correlation_id', $body, 'correlation_id', self::CORRELATION);
        if (!isset($this->safe['correlation_id'])) {
            $this->keep('correlation_id', $body, 'request_id', self::CORRELATION);
        }
        $this->keep('client_ref', $body, 'client_ref', self::CORRELATION);

        // Fallback for gateways that send only the free-text cause: parse ONLY the PostgreSQL primary message
        // (the first line / first " | " segment), never DETAIL, HINT, CONTEXT or the trailing SCHEMA/TABLE/COLUMN
        // segments (a RAISE can forge those). See parsePrimary().
        if ($cause !== null) {
            $parsed = self::parsePrimary(self::primaryLine($cause), $this->safe['sqlstate'] ?? null);
            foreach ($parsed as $field => $value) {
                if (!isset($this->safe[$field])) {
                    $this->safe[$field] = $value;
                }
            }
        }
    }

    /** The PostgreSQL primary message: text before the first newline or gateway " | " separator. */
    private static function primaryLine(string $cause): string
    {
        $cut = strlen($cause);
        foreach (["\n", ' | '] as $sep) {
            $i = strpos($cause, $sep);
            if ($i !== false && $i < $cut) {
                $cut = $i;
            }
        }
        return trim(substr($cause, 0, $cut));
    }

    /**
     * @return array<string,string> subset of sqlstate, constraint, table, column, app_code
     */
    private static function parsePrimary(string $line, ?string $knownState): array
    {
        $out = [];
        $q = '"([A-Za-z_][A-Za-z0-9_$]{0,62})"';
        // PostgreSQL's own fixed phrasings, matched against the WHOLE primary line.
        $fixed = [
            ['/^duplicate key value violates unique constraint ' . $q . '$/', '23505', ['constraint']],
            ['/^insert or update on table ' . $q . ' violates foreign key constraint ' . $q . '$/', '23503', ['table', 'constraint']],
            ['/^update or delete on table ' . $q . ' violates foreign key constraint ' . $q . ' on table ' . $q . '$/', '23503', ['table', 'constraint', null]],
            ['/^null value in column ' . $q . ' of relation ' . $q . ' violates not-null constraint$/', '23502', ['column', 'table']],
            ['/^null value in column ' . $q . ' violates not-null constraint$/', '23502', ['column']],
            ['/^new row for relation ' . $q . ' violates check constraint ' . $q . '$/', '23514', ['table', 'constraint']],
            ['/^relation ' . $q . ' does not exist$/', '42P01', ['table']],
            ['/^column ' . $q . ' of relation ' . $q . ' does not exist$/', '42703', ['column', 'table']],
            ['/^column ' . $q . ' does not exist$/', '42703', ['column']],
        ];
        foreach ($fixed as [$re, $state, $fields]) {
            if (preg_match($re, $line, $m) === 1) {
                if ($knownState === null) {
                    $out['sqlstate'] = $state;
                }
                foreach ($fields as $i => $f) {
                    if ($f !== null && !self::looksSecret($m[$i + 1]) && preg_match(self::IDENT, $m[$i + 1]) === 1) {
                        $out[$f] = $m[$i + 1];
                    }
                }
                return $out;
            }
        }

        // Not a PostgreSQL-generated phrasing: a candidate application error code, but only when the SQLSTATE
        // is unknown, P0001, or a custom class.
        if ($knownState !== null && in_array(substr($knownState, 0, 2), self::SYSTEM_CLASSES, true)) {
            return $out;
        }
        $code = null;
        if (preg_match('/^(\[(?:ER[A-Za-z0-9_]{1,30}|public(?::[a-z][a-z0-9_]{0,40})?(?::[1-4][0-9]{2})?)\])/', $line, $m) === 1) {
            $code = $m[1];
        } elseif (preg_match('/^([A-Za-z][A-Za-z0-9_.-]{0,63}:)/', $line, $m) === 1) {
            $code = $m[1];
        } elseif (preg_match('/^([A-Za-z][A-Za-z0-9_.-]{0,63})$/', $line, $m) === 1) {
            $code = $m[1];
        }
        if ($code !== null && !self::looksSecret($code)) {
            $out['app_code'] = $code;
        }
        return $out;
    }

    /** Long hex runs, long digit runs and long mixed alphanumeric runs look like secrets/keys/hashes, not identifiers. */
    private static function looksSecret(string $v): bool
    {
        return preg_match('/[0-9A-Fa-f]{16,}/', $v) === 1
            || preg_match('/[0-9]{6,}/', $v) === 1
            || (preg_match('/^[A-Za-z0-9+\/=]{24,}$/', $v) === 1 && preg_match('/[0-9]/', $v) === 1 && preg_match('/[A-Za-z]/', $v) === 1);
    }

    private function keep(string $field, array $body, string $key, string $shape): void
    {
        if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') {
            return;
        }
        $value = $body[$key];
        $this->safe[$field] = (is_string($value) && preg_match($shape, $value) === 1 && !self::looksSecret($value))
            ? $value
            : '<redacted>';
    }

    /**
     * Identifier-only diagnostics (error, database, function, sqlstate, schema, table, column,
     * constraint, app_code, correlation_id, client_ref). Safe to log. A value the gateway sent but that
     * failed validation reads `<redacted>`.
     *
     * @return array<string,string>
     */
    public function getSafeContext(): array
    {
        return $this->safe;
    }

    /** SQLSTATE (gateway-supplied, or inferred from a fixed PostgreSQL phrasing), else null. Safe to log. */
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

    /**
     * The application error code a function raised (`RAISE EXCEPTION 'not_found'`, `'slug_taken: ...'`,
     * `'[ER102] ...'`). Never free text. Null for sentence-style RAISEs. Safe to log.
     */
    public function getAppErrorCode(): ?string
    {
        return $this->safe['app_code'] ?? null;
    }

    /** The GATEWAY's correlation id (greppable in gateway logs), else null. Safe to log. */
    public function getCorrelationId(): ?string
    {
        return $this->safe['correlation_id'] ?? null;
    }

    /** A client-generated id used when the gateway sent none. NOT present in gateway logs. */
    public function getClientRef(): ?string
    {
        return $this->safe['client_ref'] ?? null;
    }

    /**
     * Whether a raw cause is held (without exposing it).
     */
    public function hasRawCause(): bool
    {
        return self::$rawCauses !== null && isset(self::$rawCauses[$this]);
    }

    /**
     * DEBUG-ONLY. The raw PostgreSQL / gateway cause text, which may contain customer data
     * (DETAIL, HINT, values echoed in RAISE EXCEPTION text). Use for in-process branching or
     * interactive debugging only. NEVER write the result to logs, stdout, stderr, responses or
     * exception messages.
     */
    public function getRawCauseForDebug(): ?string
    {
        return self::$rawCauses[$this] ?? null;
    }

    /**
     * @deprecated Since 4.0.0: use {@see getRawCauseForDebug()}. Returns the RAW cause (may contain
     *             customer data). Retained only for backward compatibility; never log the result.
     */
    public function getCause(): ?string
    {
        return $this->getRawCauseForDebug();
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
        $labels = [
            'database' => 'database', 'function' => 'function', 'sqlstate' => 'sqlstate', 'schema' => 'schema',
            'table' => 'table', 'column' => 'column', 'constraint' => 'constraint', 'app_code' => 'code',
            'correlation_id' => 'ref', 'client_ref' => 'client_ref',
        ];
        $parts = [];
        foreach ($labels as $k => $label) {
            if (isset($ctx[$k])) {
                $parts[] = $label . '=' . $ctx[$k];
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

    public function __debugInfo(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'safe' => $this->safe,
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
