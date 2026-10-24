<?php

namespace App\Livewire\Reports;

use App\Services\Ledger\CreditQueries;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/** Customer receipt history (type=customer) / supplier payment history (type=supplier). */
class PartyPaymentsReport extends Component
{
    public $type = 'customer';
    public $party_id = '';
    public $from = '';
    public $to = '';
    public $method = '';
    public $include_voided = false;

    public function mount($type = 'customer')
    {
        $this->type = $type === 'supplier' ? 'supplier' : 'customer';
        $this->from = today()->subDays(30)->format('Y-m-d');
        $this->to = today()->format('Y-m-d');
    }

    public function render()
    {
        $rows = app(CreditQueries::class)->payments($this->type, [
            'party_id' => $this->party_id ?: null, 'from' => $this->from ?: null, 'to' => $this->to ?: null,
            'method' => $this->method ?: null, 'include_voided' => $this->include_voided,
        ]);

        $parties = $this->type === 'customer'
            ? DB::table('customers')->orderBy('customer_name')->get(['id', 'customer_name as name'])
            : DB::table('suppliers')->orderBy('supplier_name')->get(['id', 'supplier_name as name']);

        return view('livewire.reports.party-payments-report', ['rows' => $rows, 'parties' => $parties]);
    }
}
