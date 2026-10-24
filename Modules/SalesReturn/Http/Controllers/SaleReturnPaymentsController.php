<?php

namespace Modules\SalesReturn\Http\Controllers;

use Modules\SalesReturn\DataTables\SaleReturnPaymentsDataTable;
use App\Services\Ledger\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnPayment;

class SaleReturnPaymentsController extends Controller
{

    public function index($sale_return_id, SaleReturnPaymentsDataTable $dataTable) {
        abort_if(Gate::denies('access_sale_return_payments'), 403);

        $sale_return = SaleReturn::findOrFail($sale_return_id);

        return $dataTable->render('salesreturn::payments.index', compact('sale_return'));
    }


    public function create($sale_return_id) {
        abort_if(Gate::denies('access_sale_return_payments'), 403);

        $sale_return = SaleReturn::findOrFail($sale_return_id);

        return view('salesreturn::payments.create', compact('sale_return'));
    }


    public function store(Request $request) {
        abort_if(Gate::denies('access_sale_return_payments'), 403);

        $request->validate([
            'date' => 'required|date',
            'reference' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0',
            'note' => 'nullable|string|max:1000',
            'sale_return_id' => 'required|integer|exists:sale_returns,id',
            'payment_method' => 'required|string|in:Cash,Credit Card,Bank Transfer,Cheque,Other'
        ]);

        // Amounts are converted to minor units exactly once, here; the service only deals in integers.
        // Overpayment is rejected by the service (amount may not exceed the outstanding balance).
        app(LedgerService::class)->recordReturnPayment('sale_return', (int) $request->sale_return_id, money_to_minor($request->amount), [
            'date' => $request->date, 'reference' => $request->reference, 'method' => $request->payment_method, 'note' => $request->note,
        ], auth()->id());

        toast('Sale Return Payment Created!', 'success');

        return redirect()->route('sale-returns.index');
    }


    /** Payments are never deleted: voiding keeps the record, restores the balance and reverses the ledger. */
    public function destroy(SaleReturnPayment $saleReturnPayment, Request $request) {
        abort_if(Gate::denies('access_sale_return_payments') || Gate::denies('void_payments'), 403);

        app(LedgerService::class)->voidPayment('sale_return', $saleReturnPayment->id, $request->input('reason', 'Voided by user'), auth()->id());

        toast('Sale Return Payment Voided!', 'warning');

        return redirect()->route('sale-returns.index');
    }
}
