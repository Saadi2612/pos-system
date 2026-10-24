<?php

namespace Modules\SalesReturn\Http\Controllers;

use Modules\SalesReturn\DataTables\SaleReturnsDataTable;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Routing\Controller;
use App\Services\Ledger\DocumentWriter;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Modules\SalesReturn\Http\Requests\StoreSaleReturnRequest;
use Modules\SalesReturn\Http\Requests\UpdateSaleReturnRequest;

class SalesReturnController extends Controller
{

    public function index(SaleReturnsDataTable $dataTable) {
        abort_if(Gate::denies('access_sale_returns'), 403);

        return $dataTable->render('salesreturn::index');
    }


    public function create() {
        abort_if(Gate::denies('create_sale_returns'), 403);

        Cart::instance('sale_return')->destroy();

        return view('salesreturn::create');
    }


    public function store(StoreSaleReturnRequest $request) {
        app(DocumentWriter::class)->create('sale_return', 'sale_return', [
            'date' => $request->date,
            'party_id' => $request->customer_id,
            'sale_id' => $request->sale_id,
            'tax_percentage' => $request->tax_percentage,
            'discount_percentage' => $request->discount_percentage,
            'shipping_amount' => $request->shipping_amount,
            'status' => $request->status,
            'payment_method' => $request->payment_method,
            'payment_type' => 'partial',
            'paid_amount' => $request->paid_amount,
            'note' => $request->note,
        ], auth()->id());

        Cart::instance('sale_return')->destroy();

        toast('Sale Return Created!', 'success');

        return redirect()->route('sale-returns.index');
    }


    public function show(SaleReturn $sale_return) {
        abort_if(Gate::denies('show_sale_returns'), 403);

        $customer = Customer::findOrFail($sale_return->customer_id);

        return view('salesreturn::show', compact('sale_return', 'customer'));
    }


    public function edit(SaleReturn $sale_return) {
        abort_if(Gate::denies('edit_sale_returns'), 403);
        abort_if($sale_return->cancelled_at !== null, 403, 'Cancelled documents cannot be edited.');

        $sale_return_details = $sale_return->saleReturnDetails;

        Cart::instance('sale_return')->destroy();

        $cart = Cart::instance('sale_return');

        foreach ($sale_return_details as $sale_return_detail) {
            $cart->add([
                'id'      => $sale_return_detail->product_id,
                'name'    => $sale_return_detail->product_name,
                'qty'     => $sale_return_detail->quantity,
                'price'   => $sale_return_detail->price,
                'weight'  => 1,
                'options' => [
                    'product_discount' => $sale_return_detail->product_discount_amount,
                    'product_discount_type' => $sale_return_detail->product_discount_type,
                    'sub_total'   => $sale_return_detail->sub_total,
                    'code'        => $sale_return_detail->product_code,
                    'stock'       => Product::findOrFail($sale_return_detail->product_id)->product_quantity,
                    'product_tax' => $sale_return_detail->product_tax_amount,
                    'unit_price'  => $sale_return_detail->unit_price
                ]
            ]);
        }

        return view('salesreturn::edit', compact('sale_return'));
    }


    public function update(UpdateSaleReturnRequest $request, SaleReturn $sale_return) {
        app(DocumentWriter::class)->update('sale_return', $sale_return->id, 'sale_return', [
            'date' => $request->date,
            'party_id' => $request->customer_id,
            'tax_percentage' => $request->tax_percentage,
            'discount_percentage' => $request->discount_percentage,
            'shipping_amount' => $request->shipping_amount,
            'status' => $request->status,
            'note' => $request->note,
        ], auth()->id());

        Cart::instance('sale_return')->destroy();

        toast('Sale Return Updated!', 'info');

        return redirect()->route('sale-returns.index');
    }


    /** Posted returns are never hard-deleted: cancelling reverses stock, payments and the ledger. */
    public function destroy(SaleReturn $sale_return) {
        abort_if(Gate::denies('delete_sale_returns'), 403);

        app(LedgerService::class)->cancelDocument('sale_return', $sale_return->id, 'Sale return cancelled', auth()->id());

        toast('Sale Return Cancelled!', 'warning');

        return redirect()->route('sale-returns.index');
    }
}
