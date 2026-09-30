<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_hotels', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('hbx_hotel_code');
            $table->char('country_code', 2)->nullable()->index();
            $table->char('country_iso_code', 2)->nullable();
            $table->string('state_code', 8)->nullable();
            $table->string('destination_code', 8)->nullable()->index();
            $table->char('destination_country_code', 2)->nullable();
            $table->unsignedInteger('zone_code')->nullable()->index();
            $table->decimal('latitude', 12, 8)->nullable();
            $table->decimal('longitude', 12, 8)->nullable();
            $table->string('category_code', 16)->nullable()->index();
            $table->string('category_group_code', 16)->nullable();
            $table->string('chain_code', 16)->nullable()->index();
            $table->string('accommodation_type_code', 8)->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('address_number', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('license', 64)->nullable();
            $table->unsignedInteger('giata_code')->nullable()->index();
            $table->string('web')->nullable();
            $table->date('supplier_last_update')->nullable()->index();
            $table->string('s2c', 16)->nullable();
            $table->unsignedSmallInteger('ranking')->nullable();
            $table->string('source_version', 8)->nullable();
            $table->timestamps();
            $table->unique('hbx_hotel_code');
        });

        Schema::create('content_hotel_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_hotel_id')->constrained('content_hotels')->cascadeOnDelete();
            $table->char('language', 3);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('address_line')->nullable();
            $table->string('address_street')->nullable();
            $table->string('city')->nullable();
            $table->timestamps();
            $table->unique(['content_hotel_id', 'language'], 'content_hotel_trans_language_unique');
        });

        Schema::create('content_hotel_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_hotel_id')->constrained('content_hotels')->cascadeOnDelete();
            $table->char('language', 3);
            $table->longText('raw_payload');
            $table->char('content_hash', 64);
            $table->unsignedInteger('payload_bytes');
            $table->timestamp('content_synced_at');
            $table->timestamps();
            $table->unique(['content_hotel_id', 'language'], 'content_hotel_snapshots_language_unique');
        });

        Schema::create('content_hotel_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_hotel_id')->constrained('content_hotels')->cascadeOnDelete();
            $table->string('phone_number', 32);
            $table->string('phone_type', 32);
            $table->timestamps();
            $table->unique(['content_hotel_id', 'phone_type', 'phone_number'], 'content_hotel_phones_natural_unique');
        });

        Schema::create('content_hotel_boards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_hotel_id')->constrained('content_hotels')->cascadeOnDelete();
            $table->string('board_code', 8);
            $table->timestamps();
            $table->unique(['content_hotel_id', 'board_code'], 'content_hotel_boards_natural_unique');
        });

        Schema::create('content_hotel_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_hotel_id')->constrained('content_hotels')->cascadeOnDelete();
            $table->unsignedInteger('segment_code');
            $table->timestamps();
            $table->unique(['content_hotel_id', 'segment_code'], 'content_hotel_segments_natural_unique');
        });

        Schema::create('content_hotel_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_hotel_id')->constrained('content_hotels')->cascadeOnDelete();
            $table->string('room_code', 32);
            $table->string('type_code', 16);
            $table->string('characteristic_code', 16);
            $table->boolean('is_parent_room');
            $table->unsignedSmallInteger('min_pax');
            $table->unsignedSmallInteger('max_pax');
            $table->unsignedSmallInteger('min_adults');
            $table->unsignedSmallInteger('max_adults');
            $table->unsignedSmallInteger('max_children');
            $table->string('pms_room_code', 32)->nullable()->index('content_hotel_rooms_pms_idx');
            $table->timestamps();
            $table->unique(['content_hotel_id', 'room_code'], 'content_hotel_rooms_natural_unique');
        });

        Schema::create('content_hotel_room_translations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('content_hotel_room_id');
            $table->char('language', 3);
            $table->string('description')->nullable();
            $table->string('commercial_description')->nullable();
            $table->timestamps();
            $table->foreign('content_hotel_room_id', 'content_room_trans_room_fk')
                ->references('id')->on('content_hotel_rooms')->cascadeOnDelete();
            $table->unique(['content_hotel_room_id', 'language'], 'content_room_trans_language_unique');
        });

        Schema::create('content_hotel_facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_hotel_id')->constrained('content_hotels')->cascadeOnDelete();
            $this->facilityColumns($table);
            $table->timestamps();
            $table->unique(
                ['content_hotel_id', 'facility_code', 'facility_group_code'],
                'content_hotel_facilities_natural_unique'
            );
        });

        Schema::create('content_room_facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_hotel_room_id')->constrained('content_hotel_rooms')->cascadeOnDelete();
            $this->facilityColumns($table);
            $table->timestamps();
            $table->unique(
                ['content_hotel_room_id', 'facility_code', 'facility_group_code'],
                'content_room_facilities_natural_unique'
            );
        });

        Schema::create('content_room_stays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_hotel_room_id')->constrained('content_hotel_rooms')->cascadeOnDelete();
            $table->string('stay_type', 16);
            $table->string('stay_order', 8);
            $table->timestamps();
            $table->unique(
                ['content_hotel_room_id', 'stay_type', 'stay_order'],
                'content_room_stays_natural_unique'
            );
        });

        Schema::create('content_room_stay_facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_room_stay_id')->constrained('content_room_stays')->cascadeOnDelete();
            $this->facilityColumns($table);
            $table->timestamps();
            $table->unique(
                ['content_room_stay_id', 'facility_code', 'facility_group_code'],
                'content_stay_facilities_natural_unique'
            );
        });

        Schema::create('content_hotel_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_hotel_id')->constrained('content_hotels')->cascadeOnDelete();
            $table->unsignedBigInteger('content_hotel_room_id')->nullable();
            $table->string('image_type_code', 8);
            $table->string('path');
            $table->string('room_code', 32)->nullable();
            $table->string('room_type_code', 16)->nullable();
            $table->string('characteristic_code', 16)->nullable();
            $table->unsignedInteger('supplier_order');
            $table->unsignedInteger('visual_order');
            $table->timestamps();
            $table->foreign('content_hotel_room_id', 'content_hotel_images_room_fk')
                ->references('id')->on('content_hotel_rooms')->nullOnDelete();
            $table->unique(['content_hotel_id', 'path', 'visual_order'], 'content_hotel_images_path_order_unique');
            $table->index(['content_hotel_id', 'visual_order'], 'content_hotel_images_gallery_idx');
            $table->index(['content_hotel_id', 'image_type_code', 'visual_order'], 'content_hotel_images_type_idx');
            $table->index(['content_hotel_id', 'room_code', 'visual_order'], 'content_hotel_images_room_idx');
        });

        Schema::create('content_hotel_terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_hotel_id')->constrained('content_hotels')->cascadeOnDelete();
            $table->string('terminal_code', 8);
            $table->char('terminal_type', 1)->nullable();
            $table->unsignedInteger('distance')->nullable();
            $table->timestamps();
            $table->unique(
                ['content_hotel_id', 'terminal_code', 'terminal_type'],
                'content_hotel_terminals_natural_unique'
            );
        });

        Schema::create('content_hotel_interest_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_hotel_id')->constrained('content_hotels')->cascadeOnDelete();
            $table->unsignedInteger('facility_code');
            $table->unsignedInteger('facility_group_code');
            $table->unsignedInteger('sort_order');
            $table->unsignedInteger('distance')->nullable();
            $table->timestamps();
            $table->unique(
                ['content_hotel_id', 'facility_code', 'facility_group_code', 'sort_order'],
                'content_interest_points_natural_unique'
            );
        });

        Schema::create('content_hotel_interest_point_translations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('content_hotel_interest_point_id');
            $table->char('language', 3);
            $table->string('poi_name')->nullable();
            $table->timestamps();
            $table->foreign('content_hotel_interest_point_id', 'content_ip_trans_point_fk')
                ->references('id')->on('content_hotel_interest_points')->cascadeOnDelete();
            $table->unique(
                ['content_hotel_interest_point_id', 'language'],
                'content_ip_trans_language_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_hotel_interest_point_translations');
        Schema::dropIfExists('content_hotel_interest_points');
        Schema::dropIfExists('content_hotel_terminals');
        Schema::dropIfExists('content_hotel_images');
        Schema::dropIfExists('content_room_stay_facilities');
        Schema::dropIfExists('content_room_stays');
        Schema::dropIfExists('content_room_facilities');
        Schema::dropIfExists('content_hotel_facilities');
        Schema::dropIfExists('content_hotel_room_translations');
        Schema::dropIfExists('content_hotel_rooms');
        Schema::dropIfExists('content_hotel_segments');
        Schema::dropIfExists('content_hotel_boards');
        Schema::dropIfExists('content_hotel_phones');
        Schema::dropIfExists('content_hotel_snapshots');
        Schema::dropIfExists('content_hotel_translations');
        Schema::dropIfExists('content_hotels');
    }

    private function facilityColumns(Blueprint $table): void
    {
        $table->unsignedInteger('facility_code');
        $table->unsignedInteger('facility_group_code');
        $table->unsignedInteger('sort_order')->nullable();
        $table->integer('number_value')->nullable();
        $table->boolean('ind_logic')->nullable();
        $table->boolean('ind_fee')->nullable();
        $table->boolean('ind_yes_or_no')->nullable();
        $table->boolean('voucher')->nullable();
        $table->unsignedInteger('distance')->nullable();
        $table->decimal('amount', 12, 2)->nullable();
        $table->char('currency', 3)->nullable();
        $table->string('application_type', 8)->nullable();
        $table->time('time_from')->nullable();
        $table->time('time_to')->nullable();
        $table->date('date_to')->nullable();
    }
};
