<?php

namespace Modules\PurchasesReturn\Http\Controllers;

use Modules\PurchasesReturn\DataTables\PurchaseReturnsDataTable;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Routing\Controller;
use App\Services\Ledger\DocumentWriter;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnPayment;
use Modules\PurchasesReturn\Http\Requests\StorePurchaseReturnRequest;
use Modules\PurchasesReturn\Http\Requests\UpdatePurchaseReturnRequest;

class PurchasesReturnController extends Controller
{

    public function index(PurchaseReturnsDataTable $dataTable) {
        abort_if(Gate::denies('access_purchase_returns'), 403);

        return $dataTable->render('purchasesreturn::index');
    }


    public function create() {
        abort_if(Gate::denies('create_purchase_returns'), 403);

        Cart::instance('purchase_return')->destroy();

        return view('purchasesreturn::create');
    }


    public function store(StorePurchaseReturnRequest $request) {
        app(DocumentWriter::class)->create('purchase_return', 'purchase_return', [
            'date' => $request->date,
            'party_id' => $request->supplier_id,
            'purchase_id' => $request->purchase_id,
            'tax_percentage' => $request->tax_percentage,
            'discount_percentage' => $request->discount_percentage,
            'shipping_amount' => $request->shipping_amount,
            'status' => $request->status,
            'payment_method' => $request->payment_method,
            'payment_type' => 'partial',
            'paid_amount' => $request->paid_amount,
            'note' => $request->note,
        ], auth()->id());

        Cart::instance('purchase_return')->destroy();

        toast('Purchase Return Created!', 'success');

        return redirect()->route('purchase-returns.index');
    }


    public function show(PurchaseReturn $purchase_return) {
        abort_if(Gate::denies('show_purchase_returns'), 403);

        $supplier = Supplier::findOrFail($purchase_return->supplier_id);

        return view('purchasesreturn::show', compact('purchase_return', 'supplier'));
    }


    public function edit(PurchaseReturn $purchase_return) {
        abort_if(Gate::denies('edit_purchase_returns'), 403);
        abort_if($purchase_return->cancelled_at !== null, 403, 'Cancelled documents cannot be edited.');

        $purchase_return_details = $purchase_return->purchaseReturnDetails;

        Cart::instance('purchase_return')->destroy();

        $cart = Cart::instance('purchase_return');

        foreach ($purchase_return_details as $purchase_return_detail) {
            $cart->add([
                'id'      => $purchase_return_detail->product_id,
                'name'    => $purchase_return_detail->product_name,
                'qty'     => $purchase_return_detail->quantity,
                'price'   => $purchase_return_detail->price,
                'weight'  => 1,
                'options' => [
                    'product_discount' => $purchase_return_detail->product_discount_amount,
                    'product_discount_type' => $purchase_return_detail->product_discount_type,
                    'sub_total'   => $purchase_return_detail->sub_total,
                    'code'        => $purchase_return_detail->product_code,
                    'stock'       => Product::findOrFail($purchase_return_detail->product_id)->product_quantity,
                    'product_tax' => $purchase_return_detail->product_tax_amount,
                    'unit_price'  => $purchase_return_detail->unit_price
                ]
            ]);
        }

        return view('purchasesreturn::edit', compact('purchase_return'));
    }


    public function update(UpdatePurchaseReturnRequest $request, PurchaseReturn $purchase_return) {
        app(DocumentWriter::class)->update('purchase_return', $purchase_return->id, 'purchase_return', [
            'date' => $request->date,
            'party_id' => $request->supplier_id,
            'tax_percentage' => $request->tax_percentage,
            'discount_percentage' => $request->discount_percentage,
            'shipping_amount' => $request->shipping_amount,
            'status' => $request->status,
            'note' => $request->note,
        ], auth()->id());

        Cart::instance('purchase_return')->destroy();

        toast('Purchase Return Updated!', 'info');

        return redirect()->route('purchase-returns.index');
    }


    /** Posted returns are never hard-deleted: cancelling reverses stock, payments and the ledger. */
    public function destroy(PurchaseReturn $purchase_return) {
        abort_if(Gate::denies('delete_purchase_returns'), 403);

        app(LedgerService::class)->cancelDocument('purchase_return', $purchase_return->id, 'Purchase return cancelled', auth()->id());

        toast('Purchase Return Cancelled!', 'warning');

        return redirect()->route('purchase-returns.index');
    }
}
