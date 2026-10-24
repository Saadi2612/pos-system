<?php

namespace App\Services\Ledger;

use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Purchase\Entities\Purchase;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;

/**
 * Creates / updates sales, purchases and their returns on top of the cart, with server-computed totals,
 * row-locked stock movements and ledger posting inside one transaction.
 * Replaces the copy/pasted store()/update() bodies in the controllers while keeping their stock rules.
 */
class DocumentWriter
{
    private const MODELS = [
        'sale' => Sale::class,
        'purchase' => Purchase::class,
        'sale_return' => SaleReturn::class,
        'purchase_return' => PurchaseReturn::class,
    ];

    private const PARTY_TABLE = ['customer' => ['customers', 'customer_name'], 'supplier' => ['suppliers', 'supplier_name']];

    public function __construct(private LedgerService $ledger)
    {
    }

    /**
     * @param array $d keys: date, party_id, tax_percentage, discount_percentage, shipping_amount, status,
     *                 payment_method, note, due_date?, supplier_reference?, sale_id?/purchase_id?,
     *                 paid_amount (decimal, initial payment), payment_type? (full|partial|credit)
     * @return int document id
     */
    public function create(string $kind, string $cartInstance, array $d, ?int $userId): int
    {
        return DB::transaction(function () use ($kind, $cartInstance, $d, $userId) {
            $cfg = LedgerService::KINDS[$kind];
            [$total, $lines] = $this->readCart($cartInstance, $d['shipping_amount'] ?? 0);

            $paid = $this->resolvePaid($d, $total);
            $partyName = $this->partyName($cfg['party'], (int) $d['party_id']);
            $cart = Cart::instance($cartInstance);

            $attributes = [
                'date' => $d['date'],
                $cfg['party_col'] => $d['party_id'],
                $cfg['party'] . '_name' => $partyName,
                'tax_percentage' => (int) ($d['tax_percentage'] ?? 0),
                'discount_percentage' => (int) ($d['discount_percentage'] ?? 0),
                'shipping_amount' => money_to_minor($d['shipping_amount'] ?? 0),
                'paid_amount' => 0,
                'total_amount' => $total,
                'due_amount' => $total,
                'status' => $d['status'],
                'payment_status' => 'Unpaid',
                'payment_method' => $d['payment_method'],
                'note' => $d['note'] ?? null,
                'tax_amount' => money_to_minor($cart->tax()),
                'discount_amount' => money_to_minor($cart->discount()),
                'created_by' => $userId,
            ];

            if (in_array($kind, ['sale', 'purchase'])) {
                $attributes['due_date'] = $d['due_date'] ?? null;
            }
            if ($kind === 'purchase') {
                $attributes['supplier_reference'] = $d['supplier_reference'] ?? null;
            }
            if ($kind === 'sale_return') {
                $attributes['sale_id'] = $d['sale_id'] ?? null;
            }
            if ($kind === 'purchase_return') {
                $attributes['purchase_id'] = $d['purchase_id'] ?? null;
            }

            $model = self::MODELS[$kind]::create($attributes);

            $this->writeLines($kind, $model->id, $lines);

            if (StockService::moves($kind, $d['status'])) {
                StockService::applyLines($kind, $this->quantities($lines), 1);
            }

            $this->ledger->syncDocument($kind, $model->id, $userId);

            if ($paid > 0) {
                $meta = ['date' => $d['date'], 'method' => $d['payment_method'], 'note' => $d['payment_note'] ?? null];

                if (in_array($kind, ['sale', 'purchase'])) {
                    $this->ledger->payInvoice($kind, $model->id, $paid, $meta + ['reference' => 'INV/' . $model->reference], $userId);
                } else {
                    $this->ledger->recordReturnPayment($kind, $model->id, $paid, $meta + ['reference' => 'INV/' . $model->reference], $userId);
                }
            }

            return $model->id;
        });
    }

