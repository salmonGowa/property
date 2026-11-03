<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The tables whose rows can never be changed or removed once written.
     *
     * @var list<string>
     */
    private array $tables = ['audit_logs', 'journal_entries', 'journal_lines'];

    /**
     * Run the migrations.
     *
     * Database triggers enforce immutability even for writes that bypass the application.
     */
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($this->tables as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $trigger = $this->triggerName($table, $operation);

                DB::unprepared(<<<SQL
                    CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table}
                    FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} rows are immutable'
                    SQL);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($this->tables as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$this->triggerName($table, $operation)}");
            }
        }
    }

    private function triggerName(string $table, string $operation): string
    {
        return "{$table}_prevent_".strtolower($operation);
    }
};
