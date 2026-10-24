<?php

namespace App\Livewire\Reports;

use App\Services\Ledger\CreditQueries;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class PartyLedgerReport extends Component
{
    public $type = 'customer';
    public $party_id = '';
    public $from = '';
    public $to = '';

    public function mount($type = 'customer')
    {
        $this->type = $type === 'supplier' ? 'supplier' : 'customer';
        $this->from = today()->startOfMonth()->format('Y-m-d');
        $this->to = today()->format('Y-m-d');
    }

    public function render()
    {
        $ledger = $this->party_id
            ? app(CreditQueries::class)->ledger($this->type, (int) $this->party_id, $this->from ?: null, $this->to ?: null)
            : null;

        $parties = $this->type === 'customer'
            ? DB::table('customers')->orderBy('customer_name')->get(['id', 'customer_name as name'])
            : DB::table('suppliers')->orderBy('supplier_name')->get(['id', 'supplier_name as name']);

        return view('livewire.reports.party-ledger-report', ['ledger' => $ledger, 'parties' => $parties]);
    }
}
