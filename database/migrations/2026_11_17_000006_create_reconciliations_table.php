<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Our provider cost per UTC completion day vs. GET /v1/usage.
        Schema::create('reconciliations', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->bigInteger('our_cost');
            $table->unsignedInteger('our_count');
            $table->bigInteger('pixelforge_cost');
            $table->unsignedInteger('pixelforge_count');
            $table->bigInteger('difference');
            $table->timestampTz('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliations');
    }
};
