<?php

namespace Tests\Feature\Credit;

use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;

class BackfillTest extends CreditTestCase
{
    private function legacySale(int $customerId, int $total, int $paidStored, array $paymentRows, string $ref): int
    {
        $id = DB::table('sales')->insertGetId([
            'date' => '2025-11-05', 'reference' => $ref, 'customer_id' => $customerId, 'customer_name' => 'X', 'total_amount' => $total,
            'paid_amount' => $paidStored, 'due_amount' => $total - $paidStored, 'status' => 'Completed',
            'payment_status' => $paidStored === 0 ? 'Unpaid' : ($paidStored >= $total ? 'Paid' : 'Partial'), 'payment_method' => 'Cash',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($paymentRows as $amt) {
            DB::table('sale_payments')->insert(['sale_id' => $id, 'amount' => $amt, 'date' => '2025-11-06', 'reference' => 'INV/' . $ref,
                'payment_method' => 'Cash', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    public function test_backfill_posts_history_idempotently_and_balances_match(): void
    {
        $c = $this->customer();
        $s = $this->supplier();
        $this->legacySale($c, 10000, 4000, [1000, 3000], 'SL-OLD-1');
        $this->legacySale($c, 5000, 0, [], 'SL-OLD-2');
        $pid = DB::table('purchases')->insertGetId([
            'date' => '2025-11-05', 'reference' => 'PR-OLD-1', 'supplier_id' => $s, 'supplier_name' => 'S', 'total_amount' => 8000,
            'paid_amount' => 8000, 'due_amount' => 0, 'status' => 'Completed', 'payment_status' => 'Paid', 'payment_method' => 'Cash',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_payments')->insert(['purchase_id' => $pid, 'amount' => 8000, 'date' => '2025-11-06', 'reference' => 'x',
            'payment_method' => 'Cash', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('ledger:reconcile')->assertExitCode(0);
        $this->artisan('ledger:backfill', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame(0, LedgerEntry::count());   // dry-run rolled back

        $this->artisan('ledger:backfill')->assertExitCode(0);
        $count = LedgerEntry::count();
        $this->assertSame(2 + 2 + 1 + 1, $count);     // 2 sales + 2 sale payments + 1 purchase + 1 purchase payment
        $this->assertSame(11000, $this->ledger->getCustomerBalance($c));   // (10000-4000) + 5000
        $this->assertSame(0, $this->ledger->getSupplierBalance($s));

        $this->artisan('ledger:backfill')->assertExitCode(0);   // rerun
        $this->assertSame($count, LedgerEntry::count());
    }

    public function test_backfill_refuses_while_discrepancies_are_unresolved_and_changes_nothing(): void
    {
        $c = $this->customer();
        $id = $this->legacySale($c, 10000, 6000, [4000], 'SL-DRIFT');    // stored 6000 vs rows 4000

        $this->artisan('ledger:reconcile')->assertExitCode(1);
        $this->artisan('ledger:backfill')->assertExitCode(1);

        $this->assertSame(0, LedgerEntry::count());
        $this->assertSame(6000, (int) $this->row('sales', $id)->paid_amount);   // stored figures untouched
        $this->assertSame(0, DB::table('sale_payments')->where('amount', 2000)->count()); // no invented payment
    }

    public function test_backfill_with_documented_resolution_uses_payment_rows(): void
    {
        $c = $this->customer();
        $id = $this->legacySale($c, 10000, 6000, [4000], 'SL-DRIFT');

        $csv = tempnam(sys_get_temp_dir(), 'res');
        file_put_contents($csv, "kind,id,resolution,reason,approved_by\nsale,{$id},use_payments,\"Cashier confirmed 40.00 was received\",owner@example.com\n");

        $this->artisan('ledger:backfill', ['--resolutions' => $csv])->assertExitCode(0);

        $this->assertSame(4000, (int) $this->row('sales', $id)->paid_amount);
        $this->assertSame(6000, (int) $this->row('sales', $id)->due_amount);
        $this->assertSame(6000, $this->ledger->getCustomerBalance($c));
        unlink($csv);
    }

    public function test_resolution_without_reason_or_approver_does_not_count(): void
    {
        $c = $this->customer();
        $id = $this->legacySale($c, 10000, 6000, [4000], 'SL-DRIFT');
        $csv = tempnam(sys_get_temp_dir(), 'res');
        file_put_contents($csv, "kind,id,resolution,reason,approved_by\nsale,{$id},use_payments,,\n");

        $this->artisan('ledger:backfill', ['--resolutions' => $csv])->assertExitCode(1);
        $this->assertSame(0, LedgerEntry::count());
        unlink($csv);
    }
}
