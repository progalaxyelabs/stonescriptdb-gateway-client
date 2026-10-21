<?php

namespace StoneScriptDB\Tests;

use PHPUnit\Framework\TestCase;
use StoneScriptDB\GatewayClient;
use StoneScriptDB\GatewayException;

/**
 * Sentinel values planted in a PostgreSQL-shaped error must never reach the exception message,
 * previous chain, __toString, dumps, serialization, last_error or error_log output.
 */
class GatewayNoLeakTest extends TestCase
{
    private const SENTINELS = ['jane.sentinel@example.test', '+919876500123', 'Key (email)', 'DETAIL', 'HINT'];

    /** @var resource|null */
    private $proc = null;
    private int $port = 0;

    protected function setUp(): void
    {
        $this->port = random_int(20000, 40000);
        $cmd = [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", __DIR__ . '/fixtures/leaky_gateway.php'];
        $this->proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50; $i++) {
            $s = @fsockopen('127.0.0.1', $this->port);
            if ($s) { fclose($s); return; }
            usleep(100000);
        }
        $this->markTestSkipped('could not start stub gateway');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
    }

    private function assertClean(string $text, string $where): void
    {
        foreach (self::SENTINELS as $s) {
            $this->assertStringNotContainsString($s, $text, "sentinel '$s' leaked via $where");
        }
    }

    private function failingCall(): GatewayException
    {
        $client = new GatewayClient("http://127.0.0.1:{$this->port}", 'p', 'v1', 'u1');
        $client->setDebug(true);
        try {
            $client->callFunction('create_user', ['jane.sentinel@example.test', '+919876500123']);
        } catch (GatewayException $e) {
            $this->clientLastError = $client->getLastError();
            return $e;
        }
        $this->fail('expected GatewayException');
    }

    private ?string $clientLastError = null;

    public function testNothingRawEscapes(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'gwlog');
        $prev = ini_set('error_log', $logFile);
        $e = $this->failingCall();
        ini_set('error_log', $prev === false ? '' : $prev);

