<?php

namespace Modules\Sale\Http\Controllers;

use Modules\Sale\DataTables\SalesDataTable;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use App\Services\Ledger\DocumentWriter;
use App\Services\Ledger\LedgerService;
use Modules\Sale\Http\Requests\StoreSaleRequest;
use Modules\Sale\Http\Requests\UpdateSaleRequest;

class SaleController extends Controller
{

    public function index(SalesDataTable $dataTable) {
        abort_if(Gate::denies('access_sales'), 403);

        return $dataTable->render('sale::index');
    }


    public function create() {
        abort_if(Gate::denies('create_sales'), 403);

        Cart::instance('sale')->destroy();

        return view('sale::create');
    }


    public function store(StoreSaleRequest $request) {
        $sale_id = app(DocumentWriter::class)->create('sale', 'sale', [
            'date' => $request->date,
            'party_id' => $request->customer_id,
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

        Cart::instance('sale')->destroy();

        $message = 'Sale Created!';
        $customer = Customer::find($request->customer_id);
        if ($customer && $customer->credit_limit !== null) {
            $balance = app(LedgerService::class)->getCustomerBalance($customer->id);
            if ($balance > $customer->getRawOriginal('credit_limit')) {
                $message .= ' Warning: customer is over the credit limit.';
                toast($message, 'warning');

                return redirect()->route('sales.index');
            }
        }

        toast($message, 'success');

        return redirect()->route('sales.index');
    }


    public function show(Sale $sale) {
        abort_if(Gate::denies('show_sales'), 403);

        $customer = Customer::findOrFail($sale->customer_id);

        return view('sale::show', compact('sale', 'customer'));
    }


    public function edit(Sale $sale) {
        abort_if(Gate::denies('edit_sales'), 403);
        abort_if($sale->cancelled_at !== null, 403, 'Cancelled documents cannot be edited.');

        $sale_details = $sale->saleDetails;

        Cart::instance('sale')->destroy();

        $cart = Cart::instance('sale');

        foreach ($sale_details as $sale_detail) {
            $cart->add([
                'id'      => $sale_detail->product_id,
                'name'    => $sale_detail->product_name,
                'qty'     => $sale_detail->quantity,
                'price'   => $sale_detail->price,
                'weight'  => 1,
                'options' => [
                    'product_discount' => $sale_detail->product_discount_amount,
                    'product_discount_type' => $sale_detail->product_discount_type,
                    'sub_total'   => $sale_detail->sub_total,
                    'code'        => $sale_detail->product_code,
                    'stock'       => Product::findOrFail($sale_detail->product_id)->product_quantity,
                    'product_tax' => $sale_detail->product_tax_amount,
                    'unit_price'  => $sale_detail->unit_price
                ]
            ]);
        }

        return view('sale::edit', compact('sale'));
    }


    public function update(UpdateSaleRequest $request, Sale $sale) {
        app(DocumentWriter::class)->update('sale', $sale->id, 'sale', [
            'date' => $request->date,
            'party_id' => $request->customer_id,
            'tax_percentage' => $request->tax_percentage,
            'discount_percentage' => $request->discount_percentage,
            'shipping_amount' => $request->shipping_amount,
            'status' => $request->status,
            'due_date' => $request->due_date,
            'note' => $request->note,
        ], auth()->id());

        Cart::instance('sale')->destroy();

        toast('Sale Updated!', 'info');

        return redirect()->route('sales.index');
    }


    /** Posted sales are never hard-deleted: cancelling reverses stock, payments allocations and the ledger. */
    public function destroy(Sale $sale) {
        abort_if(Gate::denies('delete_sales'), 403);

        app(LedgerService::class)->cancelDocument('sale', $sale->id, 'Sale cancelled', auth()->id());

        toast('Sale Cancelled!', 'warning');

        return redirect()->route('sales.index');
    }
}
