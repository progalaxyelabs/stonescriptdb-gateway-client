<?php

namespace StoneScriptDB\Tests;

use PHPUnit\Framework\TestCase;
use StoneScriptDB\GatewayClient;
use StoneScriptDB\GatewayException;

class GatewayClientPlatformTokenTest extends TestCase
{
    private function client(?string $platformToken, ?string $adminToken = null): object
    {
        $c = new GatewayClient('http://gateway:9000', 'myapp', 'main', null, null, $adminToken, $platformToken);
        return new class($c) {
            public function __construct(private GatewayClient $c) {}
            public function header(bool $admin): ?string
            {
                $m = new \ReflectionMethod($this->c, 'buildAuthorizationHeader');
                return $m->invoke($this->c, $admin);
            }
            public function inner(): GatewayClient { return $this->c; }
        };
    }

    public function testPlatformTokenSentWhenConfigured(): void
    {
        $this->assertSame('Authorization: Bearer ptok', $this->client('ptok')->header(false));
    }

    public function testNoHeaderWhenNoTokenConfigured(): void
    {
        $this->assertNull($this->client(null)->header(false));
        $this->assertNull($this->client('')->header(false));
    }

    public function testAdminEndpointPrefersAdminToken(): void
    {
        $c = $this->client('ptok', 'atok');
        $this->assertSame('Authorization: Bearer atok', $c->header(true));
        $this->assertSame('Authorization: Bearer ptok', $c->header(false));
    }

    public function testAdminEndpointFallsBackToPlatformToken(): void
    {
        $this->assertSame('Authorization: Bearer ptok', $this->client('ptok')->header(true));
    }

    public function testSetterAndPresenceFlag(): void
    {
        $c = $this->client(null);
        $this->assertFalse($c->inner()->hasPlatformToken());
        $c->inner()->setPlatformToken('x');
        $this->assertTrue($c->inner()->hasPlatformToken());
        $this->assertSame('Authorization: Bearer x', $c->header(false));
        $c->inner()->setPlatformToken(null);
        $this->assertNull($c->header(false));
    }

    public function testTokenNotShownInDump(): void
    {
        $c = $this->client('super-secret-token-value', 'admin-secret-value')->inner();
        ob_start();
        var_dump($c);
        $dump = ob_get_clean();
        $this->assertStringNotContainsString('super-secret-token-value', $dump);
        $this->assertStringNotContainsString('admin-secret-value', $dump);
    }

    /** End-to-end over a local PHP built-in server: header is on the wire; 401/403 messages are clear and token-free. */
    public function testWireHeaderAndErrorMessages(): void
    {
        $dir = __DIR__ . '/fixtures/_tokensrv';
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/r.php', '<?php
$h = $_SERVER["HTTP_AUTHORIZATION"] ?? "";
header("Content-Type: application/json");
if ($h === "") { http_response_code(401); echo json_encode(["error"=>"unauthorized"]); return; }
if ($h !== "Bearer good") { http_response_code(403); echo json_encode(["error"=>"platform_isolation_violation"]); return; }
echo json_encode(["rows"=>[["echo"=>$h]]]);');
        $port = random_int(20000, 40000);
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $dir, $dir . '/r.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        usleep(500000);
        try {
            $ok = new GatewayClient("http://127.0.0.1:$port", 'myapp', 'main', null, null, null, 'good');
            $this->assertSame('Bearer good', $ok->callFunction('f')[0]['echo']);

            $none = new GatewayClient("http://127.0.0.1:$port", 'myapp', 'main');
            try { $none->callFunction('f'); $this->fail('expected 401'); }
            catch (GatewayException $e) {
                $this->assertStringContainsString('HTTP 401', $e->getMessage());
                $this->assertStringContainsString('DB_GATEWAY_PLATFORM_TOKEN', $e->getMessage());
            }

            $bad = new GatewayClient("http://127.0.0.1:$port", 'myapp', 'main', null, null, null, 'wrong-secret');
            try { $bad->callFunction('f'); $this->fail('expected 403'); }
            catch (GatewayException $e) {
                $this->assertStringContainsString('HTTP 403', $e->getMessage());
                $this->assertStringNotContainsString('wrong-secret', $e->getMessage());
                $this->assertStringNotContainsString('wrong-secret', $bad->getLastError() ?? '');
            }
        } finally {
            $st = proc_get_status($proc);
            proc_terminate($proc);
            proc_close($proc);
            @unlink($dir . '/r.php');
            @rmdir($dir);
        }
    }
}
