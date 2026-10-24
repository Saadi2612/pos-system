<?php

namespace App\Services\Ledger;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class CreditPermissions
{
    public const NAMES = [
        'access_customer_ledger',
        'access_supplier_ledger',
        'receive_customer_payments',
        'make_supplier_payments',
        'access_credit_reports',
        'manage_opening_balances',
        'void_payments',
    ];

    /** Idempotently create the permissions and grant them to the Admin role if it exists. */
    public static function install(): void
    {
        foreach (self::NAMES as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $admin = Role::where('name', 'Admin')->first();
        if ($admin) {
            $admin->givePermissionTo(self::NAMES);
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
