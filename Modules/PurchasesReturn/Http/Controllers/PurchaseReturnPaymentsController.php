<?php

namespace Modules\PurchasesReturn\Http\Controllers;

use Modules\PurchasesReturn\DataTables\PurchaseReturnPaymentsDataTable;
use App\Services\Ledger\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnPayment;

class PurchaseReturnPaymentsController extends Controller
{

    public function index($purchase_return_id, PurchaseReturnPaymentsDataTable $dataTable) {
        abort_if(Gate::denies('access_purchase_return_payments'), 403);

        $purchase_return = PurchaseReturn::findOrFail($purchase_return_id);

        return $dataTable->render('purchasesreturn::payments.index', compact('purchase_return'));
    }


    public function create($purchase_return_id) {
        abort_if(Gate::denies('access_purchase_return_payments'), 403);

        $purchase_return = PurchaseReturn::findOrFail($purchase_return_id);

        return view('purchasesreturn::payments.create', compact('purchase_return'));
    }


    public function store(Request $request) {
        abort_if(Gate::denies('access_purchase_return_payments'), 403);

        $request->validate([
            'date' => 'required|date',
            'reference' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0',
            'note' => 'nullable|string|max:1000',
            'purchase_return_id' => 'required|integer|exists:purchase_returns,id',
            'payment_method' => 'required|string|in:Cash,Credit Card,Bank Transfer,Cheque,Other'
        ]);

        // Amounts are converted to minor units exactly once, here; the service only deals in integers.
        // Overpayment is rejected by the service (amount may not exceed the outstanding balance).
        app(LedgerService::class)->recordReturnPayment('purchase_return', (int) $request->purchase_return_id, money_to_minor($request->amount), [
            'date' => $request->date, 'reference' => $request->reference, 'method' => $request->payment_method, 'note' => $request->note,
        ], auth()->id());

        toast('Purchase Return Payment Created!', 'success');

        return redirect()->route('purchase-returns.index');
    }


    /** Payments are never deleted: voiding keeps the record, restores the balance and reverses the ledger. */
    public function destroy(PurchaseReturnPayment $purchaseReturnPayment, Request $request) {
        abort_if(Gate::denies('access_purchase_return_payments') || Gate::denies('void_payments'), 403);

        app(LedgerService::class)->voidPayment('purchase_return', $purchaseReturnPayment->id, $request->input('reason', 'Voided by user'), auth()->id());

        toast('Purchase Return Payment Voided!', 'warning');

        return redirect()->route('purchase-returns.index');
    }
}
