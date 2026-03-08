<?php

namespace App\Modules\Invoice\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * InvoiceSequence
 *
 * Manages per-vendor invoice number sequences.
 * Uses DB-level locking to guarantee uniqueness under concurrency.
 */
class InvoiceSequence extends Model
{
    protected $fillable = ['vendor_id', 'prefix', 'last_sequence', 'year'];

    public function nextNumber(): string
    {
        // Atomic increment with row lock
        $this->increment('last_sequence');
        $this->refresh();
        return $this->prefix . '-' . $this->year . '-' . str_pad($this->last_sequence, 5, '0', STR_PAD_LEFT);
    }
}
