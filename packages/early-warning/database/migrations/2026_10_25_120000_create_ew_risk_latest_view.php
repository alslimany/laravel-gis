<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Latest valid risk assessment per org+basin joined to basin geometry (#61).
 * GeoServer publishes a per-org SQL View filtered from the same join; this
 * named view is for inspection / optional direct publish. Prefix ew_ (ADR D15).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP VIEW IF EXISTS ew_risk_latest');
            DB::statement(<<<'SQL'
CREATE VIEW ew_risk_latest AS
SELECT r.id,
       r.organization_id,
       r.basin_code,
       b.name AS basin_name,
       r.forecast_at,
       r.valid_from,
       r.valid_until,
       r.risk_level,
       r.score,
       b.geom
FROM ew_risk_assessments r
INNER JOIN ew_basins b ON b.basin_code = r.basin_code
WHERE r.forecast_at = (
    SELECT MIN(ra.forecast_at)
    FROM ew_risk_assessments ra
    WHERE ra.organization_id = r.organization_id
      AND ra.basin_code = r.basin_code
      AND ra.valid_from <= CURRENT_TIMESTAMP
      AND ra.valid_until > CURRENT_TIMESTAMP
)
AND r.valid_from <= CURRENT_TIMESTAMP
AND r.valid_until > CURRENT_TIMESTAMP
SQL);

            return;
        }

        // SQLite (tests): attribute-only view without geometry.
        DB::statement('DROP VIEW IF EXISTS ew_risk_latest');
        DB::statement(<<<'SQL'
CREATE VIEW ew_risk_latest AS
SELECT r.id,
       r.organization_id,
       r.basin_code,
       b.name AS basin_name,
       r.forecast_at,
       r.valid_from,
       r.valid_until,
       r.risk_level,
       r.score
FROM ew_risk_assessments r
INNER JOIN ew_basins b ON b.basin_code = r.basin_code
WHERE r.id = (
    SELECT r2.id
    FROM ew_risk_assessments r2
    WHERE r2.organization_id = r.organization_id
      AND r2.basin_code = r.basin_code
      AND r2.valid_from <= CURRENT_TIMESTAMP
      AND r2.valid_until > CURRENT_TIMESTAMP
    ORDER BY r2.forecast_at ASC
    LIMIT 1
)
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ew_risk_latest');
    }
};
