<?php

namespace Tests\Feature\Credit;

use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Database\Seeders\CurrencyDatabaseSeeder;
use Modules\Setting\Database\Seeders\SettingDatabaseSeeder;

/** Renders every page that was touched and drives the real controllers over HTTP. */
class PagesSmokeTest extends CreditTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CurrencyDatabaseSeeder::class);
        $this->seed(SettingDatabaseSeeder::class);
        $this->actingAs($this->superAdmin());
    }

    public function test_pages_render(): void
    {
        $c = $this->customer();
        $s = $this->supplier();
        $p = $this->product(10, 50);
        $sale = $this->sale($c, $p, 2, 10, 'partial', 5, ['due_date' => '2026-02-01']);
        $purchase = $this->purchase($s, $p, 2, 4, 'credit');
        $this->ledger->receiveCustomerPayment($c, 1000, ['date' => '2026-01-12', 'method' => 'Cash']);

        $urls = [
            '/home', '/sales', '/sales/create', "/sales/{$sale}", "/sales/{$sale}/edit", '/purchases', '/purchases/create',
            "/purchases/{$purchase}", "/purchases/{$purchase}/edit", '/app/pos', '/sale-returns', '/sale-returns/create', '/purchase-returns',
            '/purchase-returns/create', "/sale-payments/{$sale}", "/sale-payments/{$sale}/create", "/purchase-payments/{$purchase}",
            "/purchase-payments/{$purchase}/create", '/customers', "/customers/{$c}", "/customers/{$c}/edit", '/customers/create',
            '/suppliers', "/suppliers/{$s}", '/payments-report', '/profit-loss-report', '/sales-report',
        ];
        foreach (array_keys(\Modules\Reports\Http\Controllers\ReportsController::CREDIT_REPORTS) as $r) {
            $urls[] = "/credit-reports/{$r}";
        }

        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }

        $this->get("/customers/{$c}")->assertSee('Account Balance')->assertSee('Unallocated Advance')->assertSee('Receive Payment');
        $this->get("/suppliers/{$s}")->assertSee('Payable Balance')->assertSee('Make Payment');
        $this->get('/sales/create')->assertSee('Full credit')->assertSee('Due Date');
    }

    public function test_datatables_return_json_with_due_date_and_voided_state(): void
    {
        $c = $this->customer();
        $p = $this->product();
        $sale = $this->sale($c, $p, 1, 10, 'credit', 0, ['due_date' => '2020-01-01']);
        $this->ledger->payInvoice('sale', $sale, 300, ['date' => '2026-01-12', 'method' => 'Cash']);
        $payment = DB::table('sale_payments')->first();
        $this->ledger->voidPayment('sale', $payment->id, 'x');

        $hdr = ['X-Requested-With' => 'XMLHttpRequest'];
        $json = $this->getJson('/sales?draw=1&start=0&length=10', $hdr)->assertOk()->json();
        $this->assertStringContainsString('overdue', $json['data'][0]['due_date']);

        $json = $this->getJson("/sale-payments/{$sale}?draw=1&start=0&length=10", $hdr)->assertOk()->json();
        $this->assertSame('Voided', $json['data'][0]['status']);
    }

    public function test_http_credit_sale_and_cash_pos_sale_and_credit_purchase(): void
    {
        $c = $this->customer();
        $s = $this->supplier();
        $p = $this->product(10, 20);

        // full-credit sale through the real controller
        $this->cart('sale', $p, 3, 10);
        $this->post('/sales', [
            'customer_id' => $c, 'date' => '2026-01-10', 'tax_percentage' => 0, 'discount_percentage' => 0, 'shipping_amount' => 0,
            'status' => 'Completed', 'payment_type' => 'credit', 'paid_amount' => 999, 'payment_method' => 'Cash', 'due_date' => '2026-02-10',
            'total_amount' => 1,   // forged client total must be ignored
        ])->assertRedirect('/sales');

        $sale = DB::table('sales')->first();
        $this->assertSame(3000, (int) $sale->total_amount);
        $this->assertSame(0, (int) $sale->paid_amount);
        $this->assertSame('Unpaid', $sale->payment_status);
        $this->assertSame(3000, $this->ledger->getCustomerBalance($c));
        $this->assertSame(17, $this->stock($p));

        // cash POS sale (existing behaviour)
        $this->cart('sale', $p, 2, 10);
        $this->post('/app/pos', [
            'customer_id' => $c, 'tax_percentage' => 0, 'discount_percentage' => 0, 'shipping_amount' => 0,
            'payment_type' => 'full', 'paid_amount' => 20, 'payment_method' => 'Cash',
        ])->assertRedirect('/sales');
        $this->assertSame(3000, $this->ledger->getCustomerBalance($c));
        $this->assertSame(15, $this->stock($p));
        $this->assertSame('Paid', DB::table('sales')->orderByDesc('id')->first()->payment_status);

        // credit purchase
        $this->cart('purchase', $p, 4, 5);
        $this->post('/purchases', [
            'supplier_id' => $s, 'date' => '2026-01-10', 'tax_percentage' => 0, 'discount_percentage' => 0, 'shipping_amount' => 0,
            'status' => 'Completed', 'payment_type' => 'credit', 'payment_method' => 'Cash', 'supplier_reference' => 'S-1',
        ])->assertRedirect('/purchases');
        $this->assertSame(2000, $this->ledger->getSupplierBalance($s));
        $this->assertSame(19, $this->stock($p));

        // overpaid POST is a validation error
        $this->cart('sale', $p, 1, 10);
        $this->post('/sales', [
            'customer_id' => $c, 'date' => '2026-01-10', 'tax_percentage' => 0, 'discount_percentage' => 0, 'shipping_amount' => 0,
            'status' => 'Completed', 'payment_type' => 'partial', 'paid_amount' => 50, 'payment_method' => 'Cash',
        ])->assertSessionHasErrors('paid_amount');
        $this->assertSame(2, DB::table('sales')->count());
    }

    public function test_cancel_via_delete_route_and_payment_void_route(): void
    {
        $c = $this->customer();
        $p = $this->product(10, 10);
        $sale = $this->sale($c, $p, 2, 10, 'partial', 5);
        $payment = DB::table('sale_payments')->first();

        $this->delete("/sale-payments/destroy/{$payment->id}", ['reason' => 'oops'])->assertRedirect();
        $this->assertSame(0, (int) $this->row('sales', $sale)->paid_amount);

        $this->delete("/sales/{$sale}")->assertRedirect('/sales');
        $this->assertSame('Cancelled', $this->row('sales', $sale)->status);
        $this->assertSame(10, $this->stock($p));
        $this->assertSame(1, DB::table('sales')->count());   // never hard deleted
    }
}
