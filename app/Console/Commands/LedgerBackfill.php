<?php

namespace App\Console\Commands;

use App\Models\LedgerEntry;
use App\Services\Ledger\LedgerService;
use App\Services\Ledger\Reconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LedgerBackfill extends Command
{
    protected $signature = 'ledger:backfill
        {--dry-run : Compute and report everything, then roll back}
        {--resolutions= : CSV (kind,id,resolution,reason,approved_by) approving discrepancies. resolution=use_payments treats the payment rows as the truth and recomputes paid/due}';

    protected $description = 'Idempotently post historical invoices, returns and valid payments to the party ledger. Refuses to run while unresolved paid/due discrepancies exist. Never run automatically.';

    public function handle(Reconciler $reconciler, LedgerService $ledger): int
    {
        $discrepancies = $reconciler->discrepancies();
        $resolutions = $this->loadResolutions();

        $unresolved = $discrepancies->filter(fn ($d) => !isset($resolutions["{$d->kind}:{$d->id}"]));

        if ($unresolved->isNotEmpty()) {
            $this->error($unresolved->count() . ' invoice(s) have unresolved discrepancies. Nothing was changed.');
            $this->table(['kind', 'id', 'reference', 'stored paid', 'payments', 'issues'],
                $unresolved->map(fn ($d) => [$d->kind, $d->id, $d->reference, $d->stored_paid, $d->payments, $d->issues]));
            $this->line('Run `php artisan ledger:reconcile --csv`, review each row with the business, and supply --resolutions=<csv>.');

            return self::FAILURE;
        }

        $before = LedgerEntry::count();
        $dry = $this->option('dry-run');

        DB::beginTransaction();
        try {
            foreach ($discrepancies as $d) {
                $res = $resolutions["{$d->kind}:{$d->id}"];
                $ledger->recalculateInvoicePaymentStatus($d->kind, $d->id);
                Log::info('ledger:backfill discrepancy resolved', ['kind' => $d->kind, 'id' => $d->id, 'reference' => $d->reference] + $res + ['stored_paid' => $d->stored_paid, 'payments' => $d->payments]);
            }

            foreach (LedgerService::KINDS as $kind => $cfg) {
                DB::table($cfg['table'])->whereNull('cancelled_at')->orderBy('id')->pluck('id')->each(function ($id) use ($ledger, $kind) {
                    $ledger->syncDocument($kind, (int) $id);
                });

                DB::table($cfg['payment_table'])->whereNull('voided_at')
                    ->when(in_array($kind, ['sale', 'purchase']), fn ($q) => $q->whereNull('receipt_id'))
                    ->orderBy('id')->pluck('id')->each(function ($id) use ($ledger, $kind) {
                        $ledger->postDirectPayment($kind, (int) $id);
                    });
            }

            $created = LedgerEntry::count() - $before;
            $drift = $reconciler->ledgerVsDocuments();

            if ($drift->isNotEmpty()) {
                throw new \RuntimeException($drift->count() . ' party balance(s) do not agree with the documents after posting; rolled back.');
            }

            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Backfill failed and was rolled back: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(($dry ? '[dry-run] ' : '') . "Ledger entries created: {$created}. Balances agree with documents. Re-running is safe (idempotent).");

        if (!$dry && $discrepancies->isNotEmpty()) {
            $path = 'ledger/backfill-resolutions-' . now()->format('Ymd-His') . '.json';
            Storage::disk('local')->put($path, json_encode($discrepancies->map(fn ($d) => (array) $d + $resolutions["{$d->kind}:{$d->id}"])->all(), JSON_PRETTY_PRINT));
            $this->info('Applied resolutions archived at storage/app/' . $path);
        }

        return self::SUCCESS;
    }

    private function loadResolutions(): array
    {
        $file = $this->option('resolutions');
        if (!$file) {
            return [];
        }
        if (!is_readable($file)) {
            $this->error("Cannot read {$file}");
            exit(self::FAILURE);
        }

        $out = [];
        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            $r = array_combine($header, $row);
            if (($r['resolution'] ?? '') !== 'use_payments' || empty($r['reason']) || empty($r['approved_by'])) {
                continue; // incomplete resolutions are ignored => row stays unresolved
            }
            $out["{$r['kind']}:{$r['id']}"] = ['resolution' => $r['resolution'], 'reason' => $r['reason'], 'approved_by' => $r['approved_by']];
        }
        fclose($handle);

        return $out;
    }
}
