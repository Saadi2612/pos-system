<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('auth.login');
})->middleware('guest');

Auth::routes(['register' => false]);

Route::group(['middleware' => 'auth'], function () {
    Route::get('/home', 'HomeController@index')
        ->name('home');

    Route::get('/sales-purchases/chart-data', 'HomeController@salesPurchasesChart')
        ->name('sales-purchases.chart');

    Route::get('/current-month/chart-data', 'HomeController@currentMonthChart')
        ->name('current-month.chart');

    Route::get('/payment-flow/chart-data', 'HomeController@paymentChart')
        ->name('payment-flow.chart');
    // Customer / supplier accounts: payments, advances, opening balances
    Route::post('/customers/{customer}/receipts', 'PartyAccountController@receiveCustomerPayment')->name('customers.receipts.store');
    Route::post('/customer-receipts/{receipt}/allocate', 'PartyAccountController@allocateCustomerReceipt')->name('customer-receipts.allocate');
    Route::delete('/customer-receipts/{receipt}', 'PartyAccountController@voidCustomerReceipt')->name('customer-receipts.void');
    Route::post('/customers/{customer}/opening-balance', 'PartyAccountController@setCustomerOpeningBalance')->name('customers.opening-balance');

    Route::post('/suppliers/{supplier}/payments', 'PartyAccountController@paySupplier')->name('suppliers.payments.store');
    Route::post('/supplier-payments/{payment}/allocate', 'PartyAccountController@allocateSupplierPayment')->name('supplier-payments.allocate');
    Route::delete('/supplier-payments/{payment}', 'PartyAccountController@voidSupplierPayment')->name('supplier-payments.void');
    Route::post('/suppliers/{supplier}/opening-balance', 'PartyAccountController@setSupplierOpeningBalance')->name('suppliers.opening-balance');
});
