<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_searches', function (Blueprint $table) {
            $table->id();
            $table->string('destination_code', 16)->nullable()->index();
            $table->json('hotel_codes')->nullable();
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedSmallInteger('rooms_count');
            $table->unsignedSmallInteger('adults_count');
            $table->unsignedSmallInteger('children_count');
            $table->json('child_ages')->nullable();
            $table->longText('request_payload');
            $table->longText('response_payload')->nullable();
            $table->unsignedInteger('hotels_returned')->nullable();
            $table->timestamps();
        });

        Schema::create('rate_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_search_id')->constrained()->cascadeOnDelete();
            $table->string('hotel_code', 32)->nullable()->index();
            $table->string('hotel_name')->nullable();
            $table->string('category_name')->nullable();
            $table->string('destination_code', 16)->nullable();
            $table->string('destination_name')->nullable();
            $table->string('zone_name')->nullable();
            $table->string('latitude', 32)->nullable();
            $table->string('longitude', 32)->nullable();
            $table->string('room_code', 64)->nullable();
            $table->string('room_name')->nullable();
            $table->longText('original_rate_key');
            $table->longText('rate_key');
            $table->string('original_rate_type', 32);
            $table->string('rate_type', 32);
            $table->string('rate_class', 32)->nullable();
            $table->decimal('net', 14, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->unsignedInteger('allotment')->nullable();
            $table->string('board_code', 16)->nullable();
            $table->string('board_name')->nullable();
            $table->string('payment_type', 32)->nullable();
            $table->boolean('packaging')->nullable();
            $table->boolean('payment_data_required')->nullable();
            $table->longText('rate_comments')->nullable();
            $table->longText('taxes')->nullable();
            $table->longText('cancellation_policies')->nullable();
            $table->longText('promotions')->nullable();
            $table->longText('checkrate_response')->nullable();
            $table->timestamp('checkrate_completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hotel_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_search_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rate_selection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hbx_reference')->nullable()->index();
            $table->string('client_reference')->unique();
            $table->string('submission_token', 64)->unique();
            $table->string('status', 32)->index();
            $table->string('holder_name')->nullable();
            $table->string('holder_surname')->nullable();
            $table->string('hotel_code', 32)->nullable()->index();
            $table->string('hotel_name')->nullable();
            $table->string('category_name')->nullable();
            $table->string('destination_name')->nullable();
            $table->string('zone_name')->nullable();
            $table->date('check_in')->nullable();
            $table->date('check_out')->nullable();
            $table->string('currency', 8)->nullable();
            $table->decimal('total_net', 14, 2)->nullable();
            $table->decimal('pending_amount', 14, 2)->nullable();
            $table->string('payment_type', 32)->nullable();
            $table->boolean('payment_data_required')->nullable();
            $table->string('supplier_name')->nullable();
            $table->string('supplier_vat')->nullable();
            $table->string('creation_date', 32)->nullable();
            $table->boolean('modification_allowed')->nullable();
            $table->boolean('cancellation_allowed')->nullable();
            $table->string('cancellation_reference')->nullable();
            $table->decimal('cancellation_amount', 14, 2)->nullable();
            $table->string('remark')->nullable();
            $table->longText('raw_booking_response')->nullable();
            $table->longText('live_hbx_response')->nullable();
            $table->timestamp('last_hbx_sync_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('booking_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_booking_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('supplier_room_id')->nullable();
            $table->string('room_code', 64)->nullable();
            $table->string('room_name')->nullable();
            $table->string('status', 32)->nullable();
            $table->string('board_code', 16)->nullable();
            $table->string('board_name')->nullable();
            $table->string('rate_class', 32)->nullable();
            $table->string('rate_type', 32)->nullable();
            $table->longText('rate_key')->nullable();
            $table->decimal('net', 14, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->unsignedInteger('allotment')->nullable();
            $table->boolean('packaging')->nullable();
            $table->string('payment_type', 32)->nullable();
            $table->longText('rate_comments')->nullable();
            $table->longText('promotions')->nullable();
            $table->longText('raw_data')->nullable();
            $table->timestamps();
        });

        Schema::create('booking_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_room_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('room_id');
            $table->string('type', 8);
            $table->string('name');
            $table->string('surname');
            $table->unsignedTinyInteger('age')->nullable();
            $table->timestamps();
        });

        Schema::create('booking_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_room_id')->constrained()->cascadeOnDelete();
            $table->string('sub_type')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->boolean('included')->default(false);
            $table->decimal('client_amount', 14, 2)->nullable();
            $table->string('client_currency', 8)->nullable();
            $table->timestamps();
        });

        Schema::create('booking_cancellation_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_room_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('raw_from_value', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('booking_simulations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_booking_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->index();
            $table->string('simulated_status', 32)->nullable();
            $table->decimal('cancellation_amount', 14, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('supplier_cancellation_reference')->nullable();
            $table->string('holder_name')->nullable();
            $table->string('holder_surname')->nullable();
            $table->longText('request_payload')->nullable();
            $table->longText('response_payload')->nullable();
            $table->timestamp('simulated_at');
            $table->timestamps();
        });

        Schema::create('hbx_api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hotel_search_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation', 64)->index();
            $table->string('request_method', 8);
            $table->string('endpoint');
            $table->unsignedSmallInteger('http_status')->nullable()->index();
            $table->boolean('successful')->index();
            $table->longText('request_payload')->nullable();
            $table->longText('response_payload')->nullable();
            $table->string('supplier_process_time', 32)->nullable();
            $table->unsignedInteger('local_duration_ms')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->string('hbx_reference')->nullable()->index();
            $table->string('client_reference')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hbx_api_logs');
        Schema::dropIfExists('booking_simulations');
        Schema::dropIfExists('booking_cancellation_policies');
        Schema::dropIfExists('booking_taxes');
        Schema::dropIfExists('booking_guests');
        Schema::dropIfExists('booking_rooms');
        Schema::dropIfExists('hotel_bookings');
        Schema::dropIfExists('rate_selections');
        Schema::dropIfExists('hotel_searches');
    }
};
