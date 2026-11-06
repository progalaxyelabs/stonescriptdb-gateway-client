<?php

namespace StoneScriptDB\Tests;

use PHPUnit\Framework\TestCase;
use StoneScriptDB\GatewayClient;
use StoneScriptDB\GatewayException;

/**
 * Every request type, observed on the wire against a local PHP built-in
 * server that echoes back the method, path, credentials and body it received.
 */
class GatewayClientWireTest extends TestCase
{
    private static string $dir;
    private static $proc;
    private static int $port;
    private static $proc2;
    private static int $port2;
    private static string $hitLog;

    public static function setUpBeforeClass(): void
    {
        self::$dir = __DIR__ . '/fixtures/_wiresrv';
        @mkdir(self::$dir, 0775, true);
        self::$hitLog = self::$dir . '/hits.log';
        file_put_contents(self::$dir . '/echo.php', '<?php
header("Content-Type: application/json");
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($path === "/admin/database/create") { http_response_code(201); }
if ($path === "/v2/migrate-all") { http_response_code(202); }
if ($path === "/health") { echo json_encode(["status"=>"healthy","hdr"=>$_SERVER["HTTP_AUTHORIZATION"] ?? null]); return; }
if (str_starts_with($path, "/redirect/")) {
    http_response_code(302);
    header("Location: " . getenv("WIRE_REDIRECT_TARGET") . "/landed");
    return;
}
$echo = [
  "method" => $_SERVER["REQUEST_METHOD"],
  "path" => $path,
  "authorization" => $_SERVER["HTTP_AUTHORIZATION"] ?? null,
  "admin_header" => $_SERVER["HTTP_X_GATEWAY_ADMIN_TOKEN"] ?? null,
  "body" => json_decode(file_get_contents("php://input"), true),
  "rows" => [["authorization" => $_SERVER["HTTP_AUTHORIZATION"] ?? null, "admin_header" => $_SERVER["HTTP_X_GATEWAY_ADMIN_TOKEN"] ?? null]],
];
echo json_encode($echo);');
        file_put_contents(self::$dir . '/sink.php', '<?php
file_put_contents(getenv("WIRE_HIT_LOG"), ($_SERVER["HTTP_AUTHORIZATION"] ?? "none") . "|" . ($_SERVER["HTTP_X_GATEWAY_ADMIN_TOKEN"] ?? "none") . "\n", FILE_APPEND);
header("Content-Type: application/json"); echo json_encode(["rows"=>[]]);');
        self::$port2 = random_int(20000, 29999);
        self::$port = random_int(30000, 40000);
        $env = array_merge(getenv(), ['WIRE_HIT_LOG' => self::$hitLog, 'WIRE_REDIRECT_TARGET' => 'http://127.0.0.1:' . self::$port2]);
        $null = ['file', '/dev/null', 'w'];
        self::$proc2 = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$port2, '-t', self::$dir, self::$dir . '/sink.php'], [1 => $null, 2 => $null], $p2, null, $env);
        self::$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', self::$dir, self::$dir . '/echo.php'], [1 => $null, 2 => $null], $p1, null, $env);
        usleep(600000);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$proc, self::$proc2] as $p) {
            if (is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        foreach (['echo.php', 'sink.php', 'hits.log'] as $f) {
            @unlink(self::$dir . '/' . $f);
        }
        @rmdir(self::$dir);
    }

    private function client(?string $platformToken, ?string $adminToken, ?string $deployToken = null): GatewayClient
    {
        return new GatewayClient('http://127.0.0.1:' . self::$port, 'myapp', 'main', 'tenant', null, $adminToken, $platformToken, $deployToken);
    }

    private function archive(): string
    {
        $f = tempnam(sys_get_temp_dir(), 'ar');
        file_put_contents($f, 'x');
        return $f;
    }

    public function testCallFunctionSendsRuntimeTokenOnly(): void
    {
        $rows = $this->client('ptok', 'atok', 'dtok')->callFunction('f', []);
        $this->assertSame('Bearer ptok', $rows[0]['authorization']);
        $this->assertNull($rows[0]['admin_header']);
    }

    public function testCallFunctionNeverFallsBackToTheDeployToken(): void
    {
        $rows = $this->client(null, 'atok', 'dtok')->callFunction('f', []);
        $this->assertNull($rows[0]['authorization']);
    }

    public function testRegisterPlatformUsesAdminCredentialWhenSet(): void
    {
        $r = $this->raw($this->client('ptok', 'atok', 'dtok'), 'registerPlatform');
        $this->assertSame('/platform/register', $r['path']);
        $this->assertSame('Bearer atok', $r['authorization']);
        $this->assertSame(['platform' => 'myapp'], $r['body']);
    }

    public function testRegisterPlatformFallsBackToDeployTokenNeverRuntime(): void
    {
        $r = $this->raw($this->client('ptok', null, 'dtok'), 'registerPlatform');
        $this->assertSame('Bearer dtok', $r['authorization']);
        $r = $this->raw($this->client('ptok', null, null), 'registerPlatform');
        $this->assertNull($r['authorization']);
    }

    public function testCreateTenantDatabaseUsesRuntimeTokenNeverAdminToken(): void
    {
        $r = $this->raw($this->client('ptok', 'atok', 'dtok'), 'createDatabase', 'tenant', 'u1');
        $this->assertSame('/admin/database/create', $r['path']);
        $this->assertSame('Bearer ptok', $r['authorization']);
        $this->assertNull($r['admin_header']);
        $this->assertSame('u1', $r['body']['uuid']);
    }

    public function testCreateTenantDatabaseFallsBackToDeployTokenWhenNoRuntimeToken(): void
    {
        $r = $this->raw($this->client(null, null, 'dtok'), 'createDatabase', 'tenant', 'u1');
        $this->assertSame('Bearer dtok', $r['authorization']);
    }

    public function testCreateMainDatabaseUsesDeployToken(): void
    {
        $r = $this->raw($this->client('ptok', 'atok', 'dtok'), 'createDatabase', 'main');
        $this->assertSame('Bearer dtok', $r['authorization']);
        $this->assertNull($r['admin_header']);
        $this->assertArrayNotHasKey('uuid', $r['body']);
    }

    public function testMigrateMainUsesDeployTokenWithoutAdminHeader(): void
    {
        $r = $this->raw($this->client('ptok', 'atok', 'dtok'), 'migrateV2', 'main');
        $this->assertSame('/v2/migrate', $r['path']);
        $this->assertSame('Bearer dtok', $r['authorization']);
        $this->assertNull($r['admin_header']);
        $this->assertFalse($r['body']['force']);
        $this->assertArrayNotHasKey('allow', $r['body']);
        $this->assertArrayNotHasKey('uuid', $r['body']);
    }

    public function testMigrateNewTenantUsesRuntimeToken(): void
    {
        $r = $this->raw($this->client('ptok', 'atok', 'dtok'), 'migrateV2', 'tenant', 'u1');
        $this->assertSame('Bearer ptok', $r['authorization']);
        $this->assertNull($r['admin_header']);
        $this->assertSame('u1', $r['body']['uuid']);
    }

    public function testMigrateForceSendsDeployTokenAndAdminHeader(): void
    {
        $r = $this->raw($this->client('ptok', 'atok', 'dtok'), 'migrateV2', 'tenant', 'u1', true);
        $this->assertSame('Bearer dtok', $r['authorization']);
        $this->assertSame('atok', $r['admin_header']);
        $this->assertTrue($r['body']['force']);
    }

    public function testMigrateAllowSendsDeployTokenAdminHeaderAndList(): void
    {
        $r = $this->raw($this->client('ptok', 'atok', 'dtok'), 'migrateV2', 'main', null, false, ['drop_column']);
        $this->assertSame('Bearer dtok', $r['authorization']);
        $this->assertSame('atok', $r['admin_header']);
        $this->assertSame(['drop_column'], $r['body']['allow']);
    }

    public function testMigrateAllUsesDeployToken(): void
    {
        $r = $this->raw($this->client('ptok', 'atok', 'dtok'), 'migrateAllV2', 'tenant');
        $this->assertSame('/v2/migrate-all', $r['path']);
        $this->assertSame('Bearer dtok', $r['authorization']);
        $this->assertNull($r['admin_header']);
        $r = $this->raw($this->client('ptok', 'atok', 'dtok'), 'migrateAllV2', 'main', true);
        $this->assertSame('atok', $r['admin_header']);
        $this->assertSame('Bearer dtok', $r['authorization']);
    }

    public function testForceWithoutAdminTokenSendsNoAdminHeader(): void
    {
        $r = $this->raw($this->client('ptok', null, 'dtok'), 'migrateV2', 'main', null, true);
        $this->assertNull($r['admin_header']);
    }

    public function testUploadSchemaUsesDeployToken(): void
    {
        $f = $this->archive();
        try {
            $r = $this->raw($this->client('ptok', 'atok', 'dtok'), 'uploadSchema', $f, 'main');
            $this->assertSame('/platform/myapp/schema', $r['path']);
            $this->assertSame('Bearer dtok', $r['authorization']);
            $this->assertNull($r['admin_header']);
            // a client holding only the runtime token sends no credential here
            $r = $this->raw($this->client('ptok', 'atok', null), 'uploadSchema', $f, 'main');
            $this->assertNull($r['authorization']);
        } finally {
            @unlink($f);
        }
    }

    public function testHealthCheckSendsNoCredential(): void
    {
        $this->assertTrue($this->client('ptok', 'atok')->healthCheck());
    }

    public function testRedirectsAreNotFollowedAndCredentialsDoNotLeave(): void
    {
        @unlink(self::$hitLog);
        $c = new GatewayClient('http://127.0.0.1:' . self::$port . '/redirect', 'myapp', 'main', null, null, 'atok', 'ptok', 'dtok');
        try {
            $c->migrateV2('main', 'main', true);
            $this->fail('a redirect must not be treated as success');
        } catch (GatewayException $e) {
            $this->assertStringContainsString('HTTP 302', $e->getMessage());
        }
        $this->assertFileDoesNotExist(self::$hitLog, 'the redirect target must never be contacted');
    }

    /** Invoke $method and return the server's echo of what it received. */
    private function raw(GatewayClient $c, string $method, ...$args): array
    {
        return $c->$method(...$args);
    }
}
