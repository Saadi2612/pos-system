<?php

namespace App\Services\Ledger;

use App\Models\LedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Central party sub-ledger + payment service. Every ledger posting in the application goes through here.
 *
 * Conventions (all amounts are integer minor units):
 *   Customer: sale = DEBIT, receipt = CREDIT, sale return = CREDIT, refund = DEBIT, opening debit = DEBIT.
 *             balance = SUM(debit) - SUM(credit); positive => customer owes us, negative => customer credit/advance.
 *   Supplier: purchase = CREDIT, payment = DEBIT, purchase return = DEBIT, refund received = CREDIT, opening credit = CREDIT.
 *             balance = SUM(credit) - SUM(debit); positive => we owe supplier, negative => supplier advance.
 *
 * Invoice payment rows (sale_payments / purchase_payments) are the allocations of a receipt/payment header
 * (receipt_id set). Legacy rows with receipt_id NULL are "direct" payments and carry their own ledger entry.
 * Every public mutator opens (or joins) a DB transaction and row-locks the documents it changes.
 */
class LedgerService
{
    public const KINDS = [
        'sale' => [
            'table' => 'sales', 'payment_table' => 'sale_payments', 'fk' => 'sale_id', 'party' => 'customer',
            'party_col' => 'customer_id', 'side' => 'debit', 'entry_type' => 'invoice', 'label' => 'Sale',
        ],
        'purchase' => [
            'table' => 'purchases', 'payment_table' => 'purchase_payments', 'fk' => 'purchase_id', 'party' => 'supplier',
            'party_col' => 'supplier_id', 'side' => 'credit', 'entry_type' => 'invoice', 'label' => 'Purchase',
        ],
        'sale_return' => [
            'table' => 'sale_returns', 'payment_table' => 'sale_return_payments', 'fk' => 'sale_return_id', 'party' => 'customer',
            'party_col' => 'customer_id', 'side' => 'credit', 'entry_type' => 'return', 'label' => 'Sale return',
        ],
        'purchase_return' => [
            'table' => 'purchase_returns', 'payment_table' => 'purchase_return_payments', 'fk' => 'purchase_return_id', 'party' => 'supplier',
            'party_col' => 'supplier_id', 'side' => 'debit', 'entry_type' => 'return', 'label' => 'Purchase return',
        ],
    ];

    public const HEADERS = [
        'customer' => [
            'table' => 'customer_receipts', 'party_table' => 'customers', 'party_col' => 'customer_id', 'kind' => 'sale',
            'side' => 'credit', 'prefix' => 'RCV', 'source' => 'customer_receipt', 'label' => 'Receipt',
        ],
        'supplier' => [
            'table' => 'supplier_payments', 'party_table' => 'suppliers', 'party_col' => 'supplier_id', 'kind' => 'purchase',
            'side' => 'debit', 'prefix' => 'SPY', 'source' => 'supplier_payment', 'label' => 'Payment',
        ],
    ];

    // ------------------------------------------------------------------ balances

    /** Signed balance in minor units using the party-specific convention described above. */
    public function partyBalance(string $partyType, int $partyId, ?string $asOf = null): int
    {
        $row = LedgerEntry::forParty($partyType, $partyId)
            ->when($asOf, fn ($q) => $q->where('entry_date', '<=', $asOf))
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')->first();

        return $partyType === 'customer' ? (int) ($row->d - $row->c) : (int) ($row->c - $row->d);
    }

    public function getCustomerBalance(int $customerId, ?string $asOf = null): int
    {
        return $this->partyBalance('customer', $customerId, $asOf);
    }

    public function getSupplierBalance(int $supplierId, ?string $asOf = null): int
    {
        return $this->partyBalance('supplier', $supplierId, $asOf);
    }

    /** Sum of header amounts not yet allocated to invoices (customer / supplier advances). */
    public function unallocatedAdvance(string $partyType, int $partyId): int
    {
        $total = 0;
        foreach ($this->openHeaders($partyType, $partyId) as $h) {
            $total += $h->unallocated;
        }

        return $total;
    }

