-- =============================================================================
-- Lumina GIS — least-privilege database roles (DG-0.1 / ADR-0002 §3)
-- =============================================================================
-- Apply ONLY as a PostgreSQL superuser / bootstrap role on a throwaway or
-- staging database. Do NOT run against Abdullah's local NCMCD demo volume
-- until gate G1 is lifted (ADR-0002 §8 / DG-0.7).
--
-- This file is documentation + a reviewable script. It is NOT mounted into
-- docker-entrypoint-initdb.d by default (see docker/postgres/init/). Wire it
-- into fresh environments deliberately (DG-0.3+).
--
-- Passwords are intentionally omitted. Set them out-of-band, e.g.:
--   ALTER ROLE lumina_migrator PASSWORD '...';
--   ALTER ROLE lumina_app PASSWORD '...';
--   ALTER ROLE lumina_backup PASSWORD '...';
-- Per-org GeoServer roles get passwords when created (template below).
--
-- Idempotent: safe to re-run (CREATE ROLE IF NOT EXISTS / CREATE SCHEMA IF NOT
-- EXISTS / grants that are already present).
-- =============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- 1. Roles — all NOSUPERUSER NOCREATEDB NOCREATEROLE
-- ---------------------------------------------------------------------------

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lumina_migrator') THEN
        CREATE ROLE lumina_migrator
            LOGIN
            NOSUPERUSER
            NOCREATEDB
            NOCREATEROLE
            NOINHERIT
            NOREPLICATION;
        COMMENT ON ROLE lumina_migrator IS
            'Lumina migrator: owns core schema objects; used by artisan migrate, plugin lifecycle, PluginBackup (ADR-0002 §3).';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lumina_app') THEN
        CREATE ROLE lumina_app
            LOGIN
            NOSUPERUSER
            NOCREATEDB
            NOCREATEROLE
            INHERIT
            NOREPLICATION;
        COMMENT ON ROLE lumina_app IS
            'Lumina app runtime: DML on core + granted layer tables; CREATE only in staging; EXECUTE on provisioning functions. Never superuser (boot check in DG-0.3).';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lumina_layer_owner') THEN
        CREATE ROLE lumina_layer_owner
            NOLOGIN
            NOSUPERUSER
            NOCREATEDB
            NOCREATEROLE
            INHERIT
            NOREPLICATION;
        COMMENT ON ROLE lumina_layer_owner IS
            'Owns every layer feature table. SECURITY DEFINER provisioning functions (DG-0.2) run as this role with a fixed search_path.';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lumina_backup') THEN
        CREATE ROLE lumina_backup
            LOGIN
            NOSUPERUSER
            NOCREATEDB
            NOCREATEROLE
            INHERIT
            NOREPLICATION;
        COMMENT ON ROLE lumina_backup IS
            'Read-only dumps (pg_dump). No INSERT/UPDATE/DELETE/DDL.';
    END IF;
END
$$;

-- Migrator may SET ROLE to layer_owner when creating SECURITY DEFINER functions
-- and transferring ownership of promoted layer tables (DG-0.2).
GRANT lumina_layer_owner TO lumina_migrator;

-- ---------------------------------------------------------------------------
-- 2. Schemas
-- ---------------------------------------------------------------------------

-- Core + layer tables live in public (existing Lumina layout).
-- Revoke blanket CREATE from PUBLIC so lumina_app cannot create layer tables
-- directly (must go through SECURITY DEFINER provisioning — DG-0.2).
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO lumina_migrator, lumina_app, lumina_layer_owner, lumina_backup;
GRANT CREATE ON SCHEMA public TO lumina_migrator, lumina_layer_owner;
REVOKE CREATE ON SCHEMA public FROM lumina_app, lumina_backup;

-- Imports (ogr2ogr / Excel) land in staging; only lumina_app may CREATE there.
CREATE SCHEMA IF NOT EXISTS staging AUTHORIZATION lumina_migrator;
COMMENT ON SCHEMA staging IS
    'Scratch schema for imports. lumina_app has CREATE; promote to public via SECURITY DEFINER (DG-0.2).';
GRANT USAGE, CREATE ON SCHEMA staging TO lumina_app;
GRANT USAGE ON SCHEMA staging TO lumina_migrator, lumina_layer_owner;
REVOKE ALL ON SCHEMA staging FROM PUBLIC;

-- ---------------------------------------------------------------------------
-- 3. Core-object grants (existing tables + future defaults)
-- ---------------------------------------------------------------------------
-- lumina_migrator is expected to own core tables after DG-0.3 cutover
-- (REASSIGN OWNED / migrations run as migrator). Until then these GRANTs are
-- no-ops if objects are still owned by the bootstrap superuser — re-run or
-- ALTER DEFAULT PRIVILEGES after ownership transfer.

-- Current core tables (best-effort; ignore missing relations on empty DBs).
DO $$
DECLARE
    core_tables text[] := ARRAY[
        'users', 'password_reset_tokens', 'sessions',
        'organizations', 'projects', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs',
        'roles', 'role_user',
        'layers', 'layer_fields', 'layer_relationships',
        'maps', 'data_imports', 'analysis_results',
        'groups', 'group_user', 'content_access',
        'forms', 'form_submissions', 'attachments', 'dashboards', 'webhooks',
        'personal_access_tokens',
        'project_user', 'project_activities', 'project_comments',
        'agent_conversations',
        'solution_pack_definitions',
        'installed_plugins', 'organization_plugins', 'plugin_events',
        'migrations'
    ];
    t text;
