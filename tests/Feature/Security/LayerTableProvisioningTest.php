<?php

namespace Tests\Feature\Security;

use App\Models\Layer;
use App\Services\FeatureTableProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;
use Throwable;

/**
 * DG-0.2 layer provisioning: create-empty path + promote helper.
 *
 * Default suite (sqlite / no roles.sql): exercises fallback createEmpty and
 * name validation. Promote-specific assertions soft-skip unless the SECURITY
 * DEFINER function exists (throwaway DB with roles.sql + migration).
 *
 * Full privilege checks under lumina_app remain behind DB_ROLES_TEST=1.
 */
class LayerTableProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_empty_fallback_creates_table_when_promote_unavailable(): void
    {
        $provisioner = app(FeatureTableProvisioner::class);

        if ($provisioner->isPromoteAvailable()) {
            $this->markTestSkipped('Promote function present; fallback path covered when roles are absent.');
        }

        $table = 'feat_prov_empty_'.substr(md5((string) microtime(true)), 0, 8);
        $provisioner->createEmpty($table, 'Point');

        $this->assertTrue(Schema::hasTable($table));

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::drop($table);
        }
    }

    public function test_ensure_for_creates_missing_feature_table(): void
    {
        $provisioner = app(FeatureTableProvisioner::class);
        $table = 'feat_prov_ensure_'.substr(md5((string) microtime(true)), 0, 8);

        $layer = new Layer([
            'table_name' => $table,
            'geometry_type' => 'Point',
        ]);

        $this->assertTrue($provisioner->ensureFor($layer));
        $this->assertTrue(Schema::hasTable($table));
        $this->assertFalse($provisioner->ensureFor($layer));

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::drop($table);
        }
    }

    public function test_assert_safe_table_name_rejects_unsafe_and_reserved(): void
    {
        $provisioner = app(FeatureTableProvisioner::class);

        $this->assertSame('roads_2026', $provisioner->assertSafeTableName('Roads_2026'));

        foreach (['', '1bad', 'Has-Dash', 'semi;drop', 'layers', 'users'] as $bad) {
            try {
                $provisioner->assertSafeTableName($bad);
                $this->fail("Expected InvalidArgumentException for [{$bad}]");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_promote_path_create_empty_when_function_exists(): void
    {
        $provisioner = app(FeatureTableProvisioner::class);

        if (! $provisioner->isPromoteAvailable()) {
            $this->markTestSkipped(
                'lumina_promote_layer_table absent (apply roles.sql on a non-demo DB, then migrate).'
            );
        }

        $table = 'feat_prov_promote_'.substr(md5((string) microtime(true)), 0, 8);
        $provisioner->createEmpty($table, 'LineString');

        $this->assertTrue(Schema::hasTable($table));

        $owner = DB::selectOne(
            <<<'SQL'
            SELECT pg_get_userbyid(c.relowner) AS owner
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relname = ?
            SQL,
            [$table]
        );
        $this->assertSame('lumina_layer_owner', $owner->owner);

        $stagingGone = DB::selectOne(
            'SELECT to_regclass(?) AS oid',
            ['staging.'.$table]
        );
        $this->assertNull($stagingGone->oid);

        Schema::drop($table);
    }

    public function test_promote_sql_rejects_unsafe_names_when_function_exists(): void
    {
        $provisioner = app(FeatureTableProvisioner::class);

        if (! $provisioner->isPromoteAvailable()) {
            $this->markTestSkipped(
                'lumina_promote_layer_table absent (apply roles.sql on a non-demo DB, then migrate).'
            );
        }

        try {
            DB::statement('SELECT public.lumina_promote_layer_table(?, ?)', ['Bad-Name', 'ok_target']);
            $this->fail('Expected unsafe staging name to be rejected by SQL.');
        } catch (Throwable $e) {
            $this->assertStringContainsStringIgnoringCase('unsafe', $e->getMessage());
        }
    }
}
