<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $documents = ['sales', 'purchases', 'sale_returns', 'purchase_returns'];

    private array $paymentTables = ['sale_payments', 'purchase_payments', 'sale_return_payments', 'purchase_return_payments'];

    public function up()
    {
        foreach ($this->documents as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->timestamp('cancelled_at')->nullable();
                $t->unsignedBigInteger('cancelled_by')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();

                if (in_array($table, ['sales', 'purchases'])) {
                    $t->date('due_date')->nullable()->index();
                }
            });
        }

        Schema::table('purchases', function (Blueprint $t) {
            $t->string('supplier_reference')->nullable();
        });

        Schema::table('sale_returns', function (Blueprint $t) {
            $t->unsignedBigInteger('sale_id')->nullable()->index();
        });

        Schema::table('purchase_returns', function (Blueprint $t) {
            $t->unsignedBigInteger('purchase_id')->nullable()->index();
        });

        Schema::table('customers', function (Blueprint $t) {
            $t->bigInteger('opening_balance')->default(0);
            $t->date('opening_balance_date')->nullable();
            $t->bigInteger('credit_limit')->nullable();
        });

        Schema::table('suppliers', function (Blueprint $t) {
            $t->bigInteger('opening_balance')->default(0);
            $t->date('opening_balance_date')->nullable();
        });

        foreach ($this->paymentTables as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (in_array($table, ['sale_payments', 'purchase_payments'])) {
                    $t->unsignedBigInteger('receipt_id')->nullable()->index();
                }
                $t->timestamp('voided_at')->nullable()->index();
                $t->unsignedBigInteger('voided_by')->nullable();
                $t->string('void_reason')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
            });
        }
    }

    public function down()
    {
        foreach ($this->paymentTables as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (in_array($table, ['sale_payments', 'purchase_payments'])) {
                    $t->dropIndex([ 'receipt_id' ]);
                    $t->dropColumn('receipt_id');
                }
                $t->dropIndex(['voided_at']);
                $t->dropColumn(['voided_at', 'voided_by', 'void_reason', 'created_by']);
            });
        }

        Schema::table('suppliers', function (Blueprint $t) {
            $t->dropColumn(['opening_balance', 'opening_balance_date']);
        });
        Schema::table('customers', function (Blueprint $t) {
            $t->dropColumn(['opening_balance', 'opening_balance_date', 'credit_limit']);
        });
        Schema::table('purchase_returns', function (Blueprint $t) {
            $t->dropIndex(['purchase_id']);
            $t->dropColumn('purchase_id');
        });
        Schema::table('sale_returns', function (Blueprint $t) {
            $t->dropIndex(['sale_id']);
            $t->dropColumn('sale_id');
        });
        Schema::table('purchases', function (Blueprint $t) {
            $t->dropColumn('supplier_reference');
        });

        foreach ($this->documents as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (in_array($table, ['sales', 'purchases'])) {
                    $t->dropIndex(['due_date']);
                    $t->dropColumn('due_date');
                }
                $t->dropColumn(['cancelled_at', 'cancelled_by', 'created_by']);
            });
        }
    }
};
