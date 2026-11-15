<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plugin API keys grant full remote control of a WordPress site (backup, restore, URL rewrite),
 * so they are stored encrypted with APP_KEY. Ciphertext needs a TEXT column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->text('api_key')->change();
        });

        DB::table('websites')->orderBy('id')->each(function (object $website) {
            if (! $this->isEncrypted($website->api_key)) {
                DB::table('websites')->where('id', $website->id)->update([
                    'api_key' => Crypt::encryptString($website->api_key),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('websites')->orderBy('id')->each(function (object $website) {
            if ($this->isEncrypted($website->api_key)) {
                DB::table('websites')->where('id', $website->id)->update([
                    'api_key' => Crypt::decryptString($website->api_key),
                ]);
            }
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->string('api_key')->change();
        });
    }

    private function isEncrypted(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
