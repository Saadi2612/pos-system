<?php

namespace App\Livewire\Reports;

use Livewire\Component;
use Livewire\WithPagination;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\PurchasesReturn\Entities\PurchaseReturnPayment;
use App\Models\CustomerReceipt;
use App\Models\SupplierPayment;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SaleReturnPayment;

class PaymentsReport extends Component
{

    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $start_date;
    public $end_date;
    public $payments;
    public $payment_method;

    protected $rules = [
        'start_date' => 'required|date|before:end_date',
        'end_date'   => 'required|date|after:start_date',
        'payments'   => 'required|string'
    ];
    protected $query;

    public function mount() {
        $this->start_date = today()->subDays(30)->format('Y-m-d');
        $this->end_date = today()->format('Y-m-d');
        $this->payments = '';
        $this->query = null;
    }

    public function render() {
        $this->getQuery();

        return view('livewire.reports.payments-report', [
            'information' => $this->query ? $this->query->orderBy('date', 'desc')
                ->when($this->start_date, function ($query) {
                    return $query->whereDate('date', '>=', $this->start_date);
                })
                ->when($this->end_date, function ($query) {
                    return $query->whereDate('date', '<=', $this->end_date);
                })
                ->when($this->payment_method, function ($query) {
                    return $query->where('payment_method', $this->payment_method);
                })
                ->paginate(10) : collect()
        ]);
    }

    public function generateReport() {
        $this->validate();
        $this->render();
    }

    public function updatedPayments($value) {
        $this->resetPage();
    }

    public function getQuery() {
        if ($this->payments == 'sale') {
            $this->query = SalePayment::query()->with('sale')->whereNull('voided_at');
        } elseif ($this->payments == 'sale_return') {
            $this->query = SaleReturnPayment::query()->with('saleReturn')->whereNull('voided_at');
        } elseif ($this->payments == 'purchase') {
            $this->query = PurchasePayment::query()->with('purchase')->whereNull('voided_at');
        } elseif ($this->payments == 'purchase_return') {
            $this->query = PurchaseReturnPayment::query()->with('purchaseReturn')->whereNull('voided_at');
        } elseif ($this->payments == 'customer_receipt') {
            $this->query = CustomerReceipt::query()->with('customer')->whereNull('voided_at');
        } elseif ($this->payments == 'supplier_payment') {
            $this->query = SupplierPayment::query()->with('supplier')->whereNull('voided_at');
        } else {
            $this->query = null;
        }
    }
}
