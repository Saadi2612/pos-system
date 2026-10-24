<?php

namespace App\Console\Commands;

use App\Services\Ledger\Reconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class LedgerReconcile extends Command
{
    protected $signature = 'ledger:reconcile {--csv : Also write the report to storage/app/ledger/}';

    protected $description = 'Read-only: list invoices whose stored paid/due figures disagree with their payment rows, duplicate references, and ledger-vs-document differences.';

    public function handle(Reconciler $reconciler): int
    {
        $rows = $reconciler->discrepancies();
        $dups = $reconciler->duplicateReferences();

        $this->info('Invoice / return payment reconciliation (amounts in minor units, i.e. cents)');
        if ($rows->isEmpty()) {
            $this->line('  No discrepancies.');
        } else {
            $this->table(['kind', 'id', 'reference', 'total', 'stored paid', 'stored due', 'sum(valid payments)', 'paid diff', 'due diff', 'issues'],
                $rows->map(fn ($r) => [$r->kind, $r->id, $r->reference, $r->total, $r->stored_paid, $r->stored_due, $r->payments, $r->paid_diff, $r->due_diff, $r->issues]));
        }

        if ($dups->isNotEmpty()) {
            $this->warn('Duplicate document references (unique index will be skipped until resolved):');
            $this->table(['table', 'reference', 'count'], $dups->map(fn ($d) => [$d->table, $d->reference, $d->count]));
        }

        $drift = $reconciler->ledgerVsDocuments();
        if ($drift->isNotEmpty()) {
            $this->warn('Ledger balance differs from document-derived balance (expected before the backfill is run):');
            $this->table(['party', 'id', 'expected from documents', 'ledger', 'diff'], $drift->map(fn ($d) => [$d->party_type, $d->party_id, $d->expected, $d->ledger, $d->diff]));
        } else {
            $this->info('Ledger balances agree with document-derived balances.');
        }

        if ($this->option('csv')) {
            $csv = "kind,id,reference,total,stored_paid,stored_due,payments,paid_diff,due_diff,issues\n";
            foreach ($rows as $r) {
                $csv .= implode(',', [$r->kind, $r->id, '"' . $r->reference . '"', $r->total, $r->stored_paid, $r->stored_due, $r->payments, $r->paid_diff, $r->due_diff, '"' . $r->issues . '"']) . "\n";
            }
            $path = 'ledger/reconciliation-' . now()->format('Ymd-His') . '.csv';
            Storage::disk('local')->put($path, $csv);
            $this->info('Report written to storage/app/' . $path . ' (resolutions template columns: kind,id,resolution,reason,approved_by)');
        }

        return $rows->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
