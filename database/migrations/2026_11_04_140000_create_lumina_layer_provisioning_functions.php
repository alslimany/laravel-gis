<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DG-0.2 — SECURITY DEFINER layer provisioning helpers.
 *
 * Gate (ADR-0002 G1 / DG-0.7): this migration MUST NOT create roles or run
 * docker/postgres/roles.sql. When lumina_layer_owner / lumina_app are absent
 * (Abdullah's local demo DB, default CI), the migration is a deliberate no-op.
 *
 * Apply roles.sql on a throwaway/staging DB first; re-run migrate (or migrate:fresh
 * on that stack) so the functions are created owned by lumina_layer_owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (! $this->rolesReady()) {
            Log::info('dg02.provisioning_functions.skipped', [
                'reason' => 'lumina_layer_owner and/or lumina_app roles are absent; apply docker/postgres/roles.sql on a non-demo DB first.',
            ]);

            return;
        }

        DB::unprepared(<<<'SQL'
CREATE SCHEMA IF NOT EXISTS staging;

COMMENT ON SCHEMA staging IS
    'Scratch schema for imports (DG-0.2). Promote via public.lumina_promote_layer_table.';

GRANT USAGE, CREATE ON SCHEMA staging TO lumina_app;
GRANT USAGE ON SCHEMA staging TO lumina_migrator, lumina_layer_owner;

CREATE OR REPLACE FUNCTION public.lumina_promote_layer_table(
    p_staging_name text,
    p_target_name text
)
RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public, staging, pg_temp
AS $fn$
DECLARE
    v_staging text;
    v_target text;
    v_reserved text[] := ARRAY[
        'layers', 'maps', 'users', 'organizations', 'migrations', 'sessions', 'jobs', 'cache'
    ];
BEGIN
    v_staging := lower(btrim(COALESCE(p_staging_name, '')));
    v_target := lower(btrim(COALESCE(p_target_name, '')));

    IF v_staging = '' OR v_staging !~ '^[a-z][a-z0-9_]*$' THEN
        RAISE EXCEPTION 'unsafe staging table name: %', p_staging_name
            USING ERRCODE = '22023';
    END IF;

    IF v_target = '' OR v_target !~ '^[a-z][a-z0-9_]*$' THEN
        RAISE EXCEPTION 'unsafe target table name: %', p_target_name
            USING ERRCODE = '22023';
    END IF;

    IF v_target = ANY (v_reserved) THEN
        RAISE EXCEPTION 'target table name is reserved: %', v_target
            USING ERRCODE = '22023';
    END IF;

    IF to_regclass(format('staging.%I', v_staging)) IS NULL THEN
        RAISE EXCEPTION 'staging table staging.% does not exist', v_staging
            USING ERRCODE = '42P01';
    END IF;

    IF to_regclass(format('public.%I', v_target)) IS NOT NULL THEN
        RAISE EXCEPTION 'target table public.% already exists', v_target
            USING ERRCODE = '42P07';
    END IF;

    EXECUTE format('ALTER TABLE staging.%I SET SCHEMA public', v_staging);

    IF v_staging <> v_target THEN
        EXECUTE format('ALTER TABLE public.%I RENAME TO %I', v_staging, v_target);
    END IF;

    EXECUTE format('ALTER TABLE public.%I OWNER TO lumina_layer_owner', v_target);

    IF to_regclass(format('public.%I', v_target || '_id_seq')) IS NOT NULL THEN
        EXECUTE format(
            'ALTER SEQUENCE public.%I OWNER TO lumina_layer_owner',
            v_target || '_id_seq'
        );
    END IF;

    -- Ensure an id primary key exists (BIGSERIAL). Skip when already present.
    IF NOT EXISTS (
        SELECT 1
        FROM pg_attribute a
        JOIN pg_class c ON c.oid = a.attrelid
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public'
          AND c.relname = v_target
          AND a.attname = 'id'
          AND a.attnum > 0
          AND NOT a.attisdropped
    ) THEN
        EXECUTE format(
            'ALTER TABLE public.%I ADD COLUMN id bigserial PRIMARY KEY',
            v_target
        );
        IF to_regclass(format('public.%I', v_target || '_id_seq')) IS NOT NULL THEN
            EXECUTE format(
                'ALTER SEQUENCE public.%I OWNER TO lumina_layer_owner',
                v_target || '_id_seq'
            );
        END IF;
    END IF;

    EXECUTE format(
        'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.%I TO lumina_app',
        v_target
    );

    IF to_regclass(format('public.%I', v_target || '_id_seq')) IS NOT NULL THEN
        EXECUTE format(
            'GRANT USAGE, SELECT ON SEQUENCE public.%I TO lumina_app',
            v_target || '_id_seq'
        );
    END IF;

    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lumina_backup') THEN
        EXECUTE format('GRANT SELECT ON TABLE public.%I TO lumina_backup', v_target);
    END IF;

    -- DG-1.2 / DG-1.3 hooks (soft): attach only when helpers already exist.
    IF to_regprocedure('public.lumina_attach_layer_audit(text)') IS NOT NULL THEN
        EXECUTE format('SELECT public.lumina_attach_layer_audit(%L)', v_target);
    END IF;

    IF to_regprocedure('public.lumina_attach_layer_validation(text)') IS NOT NULL THEN
        EXECUTE format('SELECT public.lumina_attach_layer_validation(%L)', v_target);
    END IF;
END;
$fn$;

COMMENT ON FUNCTION public.lumina_promote_layer_table(text, text) IS
    'DG-0.2: move staging.<name> to public.<target>, own as lumina_layer_owner, grant DML to lumina_app. SECURITY DEFINER; fixed search_path.';

ALTER FUNCTION public.lumina_promote_layer_table(text, text) OWNER TO lumina_layer_owner;

REVOKE ALL ON FUNCTION public.lumina_promote_layer_table(text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.lumina_promote_layer_table(text, text) TO lumina_app;
GRANT EXECUTE ON FUNCTION public.lumina_promote_layer_table(text, text) TO lumina_migrator;
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP FUNCTION IF EXISTS public.lumina_promote_layer_table(text, text);
SQL);
    }

    private function rolesReady(): bool
    {
        $row = DB::selectOne(
            <<<'SQL'
            SELECT
                EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lumina_layer_owner') AS has_owner,
                EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lumina_app') AS has_app
            SQL
        );

        return $row && (bool) $row->has_owner && (bool) $row->has_app;
    }
};
