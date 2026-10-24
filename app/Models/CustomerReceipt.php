<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\SalePayment;

/** Money received from a customer. `amount` is stored in minor units (raw column). */
class CustomerReceipt extends Model
{
    protected $guarded = [];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function allocations()
    {
        return $this->hasMany(SalePayment::class, 'receipt_id');
    }

    public function getAmountAttribute($value)
    {
        return $value / 100;
    }

    public function getAmountMinorAttribute(): int
    {
        return (int) $this->getRawOriginal('amount');
    }

    public function getIsVoidedAttribute(): bool
    {
        return $this->voided_at !== null;
    }
}
