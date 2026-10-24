<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = ['sales', 'purchases', 'sale_returns', 'purchase_returns'];

    /**
     * Adds a unique index on `reference` only where no duplicates exist.
     * Tables that already contain duplicates are skipped (logged) so existing data is never altered;
     * resolve the duplicates manually and re-run the migration.
     */
    public function up()
    {
        foreach ($this->tables as $table) {
            $duplicates = DB::table($table)->select('reference')->groupBy('reference')->havingRaw('COUNT(*) > 1')->count();

            if ($duplicates > 0) {
                Log::warning("Unique reference index NOT added to {$table}: {$duplicates} duplicate reference(s) exist.");
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->unique('reference', "{$table}_reference_unique");
            });
        }
    }

    public function down()
    {
        foreach ($this->tables as $table) {
            try {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropUnique("{$table}_reference_unique");
                });
            } catch (\Throwable $e) {
                // index was never created (duplicates existed)
            }
        }
    }
};
