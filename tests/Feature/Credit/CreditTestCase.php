<?php

namespace Tests\Feature\Credit;

use App\Models\User;
use App\Services\Ledger\DocumentWriter;
use App\Services\Ledger\LedgerService;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Shared fixtures for the credit/ledger tests. Amounts passed to helpers are decimals; assertions use minor units. */
abstract class CreditTestCase extends TestCase
{
    use RefreshDatabase;

    protected LedgerService $ledger;
    protected DocumentWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);
        $this->writer = app(DocumentWriter::class);
    }

    protected function customer(array $o = []): int
    {
        return DB::table('customers')->insertGetId($o + [
            'customer_name' => 'Cust ' . uniqid(), 'customer_email' => 'c@example.com', 'customer_phone' => '1',
            'city' => 'X', 'country' => 'Y', 'address' => 'Z', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function supplier(array $o = []): int
    {
        return DB::table('suppliers')->insertGetId($o + [
            'supplier_name' => 'Supp ' . uniqid(), 'supplier_email' => 's@example.com', 'supplier_phone' => '1',
            'city' => 'X', 'country' => 'Y', 'address' => 'Z', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function product(float $price = 10, int $qty = 100, float $cost = 4): int
    {
        $cat = DB::table('categories')->insertGetId(['category_code' => 'C' . uniqid(), 'category_name' => 'Cat', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('products')->insertGetId([
            'category_id' => $cat, 'product_name' => 'P ' . uniqid(), 'product_code' => 'PC' . uniqid(),
            'product_quantity' => $qty, 'product_cost' => (int) round($cost * 100), 'product_price' => (int) round($price * 100),
            'product_stock_alert' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function stock(int $productId): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('product_quantity');
    }

    /** Fill a cart instance with one line: price is the all-in unit price (no tax). */
    protected function cart(string $instance, int $productId, int $qty, float $price): void
    {
        Cart::instance($instance)->destroy();
        Cart::instance($instance)->add([
            'id' => $productId, 'name' => 'Item', 'qty' => $qty, 'price' => $price, 'weight' => 1,
            'options' => [
                'product_discount' => 0, 'product_discount_type' => 'fixed', 'sub_total' => $price * $qty,
                'code' => 'X', 'stock' => 999, 'unit' => null, 'product_tax' => 0, 'unit_price' => $price,
            ],
        ]);
    }

    protected function sale(int $customerId, int $productId, int $qty, float $price, string $type = 'credit', float $paid = 0, array $o = []): int
    {
        $this->cart('sale', $productId, $qty, $price);

        return $this->writer->create('sale', 'sale', $o + [
            'date' => '2026-01-10', 'party_id' => $customerId, 'tax_percentage' => 0, 'discount_percentage' => 0,
            'shipping_amount' => 0, 'status' => 'Completed', 'payment_method' => 'Cash', 'payment_type' => $type,
            'paid_amount' => $paid, 'due_date' => null, 'note' => null,
        ], null);
    }

    protected function purchase(int $supplierId, int $productId, int $qty, float $price, string $type = 'credit', float $paid = 0, array $o = []): int
    {
        $this->cart('purchase', $productId, $qty, $price);

        return $this->writer->create('purchase', 'purchase', $o + [
            'date' => '2026-01-10', 'party_id' => $supplierId, 'tax_percentage' => 0, 'discount_percentage' => 0,
            'shipping_amount' => 0, 'status' => 'Completed', 'payment_method' => 'Cash', 'payment_type' => $type,
            'paid_amount' => $paid, 'due_date' => null, 'note' => null,
        ], null);
    }

    protected function row(string $table, int $id): object
    {
        return DB::table($table)->where('id', $id)->first();
    }

    protected function userWith(array $permissions): User
    {
        $user = User::create(['name' => 'T', 'email' => uniqid() . '@t.test', 'password' => bcrypt('x'), 'is_active' => 1]);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    protected function superAdmin(): User
    {
        $user = User::create(['name' => 'S', 'email' => uniqid() . '@t.test', 'password' => bcrypt('x'), 'is_active' => 1]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin']));

        return $user;
    }
}
