<?php

namespace Modules\Purchase\Http\Controllers;

use Modules\Purchase\DataTables\PurchaseDataTable;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Routing\Controller;
use App\Services\Ledger\DocumentWriter;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Purchase\Http\Requests\StorePurchaseRequest;
use Modules\Purchase\Http\Requests\UpdatePurchaseRequest;

class PurchaseController extends Controller
{

    public function index(PurchaseDataTable $dataTable) {
        abort_if(Gate::denies('access_purchases'), 403);

        return $dataTable->render('purchase::index');
    }


    public function create() {
        abort_if(Gate::denies('create_purchases'), 403);

        Cart::instance('purchase')->destroy();

        return view('purchase::create');
    }


    public function store(StorePurchaseRequest $request) {
        app(DocumentWriter::class)->create('purchase', 'purchase', [
            'date' => $request->date,
            'party_id' => $request->supplier_id,
            'supplier_reference' => $request->supplier_reference,
            'tax_percentage' => $request->tax_percentage,
            'discount_percentage' => $request->discount_percentage,
            'shipping_amount' => $request->shipping_amount,
            'status' => $request->status,
            'payment_method' => $request->payment_method,
            'payment_type' => $request->payment_type,
            'paid_amount' => $request->paid_amount,
            'due_date' => $request->due_date,
            'note' => $request->note,
        ], auth()->id());

        Cart::instance('purchase')->destroy();

        toast('Purchase Created!', 'success');

        return redirect()->route('purchases.index');
    }


    public function show(Purchase $purchase) {
        abort_if(Gate::denies('show_purchases'), 403);

        $supplier = Supplier::findOrFail($purchase->supplier_id);

        return view('purchase::show', compact('purchase', 'supplier'));
    }


    public function edit(Purchase $purchase) {
        abort_if(Gate::denies('edit_purchases'), 403);
        abort_if($purchase->cancelled_at !== null, 403, 'Cancelled documents cannot be edited.');

        $purchase_details = $purchase->purchaseDetails;

        Cart::instance('purchase')->destroy();

        $cart = Cart::instance('purchase');

        foreach ($purchase_details as $purchase_detail) {
            $cart->add([
                'id'      => $purchase_detail->product_id,
                'name'    => $purchase_detail->product_name,
                'qty'     => $purchase_detail->quantity,
                'price'   => $purchase_detail->price,
                'weight'  => 1,
                'options' => [
                    'product_discount' => $purchase_detail->product_discount_amount,
                    'product_discount_type' => $purchase_detail->product_discount_type,
                    'sub_total'   => $purchase_detail->sub_total,
                    'code'        => $purchase_detail->product_code,
                    'stock'       => Product::findOrFail($purchase_detail->product_id)->product_quantity,
                    'product_tax' => $purchase_detail->product_tax_amount,
                    'unit_price'  => $purchase_detail->unit_price
                ]
            ]);
        }

        return view('purchase::edit', compact('purchase'));
    }


    public function update(UpdatePurchaseRequest $request, Purchase $purchase) {
        app(DocumentWriter::class)->update('purchase', $purchase->id, 'purchase', [
            'date' => $request->date,
            'party_id' => $request->supplier_id,
            'supplier_reference' => $request->supplier_reference,
            'tax_percentage' => $request->tax_percentage,
            'discount_percentage' => $request->discount_percentage,
            'shipping_amount' => $request->shipping_amount,
            'status' => $request->status,
            'due_date' => $request->due_date,
            'note' => $request->note,
        ], auth()->id());

        Cart::instance('purchase')->destroy();

        toast('Purchase Updated!', 'info');

        return redirect()->route('purchases.index');
    }


    /** Posted purchases are never hard-deleted: cancelling reverses stock, payment allocations and the ledger. */
    public function destroy(Purchase $purchase) {
        abort_if(Gate::denies('delete_purchases'), 403);

        app(LedgerService::class)->cancelDocument('purchase', $purchase->id, 'Purchase cancelled', auth()->id());

        toast('Purchase Cancelled!', 'warning');

        return redirect()->route('purchases.index');
    }
}
