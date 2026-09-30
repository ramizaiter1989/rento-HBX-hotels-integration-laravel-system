<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * source_position is the zero-based index in the supplier images array.
     * Existing rows are numbered by insertion id, which is that array order.
     */
    public function up(): void
    {
        Schema::table('content_hotel_images', function (Blueprint $table) {
            $table->dropUnique('content_hotel_images_path_order_unique');
            $table->unsignedInteger('source_position')->nullable();
            $table->index(['content_hotel_id', 'path'], 'content_hotel_images_path_idx');
        });

        $positions = [];

        DB::table('content_hotel_images')
            ->orderBy('id')
            ->select(['id', 'content_hotel_id'])
            ->chunkById(500, function ($rows) use (&$positions): void {
                foreach ($rows as $row) {
                    $hotelId = (int) $row->content_hotel_id;
                    $position = $positions[$hotelId] ?? 0;
                    DB::table('content_hotel_images')->where('id', $row->id)->update([
                        'source_position' => $position,
                    ]);
                    $positions[$hotelId] = $position + 1;
                }
            });

        Schema::table('content_hotel_images', function (Blueprint $table) {
            $table->unsignedInteger('source_position')->nullable(false)->change();
            $table->unique(
                ['content_hotel_id', 'source_position'],
                'content_hotel_images_position_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('content_hotel_images', function (Blueprint $table) {
            $table->dropUnique('content_hotel_images_position_unique');
            $table->dropIndex('content_hotel_images_path_idx');
            $table->dropColumn('source_position');
            $table->unique(
                ['content_hotel_id', 'path', 'visual_order'],
                'content_hotel_images_path_order_unique'
            );
        });
    }
};
