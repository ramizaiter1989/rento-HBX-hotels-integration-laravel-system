<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('sync_type', 16);
            $table->char('language', 3);
            $table->date('last_update_time')->nullable();
            $table->unsignedInteger('batch_size');
            $table->unsignedInteger('supplier_total')->nullable();
            $table->unsignedInteger('next_from');
            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('details_retained')->default(0);
            $table->unsignedInteger('conflicts')->default(0);
            $table->unsignedInteger('http_requests')->default(0);
            $table->string('status', 16);
            $table->text('stop_reason')->nullable();
            $table->boolean('stop_requested')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('last_progress_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['sync_type', 'language', 'status'], 'content_sync_runs_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_sync_runs');
    }
};
