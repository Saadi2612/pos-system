<?php

namespace Tests\Feature\Credit;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiptsAndVoidsTest extends CreditTestCase
{
    public function test_one_receipt_allocated_across_multiple_sales(): void
    {
        $c = $this->customer();
        $p = $this->product();
        $a = $this->sale($c, $p, 1, 30, 'credit');
        $b = $this->sale($c, $p, 1, 50, 'credit');
        $this->assertSame(8000, $this->ledger->getCustomerBalance($c));

        $r = $this->ledger->receiveCustomerPayment($c, 7000, ['date' => '2026-01-15', 'method' => 'Cash'], [$a => 3000, $b => 4000]);

        $this->assertSame('Paid', $this->row('sales', $a)->payment_status);
        $this->assertSame('Partial', $this->row('sales', $b)->payment_status);
        $this->assertSame(1000, (int) $this->row('sales', $b)->due_amount);
        $this->assertSame(1000, $this->ledger->getCustomerBalance($c));
        $this->assertSame(0, $this->ledger->unallocatedAdvance('customer', $c));
        $this->assertSame(2, DB::table('sale_payments')->where('receipt_id', $r)->count());
    }

    public function test_customer_advance_then_allocation(): void
    {
        $c = $this->customer();
        $r = $this->ledger->receiveCustomerPayment($c, 5000, ['date' => '2026-01-01', 'method' => 'Cash']);

        $this->assertSame(-5000, $this->ledger->getCustomerBalance($c));
        $this->assertSame(5000, $this->ledger->unallocatedAdvance('customer', $c));

        $sale = $this->sale($c, $this->product(), 1, 80, 'credit');
        $this->assertSame(3000, $this->ledger->getCustomerBalance($c));

        $this->ledger->allocateCustomerReceipt($r, [$sale => 5000]);
        $this->assertSame('Partial', $this->row('sales', $sale)->payment_status);
        $this->assertSame(3000, (int) $this->row('sales', $sale)->due_amount);
        $this->assertSame(0, $this->ledger->unallocatedAdvance('customer', $c));
        $this->assertSame(3000, $this->ledger->getCustomerBalance($c));
    }

    public function test_invalid_allocations_are_rejected_atomically(): void
    {
        $c1 = $this->customer();
        $c2 = $this->customer();
        $p = $this->product();
        $a = $this->sale($c1, $p, 1, 10, 'credit');
        $other = $this->sale($c2, $p, 1, 10, 'credit');
        $ok = $this->sale($c1, $p, 1, 10, 'credit');

        // second allocation is invalid => the first valid one must not persist either
        try {
            $this->ledger->receiveCustomerPayment($c1, 5000, ['date' => '2026-01-01', 'method' => 'Cash'], [$ok => 500, $other => 500]);
            $this->fail('expected rejection');
        } catch (ValidationException $e) {
            $this->assertSame(0, DB::table('customer_receipts')->count());
            $this->assertSame(0, DB::table('sale_payments')->count());
            $this->assertSame(2000, $this->ledger->getCustomerBalance($c1));
        }

        $this->expectException(ValidationException::class);
        $this->ledger->receiveCustomerPayment($c1, 500, ['date' => '2026-01-01', 'method' => 'Cash'], [$a => 600]);
    }

    public function test_voiding_a_payment_restores_invoice_balance_and_reverses_ledger(): void
    {
        $c = $this->customer();
        $id = $this->sale($c, $this->product(), 1, 100, 'partial', 40);
        $paymentRow = DB::table('sale_payments')->where('sale_id', $id)->first();

        $this->ledger->voidPayment('sale', $paymentRow->id, 'wrong entry');

        $sale = $this->row('sales', $id);
        $this->assertSame(0, (int) $sale->paid_amount);
        $this->assertSame(10000, (int) $sale->due_amount);
        $this->assertSame('Unpaid', $sale->payment_status);
        $this->assertSame(10000, $this->ledger->getCustomerBalance($c));

        // history preserved
        $this->assertSame(1, DB::table('sale_payments')->where('sale_id', $id)->count());
        $this->assertNotNull(DB::table('sale_payments')->where('id', $paymentRow->id)->value('voided_at'));
        $this->assertSame(1, DB::table('ledger_entries')->where('entry_type', 'reversal')->count());

        // double void rejected
        $this->expectException(ValidationException::class);
        $this->ledger->voidPayment('sale', $paymentRow->id, 'again');
    }

