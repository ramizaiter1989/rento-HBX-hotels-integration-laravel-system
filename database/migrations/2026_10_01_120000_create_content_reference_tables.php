<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->codeTable('content_ref_facility_groups', function (Blueprint $table): void {
            $table->unsignedInteger('code')->unique();
        });
        $this->translations('content_ref_facility_group_translations', 'content_ref_facility_groups', 'cfg');

        Schema::create('content_ref_facilities', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('code');
            $table->unsignedInteger('facility_group_code');
            $table->unsignedInteger('facility_typology_code')->nullable();
            $table->timestamps();
            $table->unique(['code', 'facility_group_code'], 'content_ref_facilities_identity');
            $table->index('facility_group_code', 'content_ref_facilities_group_idx');
        });
        $this->translations('content_ref_facility_translations', 'content_ref_facilities', 'cfc');

        $this->codeTable('content_ref_room_types', function (Blueprint $table): void {
            $table->string('code', 16)->unique();
        });
        $this->translations('content_ref_room_type_translations', 'content_ref_room_types', 'crt');

        $this->codeTable('content_ref_room_characteristics', function (Blueprint $table): void {
            $table->string('code', 16)->unique();
        });
        $this->translations('content_ref_room_characteristic_translations', 'content_ref_room_characteristics', 'crc');

        Schema::create('content_ref_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('type_code', 16)->nullable()->index();
            $table->string('characteristic_code', 16)->nullable()->index();
            $table->unsignedSmallInteger('min_pax')->nullable();
            $table->unsignedSmallInteger('max_pax')->nullable();
            $table->unsignedSmallInteger('max_adults')->nullable();
            $table->unsignedSmallInteger('max_children')->nullable();
            $table->unsignedSmallInteger('min_adults')->nullable();
            $table->timestamps();
        });
        $this->translations('content_ref_room_translations', 'content_ref_rooms', 'crm');

        Schema::create('content_ref_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('category_group_code', 16)->nullable()->index();
            $table->timestamps();
        });
        $this->translations('content_ref_category_translations', 'content_ref_categories', 'cct');

        $this->codeTable('content_ref_category_groups', function (Blueprint $table): void {
            $table->string('code', 16)->unique();
        });
        $this->translations('content_ref_category_group_translations', 'content_ref_category_groups', 'ccg');

        $this->codeTable('content_ref_chains', function (Blueprint $table): void {
            $table->string('code', 16)->unique();
        });
        $this->translations('content_ref_chain_translations', 'content_ref_chains', 'cch');

        $this->codeTable('content_ref_accommodation_types', function (Blueprint $table): void {
            $table->string('code', 16)->unique();
        });
        $this->translations('content_ref_accommodation_type_translations', 'content_ref_accommodation_types', 'cat');

        $this->codeTable('content_ref_boards', function (Blueprint $table): void {
            $table->string('code', 8)->unique();
        });
        $this->translations('content_ref_board_translations', 'content_ref_boards', 'cbd');

        $this->codeTable('content_ref_segments', function (Blueprint $table): void {
            $table->unsignedInteger('code')->unique();
        });
        $this->translations('content_ref_segment_translations', 'content_ref_segments', 'csg');

        $this->codeTable('content_ref_image_types', function (Blueprint $table): void {
            $table->string('code', 16)->unique();
        });
        $this->translations('content_ref_image_type_translations', 'content_ref_image_types', 'cit');

        $this->codeTable('content_ref_countries', function (Blueprint $table): void {
            $table->string('code', 8)->unique();
        });
        $this->translations('content_ref_country_translations', 'content_ref_countries', 'ccy');

        Schema::create('content_ref_destinations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->string('country_code', 8)->nullable()->index();
            $table->timestamps();
        });
        $this->translations('content_ref_destination_translations', 'content_ref_destinations', 'cds');

        Schema::create('content_ref_zones', function (Blueprint $table) {
            $table->id();
            $table->string('destination_code', 8);
            $table->unsignedInteger('zone_code');
            $table->timestamps();
            $table->unique(['destination_code', 'zone_code'], 'content_ref_zones_identity');
        });
        $this->translations('content_ref_zone_translations', 'content_ref_zones', 'czn');

        Schema::create('content_sync_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_sync_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('hbx_hotel_code')->nullable()->index();
            $table->string('failure_type', 32);
            $table->string('message', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['content_sync_run_id', 'created_at'], 'content_sync_failures_run_idx');
        });

        Schema::table('rate_selections', function (Blueprint $table) {
            $table->decimal('availability_net', 14, 2)->nullable()->after('net');
        });

        Schema::table('hotel_searches', function (Blueprint $table) {
            $table->string('availability_source', 16)->nullable()->after('hotels_returned');
        });
    }

    public function down(): void
    {
        Schema::table('hotel_searches', function (Blueprint $table) {
            $table->dropColumn('availability_source');
        });
        Schema::table('rate_selections', function (Blueprint $table) {
            $table->dropColumn('availability_net');
        });
        Schema::dropIfExists('content_sync_failures');

        foreach ([
            'content_ref_zone_translations',
            'content_ref_zones',
            'content_ref_destination_translations',
            'content_ref_destinations',
            'content_ref_country_translations',
            'content_ref_countries',
            'content_ref_image_type_translations',
            'content_ref_image_types',
            'content_ref_segment_translations',
            'content_ref_segments',
            'content_ref_board_translations',
            'content_ref_boards',
            'content_ref_accommodation_type_translations',
            'content_ref_accommodation_types',
            'content_ref_chain_translations',
            'content_ref_chains',
            'content_ref_category_group_translations',
            'content_ref_category_groups',
            'content_ref_category_translations',
            'content_ref_categories',
            'content_ref_room_translations',
            'content_ref_rooms',
            'content_ref_room_characteristic_translations',
            'content_ref_room_characteristics',
            'content_ref_room_type_translations',
            'content_ref_room_types',
            'content_ref_facility_translations',
            'content_ref_facilities',
            'content_ref_facility_group_translations',
            'content_ref_facility_groups',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function codeTable(string $name, callable $columns): void
    {
        Schema::create($name, function (Blueprint $table) use ($columns): void {
            $table->id();
            $columns($table);
            $table->timestamps();
        });
    }

    private function translations(string $table, string $parent, string $short): void
    {
        Schema::create($table, function (Blueprint $blueprint) use ($parent, $short): void {
            $blueprint->id();
            $blueprint->unsignedBigInteger('record_id');
            $blueprint->foreign('record_id', $short.'_rec_fk')->references('id')->on($parent)->cascadeOnDelete();
            $blueprint->char('language', 3);
            $blueprint->string('description', 512)->nullable();
            $blueprint->timestamps();
            $blueprint->unique(['record_id', 'language'], $short.'_lang_uq');
        });
    }
};
