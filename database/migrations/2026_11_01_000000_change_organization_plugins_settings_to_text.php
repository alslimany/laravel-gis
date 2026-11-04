<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * organization_plugins.settings uses the `encrypted:array` cast (A5 / #57), so the stored value
 * is a Laravel Crypt payload (base64 text), not JSON. On PostgreSQL the column was created as
 * jsonb, which rejects that payload ("invalid input syntax for type json") on every save.
 * Change it to text on pgsql. Other drivers already accept the string, so this is a no-op there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE organization_plugins ALTER COLUMN settings TYPE text USING settings::text');

        // Rows written as plain JSON while the column was jsonb (seeders / raw inserts) would fail
        // to decrypt under the encrypted:array cast. Re-encrypt them the same way the cast does
        // (Crypt::encryptString of the JSON string). Encrypted payloads are base64 ("ey..."), never
        // '{' or '['. JSON arrays ('[...]') are re-encrypted too.
        // NOTE: up() is ONE-WAY for re-encrypted rows: down() cannot restore their plain JSON and
        // will refuse to run while they exist (see down()).
        DB::table('organization_plugins')
            ->where(fn ($q) => $q->where('settings', 'like', '{%')->orWhere('settings', 'like', '[%'))
            ->orderBy('id')
            ->get(['id', 'settings'])
            ->each(function ($row) {
                $decoded = json_decode((string) $row->settings, true);
                if (! is_array($decoded)) {
                    return;
                }
                DB::table('organization_plugins')->where('id', $row->id)
                    ->update(['settings' => Crypt::encryptString(json_encode($decoded))]);
            });
    }

    /**
     * Back to jsonb. This is only possible when every non-null value is valid JSON. Encrypted values
     * (the normal case after up(), since the model always encrypts) are not JSON, so down() FAILS
     * with a clear message instead of nulling or decrypting them: nulling would silently destroy
     * plugin settings, and decrypting would store secrets in plaintext. Clear or re-save those
     * rows deliberately first if you really need to roll back.
     *
     * Note: no table lock is taken between the JSON check and the ALTER; a row encrypted by a
     * concurrent write in that window makes the ALTER itself fail (safe, but run it with the app idle).
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $blocking = DB::table('organization_plugins')
            ->whereNotNull('settings')
            ->get(['id', 'settings'])
            ->filter(function ($row) {
                json_decode((string) $row->settings);

                return json_last_error() !== JSON_ERROR_NONE;
            })
            ->pluck('id');

        if ($blocking->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot convert organization_plugins.settings back to jsonb: rows '
                .$blocking->implode(', ')
                .' hold encrypted (non-JSON) values. Refusing to null or decrypt them.'
            );
        }

        DB::statement('ALTER TABLE organization_plugins ALTER COLUMN settings TYPE jsonb USING settings::jsonb');
    }
};
