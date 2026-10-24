<?php

use App\Services\Ledger\CreditPermissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up()
    {
        CreditPermissions::install();
    }

    public function down()
    {
        Permission::whereIn('name', CreditPermissions::NAMES)->delete();
    }
};
