<?php

namespace StoneScriptDB\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StoneScriptDB\GatewayClient;
use StoneScriptDB\GatewayException;

/**
 * The client against a REAL gateway + PostgreSQL. The stack and the fixture platform are created by the
 * StoneScriptPHP framework repository's tests/E2E/run-e2e.sh, which exports:
 *   STONESCRIPT_E2E_GATEWAY_URL, STONESCRIPT_E2E_PLATFORM (a platform provisioned from its fixture schema)
 * Without them every test here SKIPS LOUDLY (banner on STDERR); STONESCRIPT_E2E_REQUIRE=1 turns that into a failure.
 */
final class RealGatewayClientTest extends TestCase
{
    private const FORBIDDEN = ['jane.sentinel', 'Key (', 'DETAIL', 'HINT', 'Failing row', 'is not present in table', 'smuggled_key', 'smuggled_col'];

    public static function setUpBeforeClass(): void
    {
        if ((string) getenv('STONESCRIPT_E2E_GATEWAY_URL') === '' || (string) getenv('STONESCRIPT_E2E_PLATFORM') === '') {
            $msg = 'E2E NOT RUN: no real gateway stack (STONESCRIPT_E2E_GATEWAY_URL / STONESCRIPT_E2E_PLATFORM unset). '
                . 'The client error contract is NOT verified against a real gateway in this run.';
            fwrite(STDERR, "\n!!!!!! $msg !!!!!!\n");
            if (getenv('STONESCRIPT_E2E_REQUIRE') === '1') {
                self::fail($msg . ' (STONESCRIPT_E2E_REQUIRE=1)');
            }
            self::markTestSkipped($msg);
        }
    }

    /** @return array<string,array{0:string,1:string,2:array}> */
    public static function scenarios(): array
    {
        return [
            'unique' => ['unique', 'x', ['sqlstate' => '23505', 'constraint' => 'users_email_key', 'table' => 'users']],
            'fk' => ['fk', 'x', ['sqlstate' => '23503', 'constraint' => 'members_user_fk', 'table' => 'members']],
            'not null' => ['notnull', 'x', ['sqlstate' => '23502', 'column' => 'name', 'table' => 'users']],
            'check' => ['check', 'x', ['sqlstate' => '23514', 'constraint' => 'users_age_check']],
            'bad uuid' => ['baduuid', 'x', ['sqlstate' => '22P02']],
            'bad int' => ['badint', 'x', ['sqlstate' => '22P02']],
            'missing function' => ['nofn', 'x', ['sqlstate' => '42883']],
            'missing table' => ['notable', 'x', ['sqlstate' => '42P01', 'table' => 'ghost_table']],
            'code' => ['raise', 'code', ['app' => 'not_found']],
            'code slug' => ['raise', 'code_slug', ['app' => 'slug_taken']],
            'code + text' => ['raise', 'code_text', ['app' => 'job_conflict:']],
            'bracket ER' => ['raise', 'tag_er', ['app' => '[ER102]']],
            'bracket public' => ['raise', 'tag_public', ['app' => '[public:code:409]']],
            'sentence' => ['raise', 'sentence', ['app' => null]],
            'value interpolating' => ['raise', 'value_exists', ['app' => null]],
            'secondary fields' => ['raise', 'detail', ['app' => null]],
            'smuggling' => ['raise', 'smuggle', ['app' => null, 'constraint' => null, 'column' => null]],
        ];
    }

    #[DataProvider('scenarios')]
    public function testExtractionAgainstRealGateway(string $kind, string $arg, array $expect): void
    {
        $email = 'jane.sentinel+' . bin2hex(random_bytes(3)) . '@e2e.test';
        $client = new GatewayClient((string) getenv('STONESCRIPT_E2E_GATEWAY_URL'), (string) getenv('STONESCRIPT_E2E_PLATFORM'), 'main', null, null);
        switch ($kind) {
            case 'unique':
                $client->callFunction('create_user', [$email, 'One', 30]);
                $call = ['create_user', [$email, 'Two', 31]];
                break;
            case 'fk':
                $call = ['create_member', ['00000000-0000-0000-0000-0000000000a1', '00000000-0000-0000-0000-0000000000a2']];
                break;
            case 'notnull':
                $call = ['create_user', [$email, null, 1]];
                break;
            case 'check':
                $call = ['create_user', [$email, 'Neg', -5]];
                break;
            case 'baduuid':
                $call = ['user_by_uuid', ['not-a-uuid-' . $email]];
                break;
            case 'badint':
                $call = ['double_it', ['abc-' . $email]];
                break;
            case 'nofn':
                $call = ['no_such_fn', [$email]];
                break;
            case 'notable':
                $call = ['ghost_table_fn', []];
                break;
            default:
                $call = ['raise_it', [$arg, $email]];
        }
        try {
            $client->callFunction($call[0], $call[1]);
            $this->fail('the call must fail');
        } catch (GatewayException $e) {
            $this->assertSame($expect['sqlstate'] ?? null, $e->getSqlState(), 'sqlstate');
            $this->assertSame($expect['constraint'] ?? null, $e->getConstraint(), 'constraint');
            $this->assertSame($expect['column'] ?? null, $e->getColumn(), 'column');
            if (array_key_exists('table', $expect)) {
                $this->assertSame($expect['table'], $e->getSafeContext()['table'] ?? null, 'table');
            }
            $this->assertSame($expect['app'] ?? null, $e->getAppErrorCode(), 'app code');
            if (($expect['app'] ?? null) !== null) {
                $this->assertStringContainsString('code=' . $expect['app'], $e->getMessage());
            }
            foreach ([$e->getMessage(), (string) $e, print_r($e, true), serialize($e), (string) $client->getLastError()] as $i => $form) {
                foreach (self::FORBIDDEN as $s) {
                    if (str_contains($form, $s)) {
                        $this->fail("'$s' leaked via form #$i");
                    }
                }
            }
            $this->assertNotSame('', (string) $e->getRawCauseForDebug(), 'raw cause stays available for debugging');
            $this->assertNull($e->getPrevious());
        }
    }
}
