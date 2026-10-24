<?php

namespace App\Livewire\Reports;

use App\Services\Ledger\CreditQueries;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/** Invoice-wise outstanding receivables (type=customer) / payables (type=supplier). */
class OutstandingInvoicesReport extends Component
{
    public $type = 'customer';
    public $party_id = '';
    public $invoice_from = '';
    public $invoice_to = '';
    public $due_from = '';
    public $due_to = '';
    public $status = '';
    public $overdue_only = false;
    public $as_of;

    public function mount($type = 'customer', $overdueOnly = false)
    {
        $this->type = $type === 'supplier' ? 'supplier' : 'customer';
        $this->overdue_only = (bool) $overdueOnly;
        $this->as_of = today()->format('Y-m-d');
    }

    public function render()
    {
        $rows = app(CreditQueries::class)->outstanding($this->type, [
            'party_id' => $this->party_id ?: null,
            'invoice_from' => $this->invoice_from ?: null,
            'invoice_to' => $this->invoice_to ?: null,
            'due_from' => $this->due_from ?: null,
            'due_to' => $this->due_to ?: null,
            'status' => $this->status ?: null,
            'overdue_only' => $this->overdue_only,
            'as_of' => $this->as_of ?: null,
        ]);

        return view('livewire.reports.outstanding-invoices-report', [
            'rows' => $rows,
            'parties' => $this->parties(),
        ]);
    }

    private function parties()
    {
        return $this->type === 'customer'
            ? DB::table('customers')->orderBy('customer_name')->get(['id', 'customer_name as name'])
            : DB::table('suppliers')->orderBy('supplier_name')->get(['id', 'supplier_name as name']);
    }
}
