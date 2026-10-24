<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class ReportsController extends Controller
{

    public function profitLossReport() {
        abort_if(Gate::denies('access_reports'), 403);

        return view('reports::profit-loss.index');
    }

    public function paymentsReport() {
        abort_if(Gate::denies('access_reports'), 403);

        return view('reports::payments.index');
    }

    public function salesReport() {
        abort_if(Gate::denies('access_reports'), 403);

        return view('reports::sales.index');
    }

    public function purchasesReport() {
        abort_if(Gate::denies('access_reports'), 403);

        return view('reports::purchases.index');
    }

    public function salesReturnReport() {
        abort_if(Gate::denies('access_reports'), 403);

        return view('reports::sales-return.index');
    }

    public function purchasesReturnReport() {
        abort_if(Gate::denies('access_reports'), 403);

        return view('reports::purchases-return.index');
    }

    /** Credit / ledger reports (Livewire). Each route passes a `report` key. */
    public const CREDIT_REPORTS = [
        'customer-outstanding' => ['reports.outstanding-invoices-report', ['type' => 'customer'], 'Customer Outstanding Invoices', 'access_customer_ledger'],
        'supplier-outstanding' => ['reports.outstanding-invoices-report', ['type' => 'supplier'], 'Supplier Outstanding Invoices', 'access_supplier_ledger'],
        'overdue-receivables' => ['reports.outstanding-invoices-report', ['type' => 'customer', 'overdueOnly' => true], 'Overdue Receivables', 'access_customer_ledger'],
        'overdue-payables' => ['reports.outstanding-invoices-report', ['type' => 'supplier', 'overdueOnly' => true], 'Overdue Payables', 'access_supplier_ledger'],
        'customer-ledger' => ['reports.party-ledger-report', ['type' => 'customer'], 'Customer Ledger', 'access_customer_ledger'],
        'supplier-ledger' => ['reports.party-ledger-report', ['type' => 'supplier'], 'Supplier Ledger', 'access_supplier_ledger'],
        'customer-receipts' => ['reports.party-payments-report', ['type' => 'customer'], 'Customer Receipt History', 'access_customer_ledger'],
        'supplier-payments' => ['reports.party-payments-report', ['type' => 'supplier'], 'Supplier Payment History', 'access_supplier_ledger'],
        'receivables-ageing' => ['reports.ageing-report', ['type' => 'customer'], 'Receivables Ageing', 'access_customer_ledger'],
        'payables-ageing' => ['reports.ageing-report', ['type' => 'supplier'], 'Payables Ageing', 'access_supplier_ledger'],
    ];

    public function credit(Request $request) {
        $key = $request->route('report');
        abort_unless(isset(self::CREDIT_REPORTS[$key]), 404);

        [$component, $params, $title, $permission] = self::CREDIT_REPORTS[$key];

        abort_if(Gate::denies('access_credit_reports') || Gate::denies($permission), 403);

        return view('reports::credit.page', compact('component', 'params', 'title'));
    }
}
