<?php

namespace App\Services\Ledger;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Row-locked stock movements for invoice/return documents.
 * Which document statuses move stock mirrors the pre-existing controller rules.
 */
class StockService
{
    /** Statuses at which each document kind has moved stock (verified from the original controllers). */
    public const MOVING_STATUSES = [
        'sale'            => ['Shipped', 'Completed'],   // stock out
        'purchase'        => ['Completed'],              // stock in
        'sale_return'     => ['Completed'],              // stock in
        'purchase_return' => ['Shipped', 'Completed'],   // stock out
    ];

    /** +1 stock in, -1 stock out when the document is posted. */
    public const DIRECTION = [
        'sale' => -1, 'purchase' => 1, 'sale_return' => 1, 'purchase_return' => -1,
    ];

    public const DETAILS = [
        'sale' => ['sale_details', 'sale_id'],
        'purchase' => ['purchase_details', 'purchase_id'],
        'sale_return' => ['sale_return_details', 'sale_return_id'],
        'purchase_return' => ['purchase_return_details', 'purchase_return_id'],
    ];

    public static function moves(string $kind, ?string $status): bool
    {
        return in_array($status, self::MOVING_STATUSES[$kind], true);
    }

    /** Apply ($sign = 1) or reverse ($sign = -1) the stock effect of a document's stored detail lines. */
    public static function applyStored(string $kind, int $documentId, int $sign = 1): void
    {
        [$table, $fk] = self::DETAILS[$kind];

        $lines = DB::table($table)->where($fk, $documentId)->whereNotNull('product_id')
            ->selectRaw('product_id, SUM(quantity) as qty')->groupBy('product_id')->orderBy('product_id')->get();

        foreach ($lines as $line) {
            self::move((int) $line->product_id, self::DIRECTION[$kind] * $sign * (int) $line->qty);
        }
    }

    /** Apply the stock effect for in-memory lines [product_id => qty] (used before detail rows exist). */
    public static function applyLines(string $kind, array $lines, int $sign = 1): void
    {
        ksort($lines);
        foreach ($lines as $productId => $qty) {
            self::move((int) $productId, self::DIRECTION[$kind] * $sign * (int) $qty);
        }
    }

    public static function move(int $productId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $product = DB::table('products')->where('id', $productId)->lockForUpdate()->first();

        if (!$product) {
            return;
        }

        $new = $product->product_quantity + $delta;

        if ($new < 0) {
            throw ValidationException::withMessages([
                'stock' => "Insufficient stock for \"{$product->product_name}\" (available {$product->product_quantity}, required " . abs($delta) . ').',
            ]);
        }

        DB::table('products')->where('id', $productId)->update(['product_quantity' => $new]);
    }
}
