<?php

namespace App\Services\Ledger;

use Illuminate\Support\Facades\DB;

/**
 * Concurrency-safe replacement for `Model::max('id') + 1` reference numbers.
 * Keeps the existing "PREFIX-00001" format. Must be called inside the creating DB transaction
 * (the counter row is locked until it commits).
 */
class ReferenceGenerator
{
    public static function next(string $prefix, string $table): string
    {
        return DB::transaction(function () use ($prefix, $table) {
            $row = DB::table('reference_counters')->where('prefix', $prefix)->lockForUpdate()->first();

            if (!$row) {
                // Seed from existing data once so numbering continues after historical references.
                $seed = max((int) DB::table($table)->max('id'), self::highestExisting($prefix, $table));
                DB::table('reference_counters')->insertOrIgnore(['prefix' => $prefix, 'last_number' => $seed]);
                $row = DB::table('reference_counters')->where('prefix', $prefix)->lockForUpdate()->first();
            }

            DB::table('reference_counters')->where('prefix', $prefix)->increment('last_number');

            return make_reference_id($prefix, $row->last_number + 1);
        });
    }

    private static function highestExisting(string $prefix, string $table): int
    {
        $max = 0;
        DB::table($table)->where('reference', 'like', $prefix . '-%')->orderBy('id')
            ->pluck('reference')
            ->each(function ($ref) use ($prefix, &$max) {
                if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', $ref, $m)) {
                    $max = max($max, (int) $m[1]);
                }
            });

        return $max;
    }
}