    public function test_voiding_a_receipt_releases_all_its_allocations(): void
    {
        $c = $this->customer();
        $p = $this->product();
        $a = $this->sale($c, $p, 1, 10, 'credit');
        $b = $this->sale($c, $p, 1, 10, 'credit');
        $r = $this->ledger->receiveCustomerPayment($c, 2000, ['date' => '2026-01-01', 'method' => 'Cash'], [$a => 1000, $b => 1000]);

        $this->ledger->voidHeader('customer', $r, 'bounced cheque');

        $this->assertSame('Unpaid', $this->row('sales', $a)->payment_status);
        $this->assertSame('Unpaid', $this->row('sales', $b)->payment_status);
        $this->assertSame(2000, $this->ledger->getCustomerBalance($c));
        $this->assertSame(0, $this->ledger->unallocatedAdvance('customer', $c));
    }

    public function test_voided_receipt_cannot_be_allocated(): void
    {
        $c = $this->customer();
        $sale = $this->sale($c, $this->product(), 1, 10, 'credit');
        $r = $this->ledger->receiveCustomerPayment($c, 1000, ['date' => '2026-01-01', 'method' => 'Cash']);
        $this->ledger->voidHeader('customer', $r, 'x');

        $this->expectException(ValidationException::class);
        $this->ledger->allocateCustomerReceipt($r, [$sale => 1000]);
    }

    public function test_legacy_direct_payment_can_be_voided(): void
    {
        // a payment row created by the pre-ledger application: no receipt header
        $c = $this->customer();
        $id = DB::table('sales')->insertGetId([
            'date' => '2025-12-01', 'reference' => 'SL-LEGACY', 'customer_id' => $c, 'customer_name' => 'X', 'total_amount' => 5000,
            'paid_amount' => 2000, 'due_amount' => 3000, 'status' => 'Completed', 'payment_status' => 'Partial', 'payment_method' => 'Cash',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $pid = DB::table('sale_payments')->insertGetId([
            'sale_id' => $id, 'amount' => 2000, 'date' => '2025-12-01', 'reference' => 'INV/SL-LEGACY', 'payment_method' => 'Cash',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ledger->syncDocument('sale', $id);
        $this->ledger->postDirectPayment('sale', $pid);
        $this->assertSame(3000, $this->ledger->getCustomerBalance($c));

        $this->ledger->voidPayment('sale', $pid, 'mistake');

        $this->assertSame(5000, $this->ledger->getCustomerBalance($c));
        $this->assertSame('Unpaid', $this->row('sales', $id)->payment_status);
    }

    public function test_unreconciled_legacy_invoice_refuses_new_payments(): void
    {
        $c = $this->customer();
        $id = DB::table('sales')->insertGetId([
            'date' => '2025-12-01', 'reference' => 'SL-DRIFT', 'customer_id' => $c, 'customer_name' => 'X', 'total_amount' => 5000,
            'paid_amount' => 2000, 'due_amount' => 3000, 'status' => 'Completed', 'payment_status' => 'Partial', 'payment_method' => 'Cash',
            'created_at' => now(), 'updated_at' => now(),
        ]);   // paid_amount says 2000 but there are no payment rows

        $this->expectException(ValidationException::class);
        $this->ledger->payInvoice('sale', $id, 1000, ['date' => '2026-01-01', 'method' => 'Cash']);
    }
}
