<?php

namespace Tests\Feature\Credit;

use Illuminate\Validation\ValidationException;

class PurchasesCreditTest extends CreditTestCase
{
    public function test_fully_paid_purchase(): void
    {
        $s = $this->supplier();
        $p = $this->product(10, 5);
        $id = $this->purchase($s, $p, 10, 4, 'full');

        $row = $this->row('purchases', $id);
        $this->assertSame(4000, (int) $row->total_amount);
        $this->assertSame('Paid', $row->payment_status);
        $this->assertSame(15, $this->stock($p));
        $this->assertSame(0, $this->ledger->getSupplierBalance($s));
        $this->assertSame('PR-00001', $row->reference);
    }

    public function test_credit_purchase_credits_supplier_and_receives_stock(): void
    {
        $s = $this->supplier();
        $p = $this->product(10, 0);
        $id = $this->purchase($s, $p, 10, 4, 'credit', 0, ['due_date' => '2026-03-01', 'supplier_reference' => 'INV-77']);

        $row = $this->row('purchases', $id);
        $this->assertSame('Unpaid', $row->payment_status);
        $this->assertSame(4000, (int) $row->due_amount);
        $this->assertSame('INV-77', $row->supplier_reference);
        $this->assertSame(4000, $this->ledger->getSupplierBalance($s));
        $this->assertSame(10, $this->stock($p));
        $this->assertSame(0, \DB::table('purchase_payments')->count());
    }

    public function test_pending_purchase_does_not_receive_stock_but_posts_payable(): void
    {
        $s = $this->supplier();
        $p = $this->product(10, 3);
        $this->purchase($s, $p, 5, 4, 'credit', 0, ['status' => 'Pending']);

        $this->assertSame(3, $this->stock($p));
        $this->assertSame(2000, $this->ledger->getSupplierBalance($s));
    }

    public function test_partial_supplier_payment_and_final_settlement(): void
    {
        $s = $this->supplier();
        $id = $this->purchase($s, $this->product(), 10, 5, 'partial', 20);

        $this->assertSame('Partial', $this->row('purchases', $id)->payment_status);
        $this->assertSame(3000, $this->ledger->getSupplierBalance($s));

        $this->ledger->payInvoice('purchase', $id, 3000, ['date' => '2026-01-20', 'method' => 'Bank Transfer', 'reference' => 'X']);
        $this->assertSame('Paid', $this->row('purchases', $id)->payment_status);
        $this->assertSame(0, $this->ledger->getSupplierBalance($s));
    }

    public function test_supplier_advance_then_allocation(): void
    {
        $s = $this->supplier();
        $pay = $this->ledger->makeSupplierPayment($s, 10000, ['date' => '2026-01-05', 'method' => 'Cash']);

        // advance: supplier holds 100.00 of ours => negative payable balance
        $this->assertSame(-10000, $this->ledger->getSupplierBalance($s));
        $this->assertSame(10000, $this->ledger->unallocatedAdvance('supplier', $s));

        $id = $this->purchase($s, $this->product(), 10, 6, 'credit');   // 60.00
        $this->assertSame(-4000, $this->ledger->getSupplierBalance($s));

        $this->ledger->allocateSupplierPayment($pay, [$id => 6000]);

        $this->assertSame('Paid', $this->row('purchases', $id)->payment_status);
        $this->assertSame(4000, $this->ledger->unallocatedAdvance('supplier', $s));
        // allocation never changes the ledger balance
        $this->assertSame(-4000, $this->ledger->getSupplierBalance($s));
    }

    public function test_cannot_allocate_to_other_suppliers_invoice_or_more_than_due_or_more_than_payment(): void
    {
        $s1 = $this->supplier();
        $s2 = $this->supplier();
        $inv1 = $this->purchase($s1, $this->product(), 1, 50, 'credit');
        $inv2 = $this->purchase($s2, $this->product(), 1, 50, 'credit');
        $pay = $this->ledger->makeSupplierPayment($s1, 3000, ['date' => '2026-01-05', 'method' => 'Cash']);

        foreach ([[$inv2 => 1000], [$inv1 => 3001], [$inv1 => 5100]] as $alloc) {
            try {
                $this->ledger->allocateSupplierPayment($pay, $alloc);
                $this->fail('allocation should have been rejected');
            } catch (ValidationException $e) {
                $this->assertSame(0, \DB::table('purchase_payments')->count());
            }
        }
    }

    public function test_purchase_overpayment_rejected(): void
    {
        $s = $this->supplier();
        $id = $this->purchase($s, $this->product(), 1, 10, 'credit');
        $this->expectException(ValidationException::class);
        $this->ledger->payInvoice('purchase', $id, 1001, ['date' => '2026-01-12', 'method' => 'Cash']);
    }
}