    /** Non-voided receipt/payment headers with their unallocated remainder. */
    public function openHeaders(string $partyType, int $partyId)
    {
        $h = self::HEADERS[$partyType];
        $k = self::KINDS[$h['kind']];

        return DB::table($h['table'])->where($h['party_col'], $partyId)->whereNull('voided_at')
            ->orderBy('date')->orderBy('id')->get()
            ->map(function ($row) use ($k) {
                $allocated = (int) DB::table($k['payment_table'])->where('receipt_id', $row->id)->whereNull('voided_at')->sum('amount');
                $row->allocated = $allocated;
                $row->unallocated = (int) $row->amount - $allocated;

                return $row;
            })->filter(fn ($row) => $row->unallocated > 0)->values();
    }

    // ------------------------------------------------------------------ documents

    /**
     * Make the ledger agree with the document (idempotent): posts the document entry once, reposts through
     * reversal if amount/party/date changed, and reverses it if the document is cancelled.
     */
    public function syncDocument(string $kind, int $id, ?int $userId = null): void
    {
        DB::transaction(function () use ($kind, $id, $userId) {
            $cfg = self::KINDS[$kind];
            $doc = $this->lockDocument($kind, $id);
            $active = $this->activeDocumentEntry($kind, $id);

            if ($doc->cancelled_at) {
                if ($active) {
                    $this->reverseEntry($active, 'Document cancelled', $userId);
                }

                return;
            }

            $partyId = $doc->{$cfg['party_col']};
            if (!$partyId) {
                throw $this->fail('The document has no ' . $cfg['party'] . '.', 'ledger');
            }

            $amount = (int) $doc->total_amount;
            $date = $this->date($doc->date);

            if ($active) {
                $same = (int) $active->party_id === (int) $partyId
                    && $this->date($active->entry_date) === $date
                    && ((int) $active->debit + (int) $active->credit) === $amount;

                if ($same) {
                    return;
                }

                $this->reverseEntry($active, 'Document amended', $userId);
            }

            if ($amount === 0) {
                return;
            }

            $version = LedgerEntry::where('source_type', $kind)->where('source_id', $id)
                ->where('entry_type', $cfg['entry_type'])->count() + 1;

            $this->post(
                $cfg['party'], (int) $partyId, $date, $cfg['entry_type'], $kind, $id,
                $cfg['side'] === 'debit' ? $amount : 0, $cfg['side'] === 'credit' ? $amount : 0,
                "{$kind}:{$id}:doc:{$version}", $doc->reference, $cfg['label'] . ' ' . $doc->reference, $userId
            );
        });
    }

    public function postCustomerInvoice(int $saleId, ?int $userId = null): void
    {
        $this->syncDocument('sale', $saleId, $userId);
    }

    public function postSupplierInvoice(int $purchaseId, ?int $userId = null): void
    {
        $this->syncDocument('purchase', $purchaseId, $userId);
    }

    public function postCustomerReturn(int $saleReturnId, ?int $userId = null): void
    {
        $this->syncDocument('sale_return', $saleReturnId, $userId);
    }

    public function postSupplierReturn(int $purchaseReturnId, ?int $userId = null): void
    {
        $this->syncDocument('purchase_return', $purchaseReturnId, $userId);
    }

    /** Valid (non-voided) payment total for a document. */
    public function paidTotal(string $kind, int $id): int
    {
        $cfg = self::KINDS[$kind];

        return (int) DB::table($cfg['payment_table'])->where($cfg['fk'], $id)->whereNull('voided_at')->sum('amount');
    }

