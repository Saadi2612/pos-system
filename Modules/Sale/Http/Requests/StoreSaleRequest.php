<?php

namespace Modules\Sale\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreSaleRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'customer_id' => 'required|integer|exists:customers,id',
            'date' => 'required|date',
            'tax_percentage' => 'required|integer|min:0|max:100',
            'discount_percentage' => 'required|integer|min:0|max:100',
            'shipping_amount' => 'required|numeric|min:0',
            'status' => 'required|string|in:Pending,Shipped,Completed',
            'payment_type' => 'required|string|in:full,partial,credit',
            'paid_amount' => 'nullable|numeric|min:0',
            'due_date' => 'nullable|date|after_or_equal:date',
            'payment_method' => 'required|string|in:Cash,Credit Card,Bank Transfer,Cheque,Other',
            'note' => 'nullable|string|max:1000'
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return Gate::allows('create_sales');
    }
}
