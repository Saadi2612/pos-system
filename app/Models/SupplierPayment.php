<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PurchasePayment;

/** Money paid to a supplier. `amount` is stored in minor units (raw column). */
class SupplierPayment extends Model
{
    protected $guarded = [];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function allocations()
    {
        return $this->hasMany(PurchasePayment::class, 'receipt_id');
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
