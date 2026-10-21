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
        $this->assertSame('email', $e->getColumn());
        $this->assertNotNull($e->getCorrelationId());
        $ctx = $e->getSafeContext();
        $this->assertSame('create_user', $ctx['function']);
        $this->assertSame('users', $ctx['table']);
        $this->assertStringContainsString('constraint=users_email_key', $e->getMessage());
        $this->assertStringContainsString('function=create_user', $e->getMessage());
        // explicit opt-in still works, and so does the deprecated accessor
        $this->assertStringContainsString('jane.sentinel@example.test', $e->getRawCauseForDebug());
        $this->assertSame($e->getRawCauseForDebug(), $e->getCause());
    }

    public function testNonIdentifierFieldsAreDropped(): void
    {
        $e = new GatewayException('x', 500, null, ['error' => 'query_failed', 'constraint' => 'a@b.c', 'sqlstate' => '23505', 'column' => 'has space']);
        $this->assertNull($e->getConstraint());
        $this->assertNull($e->getColumn());
        $this->assertSame('23505', $e->getSqlState());
    }
}
