<?php

namespace App\Console\Commands;

use App\Services\Ledger\LedgerService;
use App\Services\Ledger\Reconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds party history from the legacy (ARG Systems) ledger CSV: customers/suppliers, sales/purchases with their
 * item lines, and receipts/payments allocated FIFO to open invoices. Everything is posted through LedgerService,
 * so ledger_entries and paid/due/status stay consistent. Stock is never touched (detail lines have no product).
 * Plain PHP/Laravel only, so it runs the same on macOS and Windows. See docs/legacy-ledger-migration.md.
 */
class LegacyImportLedgers extends Command
{
    protected $signature = 'legacy:import-ledgers
        {file=database/data/legacy_ledgers.csv : Path to the combined ledger CSV}
        {--dry-run : Import, verify and report everything, then roll back}';

    protected $description = 'Idempotently import the legacy customer/supplier ledgers CSV into the POS. Verifies closing balances against the CSV and rolls back on any mismatch.';

    private const COLUMNS = ['ledger_owner', 'ledger_type', 'party_code', 'party_name', 'line_no', 'date', 'ref_no',
        'voucher_type', 'description', 'rate', 'qty', 'debit', 'credit', 'balance'];

    private const PARTY = [
        'CUSTOMER LEDGER' => ['type' => 'customer', 'table' => 'customers', 'prefix' => 'customer', 'doc' => 'sale', 'doc_voucher' => 'SAL'],
        'SUPPLIER LEDGER' => ['type' => 'supplier', 'table' => 'suppliers', 'prefix' => 'supplier', 'doc' => 'purchase', 'doc_voucher' => 'PUR'],
    ];

    /** Printed amounts are rounded whole rupees; the running balance may drift this much from the line sum. */
    private const BALANCE_TOLERANCE = 2;

    public function handle(LedgerService $ledger, Reconciler $reconciler): int
    {
        $file = $this->argument('file');
        $dry = (bool) $this->option('dry-run');

        try {
            $ledgers = $this->parse($file);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage() . ' Nothing was changed.');

            return self::FAILURE;
        }

