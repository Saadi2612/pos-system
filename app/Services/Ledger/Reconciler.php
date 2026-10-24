<?php

namespace App\Services\Ledger;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only comparison of stored invoice paid/due figures against their payment rows. Never modifies data. */
class Reconciler
{
    /**
     * @return Collection<object{kind:string,id:int,reference:string,total:int,stored_paid:int,stored_due:int,payments:int,paid_diff:int,due_diff:int,issues:string}>
     */
    public function discrepancies(): Collection
    {
        $out = collect();

        foreach (LedgerService::KINDS as $kind => $cfg) {
            DB::table($cfg['table'])->orderBy('id')->chunkById(500, function ($docs) use (&$out, $kind, $cfg) {
                $sums = DB::table($cfg['payment_table'])->whereNull('voided_at')
                    ->whereIn($cfg['fk'], $docs->pluck('id'))
                    ->selectRaw("{$cfg['fk']} as doc_id, SUM(amount) as s")->groupBy($cfg['fk'])->pluck('s', 'doc_id');

                foreach ($docs as $d) {
                    $payments = (int) ($sums[$d->id] ?? 0);
                    $total = (int) $d->total_amount;
                    $paidDiff = (int) $d->paid_amount - $payments;
                    $dueDiff = (int) $d->due_amount - ($total - $payments);
                    $issues = [];

                    if ($paidDiff !== 0) {
                        $issues[] = 'paid_amount differs from payment rows';
                    }
                    if ($dueDiff !== 0) {
                        $issues[] = 'due_amount differs from total - payments';
                    }
                    if ($payments > $total) {
                        $issues[] = 'payments exceed total';
                    }

                    if ($issues) {
                        $out->push((object) [
                            'kind' => $kind, 'id' => (int) $d->id, 'reference' => $d->reference, 'total' => $total,
                            'stored_paid' => (int) $d->paid_amount, 'stored_due' => (int) $d->due_amount, 'payments' => $payments,
                            'paid_diff' => $paidDiff, 'due_diff' => $dueDiff, 'issues' => implode('; ', $issues),
                        ]);
                    }
                }
            });
        }

        return $out;
    }

    /** Duplicate references per document table (blocks adding the unique index). */
    public function duplicateReferences(): Collection
    {
        $out = collect();

        foreach (LedgerService::KINDS as $kind => $cfg) {
            DB::table($cfg['table'])->select('reference', DB::raw('COUNT(*) as n'))->groupBy('reference')
                ->havingRaw('COUNT(*) > 1')->get()
                ->each(fn ($r) => $out->push((object) ['table' => $cfg['table'], 'reference' => $r->reference, 'count' => (int) $r->n]));
        }

        return $out;
    }

    /** Party balances derived from documents vs. the ledger, for post-backfill verification. */
    public function ledgerVsDocuments(): Collection
    {
        $ledger = app(LedgerService::class);
        $out = collect();

        foreach (['customer' => ['sale', 'sale_return', 'customers'], 'supplier' => ['purchase', 'purchase_return', 'suppliers']] as $type => [$doc, $ret, $table]) {
            $dc = LedgerService::KINDS[$doc];
            $rc = LedgerService::KINDS[$ret];

            foreach (DB::table($table)->pluck('id') as $partyId) {
                $docs = (int) DB::table($dc['table'])->where($dc['party_col'], $partyId)->whereNull('cancelled_at')->sum('total_amount');
                $docPaid = (int) DB::table($dc['payment_table'])->whereNull('voided_at')
                    ->whereIn($dc['fk'], DB::table($dc['table'])->where($dc['party_col'], $partyId)->select('id'))->sum('amount');
                $rets = (int) DB::table($rc['table'])->where($rc['party_col'], $partyId)->whereNull('cancelled_at')->sum('total_amount');
                $retPaid = (int) DB::table($rc['payment_table'])->whereNull('voided_at')
                    ->whereIn($rc['fk'], DB::table($rc['table'])->where($rc['party_col'], $partyId)->select('id'))->sum('amount');
                $headers = (int) DB::table(LedgerService::HEADERS[$type]['table'])->where(LedgerService::HEADERS[$type]['party_col'], $partyId)->whereNull('voided_at')->sum('amount');
                $headerAllocated = (int) DB::table($dc['payment_table'])->whereNull('voided_at')->whereNotNull('receipt_id')
                    ->whereIn($dc['fk'], DB::table($dc['table'])->where($dc['party_col'], $partyId)->select('id'))->sum('amount');
                $directPaid = $docPaid - $headerAllocated;
                $opening = (int) DB::table($table)->where('id', $partyId)->value('opening_balance');

                // customer: owes = sales - sale payments(cash) - returns + refunds + opening ; supplier mirrors.
                $expected = $docs - $directPaid - $headers - $rets + $retPaid + $opening;
                $actual = $ledger->partyBalance($type, $partyId);

                if ($expected !== $actual) {
                    $out->push((object) ['party_type' => $type, 'party_id' => $partyId, 'expected' => $expected, 'ledger' => $actual, 'diff' => $actual - $expected]);
                }
            }
        }

        return $out;
    }
}
