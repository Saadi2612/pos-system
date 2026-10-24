<?php

namespace Modules\Purchase\Http\Controllers;

use Modules\Purchase\DataTables\PurchasePaymentsDataTable;
use App\Services\Ledger\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;

class PurchasePaymentsController extends Controller
{

    public function index($purchase_id, PurchasePaymentsDataTable $dataTable) {
        abort_if(Gate::denies('access_purchase_payments'), 403);

        $purchase = Purchase::findOrFail($purchase_id);

        return $dataTable->render('purchase::payments.index', compact('purchase'));
    }


    public function create($purchase_id) {
        abort_if(Gate::denies('access_purchase_payments'), 403);

        $purchase = Purchase::findOrFail($purchase_id);

        return view('purchase::payments.create', compact('purchase'));
    }


    public function store(Request $request) {
        abort_if(Gate::denies('access_purchase_payments'), 403);

        $request->validate([
            'date' => 'required|date',
            'reference' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0',
            'note' => 'nullable|string|max:1000',
            'purchase_id' => 'required|integer|exists:purchases,id',
            'payment_method' => 'required|string|in:Cash,Credit Card,Bank Transfer,Cheque,Other'
        ]);

        // Amounts are converted to minor units exactly once, here; the service only deals in integers.
        // Overpayment is rejected by the service (amount may not exceed the outstanding balance).
        app(LedgerService::class)->payInvoice('purchase', (int) $request->purchase_id, money_to_minor($request->amount), [
            'date' => $request->date, 'reference' => $request->reference, 'method' => $request->payment_method, 'note' => $request->note,
        ], auth()->id());

        toast('Purchase Payment Created!', 'success');

        return redirect()->route('purchases.index');
    }


    /** Payments are never deleted: voiding keeps the record, restores the balance and reverses the ledger. */
    public function destroy(PurchasePayment $purchasePayment, Request $request) {
        abort_if(Gate::denies('access_purchase_payments') || Gate::denies('void_payments'), 403);

        app(LedgerService::class)->voidPayment('purchase', $purchasePayment->id, $request->input('reason', 'Voided by user'), auth()->id());

        toast('Purchase Payment Voided!', 'warning');

        return redirect()->route('purchases.index');
    }
}