        DB::beginTransaction();
        try {
            $summary = [];
            foreach ($ledgers as $owner => $rows) {
                $summary[] = $this->importLedger($ledger, $owner, $rows);
            }

            $drift = $reconciler->ledgerVsDocuments();
            if ($drift->isNotEmpty()) {
                throw new RuntimeException($drift->count() . ' party balance(s) do not agree with their documents after import.');
            }
            if ($reconciler->discrepancies()->isNotEmpty()) {
                throw new RuntimeException('Invoice paid/due figures disagree with their payment rows after import.');
            }

            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Import failed and was rolled back: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Ledger', 'Party', 'Documents', 'Receipts / payments', 'Skipped (already imported)', 'Closing (CSV)', 'Closing (POS)'], $summary);
        $this->info(($dry ? '[dry-run] Rolled back. ' : '') . 'Re-running is safe: vouchers already present are skipped.');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ CSV

    /** @return array<string, array<int, array>> rows grouped by ledger_owner, ordered by line_no */
    private function parse(string $file): array
    {
        if (!is_readable($file)) {
            throw new RuntimeException("Cannot read {$file}.");
        }

        $handle = fopen($file, 'r');
        $header = fgetcsv($handle, 0, ',', '"', '');
        if ($header && isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }
        $header = array_map('trim', $header ?: []);

        if ($header !== self::COLUMNS) {
            throw new RuntimeException('Unexpected CSV header. Expected: ' . implode(',', self::COLUMNS));
        }

        $ledgers = [];
        $line = 1;
        while (($raw = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;
            if ($raw === [null] || $raw === ['']) {
                continue;
            }
            if (count($raw) !== count(self::COLUMNS)) {
                throw new RuntimeException("CSV line {$line}: expected " . count(self::COLUMNS) . ' columns, got ' . count($raw) . '.');
            }

            $r = array_map('trim', array_combine(self::COLUMNS, $raw));

            if (!isset(self::PARTY[$r['ledger_type']])) {
                throw new RuntimeException("CSV line {$line}: ledger_type must be CUSTOMER LEDGER or SUPPLIER LEDGER.");
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['date']) || !checkdate((int) substr($r['date'], 5, 2), (int) substr($r['date'], 8, 2), (int) substr($r['date'], 0, 4))) {
                throw new RuntimeException("CSV line {$line}: date must be YYYY-MM-DD.");
            }
            foreach (['line_no', 'debit', 'credit', 'balance'] as $col) {
                if (!preg_match('/^-?\d+$/', $r[$col])) {
                    throw new RuntimeException("CSV line {$line}: {$col} must be a whole number.");
                }
                $r[$col] = (int) $r[$col];
            }
            $r['qty'] = $r['qty'] === '' ? null : (int) $r['qty'];
            if ($r['debit'] < 0 || $r['credit'] < 0 || ($r['debit'] > 0) === ($r['credit'] > 0)) {
                throw new RuntimeException("CSV line {$line}: exactly one of debit/credit must be positive.");
            }

            $ledgers[$r['ledger_owner']][] = $r;
        }
        fclose($handle);

        if (!$ledgers) {
            throw new RuntimeException('The CSV has no data rows.');
        }

        foreach ($ledgers as $owner => &$rows) {
            usort($rows, fn ($a, $b) => $a['line_no'] <=> $b['line_no']);
            $this->checkLedger($owner, $rows);
        }

        return $ledgers;
    }

    private function checkLedger(string $owner, array $rows): void
    {
        foreach (['ledger_type', 'party_code', 'party_name'] as $col) {
            if (count(array_unique(array_column($rows, $col))) !== 1) {
                throw new RuntimeException("{$owner}: every row must have the same {$col}.");
            }
        }

        $running = 0;
        foreach ($rows as $r) {
            $running += $r['debit'] - $r['credit'];
            if (abs($running - $r['balance']) > self::BALANCE_TOLERANCE) {
                throw new RuntimeException("{$owner} line {$r['line_no']}: running balance {$running} does not match printed balance {$r['balance']}.");
            }
        }
    }

    /**
     * Groups consecutive rows by ref_no. A voucher's amount is taken from the printed running balance (its last row
     * minus the balance before its first row), which carries the legacy system's unrounded figures.
     */
    private function vouchers(string $owner, array $rows): array
    {
        $out = [];
        $before = 0;
        $seen = [];

        foreach ($rows as $r) {
            $last = array_key_last($out);
            if ($last !== null && $out[$last]['ref'] === $r['ref_no']) {
                $out[$last]['rows'][] = $r;
                $out[$last]['after'] = $r['balance'];
                continue;
            }
            if (isset($seen[$r['ref_no']])) {
                throw new RuntimeException("{$owner}: rows for {$r['ref_no']} are not consecutive.");
            }
            $seen[$r['ref_no']] = true;
            $before = $last === null ? 0 : $out[$last]['after'];
            $out[] = ['ref' => $r['ref_no'], 'type' => $r['voucher_type'], 'date' => $r['date'], 'before' => $before, 'after' => $r['balance'], 'rows' => [$r]];
        }

        foreach ($out as &$v) {
            $dr = array_sum(array_column($v['rows'], 'debit'));
            $cr = array_sum(array_column($v['rows'], 'credit'));
            if ($dr > 0 && $cr > 0) {
                throw new RuntimeException("{$owner}: voucher {$v['ref']} mixes debit and credit rows.");
            }
            if (count(array_unique(array_column($v['rows'], 'date'))) !== 1) {
                throw new RuntimeException("{$owner}: voucher {$v['ref']} spans several dates.");
            }
            $v['side'] = $dr > 0 ? 'debit' : 'credit';
            $v['amount'] = abs($v['after'] - $v['before']);
            $v['printed'] = $dr + $cr;
        }

        return $out;
    }

    // ------------------------------------------------------------------ import

    private function importLedger(LedgerService $ledger, string $owner, array $rows): array
    {
        $first = $rows[0];
        $cfg = self::PARTY[$first['ledger_type']];
        $partyId = $this->party($cfg, $first);
        $docSide = $cfg['type'] === 'customer' ? 'debit' : 'credit';
        $created = ['docs' => 0, 'headers' => 0, 'skipped' => 0];

        foreach ($this->vouchers($owner, $rows) as $v) {
            $isDocument = $v['side'] === $docSide;

            if ($isDocument && $v['type'] !== $cfg['doc_voucher']) {
                throw new RuntimeException("{$owner}: {$v['ref']} ({$v['type']}) is on the invoice side but is not a {$cfg['doc_voucher']} voucher.");
            }

            $done = $isDocument
                ? $this->importDocument($ledger, $cfg, $partyId, $owner, $v)
                : $this->importHeader($ledger, $cfg, $partyId, $owner, $v);

            $done ? $created[$isDocument ? 'docs' : 'headers']++ : $created['skipped']++;
        }

        $expected = $cfg['type'] === 'customer' ? end($rows)['balance'] : -end($rows)['balance'];
        $actual = $ledger->partyBalance($cfg['type'], $partyId);

        // Only assert when this run imported something; on a re-run the party may already carry manual entries.
        if ($created['docs'] + $created['headers'] > 0 && $actual !== $expected * 100) {
            throw new RuntimeException("{$owner}: POS balance " . number_format($actual / 100, 2) . ' does not match the ledger closing ' . number_format($expected, 2) . '.');
        }

        return [$owner, $first['party_name'], $created['docs'], $created['headers'], $created['skipped'],
            number_format($expected), number_format($actual / 100)];
    }

    /** Find the party by exact name or create it with placeholder contact details (editable later in the UI). */
    private function party(array $cfg, array $row): int
    {
        $p = $cfg['prefix'];
        $existing = DB::table($cfg['table'])->where("{$p}_name", $row['party_name'])->value('id');
        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::table($cfg['table'])->insertGetId([
            "{$p}_name" => $row['party_name'],
            "{$p}_email" => strtolower($row['party_code']) . '@legacy.invalid',
            "{$p}_phone" => '-',
            'city' => '-',
            'country' => 'Pakistan',
            'address' => "Legacy account {$row['party_code']}",
            'opening_balance' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Sale (customer) or purchase (supplier) with informational detail lines; then soaks up any open advances. */
    private function importDocument(LedgerService $ledger, array $cfg, int $partyId, string $owner, array $v): bool
    {
        $kind = $cfg['doc'];
        $k = LedgerService::KINDS[$kind];

        $existing = DB::table($k['table'])->where('reference', $v['ref'])->first();
        if ($existing) {
            if ((int) $existing->{$k['party_col']} !== $partyId) {
                throw new RuntimeException("{$owner}: {$v['ref']} already exists for a different {$cfg['type']}.");
            }

            return false;
        }

        $total = $v['amount'] * 100;
        $note = "Imported from legacy ledger ({$owner})."
            . ($v['amount'] !== $v['printed'] ? " Printed lines total {$v['printed']}; ledger balance moved {$v['amount']} (legacy rounding)." : '');

        $id = DB::table($k['table'])->insertGetId([
            'date' => $v['date'],
            'reference' => $v['ref'],
            $k['party_col'] => $partyId,
            "{$cfg['prefix']}_name" => $v['rows'][0]['party_name'],
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0,
            'total_amount' => $total, 'paid_amount' => 0, 'due_amount' => $total,
            'status' => 'Completed', 'payment_status' => 'Unpaid', 'payment_method' => 'Other',
            'note' => $note,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->writeLines($kind, $id, $v, $total);
        $ledger->syncDocument($kind, $id);

        // Earlier receipts/payments kept as advances are allocated to this invoice, oldest first.
        foreach ($ledger->openHeaders($cfg['type'], $partyId) as $header) {
            $due = $total - $ledger->paidTotal($kind, $id);
            if ($due <= 0) {
                break;
            }
            $ledger->allocate($cfg['type'], (int) $header->id, [$id => min($due, (int) $header->unallocated)]);
        }

        return true;
    }

    /** One detail row per printed line, product-less (no stock effect). The last line absorbs legacy rounding. */
    private function writeLines(string $kind, int $id, array $v, int $total): void
    {
        $table = $kind === 'sale' ? 'sale_details' : 'purchase_details';
        $lines = [];

        foreach ($v['rows'] as $r) {
            $qty = max(1, (int) $r['qty']);
            $subTotal = ($r['debit'] + $r['credit']) * 100;
            $unit = (int) round($subTotal / $qty);
            $lines[] = [
                "{$kind}_id" => $id, 'product_id' => null,
                'product_name' => mb_substr($r['description'], 0, 255), 'product_code' => 'LEGACY',
                'quantity' => $qty, 'price' => $unit, 'unit_price' => $unit, 'sub_total' => $subTotal,
                'product_discount_amount' => 0, 'product_discount_type' => 'fixed', 'product_tax_amount' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }

        $lines[array_key_last($lines)]['sub_total'] += $total - array_sum(array_column($lines, 'sub_total'));

        DB::table($table)->insert($lines);
    }

    /** Customer receipt or supplier payment, allocated FIFO to open invoices; any remainder stays as an advance. */
    private function importHeader(LedgerService $ledger, array $cfg, int $partyId, string $owner, array $v): bool
    {
        $h = LedgerService::HEADERS[$cfg['type']];

        if (DB::table($h['table'])->where($h['party_col'], $partyId)->where('reference', $v['ref'])->exists()) {
            return false;
        }

        $amount = $v['amount'] * 100;
        [$method, $note] = $this->methodAndNote($cfg['type'], $v);

        $allocations = [];
        $left = $amount;
        foreach ($this->openDocuments($ledger, $cfg['doc'], $partyId) as $docId => $due) {
            if ($left <= 0) {
                break;
            }
            $allocations[$docId] = min($due, $left);
            $left -= $allocations[$docId];
        }

        $meta = ['date' => $v['date'], 'reference' => $v['ref'], 'method' => $method, 'note' => "{$note} Imported from legacy ledger ({$owner})."];

        $cfg['type'] === 'customer'
            ? $ledger->receiveCustomerPayment($partyId, $amount, $meta, $allocations)
            : $ledger->makeSupplierPayment($partyId, $amount, $meta, $allocations);

        return true;
    }

    /**
     * BRV/BPV are bank, CRV/CPV cash. Two kinds of legacy vouchers moved no money through the business and are
     * recorded as "Other" set-offs: rent settled between a customer and ST INTERNATIONAL, and goods sold to a supplier.
     */
    private function methodAndNote(string $partyType, array $v): array
    {
        $text = implode('; ', array_unique(array_column($v['rows'], 'description')));

        if ($v['type'] === 'SAL') {
            return ['Other', "Set-off: goods sold {$v['ref']} ({$text})."];
        }
        if (str_contains(strtoupper($text), 'RENT')) {
            return ['Other', "Set-off (contra): {$text}."];
        }

        $method = match ($v['type']) {
            'BRV', 'BPV' => 'Bank Transfer',
            'CRV', 'CPV' => 'Cash',
            default => throw new RuntimeException("Unknown {$partyType} voucher type {$v['type']} ({$v['ref']})."),
        };

        return [$method, "{$text}."];
    }

    /** @return array<int,int> document id => outstanding minor units, oldest first */
    private function openDocuments(LedgerService $ledger, string $kind, int $partyId): array
    {
        $k = LedgerService::KINDS[$kind];
        $out = [];

        $docs = DB::table($k['table'])->where($k['party_col'], $partyId)->whereNull('cancelled_at')
            ->orderBy('date')->orderBy('id')->get(['id', 'total_amount']);

        foreach ($docs as $d) {
            $due = (int) $d->total_amount - $ledger->paidTotal($kind, (int) $d->id);
            if ($due > 0) {
                $out[(int) $d->id] = $due;
            }
        }

        return $out;
    }
}