    /** Re-derive paid/due/status from valid payments. The only place these three columns are written. */
    public function recalculateInvoicePaymentStatus(string $kind, int $id): object
    {
        return DB::transaction(function () use ($kind, $id) {
            $cfg = self::KINDS[$kind];
            $doc = $this->lockDocument($kind, $id);
            $paid = $this->paidTotal($kind, $id);
            $total = (int) $doc->total_amount;

            if ($paid > $total) {
                throw $this->fail('Payments exceed the document total; refusing to create a negative balance.', 'amount');
            }

            $due = $total - $paid;
            $status = $due === $total ? 'Unpaid' : ($due > 0 ? 'Partial' : 'Paid');

            DB::table($cfg['table'])->where('id', $id)->update([
                'paid_amount' => $paid, 'due_amount' => $due, 'payment_status' => $status, 'updated_at' => now(),
            ]);

            return $this->lockDocument($kind, $id);
        });
    }

    /** Refuse to touch a document whose stored paid amount disagrees with its payment rows (legacy drift). */
    public function assertReconciled(string $kind, int $id): void
    {
        $doc = $this->lockDocument($kind, $id);
        $paid = $this->paidTotal($kind, $id);

        if ((int) $doc->paid_amount !== $paid || (int) $doc->due_amount !== (int) $doc->total_amount - $paid) {
            throw $this->fail(
                "{$doc->reference}: stored paid/due amounts do not match its payment records. Run `php artisan ledger:reconcile` and resolve the discrepancy first.",
                'ledger'
            );
        }
    }

    // ------------------------------------------------------------------ payments

    /**
     * Receive money from a customer. $allocations = [sale_id => minor]. Any remainder is kept as an advance.
     *
     * @return int receipt id
     */
    public function receiveCustomerPayment(int $customerId, int $amount, array $meta, array $allocations = [], ?int $userId = null): int
    {
        return $this->createHeader('customer', $customerId, $amount, $meta, $allocations, $userId);
    }

    /** @return int supplier payment id */
    public function makeSupplierPayment(int $supplierId, int $amount, array $meta, array $allocations = [], ?int $userId = null): int
    {
        return $this->createHeader('supplier', $supplierId, $amount, $meta, $allocations, $userId);
    }

    public function recordCustomerReceipt(int $customerId, int $amount, array $meta, array $allocations = [], ?int $userId = null): int
    {
        return $this->receiveCustomerPayment($customerId, $amount, $meta, $allocations, $userId);
    }

    public function recordSupplierPayment(int $supplierId, int $amount, array $meta, array $allocations = [], ?int $userId = null): int
    {
        return $this->makeSupplierPayment($supplierId, $amount, $meta, $allocations, $userId);
    }

    /** Pay a single sale/purchase invoice (creates a header fully allocated to it). */
    public function payInvoice(string $kind, int $id, int $amount, array $meta, ?int $userId = null): int
    {
        $cfg = self::KINDS[$kind];
        $party = $cfg['party'];

        return DB::transaction(function () use ($cfg, $kind, $id, $amount, $meta, $userId, $party) {
            $doc = $this->lockDocument($kind, $id);

            return $this->createHeader($party, (int) $doc->{$cfg['party_col']}, $amount, $meta, [$id => $amount], $userId);
        });
    }

    public function allocateCustomerReceipt(int $receiptId, array $allocations, ?int $userId = null): void
    {
        $this->allocate('customer', $receiptId, $allocations, $userId);
    }

    public function allocateSupplierPayment(int $paymentId, array $allocations, ?int $userId = null): void
    {
        $this->allocate('supplier', $paymentId, $allocations, $userId);
    }

