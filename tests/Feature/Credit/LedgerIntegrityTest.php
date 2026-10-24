<?php

namespace Tests\Feature\Credit;

use App\Models\LedgerEntry;
use App\Services\Ledger\CashFlow;
use App\Services\Ledger\CreditQueries;
use App\Services\Ledger\Reconciler;
use Illuminate\Support\Facades\DB;
use LogicException;

class LedgerIntegrityTest extends CreditTestCase
{
    public function test_ledger_entries_are_append_only(): void
    {
        $c = $this->customer();
        $this->sale($c, $this->product(), 1, 10, 'credit');
        $entry = LedgerEntry::first();

        try {
            $entry->update(['debit' => 1]);
            $this->fail('update allowed');
        } catch (LogicException $e) {
        }

        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_posting_is_idempotent_and_never_duplicates(): void
    {
        $c = $this->customer();
        $id = $this->sale($c, $this->product(), 2, 10, 'credit');

        $this->ledger->syncDocument('sale', $id);
        $this->ledger->syncDocument('sale', $id);
        $this->ledger->postCustomerInvoice($id);

        $this->assertSame(1, LedgerEntry::where('source_type', 'sale')->where('source_id', $id)->count());
        $this->assertSame(2000, $this->ledger->getCustomerBalance($c));
    }

    public function test_a_reversal_can_only_exist_once(): void
    {
        $c = $this->customer();
        $this->sale($c, $this->product(), 1, 10, 'credit');
        $entry = LedgerEntry::first();

        $a = $this->ledger->reverseEntry($entry, 'x');
        $b = $this->ledger->reverseEntry($entry, 'y');

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, LedgerEntry::where('entry_type', 'reversal')->count());
        $this->assertSame(0, $this->ledger->getCustomerBalance($c));
    }

    public function test_opening_balances_customer_and_supplier_with_sign_conventions(): void
    {
        $c = $this->customer();
        $s = $this->supplier();

        $this->ledger->postOpeningBalance('customer', $c, 25000, '2026-01-01');
        $this->ledger->postOpeningBalance('supplier', $s, 40000, '2026-01-01');

        $this->assertSame(25000, $this->ledger->getCustomerBalance($c));
        $this->assertSame(40000, $this->ledger->getSupplierBalance($s));
        $this->assertSame(25000, (int) $this->row('customers', $c)->opening_balance);
        $this->assertSame(25000, (int) LedgerEntry::where('party_type', 'customer')->first()->debit);
        $this->assertSame(40000, (int) LedgerEntry::where('party_type', 'supplier')->first()->credit);

        // change: reversal + new entry, never an edit
        $this->ledger->postOpeningBalance('customer', $c, 10000, '2026-01-01');
        $this->assertSame(10000, $this->ledger->getCustomerBalance($c));
        $this->assertSame(3, LedgerEntry::where('party_type', 'customer')->count());

        // negative opening = customer credit
        $this->ledger->postOpeningBalance('customer', $c, -500, '2026-01-01');
        $this->assertSame(-500, $this->ledger->getCustomerBalance($c));
    }

    public function test_existing_party_without_any_history_is_valid(): void
    {
        $c = $this->customer();
        $s = $this->supplier();
        $this->assertSame(0, $this->ledger->getCustomerBalance($c));
        $this->assertSame(0, $this->ledger->getSupplierBalance($s));
        $this->assertSame(0, (int) $this->row('customers', $c)->opening_balance);
        $this->assertNull($this->row('customers', $c)->credit_limit);
    }

    public function test_ledger_balance_reconciles_with_documents_payments_returns_and_opening(): void
    {
        $c = $this->customer();
        $p = $this->product(10, 100);
        $this->ledger->postOpeningBalance('customer', $c, 10000, '2026-01-01');
        $s1 = $this->sale($c, $p, 5, 10, 'partial', 20);
        $s2 = $this->sale($c, $p, 3, 10, 'credit');
        $this->ledger->receiveCustomerPayment($c, 6000, ['date' => '2026-01-20', 'method' => 'Cash'], [$s1 => 3000]);   // 30 allocated, 30 advance
        $this->cart('sale_return', $p, 1, 10);
        $this->writer->create('sale_return', 'sale_return', [
            'date' => '2026-01-21', 'party_id' => $c, 'tax_percentage' => 0, 'discount_percentage' => 0, 'shipping_amount' => 0,
            'status' => 'Completed', 'payment_method' => 'Cash', 'payment_type' => 'partial', 'paid_amount' => 5, 'note' => null,
        ], null);

        // opening 100 + 50 + 30 - 20 (cash on s1) - 60 (receipt) - 10 (return) + 5 (refund) = 95
        $this->assertSame(9500, $this->ledger->getCustomerBalance($c));
        $this->assertCount(0, (new Reconciler)->ledgerVsDocuments());
        $this->assertCount(0, (new Reconciler)->discrepancies());
    }

