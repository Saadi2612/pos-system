<?php

namespace App\Http\Controllers;

use App\Models\CustomerReceipt;
use App\Models\SupplierPayment;
use App\Services\Ledger\CreditQueries;
use App\Services\Ledger\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;

/** Receiving customer payments, paying suppliers, allocating advances, voiding and opening balances. */
class PartyAccountController extends Controller
{
    private const METHODS = 'required|string|in:Cash,Credit Card,Bank Transfer,Cheque,Other';

    public function __construct(private LedgerService $ledger, private CreditQueries $queries)
    {
    }

    // ---- customers

    public function receiveCustomerPayment(Request $request, Customer $customer)
    {
        abort_if(Gate::denies('receive_customer_payments'), 403);

        $this->storePayment($request, 'customer', $customer->id);

        toast('Payment received!', 'success');

        return redirect()->route('customers.show', $customer->id);
    }

    public function allocateCustomerReceipt(Request $request, CustomerReceipt $receipt)
    {
        abort_if(Gate::denies('receive_customer_payments'), 403);

        $this->ledger->allocate('customer', $receipt->id, $this->allocationsFrom($request), auth()->id());

        toast('Payment allocated!', 'success');

        return redirect()->route('customers.show', $receipt->customer_id);
    }

    public function voidCustomerReceipt(Request $request, CustomerReceipt $receipt)
    {
        abort_if(Gate::denies('void_payments'), 403);
        $request->validate(['reason' => 'required|string|max:200']);

        $this->ledger->voidHeader('customer', $receipt->id, $request->reason, auth()->id());

        toast('Receipt voided!', 'warning');

        return redirect()->route('customers.show', $receipt->customer_id);
    }

    public function setCustomerOpeningBalance(Request $request, Customer $customer)
    {
        abort_if(Gate::denies('manage_opening_balances'), 403);

        $this->storeOpening($request, 'customer', $customer->id);

        toast('Opening balance saved!', 'success');

        return redirect()->route('customers.show', $customer->id);
    }

    // ---- suppliers

    public function paySupplier(Request $request, Supplier $supplier)
    {
        abort_if(Gate::denies('make_supplier_payments'), 403);

        $this->storePayment($request, 'supplier', $supplier->id);

        toast('Payment recorded!', 'success');

        return redirect()->route('suppliers.show', $supplier->id);
    }

    public function allocateSupplierPayment(Request $request, SupplierPayment $payment)
    {
        abort_if(Gate::denies('make_supplier_payments'), 403);

        $this->ledger->allocate('supplier', $payment->id, $this->allocationsFrom($request), auth()->id());

        toast('Payment allocated!', 'success');

        return redirect()->route('suppliers.show', $payment->supplier_id);
    }

    public function voidSupplierPayment(Request $request, SupplierPayment $payment)
    {
        abort_if(Gate::denies('void_payments'), 403);
        $request->validate(['reason' => 'required|string|max:200']);

        $this->ledger->voidHeader('supplier', $payment->id, $request->reason, auth()->id());

        toast('Payment voided!', 'warning');

        return redirect()->route('suppliers.show', $payment->supplier_id);
    }

    public function setSupplierOpeningBalance(Request $request, Supplier $supplier)
    {
        abort_if(Gate::denies('manage_opening_balances'), 403);

        $this->storeOpening($request, 'supplier', $supplier->id);

        toast('Opening balance saved!', 'success');

        return redirect()->route('suppliers.show', $supplier->id);
    }

    // ---- shared

    private function storePayment(Request $request, string $type, int $partyId): void
    {
        $request->validate([
            'date' => 'required|date',
            'amount' => 'required|numeric|gt:0',
            'payment_method' => self::METHODS,
            'reference' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:1000',
            'auto_allocate' => 'nullable|boolean',
            'allocations' => 'nullable|array',
            'allocations.*' => 'nullable|numeric|min:0',
        ]);

        $amount = money_to_minor($request->amount);
        $allocations = $this->allocationsFrom($request);

        if ($request->boolean('auto_allocate') && !$allocations) {
            $left = $amount;
            foreach ($this->queries->outstanding($type, ['party_id' => $partyId]) as $inv) {
                if ($left <= 0) {
                    break;
                }
                $take = min($left, $inv->due);
                $allocations[$inv->id] = $take;
                $left -= $take;
            }
        }

        $meta = ['date' => $request->date, 'method' => $request->payment_method, 'reference' => $request->reference, 'note' => $request->note];

        if ($type === 'customer') {
            $this->ledger->receiveCustomerPayment($partyId, $amount, $meta, $allocations, auth()->id());
        } else {
            $this->ledger->makeSupplierPayment($partyId, $amount, $meta, $allocations, auth()->id());
        }
    }

    private function allocationsFrom(Request $request): array
    {
        $out = [];
        foreach ((array) $request->input('allocations', []) as $invoiceId => $value) {
            $minor = money_to_minor($value);
            if ($minor > 0) {
                $out[(int) $invoiceId] = $minor;
            }
        }

        return $out;
    }

    private function storeOpening(Request $request, string $type, int $partyId): void
    {
        $request->validate([
            'opening_balance' => 'required|numeric|between:-9999999999,9999999999',
            'date' => 'required|date',
        ]);

        $this->ledger->postOpeningBalance($type, $partyId, money_to_minor($request->opening_balance), $request->date, auth()->id());
    }
}