    /** Allocate (part of) an existing header's unallocated amount to invoices. */
    public function allocate(string $partyType, int $headerId, array $allocations, ?int $userId = null): void
    {
        DB::transaction(function () use ($partyType, $headerId, $allocations, $userId) {
            $h = self::HEADERS[$partyType];
            $k = self::KINDS[$h['kind']];

            $header = DB::table($h['table'])->where('id', $headerId)->lockForUpdate()->first();
            if (!$header) {
                throw $this->fail('Payment not found.', 'allocations');
            }
            if ($header->voided_at) {
                throw $this->fail('This payment has been voided and cannot be allocated.', 'allocations');
            }

            $allocations = array_filter(array_map('intval', $allocations), fn ($v) => $v !== 0);
            ksort($allocations);

            foreach ($allocations as $amt) {
                if ($amt < 0) {
                    throw $this->fail('Allocation amounts must be positive.', 'allocations');
                }
            }

            $already = (int) DB::table($k['payment_table'])->where('receipt_id', $headerId)->whereNull('voided_at')->sum('amount');
            $available = (int) $header->amount - $already;

            if (array_sum($allocations) > $available) {
                throw $this->fail('Allocations exceed the unallocated amount of this payment (' . number_format($available / 100, 2) . ').', 'allocations');
            }

            foreach ($allocations as $docId => $amt) {
                $doc = $this->lockDocument($h['kind'], (int) $docId);

                if ((int) $doc->{$k['party_col']} !== (int) $header->{$h['party_col']}) {
                    throw $this->fail("{$doc->reference} belongs to a different {$partyType}.", 'allocations');
                }
                if ($doc->cancelled_at) {
                    throw $this->fail("{$doc->reference} is cancelled.", 'allocations');
                }

                $this->assertReconciled($h['kind'], (int) $docId);
                $due = (int) $doc->total_amount - $this->paidTotal($h['kind'], (int) $docId);

                if ($amt > $due) {
                    throw $this->fail("Amount for {$doc->reference} exceeds its outstanding balance (" . number_format($due / 100, 2) . ').', 'allocations');
                }

                DB::table($k['payment_table'])->insert([
                    $k['fk'] => $docId, 'receipt_id' => $headerId, 'amount' => $amt, 'date' => $this->date($header->date),
                    'reference' => $header->reference, 'payment_method' => $header->payment_method, 'note' => $header->note,
                    'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ]);

                $this->recalculateInvoicePaymentStatus($h['kind'], (int) $docId);
            }
        });
    }

    /** Payment/refund against a return document (direct row with its own ledger entry). */
    public function recordReturnPayment(string $kind, int $id, int $amount, array $meta, ?int $userId = null): int
    {
        if (!in_array($kind, ['sale_return', 'purchase_return'], true)) {
            throw new \InvalidArgumentException('Not a return kind.');
        }

        return DB::transaction(function () use ($kind, $id, $amount, $meta, $userId) {
            $cfg = self::KINDS[$kind];
            $doc = $this->lockDocument($kind, $id);

            if ($amount <= 0) {
                throw $this->fail('Payment amount must be greater than zero.');
            }
            if ($doc->cancelled_at) {
                throw $this->fail("{$doc->reference} is cancelled.");
            }
            $this->assertReconciled($kind, $id);

            $due = (int) $doc->total_amount - $this->paidTotal($kind, $id);
            if ($amount > $due) {
                throw $this->fail('Amount exceeds the outstanding balance (' . number_format($due / 100, 2) . ').');
            }

            $paymentId = DB::table($cfg['payment_table'])->insertGetId([
                $cfg['fk'] => $id, 'amount' => $amount, 'date' => $this->date($meta['date'] ?? now()),
                'reference' => $meta['reference'] ?? ('INV/' . $doc->reference), 'payment_method' => $meta['method'],
                'note' => $meta['note'] ?? null, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->postDirectPayment($kind, $paymentId, $userId);
            $this->recalculateInvoicePaymentStatus($kind, $id);

            return $paymentId;
        });
    }

    /** Post (once) the ledger entry of a direct (header-less) payment row. Idempotent. */
    public function postDirectPayment(string $kind, int $paymentId, ?int $userId = null): ?LedgerEntry
    {
        $cfg = self::KINDS[$kind];
        $row = DB::table($cfg['payment_table'])->where('id', $paymentId)->first();
        $doc = DB::table($cfg['table'])->where('id', $row->{$cfg['fk']})->first();

        if ($row->receipt_id ?? null) {
            return null;
        }

        $amount = (int) $row->amount;
        // payment is posted on the opposite side of the document
        $debit = $cfg['side'] === 'credit' ? $amount : 0;
        $credit = $cfg['side'] === 'debit' ? $amount : 0;

        return $this->post(
            $cfg['party'], (int) $doc->{$cfg['party_col']}, $this->date($row->date), 'payment', $kind . '_payment', $paymentId,
            $debit, $credit, "{$kind}_payment:{$paymentId}", $row->reference, 'Payment for ' . $doc->reference, $userId
        );
    }

    // ------------------------------------------------------------------ voids / cancellation

    /** Void a receipt/payment header: releases all its allocations and reverses its ledger entry. */
    public function voidHeader(string $partyType, int $headerId, string $reason, ?int $userId = null): void
    {
        DB::transaction(function () use ($partyType, $headerId, $reason, $userId) {
            $h = self::HEADERS[$partyType];
            $k = self::KINDS[$h['kind']];

            $header = DB::table($h['table'])->where('id', $headerId)->lockForUpdate()->first();
            if (!$header) {
                throw $this->fail('Payment not found.', 'ledger');
            }
            if ($header->voided_at) {
                throw $this->fail('This payment is already voided.', 'ledger');
            }

            $docIds = DB::table($k['payment_table'])->where('receipt_id', $headerId)->whereNull('voided_at')
                ->orderBy($k['fk'])->pluck($k['fk'])->unique()->all();

            foreach ($docIds as $docId) {
                $this->lockDocument($h['kind'], (int) $docId);
            }

            DB::table($k['payment_table'])->where('receipt_id', $headerId)->whereNull('voided_at')->update([
                'voided_at' => now(), 'voided_by' => $userId, 'void_reason' => $reason, 'updated_at' => now(),
            ]);

            DB::table($h['table'])->where('id', $headerId)->update([
                'voided_at' => now(), 'voided_by' => $userId, 'void_reason' => $reason, 'updated_at' => now(),
            ]);

            $entry = LedgerEntry::where('posting_key', "{$h['source']}:{$headerId}")->first();
            if ($entry && !$entry->reversal) {
                $this->reverseEntry($entry, 'Payment voided: ' . $reason, $userId);
            }

            foreach ($docIds as $docId) {
                $this->recalculateInvoicePaymentStatus($h['kind'], (int) $docId);
            }
        });
    }

    /** Void one payment row. Header-linked rows void their whole header (the cash movement is the header). */
    public function voidPayment(string $kind, int $paymentId, string $reason, ?int $userId = null): void
    {
        DB::transaction(function () use ($kind, $paymentId, $reason, $userId) {
            $cfg = self::KINDS[$kind];
            $row = DB::table($cfg['payment_table'])->where('id', $paymentId)->lockForUpdate()->first();

            if (!$row) {
                throw $this->fail('Payment not found.', 'ledger');
            }
            if ($row->voided_at) {
                throw $this->fail('This payment is already voided.', 'ledger');
            }

            if (!empty($row->receipt_id)) {
                $this->voidHeader($cfg['party'], (int) $row->receipt_id, $reason, $userId);

                return;
            }

            $this->lockDocument($kind, (int) $row->{$cfg['fk']});

            DB::table($cfg['payment_table'])->where('id', $paymentId)->update([
                'voided_at' => now(), 'voided_by' => $userId, 'void_reason' => $reason, 'updated_at' => now(),
            ]);

            $entry = LedgerEntry::where('posting_key', "{$kind}_payment:{$paymentId}")->first();
            if ($entry && !$entry->reversal) {
                $this->reverseEntry($entry, 'Payment voided: ' . $reason, $userId);
            }

            $this->recalculateInvoicePaymentStatus($kind, (int) $row->{$cfg['fk']});
        });
    }

    /**
     * Cancel a posted document: reverses stock (if it had moved), releases/voids payments, reverses the ledger.
     * The row, its lines and references are preserved.
     */
    public function cancelDocument(string $kind, int $id, string $reason, ?int $userId = null): void
    {
        DB::transaction(function () use ($kind, $id, $reason, $userId) {
            $cfg = self::KINDS[$kind];
            $doc = $this->lockDocument($kind, $id);

            if ($doc->cancelled_at || $doc->status === 'Cancelled') {
                throw $this->fail("{$doc->reference} is already cancelled.", 'ledger');
            }

            if (StockService::moves($kind, $doc->status)) {
                StockService::applyStored($kind, $id, -1);
            }

            $rows = DB::table($cfg['payment_table'])->where($cfg['fk'], $id)->whereNull('voided_at')->orderBy('id')->get();
            foreach ($rows as $row) {
                if (!empty($row->receipt_id)) {
                    // Money stays with the party: allocation released, header becomes an advance.
                    DB::table($cfg['payment_table'])->where('id', $row->id)->update([
                        'voided_at' => now(), 'voided_by' => $userId, 'void_reason' => 'Released: ' . $reason, 'updated_at' => now(),
                    ]);
                } else {
                    $this->voidPayment($kind, (int) $row->id, 'Document cancelled: ' . $reason, $userId);
                }
            }

            DB::table($cfg['table'])->where('id', $id)->update([
                'status' => 'Cancelled', 'cancelled_at' => now(), 'cancelled_by' => $userId, 'updated_at' => now(),
            ]);

            $this->recalculateInvoicePaymentStatus($kind, $id);
            $this->syncDocument($kind, $id, $userId);
        });
    }

    // ------------------------------------------------------------------ opening balances

    /**
     * Set a party's opening balance. Customer: positive = owes us (debit). Supplier: positive = we owe (credit).
     * Negative values post on the opposite side. Changing it reverses the earlier entry and posts a new one.
     */
    public function postOpeningBalance(string $partyType, int $partyId, int $amount, string $date, ?int $userId = null): void
    {
        DB::transaction(function () use ($partyType, $partyId, $amount, $date, $userId) {
            $table = $partyType === 'customer' ? 'customers' : 'suppliers';
            $party = DB::table($table)->where('id', $partyId)->lockForUpdate()->first();

            if (!$party) {
                throw $this->fail('Party not found.', 'opening_balance');
            }

            $active = LedgerEntry::forParty($partyType, $partyId)->where('entry_type', 'opening')
                ->whereDoesntHave('reversal')->first();

            if ($active) {
                $this->reverseEntry($active, 'Opening balance changed', $userId);
            }

            DB::table($table)->where('id', $partyId)->update([
                'opening_balance' => $amount, 'opening_balance_date' => $amount === 0 ? null : $this->date($date), 'updated_at' => now(),
            ]);

            if ($amount === 0) {
                return;
            }

            $natural = $partyType === 'customer' ? 'debit' : 'credit';
            $abs = abs($amount);
            $onNatural = $amount > 0;
            $debit = ($natural === 'debit') === $onNatural ? $abs : 0;
            $credit = $abs - $debit;

            $version = LedgerEntry::forParty($partyType, $partyId)->where('entry_type', 'opening')->count() + 1;

            $this->post($partyType, $partyId, $this->date($date), 'opening', 'opening_balance', $partyId, $debit, $credit,
                "opening:{$partyType}:{$partyId}:{$version}", 'OPENING', 'Opening balance', $userId);
        });
    }

    // ------------------------------------------------------------------ reversals

    /** Post a compensating entry. Idempotent: a second call for the same entry returns the existing reversal. */
    public function reverseEntry(LedgerEntry $entry, string $reason, ?int $userId = null): LedgerEntry
    {
        $existing = LedgerEntry::where('reverses_entry_id', $entry->id)->first();
        if ($existing) {
            return $existing;
        }

        return LedgerEntry::create([
            'party_type' => $entry->party_type, 'party_id' => $entry->party_id, 'entry_date' => now()->toDateString(),
            'entry_type' => 'reversal', 'source_type' => $entry->source_type, 'source_id' => $entry->source_id,
            'debit' => $entry->credit, 'credit' => $entry->debit, 'reference' => $entry->reference,
            'description' => mb_substr('Reversal: ' . $reason, 0, 250), 'posting_key' => 'reversal:' . $entry->id,
            'reverses_entry_id' => $entry->id, 'created_by' => $userId,
        ]);
    }

    // ------------------------------------------------------------------ internals

    protected function createHeader(string $partyType, int $partyId, int $amount, array $meta, array $allocations, ?int $userId): int
    {
        return DB::transaction(function () use ($partyType, $partyId, $amount, $meta, $allocations, $userId) {
            $h = self::HEADERS[$partyType];

            if ($amount <= 0) {
                throw $this->fail('Payment amount must be greater than zero.');
            }
            if (empty($meta['method'])) {
                throw $this->fail('Payment method is required.', 'payment_method');
            }

            $party = DB::table($h['party_table'])->where('id', $partyId)->lockForUpdate()->first();
            if (!$party) {
                throw $this->fail('Party not found.', 'amount');
            }

            $normalized = array_filter(array_map('intval', $allocations), fn ($v) => $v > 0);
            if (array_sum($normalized) > $amount) {
                throw $this->fail('Allocations exceed the payment amount.', 'allocations');
            }

            $date = $this->date($meta['date'] ?? now());
            $reference = $meta['reference'] ?? null;
            if (!$reference) {
                $reference = ReferenceGenerator::next($h['prefix'], $h['table']);
            }

            $id = DB::table($h['table'])->insertGetId([
                $h['party_col'] => $partyId, 'date' => $date, 'reference' => $reference, 'amount' => $amount,
                'payment_method' => $meta['method'], 'note' => $meta['note'] ?? null, 'created_by' => $userId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->post($partyType, $partyId, $date, 'payment', $h['source'], $id,
                $h['side'] === 'debit' ? $amount : 0, $h['side'] === 'credit' ? $amount : 0,
                "{$h['source']}:{$id}", $reference,
                ($partyType === 'customer' ? 'Receipt ' : 'Payment ') . $reference, $userId);

            if ($normalized) {
                $this->allocate($partyType, $id, $normalized, $userId);
            }

            return $id;
        });
    }

    protected function post(string $partyType, int $partyId, string $date, string $entryType, string $sourceType, int $sourceId,
        int $debit, int $credit, string $key, ?string $reference, ?string $description, ?int $userId): LedgerEntry
    {
        $existing = LedgerEntry::where('posting_key', $key)->first();
        if ($existing) {
            return $existing;
        }

        if ($debit < 0 || $credit < 0 || ($debit === 0 && $credit === 0)) {
            throw $this->fail('Invalid ledger amount.', 'ledger');
        }

        return LedgerEntry::create([
            'party_type' => $partyType, 'party_id' => $partyId, 'entry_date' => $date, 'entry_type' => $entryType,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'debit' => $debit, 'credit' => $credit,
            'reference' => $reference, 'description' => $description ? mb_substr($description, 0, 250) : null,
            'posting_key' => $key, 'created_by' => $userId,
        ]);
    }

    protected function activeDocumentEntry(string $kind, int $id): ?LedgerEntry
    {
        return LedgerEntry::where('source_type', $kind)->where('source_id', $id)
            ->where('entry_type', self::KINDS[$kind]['entry_type'])->whereDoesntHave('reversal')->orderByDesc('id')->first();
    }

    protected function lockDocument(string $kind, int $id): object
    {
        $doc = DB::table(self::KINDS[$kind]['table'])->where('id', $id)->lockForUpdate()->first();

        if (!$doc) {
            throw $this->fail(self::KINDS[$kind]['label'] . ' not found.', 'ledger');
        }

        return $doc;
    }

    protected function date($value): string
    {
        return Carbon::parse($value)->toDateString();
    }

    protected function fail(string $message, string $field = 'amount'): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }
}
