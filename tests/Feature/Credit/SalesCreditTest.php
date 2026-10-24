<?php

namespace Tests\Feature\Credit;

use Illuminate\Validation\ValidationException;

class SalesCreditTest extends CreditTestCase
{
    public function test_cash_sale_is_fully_paid_and_receivable_returns_to_zero(): void
    {
        $c = $this->customer();
        $p = $this->product(10, 50);

        $id = $this->sale($c, $p, 3, 10, 'full');

        $sale = $this->row('sales', $id);
        $this->assertSame(3000, (int) $sale->total_amount);
        $this->assertSame(3000, (int) $sale->paid_amount);
        $this->assertSame(0, (int) $sale->due_amount);
        $this->assertSame('Paid', $sale->payment_status);
        $this->assertSame(47, $this->stock($p));
        $this->assertSame(0, $this->ledger->getCustomerBalance($c));
        $this->assertSame(1, \DB::table('sale_payments')->where('sale_id', $id)->count());
    }

    public function test_credit_sale_has_zero_payment_and_debits_customer(): void
    {
        $c = $this->customer();
        $p = $this->product(10, 50);

        $id = $this->sale($c, $p, 2, 10, 'credit', 0, ['due_date' => '2026-02-01']);

        $sale = $this->row('sales', $id);
        $this->assertSame('Unpaid', $sale->payment_status);
        $this->assertSame(2000, (int) $sale->due_amount);
        $this->assertSame('2026-02-01', substr($sale->due_date, 0, 10));
        $this->assertSame(0, \DB::table('sale_payments')->where('sale_id', $id)->count());
        $this->assertSame(2000, $this->ledger->getCustomerBalance($c));
        $this->assertSame(48, $this->stock($p));
    }

    public function test_partial_sale_and_multiple_payments_settle_invoice(): void
    {
        $c = $this->customer();
        $p = $this->product();
        $id = $this->sale($c, $p, 10, 10, 'partial', 30);

        $sale = $this->row('sales', $id);
        $this->assertSame('Partial', $sale->payment_status);
        $this->assertSame(7000, (int) $sale->due_amount);
        $this->assertSame(7000, $this->ledger->getCustomerBalance($c));

        $this->ledger->payInvoice('sale', $id, 5000, ['date' => '2026-01-12', 'method' => 'Cash', 'reference' => 'R1']);
        $this->assertSame('Partial', $this->row('sales', $id)->payment_status);

        $this->ledger->payInvoice('sale', $id, 2000, ['date' => '2026-01-13', 'method' => 'Bank Transfer', 'reference' => 'R2']);
        $sale = $this->row('sales', $id);
        $this->assertSame('Paid', $sale->payment_status);
        $this->assertSame(0, (int) $sale->due_amount);
        $this->assertSame(0, $this->ledger->getCustomerBalance($c));
    }

    public function test_overpayment_of_an_invoice_is_rejected(): void
    {
        $c = $this->customer();
        $id = $this->sale($c, $this->product(), 1, 10, 'credit');

        $this->expectException(ValidationException::class);
        $this->ledger->payInvoice('sale', $id, 1001, ['date' => '2026-01-12', 'method' => 'Cash']);
    }

    public function test_second_payment_cannot_overpay_after_the_first_consumed_the_balance(): void
    {
        // Sequential stand-in for a race: the balance is re-read under a row lock for every payment.
        // NOTE: true parallel execution is not exercised (in-memory SQLite has no row locks / parallel connections).
        $c = $this->customer();
        $id = $this->sale($c, $this->product(), 1, 10, 'credit');

        $this->ledger->payInvoice('sale', $id, 1000, ['date' => '2026-01-12', 'method' => 'Cash']);

        try {
            $this->ledger->payInvoice('sale', $id, 1, ['date' => '2026-01-12', 'method' => 'Cash']);
            $this->fail('Second payment should have been rejected');
        } catch (ValidationException $e) {
            $this->assertSame(1000, (int) $this->row('sales', $id)->paid_amount);
            $this->assertSame(0, $this->ledger->getCustomerBalance($c));
        }
    }

    public function test_decimal_amounts_are_exact(): void
    {
        $this->assertSame(1999, money_to_minor(19.99));
        $this->assertSame(1999, money_to_minor('19.99'));
        $this->assertSame(30, money_to_minor(0.10 + 0.20));

        $c = $this->customer();
        $id = $this->sale($c, $this->product(), 1, 19.99, 'partial', 0.10 + 0.20);
        $sale = $this->row('sales', $id);
        $this->assertSame(1999, (int) $sale->total_amount);
        $this->assertSame(30, (int) $sale->paid_amount);
        $this->assertSame(1969, (int) $sale->due_amount);
    }

    public function test_paid_amount_above_total_is_rejected_on_create(): void
    {
        $c = $this->customer();
        $this->expectException(ValidationException::class);
        $this->sale($c, $this->product(), 1, 10, 'partial', 10.01);
    }

    public function test_insufficient_stock_is_rejected_and_nothing_is_persisted(): void
    {
        $c = $this->customer();
        $p = $this->product(10, 2);

        try {
            $this->sale($c, $p, 5, 10, 'credit');
            $this->fail('expected failure');
        } catch (ValidationException $e) {
            $this->assertSame(0, \DB::table('sales')->count());
            $this->assertSame(0, \DB::table('ledger_entries')->count());
            $this->assertSame(2, $this->stock($p));
        }
    }

    public function test_pending_sale_does_not_move_stock_but_posts_to_ledger(): void
    {
        $c = $this->customer();
        $p = $this->product(10, 10);
        $this->sale($c, $p, 4, 10, 'credit', 0, ['status' => 'Pending']);

        $this->assertSame(10, $this->stock($p));
        $this->assertSame(4000, $this->ledger->getCustomerBalance($c));
    }

    public function test_reference_numbers_are_sequential_and_keep_format(): void
    {
        $c = $this->customer();
        $p = $this->product();
        $a = $this->row('sales', $this->sale($c, $p, 1, 10, 'full'))->reference;
        $b = $this->row('sales', $this->sale($c, $p, 1, 10, 'full'))->reference;

        $this->assertSame('SL-00001', $a);
        $this->assertSame('SL-00002', $b);
    }
}
