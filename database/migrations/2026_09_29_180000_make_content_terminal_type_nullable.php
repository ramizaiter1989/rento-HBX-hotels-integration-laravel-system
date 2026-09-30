<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_hotel_terminals', function (Blueprint $table) {
            $table->char('terminal_type', 1)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('content_hotel_terminals', function (Blueprint $table) {
            $table->char('terminal_type', 1)->nullable(false)->change();
        });
    }
};
