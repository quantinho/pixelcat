<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every POST to the webhook endpoint, written outside the processing transaction.
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->nullable()->index();
            $table->string('event_type')->nullable();
            $table->unsignedInteger('delivery_attempt')->nullable();
            $table->boolean('signature_valid');
            $table->string('outcome');
            $table->foreignUuid('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('headers');
            $table->jsonb('payload')->nullable(); // null when the signature was rejected
            $table->timestampTz('received_at')->useCurrent();

            $table->index('received_at');
        });

        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_outcome_check CHECK (outcome IN ('applied', 'duplicate', 'out_of_order', 'unknown_generation', 'ping', 'rejected', 'error'))");

        // One row per accepted event; the primary key is the dedupe.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->string('event_id')->primary();
            $table->string('type');
            $table->foreignUuid('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('payload');
            $table->timestampTz('processed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('webhook_deliveries');
    }
};
