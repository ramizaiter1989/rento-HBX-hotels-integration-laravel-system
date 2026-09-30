<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_hotel_rooms', function (Blueprint $table) {
            $table->string('pms_room_code', 255)->nullable()->change();
        });

        $roomIndexes = collect(Schema::getIndexes('content_hotel_rooms'));
        $pmsIndexed = $roomIndexes->contains(
            fn (array $index): bool => ($index['columns'] ?? []) === ['pms_room_code']
        );

        if (! $pmsIndexed) {
            Schema::table('content_hotel_rooms', function (Blueprint $table) {
                $table->index('pms_room_code', 'content_hotel_rooms_pms_idx');
            });
        }

        Schema::table('content_hotels', function (Blueprint $table) {
            $table->unsignedInteger('ranking')->nullable()->change();
        });

        Schema::table('content_hotel_snapshots', function (Blueprint $table) {
            $table->string('content_origin', 16)->nullable();
        });

        DB::table('content_hotel_snapshots')->whereNull('content_origin')->update([
            'content_origin' => 'list',
        ]);
    }

    public function down(): void
    {
        Schema::table('content_hotel_snapshots', function (Blueprint $table) {
            $table->dropColumn('content_origin');
        });

        Schema::table('content_hotels', function (Blueprint $table) {
            $table->unsignedSmallInteger('ranking')->nullable()->change();
        });

        Schema::table('content_hotel_rooms', function (Blueprint $table) {
            $table->string('pms_room_code', 32)->nullable()->change();
        });
    }
};
