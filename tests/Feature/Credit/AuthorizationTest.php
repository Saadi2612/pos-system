<?php

namespace Tests\Feature\Credit;

use Illuminate\Support\Facades\DB;

class AuthorizationTest extends CreditTestCase
{
    public function test_guests_are_redirected(): void
    {
        $c = $this->customer();
        $this->post("/customers/{$c}/receipts", [])->assertRedirect('/login');
    }

    public function test_users_without_permissions_get_403_on_every_credit_action(): void
    {
        $user = $this->userWith(['access_customers', 'show_customers']);
        $c = $this->customer();
        $s = $this->supplier();
        $r = $this->ledger->receiveCustomerPayment($c, 1000, ['date' => '2026-01-01', 'method' => 'Cash']);
        $sp = $this->ledger->makeSupplierPayment($s, 1000, ['date' => '2026-01-01', 'method' => 'Cash']);
        $payload = ['date' => '2026-01-01', 'amount' => 5, 'payment_method' => 'Cash'];

        $this->actingAs($user);
        $this->post("/customers/{$c}/receipts", $payload)->assertForbidden();
        $this->post("/suppliers/{$s}/payments", $payload)->assertForbidden();
        $this->post("/customer-receipts/{$r}/allocate", ['allocations' => [1 => 1]])->assertForbidden();
        $this->post("/supplier-payments/{$sp}/allocate", ['allocations' => [1 => 1]])->assertForbidden();
        $this->delete("/customer-receipts/{$r}", ['reason' => 'x'])->assertForbidden();
        $this->delete("/supplier-payments/{$sp}", ['reason' => 'x'])->assertForbidden();
        $this->post("/customers/{$c}/opening-balance", ['opening_balance' => 5, 'date' => '2026-01-01'])->assertForbidden();
        $this->post("/suppliers/{$s}/opening-balance", ['opening_balance' => 5, 'date' => '2026-01-01'])->assertForbidden();

        foreach (['customer-outstanding', 'supplier-ledger', 'receivables-ageing', 'overdue-payables'] as $report) {
            $this->get("/credit-reports/{$report}")->assertForbidden();
        }

        $this->assertSame(0, (int) DB::table('ledger_entries')->where('entry_type', 'reversal')->count());
        $this->assertSame(1000, (int) $this->row('customer_receipts', $r)->amount);
    }

    public function test_receive_permission_does_not_imply_void_permission(): void
    {
        $user = $this->userWith(['receive_customer_payments']);
        $c = $this->customer();
        $r = $this->ledger->receiveCustomerPayment($c, 1000, ['date' => '2026-01-01', 'method' => 'Cash']);

        $this->actingAs($user)->delete("/customer-receipts/{$r}", ['reason' => 'x'])->assertForbidden();
        $this->assertNull($this->row('customer_receipts', $r)->voided_at);

        $this->actingAs($user)->post("/customers/{$c}/receipts", ['date' => '2026-01-02', 'amount' => '7.50', 'payment_method' => 'Cash'])
            ->assertRedirect();
        $this->assertSame(-1750, $this->ledger->getCustomerBalance($c));
    }

    public function test_authorised_users_can_operate_and_overpay_is_a_validation_error_not_a_500(): void
    {
        $user = $this->userWith(['receive_customer_payments', 'void_payments', 'manage_opening_balances', 'show_customers']);
        $c = $this->customer();
        $this->actingAs($user);

        $this->post("/customers/{$c}/opening-balance", ['opening_balance' => '120.50', 'date' => '2026-01-01'])->assertRedirect();
        $this->assertSame(12050, $this->ledger->getCustomerBalance($c));

        $sale = $this->sale($c, $this->product(), 1, 10, 'credit');
        $this->post("/customers/{$c}/receipts", [
            'date' => '2026-01-02', 'amount' => '5', 'payment_method' => 'Cash', 'allocations' => [$sale => '20'],
        ])->assertSessionHasErrors('allocations');
        $this->assertSame(0, DB::table('customer_receipts')->count());

        $this->post("/customers/{$c}/receipts", [
            'date' => '2026-01-02', 'amount' => '5', 'payment_method' => 'Cash', 'allocations' => [$sale => '5'],
        ])->assertRedirect();
        $this->assertSame(500, (int) $this->row('sales', $sale)->paid_amount);

        $receipt = DB::table('customer_receipts')->first();
        $this->delete("/customer-receipts/{$receipt->id}", ['reason' => 'test'])->assertRedirect();
        $this->assertSame(0, (int) $this->row('sales', $sale)->paid_amount);
    }

    public function test_super_admin_can_open_reports(): void
    {
        $this->actingAs($this->superAdmin());
        \Modules\Setting\Entities\Setting::unguard();
        $this->get('/credit-reports/does-not-exist')->assertNotFound();
    }
}