BEGIN
    FOREACH t IN ARRAY core_tables LOOP
        IF to_regclass('public.' || quote_ident(t)) IS NOT NULL THEN
            EXECUTE format(
                'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.%I TO lumina_app',
                t
            );
            EXECUTE format(
                'GRANT SELECT ON TABLE public.%I TO lumina_backup',
                t
            );
        END IF;
    END LOOP;
END
$$;

-- Sequences used by core (and any already present).
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO lumina_app;
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO lumina_backup;

-- Future objects created by the migrator.
ALTER DEFAULT PRIVILEGES FOR ROLE lumina_migrator IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO lumina_app;
ALTER DEFAULT PRIVILEGES FOR ROLE lumina_migrator IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO lumina_app;
ALTER DEFAULT PRIVILEGES FOR ROLE lumina_migrator IN SCHEMA public
    GRANT SELECT ON TABLES TO lumina_backup;
ALTER DEFAULT PRIVILEGES FOR ROLE lumina_migrator IN SCHEMA public
    GRANT SELECT ON SEQUENCES TO lumina_backup;

-- Layer tables owned by lumina_layer_owner: app needs DML for FeatureService;
-- GeoServer org roles receive SELECT / DML from provisioning (DG-0.2), not here.
ALTER DEFAULT PRIVILEGES FOR ROLE lumina_layer_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO lumina_app;
ALTER DEFAULT PRIVILEGES FOR ROLE lumina_layer_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO lumina_app;
ALTER DEFAULT PRIVILEGES FOR ROLE lumina_layer_owner IN SCHEMA public
    GRANT SELECT ON TABLES TO lumina_backup;

-- Staging: app owns transient import tables; layer_owner / migrator can read
-- during promote. Backup may SELECT for completeness.
ALTER DEFAULT PRIVILEGES FOR ROLE lumina_app IN SCHEMA staging
    GRANT SELECT, INSERT, UPDATE, DELETE, TRUNCATE ON TABLES TO lumina_app;
ALTER DEFAULT PRIVILEGES FOR ROLE lumina_app IN SCHEMA staging
    GRANT SELECT ON TABLES TO lumina_layer_owner, lumina_migrator, lumina_backup;

-- ---------------------------------------------------------------------------
-- 4. Provisioning functions (DG-0.2)
-- ---------------------------------------------------------------------------
-- Function body lives in the Laravel migration
--   database/migrations/2026_11_04_140000_create_lumina_layer_provisioning_functions.php
-- which no-ops unless lumina_layer_owner + lumina_app already exist (this script).
-- Signature:
--   public.lumina_promote_layer_table(staging_name text, target_name text)
-- Owned by lumina_layer_owner (SECURITY DEFINER, fixed search_path).
-- The migration grants EXECUTE to lumina_app / lumina_migrator.
-- Default privilege so future functions created by layer_owner are callable:
ALTER DEFAULT PRIVILEGES FOR ROLE lumina_layer_owner IN SCHEMA public
    GRANT EXECUTE ON FUNCTIONS TO lumina_app;

-- ---------------------------------------------------------------------------
-- 5. Per-org GeoServer role pattern — lumina_gs_org{id}
-- ---------------------------------------------------------------------------
-- Do NOT create a shared GeoServer login. One LOGIN role per organization that
-- has published layers. Table GRANTs (not RLS) enforce tenant isolation
-- because each layer is already its own table (ADR-0002 §3).
--
-- Template (replace {id} with the numeric organization id, e.g. 12 →
-- lumina_gs_org12). Run as superuser / migrator with CREATEROLE is NOT
-- required if a bootstrap superuser applies this:
--
--   CREATE ROLE lumina_gs_org{id}
--       LOGIN
--       NOSUPERUSER
--       NOCREATEDB
--       NOCREATEROLE
--       INHERIT
--       NOREPLICATION
--       PASSWORD '...';  -- from secrets manager
--   COMMENT ON ROLE lumina_gs_org{id} IS
--       'GeoServer PostGIS datastore for organization {id}. No core-table access.';
--   GRANT USAGE ON SCHEMA public TO lumina_gs_org{id};
--   -- Explicitly no grants on core tables (users, layers, organizations, …).
--   -- Per published layer table (applied by provisioning / desktop-connect sync):
--   --   GRANT SELECT ON TABLE public.<layer_table> TO lumina_gs_org{id};
--   -- When at least one edit grant exists for that layer:
--   --   GRANT INSERT, UPDATE, DELETE ON TABLE public.<layer_table> TO lumina_gs_org{id};
--   --   GRANT USAGE, SELECT ON SEQUENCE public.<layer_table>_id_seq TO lumina_gs_org{id};
--   -- When the last edit grant is removed, REVOKE INSERT, UPDATE, DELETE.
--
-- Example for org 12 (commented — do not uncomment blindly):
--
--   -- CREATE ROLE lumina_gs_org12 LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
--   -- GRANT USAGE ON SCHEMA public TO lumina_gs_org12;

COMMIT;
