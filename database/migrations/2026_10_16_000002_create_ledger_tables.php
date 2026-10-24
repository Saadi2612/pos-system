<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Header for money received from a customer (may be allocated to many sales, or kept as advance).
        // Allocations are the existing sale_payments rows carrying receipt_id.
        Schema::create('customer_receipts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id');
            $t->date('date');
            $t->string('reference');
            $t->bigInteger('amount');
            $t->string('payment_method');
            $t->text('note')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->unsignedBigInteger('voided_by')->nullable();
            $t->string('void_reason')->nullable();
            $t->timestamps();
            $t->index(['customer_id', 'date']);
            $t->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
        });

        Schema::create('supplier_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('supplier_id');
            $t->date('date');
            $t->string('reference');
            $t->bigInteger('amount');
            $t->string('payment_method');
            $t->text('note')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->unsignedBigInteger('voided_by')->nullable();
            $t->string('void_reason')->nullable();
            $t->timestamps();
            $t->index(['supplier_id', 'date']);
            $t->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
        });

        // Append-only party sub-ledger.
        // customer: balance = SUM(debit) - SUM(credit)   (positive => customer owes us)
        // supplier: balance = SUM(credit) - SUM(debit)   (positive => we owe supplier)
        Schema::create('ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->string('party_type', 20);
            $t->unsignedBigInteger('party_id');
            $t->date('entry_date');
            $t->string('entry_type', 20);
            $t->string('source_type', 40);
            $t->unsignedBigInteger('source_id');
            $t->bigInteger('debit')->default(0);
            $t->bigInteger('credit')->default(0);
            $t->string('reference')->nullable();
            $t->string('description')->nullable();
            $t->string('posting_key')->unique();
            $t->unsignedBigInteger('reverses_entry_id')->nullable()->unique();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['party_type', 'party_id', 'entry_date']);
            $t->index(['source_type', 'source_id']);
            $t->index('entry_type');
        });

        Schema::create('reference_counters', function (Blueprint $t) {
            $t->string('prefix')->primary();
            $t->unsignedBigInteger('last_number')->default(0);
        });
    }

    public function down()
    {
        Schema::dropIfExists('reference_counters');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('customer_receipts');
    }
};
