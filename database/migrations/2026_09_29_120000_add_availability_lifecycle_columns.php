<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_searches', function (Blueprint $table) {
            $table->char('search_fingerprint', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->index(['search_fingerprint', 'expires_at']);
        });

        Schema::table('rate_selections', function (Blueprint $table) {
            $table->timestamp('valid_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_searches', function (Blueprint $table) {
            $table->dropIndex(['search_fingerprint', 'expires_at']);
            $table->dropColumn(['search_fingerprint', 'expires_at', 'last_accessed_at']);
        });

        Schema::table('rate_selections', function (Blueprint $table) {
            $table->dropColumn('valid_until');
        });
    }
};
