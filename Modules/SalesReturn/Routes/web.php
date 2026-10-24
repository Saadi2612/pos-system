<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::group(['middleware' => 'auth'], function () {
    //Generate PDF
    Route::get('/sale-returns/pdf/{id}', function ($id) {
        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::findOrFail($id);
        $customer = \Modules\People\Entities\Customer::findOrFail($saleReturn->customer_id);

        $pdf = \PDF::loadView('salesreturn::print', [
            'sale_return' => $saleReturn,
            'customer' => $customer,
        ])->setPaper('a4');

        return $pdf->stream('sale-return-'. $saleReturn->reference .'.pdf');
    })->name('sale-returns.pdf');

    //Sale Returns
    Route::resource('sale-returns', 'SalesReturnController');

    //Payments
    Route::get('/sale-return-payments/{sale_return_id}', 'SaleReturnPaymentsController@index')
        ->name('sale-return-payments.index');
    Route::get('/sale-return-payments/{sale_return_id}/create', 'SaleReturnPaymentsController@create')
        ->name('sale-return-payments.create');
    Route::post('/sale-return-payments/store', 'SaleReturnPaymentsController@store')
        ->name('sale-return-payments.store');
    Route::delete('/sale-return-payments/destroy/{saleReturnPayment}', 'SaleReturnPaymentsController@destroy')
        ->name('sale-return-payments.destroy');
});
