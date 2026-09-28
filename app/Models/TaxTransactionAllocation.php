<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxTransactionAllocation extends Model
{
    protected $fillable = [
        'tax_transaction_id','project_id','taxable_amount','tax_amount','net_amount',
    ];

    protected $casts = [
        'tax_transaction_id' => 'integer',
        'project_id' => 'integer',
        'taxable_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];

    public function taxTransaction() { return $this->belongsTo(TaxTransaction::class); }
    public function project() { return $this->belongsTo(Project::class); }
}
