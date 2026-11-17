<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money columns are bigint nanodollars (1 USD = 1,000,000,000). See data-model.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            // Created by the form; also the Pixelforge Idempotency-Key and metadata.local_id.
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('status');

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->jsonb('reference_images');
            $table->string('request_hash', 64);

            $table->bigInteger('estimate_min');
            $table->bigInteger('estimate_likely');
            $table->bigInteger('estimate_max');
            $table->jsonb('estimate_rates');
            $table->jsonb('rates')->nullable();

            $table->integer('input_text_tokens')->nullable();
            $table->integer('input_image_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('reasoning_tokens')->nullable();
            $table->bigInteger('provider_cost')->nullable();
            $table->bigInteger('charged')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->text('image_url')->nullable();
            $table->timestampTz('image_url_expires_at')->nullable();
            $table->string('image_path')->nullable();

            $table->timestampTz('provider_created_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->timestampTz('available_at');
            $table->unsignedInteger('submit_attempts')->default(0);
            $table->boolean('had_ambiguous_attempt')->default(false);
            $table->boolean('is_anomaly')->default(false);
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'available_at', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'last_synced_at']);
            $table->index('completed_at');
        });

        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_status_check CHECK (status IN ('waiting', 'submitting', 'queued', 'processing', 'succeeded', 'failed'))");
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_size_check CHECK (size IN ('1024x1024', '1536x1024', '1024x1536'))");
        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_quality_check CHECK (quality IN ('low', 'medium', 'high'))");
        DB::statement('ALTER TABLE generations ADD CONSTRAINT generations_reference_images_check CHECK (jsonb_typeof(reference_images) = \'array\' AND jsonb_array_length(reference_images) <= 4)');
        DB::statement('ALTER TABLE generations ADD CONSTRAINT generations_charged_check CHECK (charged IS NULL OR charged >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