    public function update(string $kind, int $id, string $cartInstance, array $d, ?int $userId): void
    {
        DB::transaction(function () use ($kind, $id, $cartInstance, $d, $userId) {
            $cfg = LedgerService::KINDS[$kind];
            $doc = DB::table($cfg['table'])->where('id', $id)->lockForUpdate()->first();

            if (!$doc) {
                throw ValidationException::withMessages(['ledger' => 'Document not found.']);
            }
            if ($doc->cancelled_at) {
                throw ValidationException::withMessages(['ledger' => "{$doc->reference} is cancelled and cannot be edited."]);
            }

            $this->ledger->assertReconciled($kind, $id);

            [$total, $lines] = $this->readCart($cartInstance, $d['shipping_amount'] ?? 0);
            $paid = $this->ledger->paidTotal($kind, $id);

            if ($total < $paid) {
                throw ValidationException::withMessages([
                    'total_amount' => 'The new total (' . number_format($total / 100, 2) . ') is lower than the payments already recorded ('
                        . number_format($paid / 100, 2) . '). Void or reduce the payments first.',
                ]);
            }

            if ($paid > 0 && (int) $doc->{$cfg['party_col']} !== (int) $d['party_id']) {
                throw ValidationException::withMessages([
                    $cfg['party_col'] => 'The ' . $cfg['party'] . ' cannot be changed while payments exist. Void the payments first.',
                ]);
            }

            $partyName = $this->partyName($cfg['party'], (int) $d['party_id']);

            if (StockService::moves($kind, $doc->status)) {
                StockService::applyStored($kind, $id, -1);
            }

            [$detailTable, $fk] = StockService::DETAILS[$kind];
            DB::table($detailTable)->where($fk, $id)->delete();
            $this->writeLines($kind, $id, $lines);

            if (StockService::moves($kind, $d['status'])) {
                StockService::applyLines($kind, $this->quantities($lines), 1);
            }

            $cart = Cart::instance($cartInstance);
            $update = [
                'date' => $d['date'],
                $cfg['party_col'] => $d['party_id'],
                $cfg['party'] . '_name' => $partyName,
                'tax_percentage' => (int) ($d['tax_percentage'] ?? 0),
                'discount_percentage' => (int) ($d['discount_percentage'] ?? 0),
                'shipping_amount' => money_to_minor($d['shipping_amount'] ?? 0),
                'total_amount' => $total,
                'status' => $d['status'],
                'note' => $d['note'] ?? null,
                'tax_amount' => money_to_minor($cart->tax()),
                'discount_amount' => money_to_minor($cart->discount()),
                'updated_at' => now(),
            ];

            if (in_array($kind, ['sale', 'purchase'])) {
                $update['due_date'] = $d['due_date'] ?? null;
            }
            if ($kind === 'purchase') {
                $update['supplier_reference'] = $d['supplier_reference'] ?? null;
            }

            DB::table($cfg['table'])->where('id', $id)->update($update);

            $this->ledger->recalculateInvoicePaymentStatus($kind, $id);
            $this->ledger->syncDocument($kind, $id, $userId);
        });
    }

    // ---------------------------------------------------------------

    /** @return array{0:int,1:array} [total in minor units, normalized lines] */
    private function readCart(string $instance, $shipping): array
    {
        $cart = Cart::instance($instance);

        if ($cart->count() === 0) {
            throw ValidationException::withMessages(['cart' => 'Please add at least one product.']);
        }

        if (money_to_minor($shipping) < 0) {
            throw ValidationException::withMessages(['shipping_amount' => 'Shipping cannot be negative.']);
        }

        $lines = [];
        foreach ($cart->content() as $item) {
            $qty = (int) $item->qty;

            if ($qty < 1) {
                throw ValidationException::withMessages(['cart' => "Invalid quantity for {$item->name}."]);
            }
            if (!DB::table('products')->where('id', $item->id)->exists()) {
                throw ValidationException::withMessages(['cart' => "Product {$item->name} no longer exists."]);
            }
            if (money_to_minor($item->price) < 0) {
                throw ValidationException::withMessages(['cart' => "Invalid price for {$item->name}."]);
            }

            $lines[] = [
                'product_id' => $item->id,
                'product_name' => $item->name,
                'product_code' => $item->options->code,
                'quantity' => $qty,
                'price' => money_to_minor($item->price),
                'unit_price' => money_to_minor($item->options->unit_price),
                'sub_total' => money_to_minor($item->options->sub_total),
                'product_discount_amount' => money_to_minor($item->options->product_discount),
                'product_discount_type' => $item->options->product_discount_type,
                'product_tax_amount' => money_to_minor($item->options->product_tax),
            ];
        }

        // Authoritative total: what the cart (trusted products, taxes, discounts) says, plus shipping. Client total ignored.
        $total = money_to_minor($cart->total()) + money_to_minor($shipping);

        if ($total < 0) {
            throw ValidationException::withMessages(['total_amount' => 'Invalid total.']);
        }

        return [$total, $lines];
    }

    private function resolvePaid(array $d, int $total): int
    {
        $type = $d['payment_type'] ?? 'partial';

        $paid = match ($type) {
            'full' => $total,
            'credit' => 0,
            default => money_to_minor($d['paid_amount'] ?? 0),
        };

        if ($paid < 0) {
            throw ValidationException::withMessages(['paid_amount' => 'Paid amount cannot be negative.']);
        }
        if ($paid > $total) {
            throw ValidationException::withMessages(['paid_amount' => 'Paid amount cannot exceed the total (' . number_format($total / 100, 2) . ').']);
        }

        return $paid;
    }

    private function partyName(string $party, int $id): string
    {
        [$table, $col] = self::PARTY_TABLE[$party];
        $name = DB::table($table)->where('id', $id)->value($col);

        if ($name === null) {
            throw ValidationException::withMessages([$party . '_id' => 'Selected ' . $party . ' does not exist.']);
        }

        return $name;
    }

    private function writeLines(string $kind, int $documentId, array $lines): void
    {
        [$table, $fk] = StockService::DETAILS[$kind];

        foreach ($lines as $line) {
            DB::table($table)->insert($line + [$fk => $documentId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function quantities(array $lines): array
    {
        $q = [];
        foreach ($lines as $line) {
            $q[$line['product_id']] = ($q[$line['product_id']] ?? 0) + $line['quantity'];
        }

        return $q;
    }
}
