<?php

namespace App\Services\Ledger;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Actual money movements (never invoice totals). Voided payments are excluded.
 * Invoice payment rows that belong to a receipt/payment header are allocations of that header, so only the header
 * is counted; legacy header-less rows (receipt_id NULL) are counted directly.
 * Amounts are minor units.
 */
class CashFlow
{
    /** @return Collection<object{date:string,amount:int}> */
    public function received(?string $from = null, ?string $to = null): Collection
    {
        return $this->union([
            $this->rows('customer_receipts', $from, $to),
            $this->rows('sale_payments', $from, $to, true),
            $this->rows('purchase_return_payments', $from, $to),
        ]);
    }

    /** @return Collection<object{date:string,amount:int}> */
    public function sent(?string $from = null, ?string $to = null): Collection
    {
        return $this->union([
            $this->rows('supplier_payments', $from, $to),
            $this->rows('purchase_payments', $from, $to, true),
            $this->rows('sale_return_payments', $from, $to),
        ]);
    }

    public function receivedTotal(?string $from = null, ?string $to = null): int
    {
        return (int) $this->received($from, $to)->sum('amount');
    }

    public function sentTotal(?string $from = null, ?string $to = null): int
    {
        return (int) $this->sent($from, $to)->sum('amount');
    }

    private function rows(string $table, ?string $from, ?string $to, bool $directOnly = false): Collection
    {
        return DB::table($table)->whereNull('voided_at')
            ->when($directOnly, fn ($q) => $q->whereNull('receipt_id'))
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
            ->get(['date', 'amount'])
            ->map(fn ($r) => (object) ['date' => $r->date, 'amount' => (int) $r->amount]);
    }

    private function union(array $sets): Collection
    {
        return collect($sets)->flatten(1)->values();
    }
}
