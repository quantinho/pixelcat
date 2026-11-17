<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ledger entries are append-only (constitution II). The model refuses updates and
 * deletes, but query-builder writes bypass model events, so the database refuses
 * updates too. Deletes stay possible only through the users foreign key cascade,
 * which account deletion needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_entries_refuse_update() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'ledger_entries is append-only: entries cannot be updated';
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER ledger_entries_no_update
            BEFORE UPDATE ON ledger_entries
            FOR EACH ROW EXECUTE FUNCTION ledger_entries_refuse_update()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS ledger_entries_no_update ON ledger_entries');
        DB::statement('DROP FUNCTION IF EXISTS ledger_entries_refuse_update()');
    }
};
