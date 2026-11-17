<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only credit ledger. A balance is the sum of a user's entries.
 * The database, not only the application, guarantees one hold, one release
 * and one charge per generation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Generations are only deleted together with their user, whose entries go too.
            $table->foreignUuid('generation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->bigInteger('amount');
            $table->string('description');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['user_id', 'id']);
        });

        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type_check CHECK (type IN ('grant', 'purchase', 'hold', 'hold_release', 'charge'))");
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_sign_check CHECK (
                (type IN ('grant', 'purchase', 'hold_release') AND amount >= 0)
                OR (type IN ('hold', 'charge') AND amount <= 0)
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_generation_check CHECK (
                type IN ('grant', 'purchase') OR generation_id IS NOT NULL
            )
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ledger_entries_one_per_generation_and_type
            ON ledger_entries (generation_id, type)
            WHERE type IN ('hold', 'hold_release', 'charge')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