    public function test_outstanding_uses_valid_payments_and_ageing_buckets(): void
    {
        $c = $this->customer();
        $p = $this->product(10, 500);
        $asOf = '2026-06-30';

        $current = $this->sale($c, $p, 1, 10, 'credit', 0, ['due_date' => '2026-07-15']);
        $noDue = $this->sale($c, $p, 1, 20, 'credit');
        $d20 = $this->sale($c, $p, 1, 30, 'credit', 0, ['due_date' => '2026-06-10']);
        $d45 = $this->sale($c, $p, 1, 40, 'credit', 0, ['due_date' => '2026-05-16']);
        $d75 = $this->sale($c, $p, 1, 50, 'credit', 0, ['due_date' => '2026-04-16']);
        $d120 = $this->sale($c, $p, 1, 60, 'credit', 0, ['due_date' => '2026-02-27']);
        $paid = $this->sale($c, $p, 1, 70, 'credit', 0, ['due_date' => '2026-02-27']);
        $this->ledger->payInvoice('sale', $paid, 7000, ['date' => '2026-03-01', 'method' => 'Cash']);
        $voided = $this->sale($c, $p, 1, 5, 'credit', 0, ['due_date' => '2026-06-20']);
        $r = $this->ledger->payInvoice('sale', $voided, 500, ['date' => '2026-06-21', 'method' => 'Cash']);
        $this->ledger->voidHeader('customer', $r, 'bounced');   // back to outstanding

        $q = new CreditQueries;
        $ageing = $q->ageing('customer', $asOf)->first();

        $this->assertSame(1000 + 2000, $ageing->current);
        $this->assertSame(3000 + 500, $ageing->d1_30);
        $this->assertSame(4000, $ageing->d31_60);
        $this->assertSame(5000, $ageing->d61_90);
        $this->assertSame(6000, $ageing->d90_plus);
        $this->assertSame(21500, $ageing->total);

        $this->assertCount(7, $q->outstanding('customer', ['as_of' => $asOf]));   // fully paid one excluded
        $this->assertCount(3, $q->outstanding('customer', ['as_of' => $asOf, 'overdue_only' => true])->where('days_overdue', '>', 20));
        $this->assertSame(0, $q->outstanding('customer', ['as_of' => $asOf, 'party_id' => $this->customer()])->count());
    }

    public function test_cash_flow_counts_only_real_valid_money_not_credit_invoices(): void
    {
        $c = $this->customer();
        $s = $this->supplier();
        $p = $this->product(10, 100);

        $this->sale($c, $p, 10, 10, 'credit');                        // 100 credit => no cash
        $this->sale($c, $p, 2, 10, 'full');                           // 20 cash
        $adv = $this->ledger->receiveCustomerPayment($c, 5000, ['date' => '2026-01-10', 'method' => 'Cash']);   // 50 advance cash
        $bad = $this->ledger->receiveCustomerPayment($c, 9900, ['date' => '2026-01-10', 'method' => 'Cash']);
        $this->ledger->voidHeader('customer', $bad, 'x');
        $this->purchase($s, $p, 10, 4, 'credit');                     // 40 credit => no cash out
        $this->purchase($s, $p, 5, 4, 'full');                        // 20 cash out

        $cash = new CashFlow;
        $this->assertSame(7000, $cash->receivedTotal());              // 20 + 50, allocation rows not double counted
        $this->assertSame(2000, $cash->sentTotal());
    }

    public function test_profit_loss_report_multiplies_cost_by_quantity(): void
    {
        $c = $this->customer();
        $p = $this->product(10, 100, 4);                              // cost 4.00 per unit
        $this->sale($c, $p, 3, 10, 'full');                           // revenue 30, cost 12

        $report = new \App\Livewire\Reports\ProfitLossReport;
        $report->mount();
        $report->setValues();

        $this->assertEquals(30.0, $report->sales_amount);
        $this->assertEquals(18.0, $report->profit_amount);
        $this->assertEquals(30.0, $report->payments_received_amount);
    }

    public function test_unique_reference_prevents_duplicates(): void
    {
        $c = $this->customer();
        $p = $this->product();
        $id = $this->sale($c, $p, 1, 10, 'full');

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('sales')->insert([
            'date' => '2026-01-01', 'reference' => $this->row('sales', $id)->reference, 'customer_id' => $c, 'customer_name' => 'X',
            'total_amount' => 1, 'paid_amount' => 1, 'due_amount' => 0, 'status' => 'Completed', 'payment_status' => 'Paid', 'payment_method' => 'Cash',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
