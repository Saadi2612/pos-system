<?php

namespace App\Livewire\Reports;

use App\Services\Ledger\CreditQueries;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/** Receivables (customer) / payables (supplier) ageing. Documents without a due date count as "current". */
class AgeingReport extends Component
{
    public $type = 'customer';
    public $party_id = '';
    public $as_of;

    public function mount($type = 'customer')
    {
        $this->type = $type === 'supplier' ? 'supplier' : 'customer';
        $this->as_of = today()->format('Y-m-d');
    }

    public function render()
    {
        $rows = app(CreditQueries::class)->ageing($this->type, $this->as_of ?: null, $this->party_id ? (int) $this->party_id : null);

        $parties = $this->type === 'customer'
            ? DB::table('customers')->orderBy('customer_name')->get(['id', 'customer_name as name'])
            : DB::table('suppliers')->orderBy('supplier_name')->get(['id', 'supplier_name as name']);

        return view('livewire.reports.ageing-report', ['rows' => $rows, 'parties' => $parties, 'buckets' => CreditQueries::BUCKETS]);
    }
}
