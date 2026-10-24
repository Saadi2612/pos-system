<?php

namespace App\Services\Ledger;

use App\Models\LedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-side queries for outstanding invoices, ageing and ledgers. Outstanding amounts are derived from the
 * invoice total and valid (non-voided) payment rows, never from the cached paid/due columns.
 */
class CreditQueries
{
    public const BUCKETS = ['current' => 'Current / not due', 'd1_30' => '1-30', 'd31_60' => '31-60', 'd61_90' => '61-90', 'd90_plus' => 'Over 90'];

    /**
     * @param array $f party_id, invoice_from, invoice_to, due_from, due_to, overdue_only, status (Unpaid|Partial), as_of
     * @return Collection<object> each: id, reference, party_id, party_name, date, due_date, total, paid, due, days_overdue, bucket, payment_status
     */
    public function outstanding(string $partyType, array $f = []): Collection
    {
        $kind = $partyType === 'customer' ? 'sale' : 'purchase';
        $cfg = LedgerService::KINDS[$kind];
        $asOf = Carbon::parse($f['as_of'] ?? today())->startOfDay();

        $paidSql = "COALESCE((SELECT SUM(p.amount) FROM {$cfg['payment_table']} p WHERE p.{$cfg['fk']} = d.id AND p.voided_at IS NULL), 0)";

        $rows = DB::table("{$cfg['table']} as d")
            ->selectRaw("d.id, d.reference, d.{$cfg['party_col']} as party_id, d.{$cfg['party']}_name as party_name, d.date, d.due_date, d.total_amount as total, {$paidSql} as paid")
            ->whereNull('d.cancelled_at')
            ->whereRaw("d.total_amount - {$paidSql} > 0")
            ->when(!empty($f['party_id']), fn ($q) => $q->where("d.{$cfg['party_col']}", $f['party_id']))
            ->when(!empty($f['invoice_from']), fn ($q) => $q->where('d.date', '>=', $f['invoice_from']))
            ->when(!empty($f['invoice_to']), fn ($q) => $q->where('d.date', '<=', $f['invoice_to']))
            ->when(!empty($f['due_from']), fn ($q) => $q->where('d.due_date', '>=', $f['due_from']))
            ->when(!empty($f['due_to']), fn ($q) => $q->where('d.due_date', '<=', $f['due_to']))
            ->orderBy('d.due_date')->orderBy('d.date')->orderBy('d.id')
            ->get();

        return $rows->map(function ($r) use ($asOf) {
            $r->total = (int) $r->total;
            $r->paid = (int) $r->paid;
            $r->due = $r->total - $r->paid;
            $r->payment_status = $r->paid === 0 ? 'Unpaid' : 'Partial';
            $r->days_overdue = 0;

            if ($r->due_date) {
                $r->days_overdue = max(0, Carbon::parse($r->due_date)->startOfDay()->diffInDays($asOf, false));
            }

            $r->bucket = match (true) {
                $r->days_overdue <= 0 => 'current',
                $r->days_overdue <= 30 => 'd1_30',
                $r->days_overdue <= 60 => 'd31_60',
                $r->days_overdue <= 90 => 'd61_90',
                default => 'd90_plus',
            };

            return $r;
        })->filter(function ($r) use ($f) {
            if (!empty($f['status']) && $r->payment_status !== $f['status']) {
                return false;
            }

            return empty($f['overdue_only']) || $r->days_overdue > 0;
        })->values();
    }

    /** Per-party ageing buckets over outstanding invoices (minor units). */
    public function ageing(string $partyType, ?string $asOf = null, ?int $partyId = null): Collection
    {
        return $this->outstanding($partyType, ['as_of' => $asOf, 'party_id' => $partyId])
            ->groupBy('party_id')->map(function ($rows) {
                $out = (object) ['party_id' => $rows->first()->party_id, 'party_name' => $rows->first()->party_name, 'total' => 0];
                foreach (array_keys(self::BUCKETS) as $b) {
                    $out->$b = 0;
                }
                foreach ($rows as $r) {
                    $out->{$r->bucket} += $r->due;
                    $out->total += $r->due;
                }

                return $out;
            })->sortBy('party_name')->values();
    }

