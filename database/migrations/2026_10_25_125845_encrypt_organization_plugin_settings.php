<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Encrypt organization_plugins.settings at rest (A5 / #57).
 * Existing plaintext JSON is wrapped with Crypt; the model cast is encrypted:array.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('organization_plugins')->whereNotNull('settings')->get(['id', 'settings']);

        foreach ($rows as $row) {
            $plainJson = $this->plainJson($row->settings);
            if ($plainJson === null) {
                continue;
            }

            DB::table('organization_plugins')->where('id', $row->id)->update([
                'settings' => Crypt::encryptString($plainJson),
            ]);
        }
    }

    public function down(): void
    {
        $rows = DB::table('organization_plugins')->whereNotNull('settings')->get(['id', 'settings']);

        foreach ($rows as $row) {
            if (! is_string($row->settings) || $row->settings === '') {
                continue;
            }

            try {
                $plain = Crypt::decryptString($row->settings);
            } catch (Throwable) {
                continue;
            }

            DB::table('organization_plugins')->where('id', $row->id)->update([
                'settings' => $plain,
            ]);
        }
    }

    protected function plainJson(mixed $settings): ?string
    {
        if (is_array($settings)) {
            return json_encode($settings, JSON_THROW_ON_ERROR);
        }

        if (! is_string($settings) || $settings === '') {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($settings);
            json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);

            // Already encrypted — leave unchanged.
            return null;
        } catch (Throwable) {
            // Plaintext JSON (or legacy shape) — encrypt below.
        }

        json_decode($settings, true, 512, JSON_THROW_ON_ERROR);

        return $settings;
    }
};
