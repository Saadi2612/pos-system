<?php

namespace Modules\Sale\Http\Controllers;

use Modules\Sale\DataTables\SalePaymentsDataTable;
use App\Services\Ledger\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;

class SalePaymentsController extends Controller
{

    public function index($sale_id, SalePaymentsDataTable $dataTable) {
        abort_if(Gate::denies('access_sale_payments'), 403);

        $sale = Sale::findOrFail($sale_id);

        return $dataTable->render('sale::payments.index', compact('sale'));
    }


    public function create($sale_id) {
        abort_if(Gate::denies('access_sale_payments'), 403);

        $sale = Sale::findOrFail($sale_id);

        return view('sale::payments.create', compact('sale'));
    }


    public function store(Request $request) {
        abort_if(Gate::denies('access_sale_payments'), 403);

        $request->validate([
            'date' => 'required|date',
            'reference' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0',
            'note' => 'nullable|string|max:1000',
            'sale_id' => 'required|integer|exists:sales,id',
            'payment_method' => 'required|string|in:Cash,Credit Card,Bank Transfer,Cheque,Other'
        ]);

        // Amounts are converted to minor units exactly once, here; the service only deals in integers.
        // Overpayment is rejected by the service (amount may not exceed the outstanding balance).
        app(LedgerService::class)->payInvoice('sale', (int) $request->sale_id, money_to_minor($request->amount), [
            'date' => $request->date, 'reference' => $request->reference, 'method' => $request->payment_method, 'note' => $request->note,
        ], auth()->id());

        toast('Sale Payment Created!', 'success');

        return redirect()->route('sales.index');
    }


    /** Payments are never deleted: voiding keeps the record, restores the balance and reverses the ledger. */
    public function destroy(SalePayment $salePayment, Request $request) {
        abort_if(Gate::denies('access_sale_payments') || Gate::denies('void_payments'), 403);

        app(LedgerService::class)->voidPayment('sale', $salePayment->id, $request->input('reason', 'Voided by user'), auth()->id());

        toast('Sale Payment Voided!', 'warning');

        return redirect()->route('sales.index');
    }
}
