<?php

namespace Modules\Sale\Http\Controllers;

use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Services\Ledger\DocumentWriter;
use App\Services\Ledger\LedgerService;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Sale\Http\Requests\StorePosSaleRequest;

class PosController extends Controller
{

    public function index() {
        Cart::instance('sale')->destroy();

        $customers = Customer::all();
        $product_categories = Category::all();

        return view('sale::pos.index', compact('product_categories', 'customers'));
    }


    public function store(StorePosSaleRequest $request) {
        app(DocumentWriter::class)->create('sale', 'sale', [
            'date' => now()->format('Y-m-d'),
            'party_id' => $request->customer_id,
            'tax_percentage' => $request->tax_percentage,
            'discount_percentage' => $request->discount_percentage,
            'shipping_amount' => $request->shipping_amount,
            'status' => 'Completed',
            'payment_method' => $request->payment_method,
            'payment_type' => $request->payment_type,
            'paid_amount' => $request->paid_amount,
            'due_date' => $request->due_date,
            'note' => $request->note,
        ], auth()->id());

        Cart::instance('sale')->destroy();

        $message = 'POS Sale Created!';
        $customer = Customer::find($request->customer_id);
        if ($customer && $customer->credit_limit !== null
            && app(LedgerService::class)->getCustomerBalance($customer->id) > $customer->getRawOriginal('credit_limit')) {
            toast($message . ' Warning: customer is over the credit limit.', 'warning');

            return redirect()->route('sales.index');
        }

        toast($message, 'success');

        return redirect()->route('sales.index');
    }
}
