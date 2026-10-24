<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only party sub-ledger row. Amounts are integer minor units.
 * Corrections are made by posting a reversal entry (see LedgerService::reverseEntry).
 */
class LedgerEntry extends Model
{
    protected $guarded = [];

    protected $casts = [
        'debit' => 'integer',
        'credit' => 'integer',
    ];

    protected static function booted()
    {
        static::updating(function () {
            throw new LogicException('Ledger entries are append-only and cannot be modified.');
        });

        static::deleting(function () {
            throw new LogicException('Ledger entries are append-only and cannot be deleted.');
        });
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }

    public function scopeForParty($query, string $type, int $id)
    {
        return $query->where('party_type', $type)->where('party_id', $id);
    }
}