    public function overdueTotal(string $partyType, int $partyId): int
    {
        return (int) $this->outstanding($partyType, ['party_id' => $partyId, 'overdue_only' => true])->sum('due');
    }

    /**
     * Ledger rows with running balance in the party's own sign convention.
     * Opening carry (balance before $from) is included as the first running value.
     *
     * @return array{rows: Collection, opening: int, closing: int}
     */
    public function ledger(string $partyType, int $partyId, ?string $from = null, ?string $to = null): array
    {
        $sign = fn ($d, $c) => $partyType === 'customer' ? $d - $c : $c - $d;

        $opening = 0;
        if ($from) {
            $r = LedgerEntry::forParty($partyType, $partyId)->where('entry_date', '<', $from)
                ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();
            $opening = $sign((int) $r->d, (int) $r->c);
        }

        $running = $opening;
        $rows = LedgerEntry::forParty($partyType, $partyId)
            ->when($from, fn ($q) => $q->where('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('entry_date', '<=', $to))
            ->orderBy('entry_date')->orderBy('id')->get()
            ->map(function ($e) use (&$running, $sign) {
                $running += $sign($e->debit, $e->credit);
                $e->running = $running;

                return $e;
            });

        return ['rows' => $rows, 'opening' => $opening, 'closing' => $running];
    }

    /** Receipt / supplier-payment header history with allocated and unallocated amounts. */
    public function payments(string $partyType, array $f = []): Collection
    {
        $h = LedgerService::HEADERS[$partyType];
        $k = LedgerService::KINDS[$h['kind']];
        $partyTable = $h['party_table'];
        $nameCol = $partyType === 'customer' ? 'customer_name' : 'supplier_name';

        return DB::table("{$h['table']} as r")
            ->join("{$partyTable} as p", 'p.id', '=', "r.{$h['party_col']}")
            ->selectRaw("r.id, r.date, r.reference, r.amount, r.payment_method, r.note, r.voided_at, r.{$h['party_col']} as party_id, p.{$nameCol} as party_name,
                COALESCE((SELECT SUM(a.amount) FROM {$k['payment_table']} a WHERE a.receipt_id = r.id AND a.voided_at IS NULL), 0) as allocated")
            ->when(!empty($f['party_id']), fn ($q) => $q->where("r.{$h['party_col']}", $f['party_id']))
            ->when(!empty($f['from']), fn ($q) => $q->where('r.date', '>=', $f['from']))
            ->when(!empty($f['to']), fn ($q) => $q->where('r.date', '<=', $f['to']))
            ->when(!empty($f['method']), fn ($q) => $q->where('r.payment_method', $f['method']))
            ->when(empty($f['include_voided']), fn ($q) => $q->whereNull('r.voided_at'))
            ->orderByDesc('r.date')->orderByDesc('r.id')->get()
            ->map(function ($r) {
                $r->amount = (int) $r->amount;
                $r->allocated = (int) $r->allocated;
                $r->unallocated = $r->voided_at ? 0 : $r->amount - $r->allocated;

                return $r;
            });
    }

    /** Everything the customer / supplier account page needs. */
    public function account(string $partyType, int $partyId): array
    {
        $ledger = app(LedgerService::class);
        $outstanding = $this->outstanding($partyType, ['party_id' => $partyId]);

        return [
            'balance' => $ledger->partyBalance($partyType, $partyId),
            'overdue' => (int) $outstanding->where('days_overdue', '>', 0)->sum('due'),
            'outstandingTotal' => (int) $outstanding->sum('due'),
            'advance' => $ledger->unallocatedAdvance($partyType, $partyId),
            'invoices' => $outstanding,
            'payments' => $this->payments($partyType, ['party_id' => $partyId, 'include_voided' => true]),
            'openHeaders' => $ledger->openHeaders($partyType, $partyId),
            'ledger' => $this->ledger($partyType, $partyId),
        ];
    }
}
