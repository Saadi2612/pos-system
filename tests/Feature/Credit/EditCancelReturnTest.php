<?php

namespace Tests\Feature\Credit;

use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditCancelReturnTest extends CreditTestCase
{
    private function updateSale(int $id, int $productId, int $qty, float $price, array $o = []): void
    {
        $this->cart('sale', $productId, $qty, $price);
        $sale = $this->row('sales', $id);
        $this->writer->update('sale', $id, 'sale', $o + [
            'date' => '2026-01-10', 'party_id' => $sale->customer_id, 'tax_percentage' => 0, 'discount_percentage' => 0,
            'shipping_amount' => 0, 'status' => $sale->status, 'due_date' => null, 'note' => null,
        ], null);
    }

    public function test_editing_an_invoice_keeps_payment_history_and_reposts_ledger(): void
    {
        $c = $this->customer();
        $p = $this->product(10, 100);
        $id = $this->sale($c, $p, 5, 10, 'partial', 20);      // total 50, paid 20

        $this->updateSale($id, $p, 8, 10);                    // total 80

        $sale = $this->row('sales', $id);
        $this->assertSame(8000, (int) $sale->total_amount);
        $this->assertSame(2000, (int) $sale->paid_amount);
        $this->assertSame(6000, (int) $sale->due_amount);
        $this->assertSame(1, DB::table('sale_payments')->where('sale_id', $id)->count());
        $this->assertSame(92, $this->stock($p));              // 100 - 8
        $this->assertSame(6000, $this->ledger->getCustomerBalance($c));
        // history preserved: original debit, its reversal, the new debit, + the receipt credit
        $this->assertSame(4, LedgerEntry::count());
        $this->assertSame(1, LedgerEntry::where('entry_type', 'reversal')->count());
    }

    public function test_editing_total_below_payments_is_rejected_and_changes_nothing(): void
    {
        $c = $this->customer();
        $p = $this->product(10, 100);
        $id = $this->sale($c, $p, 5, 10, 'partial', 40);

        try {
            $this->updateSale($id, $p, 1, 10);   // total 10 < paid 40
            $this->fail('expected rejection');
        } catch (ValidationException $e) {
            $this->assertSame(5000, (int) $this->row('sales', $id)->total_amount);
            $this->assertSame(95, $this->stock($p));
        }
    }

    public function test_cannot_change_customer_while_payments_exist(): void
    {
        $c = $this->customer();
        $other = $this->customer();
        $p = $this->product();
        $id = $this->sale($c, $p, 1, 10, 'partial', 5);

        $this->expectException(ValidationException::class);
        $this->updateSale($id, $p, 1, 10, ['party_id' => $other]);
    }

    public function test_editing_status_adjusts_stock_exactly_once(): void
    {
        $c = $this->customer();
        $p = $this->product(10, 10);
        $id = $this->sale($c, $p, 4, 10, 'credit', 0, ['status' => 'Pending']);
        $this->assertSame(10, $this->stock($p));

        $this->updateSale($id, $p, 4, 10, ['status' => 'Completed']);
        $this->assertSame(6, $this->stock($p));

        $this->updateSale($id, $p, 4, 10, ['status' => 'Completed']);   // no-op edit
        $this->assertSame(6, $this->stock($p));

        $this->updateSale($id, $p, 4, 10, ['status' => 'Pending']);
        $this->assertSame(10, $this->stock($p));
    }

    public function test_cancelling_a_sale_reverses_stock_ledger_and_releases_payment_as_advance(): void
    {
        $c = $this->customer();
        $p = $this->product(10, 20);
        $id = $this->sale($c, $p, 5, 10, 'partial', 30);
        $this->assertSame(15, $this->stock($p));

        $this->ledger->cancelDocument('sale', $id, 'customer changed mind');

        $sale = $this->row('sales', $id);
        $this->assertSame('Cancelled', $sale->status);
        $this->assertNotNull($sale->cancelled_at);
        $this->assertSame(20, $this->stock($p));
        // the 30.00 received stays with the customer as an advance (credit balance)
        $this->assertSame(-3000, $this->ledger->getCustomerBalance($c));
        $this->assertSame(3000, $this->ledger->unallocatedAdvance('customer', $c));
        // record and its lines are preserved
        $this->assertSame(1, DB::table('sale_details')->where('sale_id', $id)->count());

        // cancelling twice must not double-reverse stock
        try {
            $this->ledger->cancelDocument('sale', $id, 'again');
            $this->fail('expected rejection');
        } catch (ValidationException $e) {
            $this->assertSame(20, $this->stock($p));
        }
    }

    public function test_cancelled_invoice_cannot_be_paid_or_edited(): void
    {
        $c = $this->customer();
        $p = $this->product();
        $id = $this->sale($c, $p, 1, 10, 'credit');
        $this->ledger->cancelDocument('sale', $id, 'x');

        try {
            $this->ledger->payInvoice('sale', $id, 500, ['date' => '2026-01-01', 'method' => 'Cash']);
            $this->fail('payment on cancelled invoice allowed');
        } catch (ValidationException $e) {
        }

        $this->expectException(ValidationException::class);
        $this->updateSale($id, $p, 1, 10);
    }

    public function test_cancelling_a_pending_sale_does_not_touch_stock(): void
    {
        $c = $this->customer();
        $p = $this->product(10, 10);
        $id = $this->sale($c, $p, 4, 10, 'credit', 0, ['status' => 'Pending']);

        $this->ledger->cancelDocument('sale', $id, 'x');

        $this->assertSame(10, $this->stock($p));
        $this->assertSame(0, $this->ledger->getCustomerBalance($c));
    }

    public function test_cancelling_a_purchase_removes_received_stock_but_is_blocked_if_already_sold(): void
    {
        $s = $this->supplier();
        $c = $this->customer();
        $p = $this->product(10, 0);

        $po = $this->purchase($s, $p, 10, 4, 'credit');
        $this->assertSame(10, $this->stock($p));
        $this->sale($c, $p, 8, 10, 'full');                    // stock now 2

        try {
            $this->ledger->cancelDocument('purchase', $po, 'x');   // would need -10 from 2
            $this->fail('should be blocked');
        } catch (ValidationException $e) {
            $this->assertSame(2, $this->stock($p));
            $this->assertNull($this->row('purchases', $po)->cancelled_at);
            $this->assertSame(4000, $this->ledger->getSupplierBalance($s));
        }

        $po2 = $this->purchase($s, $this->product(10, 0), 3, 4, 'credit');
        $this->ledger->cancelDocument('purchase', $po2, 'x');
        $this->assertSame(4000, $this->ledger->getSupplierBalance($s));   // only the first PO remains
    }

    public function test_sale_return_credits_customer_and_refund_is_a_separate_entry(): void
    {
        $c = $this->customer();
        $p = $this->product(10, 10);
        $sale = $this->sale($c, $p, 4, 10, 'credit');          // owes 40
        $this->cart('sale_return', $p, 1, 10);

        $rid = $this->writer->create('sale_return', 'sale_return', [
            'date' => '2026-01-11', 'party_id' => $c, 'sale_id' => $sale, 'tax_percentage' => 0, 'discount_percentage' => 0,
            'shipping_amount' => 0, 'status' => 'Completed', 'payment_method' => 'Cash', 'payment_type' => 'partial',
            'paid_amount' => 0, 'note' => null,
        ], null);

        $this->assertSame(7, $this->stock($p));                // 10 - 4 + 1
        $this->assertSame(3000, $this->ledger->getCustomerBalance($c));    // party-level credit, invoice untouched
        $this->assertSame(4000, (int) $this->row('sales', $sale)->due_amount);
        $this->assertSame($sale, (int) $this->row('sale_returns', $rid)->sale_id);

        // refund the 10.00 in cash => debit, balance back to 40
        $this->ledger->recordReturnPayment('sale_return', $rid, 1000, ['date' => '2026-01-12', 'method' => 'Cash']);
        $this->assertSame(4000, $this->ledger->getCustomerBalance($c));
        $this->assertSame('Paid', $this->row('sale_returns', $rid)->payment_status);

        // posting again is idempotent
        $this->ledger->postCustomerReturn($rid);
        $this->assertSame(4000, $this->ledger->getCustomerBalance($c));
    }

    public function test_purchase_return_debits_supplier_and_cancel_reverses(): void
    {
        $s = $this->supplier();
        $p = $this->product(10, 0);
        $this->purchase($s, $p, 10, 4, 'credit');               // owe 40, stock 10
        $this->cart('purchase_return', $p, 2, 4);

        $rid = $this->writer->create('purchase_return', 'purchase_return', [
            'date' => '2026-01-12', 'party_id' => $s, 'tax_percentage' => 0, 'discount_percentage' => 0, 'shipping_amount' => 0,
            'status' => 'Completed', 'payment_method' => 'Cash', 'payment_type' => 'partial', 'paid_amount' => 0, 'note' => null,
        ], null);

        $this->assertSame(8, $this->stock($p));
        $this->assertSame(3200, $this->ledger->getSupplierBalance($s));

        $this->ledger->cancelDocument('purchase_return', $rid, 'x');
        $this->assertSame(10, $this->stock($p));
        $this->assertSame(4000, $this->ledger->getSupplierBalance($s));
    }
}