        $this->assertClean($e->getMessage(), 'message');
        $this->assertClean((string) $e, '__toString');
        $this->assertClean($e->getTraceAsString(), 'trace');
        $this->assertClean(print_r($e, true), 'print_r');
        ob_start(); var_dump($e); $this->assertClean((string) ob_get_clean(), 'var_dump');
        $this->assertClean(serialize($e), 'serialize');
        $this->assertClean((string) $this->clientLastError, 'last_error');
        $this->assertClean((string) file_get_contents($logFile), 'error_log');
        $this->assertNull($e->getPrevious());
        unlink($logFile);
    }

    public function testDiagnosticsKeptAndRawIsOptIn(): void
    {
        $e = $this->failingCall();
        $this->assertSame('query_failed', $e->getGatewayError());
        $this->assertSame('tenant_db', $e->getDatabase());
        $this->assertSame('users_email_key', $e->getConstraint());
        $this->assertSame('23505', $e->getSqlState());
        $this->assertNull($e->getCorrelationId(), 'gateway sent none, so no gateway id is claimed');
        $this->assertNotNull($e->getClientRef());
        $this->assertStringContainsString('client_ref=', $e->getMessage());
        $this->assertStringNotContainsString(' ref=', $e->getMessage());
        $ctx = $e->getSafeContext();
        $this->assertSame('create_user', $ctx['function']);
        $this->assertStringContainsString('constraint=users_email_key', $e->getMessage());
        $this->assertStringContainsString('function=create_user', $e->getMessage());
        // explicit opt-in still works, and so does the deprecated accessor
        $this->assertStringContainsString('jane.sentinel@example.test', $e->getRawCauseForDebug());
        $this->assertSame($e->getRawCauseForDebug(), $e->getCause());
    }

    public function testNonIdentifierFieldsAreDropped(): void
    {
        $e = new GatewayException('x', 500, null, ['error' => 'query_failed', 'constraint' => 'a@b.c', 'sqlstate' => '23505', 'column' => 'has space']);
        $this->assertSame('<redacted>', $e->getConstraint(), 'dropped values are visibly marked');
        $this->assertSame('<redacted>', $e->getColumn());
        $this->assertSame('23505', $e->getSqlState());
    }

    private function exc(#[\SensitiveParameter] string $cause, #[\SensitiveParameter] array $extra = []): GatewayException
    {
        return new GatewayException('x', 500, null, array_merge(['error' => 'query_failed', 'cause' => $cause], $extra));
    }

    /** @return array<string,array{0:string,1:?string}> */
    public static function appCodeProvider(): array
    {
        return [
            'bare code' => ['not_found', 'not_found'],
            'upper code' => ['RATE_LIMITED', 'RATE_LIMITED'],
            'code with free text' => ['slug_taken: slug acme-co is taken for jane.sentinel@example.test', 'slug_taken:'],
            'colon prefix with tail' => ['job_conflict:job-9f8e', 'job_conflict:'],
            'bracket ER' => ['[ER102] Stock short for 91987', '[ER102]'],
            'bracket public' => ['[public:out_of_stock:409] Sorry', '[public:out_of_stock:409]'],
            'sentence' => ['Insufficient stock for item', null],
            'sentence with phone' => ['User +919876500123 exists', null],
            'bare email' => ['jane.sentinel@example.test', null],
            'two words' => ['not found', null],
            'secret-shaped token' => ['9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08', null],
            'digit run' => ['order12345678', null],
            // a value interpolated by RAISE '%', name must never be taken for a code
            'bare name' => ['Rahul', null],
            'bare name lower' => ['jdoe', null],
            'dotted name' => ['john.doe', null],
            'dashed name' => ['john-smith', null],
            'dashed uuid' => ['9f86d081-884c-7d65-9a2f-eaa0c55ad015', null],
            'single lowercase word' => ['locked', null],
            'colon after a name' => ['Rahul: hello', null],
            'colon after dotted name' => ['john.doe: hello', null],
            // every code the platforms use today
            'p1' => ['user_cap', 'user_cap'],
            'p2' => ['repo_op_busy', 'repo_op_busy'],
            'p3' => ['tenant_already_exists', 'tenant_already_exists'],
            'p4' => ['PY050', 'PY050'],
            'p5' => ['CODE_NOT_FOUND: no pending pairing code matches', 'CODE_NOT_FOUND:'],
            'p6' => ['CODE_EXPIRED: pairing code has expired', 'CODE_EXPIRED:'],
            'p7' => ['INVALID_DEVICE_CODE: unknown or superseded device code', 'INVALID_DEVICE_CODE:'],
            'p8' => ['UNKNOWN_PACK: pack x not found', 'UNKNOWN_PACK:'],
            'p9' => ['RATE_LIMITED: too many attempts, try again later', 'RATE_LIMITED:'],
            'p10' => ['project_member_cap_reached', 'project_member_cap_reached'],
            'p11' => ['ORDER_ALREADY_CAPTURED', 'ORDER_ALREADY_CAPTURED'],
            'p12' => ['tenant_creator_row_undeletable', 'tenant_creator_row_undeletable'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('appCodeProvider')]
    public function testAppErrorCodeFromPrimaryLineOnly(string $primary, ?string $expected): void
    {
        $e = $this->exc($primary . ' | DETAIL: Key (x)=(y)');
        $this->assertSame($expected, $e->getAppErrorCode());
        if ($expected !== null) {
            $this->assertStringContainsString($expected, GatewayException::formatSafeSuffix($e->getSafeContext()));
        }
        $this->assertNoLeak($e);
    }

    public function testAppCodeNeverTakenFromDetailHintContext(): void
    {
        foreach (['DETAIL', 'HINT', 'CONTEXT'] as $k) {
            $e = $this->exc("Something went wrong for user | $k: not_found")  ;
            $this->assertNull($e->getAppErrorCode(), $k);
            $e = $this->exc("Something went wrong for user\n$k: slug_taken");
            $this->assertNull($e->getAppErrorCode(), $k);
        }
    }

    public function testSystemSqlstateDoesNotYieldAppCode(): void
    {
        $e = $this->exc('syntax_error_here', ['sqlstate' => '42601']);
        $this->assertNull($e->getAppErrorCode());
        $e = $this->exc('not_found', ['sqlstate' => 'P0001']);
        $this->assertSame('not_found', $e->getAppErrorCode());
        $e = $this->exc('not_found', ['sqlstate' => 'ZZ001']);
        $this->assertSame('not_found', $e->getAppErrorCode(), 'custom class');
    }

    public function testStructuredFieldsWinOverParsing(): void
    {
        $e = $this->exc('other_code', ['app_code' => 'slug_taken', 'sqlstate' => 'P0001', 'correlation_id' => 'gw-abc123']);
        $this->assertSame('slug_taken', $e->getAppErrorCode());
        $this->assertSame('gw-abc123', $e->getCorrelationId());
        $this->assertNull($e->getClientRef());
    }

    public function testSmugglingIsNotLifted(): void
    {
        // identifier-shaped sentinels planted where a naive regex would lift them
        $cases = [
            'Key (name)=(Rahul column Sharma) in DETAIL' => 'duplicate key value violates unique constraint "users_name_key" | DETAIL: Key (name)=(Rahul column Sharma) already exists.',
            'RAISE with constraint quote' => 'Duplicate for constraint "smuggled_ident"',
            'RAISE with column word' => 'Order for column "smuggled_col" is invalid',
            'forged trailing segment' => 'oops | TABLE: smuggled_tbl | COLUMN: smuggled_col | SCHEMA: smuggled_sch',
            'DETAIL forged' => "x\nDETAIL: null value in column \"smuggled_col\" violates not-null constraint",
        ];
        foreach ($cases as $label => $cause) {
            $e = $this->exc($cause);
            $all = json_encode($e->getSafeContext()) . $e->getMessage() . (string) $e;
            foreach (['smuggled_ident', 'smuggled_col', 'smuggled_tbl', 'smuggled_sch', 'Sharma', 'Rahul'] as $s) {
                $this->assertStringNotContainsString($s, $all, "$label leaked $s");
            }
        }
        // PostgreSQL's own phrasing still yields identifiers, from the primary line only
        $e = $this->exc('null value in column "email" of relation "users" violates not-null constraint | DETAIL: Failing row contains (1, x@y.z)');
        $this->assertSame('email', $e->getColumn());
        $this->assertSame('users', $e->getSafeContext()['table']);
        $this->assertSame('23502', $e->getSqlState());
        // a secret-shaped "identifier" is refused
        $e = $this->exc('duplicate key value violates unique constraint "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b"');
        $this->assertNull($e->getConstraint());
    }

    public function testInlineDetailIsCutFromPrimaryLine(): void
    {
        $e = $this->exc('duplicate key value violates unique constraint "users_email_key" DETAIL:  Key (email)=(jane.sentinel@example.test) already exists.');
        $this->assertSame('users_email_key', $e->getConstraint());
        $this->assertNoLeak($e);
    }

    public function testRealGatewayShapes(): void
    {
        // shapes captured from a real gateway 4.8.2 against PostgreSQL 15
        $e = $this->exc('duplicate key value violates unique constraint "users_email_key" | DETAIL: Key (email)=(a@b.c) already exists. | SCHEMA: public | TABLE: users');
        $this->assertSame(['23505', 'users_email_key', 'users', 'public'], [$e->getSqlState(), $e->getConstraint(), $e->getSafeContext()['table'] ?? null, $e->getSafeContext()['schema'] ?? null]);
        $e = $this->exc('duplicate key value violates unique constraint "users_email_key" - Key (email)=(a@b.c) already exists.');
        $this->assertSame('users_email_key', $e->getConstraint(), 'migration/seeder " - " format');
        $e = $this->exc('invalid input syntax for type uuid: "a@b.c"');
        $this->assertSame('22P02', $e->getSqlState());
        $this->assertNoLeak($e);
        // a RAISE cannot borrow the trailing-segment trust
        $e = $this->exc('Something failed | SCHEMA: smuggled_sch | TABLE: smuggled_tbl');
        $this->assertArrayNotHasKey('table', $e->getSafeContext());
        $e = $this->exc('not_found: x | TABLE: smuggled_tbl');
        $this->assertArrayNotHasKey('table', $e->getSafeContext());
    }

    public function testMissingFunctionPhrasing(): void
    {
        $e = $this->exc('function auth_rt_inspect(text, integer) does not exist | HINT: No function matches the given name and argument types.');
        $this->assertSame('42883', $e->getSqlState());
        $this->assertSame('auth_rt_inspect', $e->getSafeContext()['function']);
    }

    public function testRawCauseNotReachableByDumpersOrCasts(): void
    {
        $e = $this->exc('duplicate key | DETAIL: Key (email)=(jane.sentinel@example.test) already exists. +919876500123');
        $this->assertNotNull($e->getRawCauseForDebug());
        $this->assertNoLeak($e);
        $this->assertStringNotContainsString('jane.sentinel', var_export($e, true));
        $this->assertStringNotContainsString('jane.sentinel', print_r((array) $e, true));
        $this->assertStringNotContainsString('jane.sentinel', json_encode(get_object_vars($e)) ?: '');
        $rp = (new \ReflectionObject($e));
        foreach ($rp->getProperties() as $p) {
            $p->setAccessible(true);
            if ($p->isStatic()) {
                continue;
            }
            $this->assertStringNotContainsString('jane.sentinel', print_r($p->getValue($e), true), $p->getName());
        }
    }

    public function testUnserializeKeepsSafeFields(): void
    {
        $e = $this->exc('not_found', ['sqlstate' => 'P0001']);
        $u = unserialize(serialize($e));
        $this->assertSame('P0001', $u->getSqlState());
        $this->assertSame('not_found', $u->getAppErrorCode());
        $this->assertNull($u->getRawCauseForDebug());
    }

    private function assertNoLeak(GatewayException $e): void
    {
        $this->assertClean($e->getMessage() . (string) $e . print_r($e, true) . serialize($e), 'exception forms');
    }
}
