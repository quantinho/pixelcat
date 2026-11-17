<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only timeline shown to the user.
        Schema::create('generation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('generation_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('message');
            $table->jsonb('data')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['generation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_events');
    }
};
