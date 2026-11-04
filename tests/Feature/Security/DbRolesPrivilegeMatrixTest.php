<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * DG-0.1 privilege matrix checks against a DB where roles.sql has been applied.
 *
 * Skipped unless DB_ROLES_TEST=1 so default CI / local suites stay green without
 * touching Abdullah's demo database (ADR-0002 gate G1).
 */
class DbRolesPrivilegeMatrixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if ((string) env('DB_ROLES_TEST', '') !== '1') {
            $this->markTestSkipped(
                'DB_ROLES_TEST=1 required; roles are not applied on the default CI/local DB (DG-0.1 gate).'
            );
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required for DB role matrix tests.');
        }

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->markTestSkipped('PostgreSQL not available: '.$e->getMessage());
        }
    }

    public function test_core_roles_exist_with_least_privilege_flags(): void
    {
        $roles = DB::select(
            <<<'SQL'
            SELECT rolname, rolsuper, rolcreatedb, rolcreaterole, rolcanlogin
            FROM pg_roles
            WHERE rolname IN (
                'lumina_migrator',
                'lumina_app',
                'lumina_layer_owner',
                'lumina_backup'
            )
            ORDER BY rolname
            SQL
        );

        $byName = collect($roles)->keyBy('rolname');

        foreach (['lumina_migrator', 'lumina_app', 'lumina_layer_owner', 'lumina_backup'] as $name) {
            $this->assertTrue($byName->has($name), "Expected role {$name} to exist.");
            $row = $byName->get($name);
            $this->assertFalse((bool) $row->rolsuper, "{$name} must be NOSUPERUSER.");
            $this->assertFalse((bool) $row->rolcreatedb, "{$name} must be NOCREATEDB.");
            $this->assertFalse((bool) $row->rolcreaterole, "{$name} must be NOCREATEROLE.");
        }

        $this->assertTrue((bool) $byName->get('lumina_migrator')->rolcanlogin);
        $this->assertTrue((bool) $byName->get('lumina_app')->rolcanlogin);
        $this->assertFalse((bool) $byName->get('lumina_layer_owner')->rolcanlogin);
        $this->assertTrue((bool) $byName->get('lumina_backup')->rolcanlogin);
    }

    public function test_staging_schema_exists_and_app_has_create(): void
    {
        $schema = DB::selectOne(
            "SELECT 1 AS ok FROM information_schema.schemata WHERE schema_name = 'staging'"
        );
        $this->assertNotNull($schema, 'Schema staging must exist.');

        $create = DB::selectOne(
            <<<'SQL'
            SELECT has_schema_privilege('lumina_app', 'staging', 'CREATE') AS can_create,
                   has_schema_privilege('lumina_app', 'public', 'CREATE') AS public_create
            SQL
        );

        $this->assertTrue((bool) $create->can_create, 'lumina_app must CREATE in staging.');
        $this->assertFalse((bool) $create->public_create, 'lumina_app must not CREATE in public.');
    }
}
