<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 3: Shelter occupancy ledger, beneficiary registry, missing persons + access audit.
 * Privacy: minimal PII, retention dates, append-only access log — see docs/registry-privacy.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dm_critical_sites', 'current_occupancy')) {
            Schema::table('dm_critical_sites', function (Blueprint $table) {
                $table->unsignedInteger('current_occupancy')->default(0)->after('capacity');
            });
        }

        Schema::create('dm_beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('household_code', 64);
            $table->unsignedInteger('household_size')->default(1);
            $table->json('needs')->nullable();
            $table->foreignId('shelter_site_id')->nullable()->constrained('dm_critical_sites')->nullOnDelete();
            $table->string('contact_ref', 128)->nullable();
            $table->json('props')->nullable();
            $table->date('retained_until')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'household_code'], 'dm_beneficiaries_org_code_uq');
            $table->index(['organization_id', 'shelter_site_id'], 'dm_beneficiaries_org_shelter_idx');
            $table->index(['retained_until'], 'dm_beneficiaries_retained_idx');
        });

        Schema::create('dm_shelter_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('shelter_site_id')->constrained('dm_critical_sites')->cascadeOnDelete();
            $table->foreignId('beneficiary_id')->nullable()->constrained('dm_beneficiaries')->nullOnDelete();
            $table->string('direction', 16);
            $table->unsignedInteger('headcount')->default(1);
            $table->timestamp('checked_at');
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'shelter_site_id', 'checked_at'], 'dm_shelter_checkins_org_site_at_idx');
            $table->index(['shelter_site_id', 'direction'], 'dm_shelter_checkins_site_dir_idx');
        });

        Schema::create('dm_missing_persons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('case_code', 64);
            $table->string('status', 32)->default('missing');
            $table->string('display_name_redacted', 128)->nullable();
            $table->string('given_name', 128)->nullable();
            $table->string('family_name', 128)->nullable();
            $table->unsignedSmallInteger('age_years')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->text('notes')->nullable();
            $table->date('retained_until')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'case_code'], 'dm_missing_persons_org_code_uq');
            $table->index(['organization_id', 'status'], 'dm_missing_persons_org_status_idx');
            $table->index(['retained_until'], 'dm_missing_persons_retained_idx');
        });
        $this->addMissingPersonsGeom();

        Schema::create('dm_person_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('missing_person_id')->nullable()->constrained('dm_missing_persons')->nullOnDelete();
            $table->string('report_type', 32);
            $table->string('reporter_ref', 128)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at'], 'dm_person_reports_org_created_idx');
            $table->index(['missing_person_id', 'report_type'], 'dm_person_reports_case_type_idx');
        });
        $this->addPersonReportsGeom();

        Schema::create('dm_registry_access_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->string('action', 16);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at'], 'dm_registry_access_log_org_created_idx');
            $table->index(['subject_type', 'subject_id'], 'dm_registry_access_log_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_registry_access_log');
        Schema::dropIfExists('dm_person_reports');
        Schema::dropIfExists('dm_missing_persons');
        Schema::dropIfExists('dm_shelter_checkins');
        Schema::dropIfExists('dm_beneficiaries');

        if (Schema::hasColumn('dm_critical_sites', 'current_occupancy')) {
            Schema::table('dm_critical_sites', function (Blueprint $table) {
                $table->dropColumn('current_occupancy');
            });
        }
    }

    protected function addMissingPersonsGeom(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dm_missing_persons ADD COLUMN geom geometry(Point, 4326)');
            DB::statement('CREATE INDEX dm_missing_persons_geom_gist ON dm_missing_persons USING GIST (geom)');
        } else {
            Schema::table('dm_missing_persons', fn (Blueprint $t) => $t->text('geom')->nullable());
        }
    }

    protected function addPersonReportsGeom(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dm_person_reports ADD COLUMN geom geometry(Point, 4326)');
            DB::statement('CREATE INDEX dm_person_reports_geom_gist ON dm_person_reports USING GIST (geom)');
        } else {
            Schema::table('dm_person_reports', fn (Blueprint $t) => $t->text('geom')->nullable());
        }
    }
};
