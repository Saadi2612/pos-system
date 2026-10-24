{{-- Shared customer / supplier account panel. Expects: $type ('customer'|'supplier'), $party, $account --}}
@php
    $isCustomer = $type === 'customer';
    $payPermission = $isCustomer ? 'receive_customer_payments' : 'make_supplier_payments';
    $payRoute = $isCustomer ? route('customers.receipts.store', $party->id) : route('suppliers.payments.store', $party->id);
    $openingRoute = $isCustomer ? route('customers.opening-balance', $party->id) : route('suppliers.opening-balance', $party->id);
    $payLabel = $isCustomer ? 'Receive Payment' : 'Make Payment';
    $balance = $account['balance'];
    $owes = $balance > 0;
@endphp

<div class="row mt-4">
    <div class="col-12">@include('utils.alerts')</div>

    <div class="col-md-3 mb-3">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted">{{ $isCustomer ? 'Account Balance' : 'Payable Balance' }}</div>
            <h4 class="{{ $owes ? 'text-danger' : 'text-success' }}">{{ balance_label($type, $balance) }}</h4>
            <small class="text-muted">
                @if($balance === 0) Settled
                @elseif($isCustomer) {{ $owes ? 'Customer owes us (debit balance)' : 'Customer has a credit / advance' }}
                @else {{ $owes ? 'We owe the supplier (credit balance)' : 'Supplier holds a debit / advance' }}
                @endif
            </small>
        </div></div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted">{{ $isCustomer ? 'Outstanding Invoices' : 'Outstanding Purchases' }}</div>
            <h4>{{ format_minor($account['outstandingTotal']) }}</h4>
            <small class="text-muted">{{ $account['invoices']->count() }} open document(s)</small>
        </div></div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted">Overdue</div>
            <h4 class="{{ $account['overdue'] > 0 ? 'text-danger' : '' }}">{{ format_minor($account['overdue']) }}</h4>
            <small class="text-muted">Past their due date</small>
        </div></div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted">Unallocated Advance</div>
            <h4>{{ format_minor($account['advance']) }}</h4>
            @if($isCustomer && $party->getRawOriginal('credit_limit') !== null)
                <small class="{{ $balance > $party->getRawOriginal('credit_limit') ? 'text-danger' : 'text-muted' }}">
                    Credit limit {{ format_minor($party->getRawOriginal('credit_limit')) }}
                    @if($balance > $party->getRawOriginal('credit_limit')) (exceeded) @endif
                </small>
            @endif
            @if((int) $party->opening_balance !== 0)
                <div><small class="text-muted">Opening balance {{ balance_label($type, (int) $party->opening_balance) }}
                    ({{ \Illuminate\Support\Carbon::parse($party->opening_balance_date)->format('d M, Y') }})</small></div>
            @endif
        </div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs" role="tablist">
            <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tab-invoices">Outstanding</a></li>
            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-payments">Payment History</a></li>
            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-ledger">Ledger</a></li>
            @can($payPermission)
                <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-pay">{{ $payLabel }}</a></li>
            @endcan
            @can('manage_opening_balances')
                <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tab-opening">Opening Balance</a></li>
            @endcan
        </ul>
    </div>
    <div class="card-body tab-content">

        {{-- Outstanding --}}
        <div class="tab-pane fade show active" id="tab-invoices">
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead><tr>
                        <th>Reference</th><th>Date</th><th>Due Date</th><th class="text-right">Total</th>
                        <th class="text-right">Paid</th><th class="text-right">Due</th><th>Status</th><th>Overdue</th>
                    </tr></thead>
                    <tbody>
                    @forelse($account['invoices'] as $inv)
                        <tr>
                            <td><a href="{{ route($isCustomer ? 'sales.show' : 'purchases.show', $inv->id) }}">{{ $inv->reference }}</a></td>
                            <td>{{ \Illuminate\Support\Carbon::parse($inv->date)->format('d M, Y') }}</td>
                            <td>{{ $inv->due_date ? \Illuminate\Support\Carbon::parse($inv->due_date)->format('d M, Y') : '-' }}</td>
                            <td class="text-right">{{ format_minor($inv->total) }}</td>
                            <td class="text-right">{{ format_minor($inv->paid) }}</td>
                            <td class="text-right">{{ format_minor($inv->due) }}</td>
                            <td><span class="badge badge-{{ $inv->payment_status === 'Partial' ? 'warning' : 'danger' }}">{{ $inv->payment_status }}</span></td>
                            <td>@if($inv->days_overdue > 0)<span class="text-danger">{{ $inv->days_overdue }} day(s)</span>@else - @endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted">Nothing outstanding.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Payment history --}}
        <div class="tab-pane fade" id="tab-payments">
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead><tr>
                        <th>Date</th><th>Reference</th><th>Method</th><th class="text-right">Amount</th>
                        <th class="text-right">Allocated</th><th class="text-right">Unallocated</th><th>Status</th><th></th>
                    </tr></thead>
                    <tbody>
                    @forelse($account['payments'] as $pay)
                        <tr class="{{ $pay->voided_at ? 'text-muted' : '' }}">
                            <td>{{ \Illuminate\Support\Carbon::parse($pay->date)->format('d M, Y') }}</td>
                            <td>{{ $pay->reference }}</td>
                            <td>{{ $pay->payment_method }}</td>
                            <td class="text-right">{{ format_minor($pay->amount) }}</td>
                            <td class="text-right">{{ format_minor($pay->allocated) }}</td>
                            <td class="text-right">{{ format_minor($pay->unallocated) }}</td>
                            <td>@if($pay->voided_at)<span class="badge badge-secondary">Voided</span>@else<span class="badge badge-success">Active</span>@endif</td>
                            <td style="min-width: 260px;">
                                @if(!$pay->voided_at)
                                    @can($payPermission)
                                        @if($pay->unallocated > 0 && $account['invoices']->count())
                                            <form class="form-inline mb-1 allocate-form" method="POST"
                                                  action="{{ route($isCustomer ? 'customer-receipts.allocate' : 'supplier-payments.allocate', $pay->id) }}">
                                                @csrf
                                                <select class="form-control form-control-sm mr-1 allocate-invoice">
                                                    @foreach($account['invoices'] as $inv)
                                                        <option value="{{ $inv->id }}">{{ $inv->reference }} ({{ format_minor($inv->due) }})</option>
                                                    @endforeach
                                                </select>
                                                <input type="number" step="0.01" min="0.01" class="form-control form-control-sm mr-1 allocate-amount"
                                                       style="width: 90px;" value="{{ min($pay->unallocated, $account['invoices']->first()->due) / 100 }}" required>
                                                <button class="btn btn-sm btn-outline-primary">Allocate</button>
                                            </form>
                                        @endif
                                    @endcan
                                    @can('void_payments')
                                        <form method="POST" class="void-form d-inline"
                                              action="{{ route($isCustomer ? 'customer-receipts.void' : 'supplier-payments.void', $pay->id) }}">
                                            @csrf @method('delete')
                                            <input type="hidden" name="reason">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Void</button>
                                        </form>
                                    @endcan
                                @else
                                    <small>{{ $pay->note }}</small>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted">No payments yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Ledger --}}
        <div class="tab-pane fade" id="tab-ledger">
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead><tr>
                        <th>Date</th><th>Type</th><th>Reference</th><th>Description</th>
                        <th class="text-right">Debit</th><th class="text-right">Credit</th><th class="text-right">Balance</th>
                    </tr></thead>
                    <tbody>
                    @forelse($account['ledger']['rows'] as $e)
                        <tr class="{{ $e->entry_type === 'reversal' ? 'text-muted' : '' }}">
                            <td>{{ \Illuminate\Support\Carbon::parse($e->entry_date)->format('d M, Y') }}</td>
                            <td>{{ ucfirst($e->entry_type) }}</td>
                            <td>{{ $e->reference }}</td>
                            <td>{{ $e->description }}</td>
                            <td class="text-right">{{ $e->debit ? format_minor($e->debit) : '' }}</td>
                            <td class="text-right">{{ $e->credit ? format_minor($e->credit) : '' }}</td>
                            <td class="text-right">{{ balance_label($type, $e->running) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted">No ledger entries.</td></tr>
                    @endforelse
                    </tbody>
                    <tfoot><tr>
                        <th colspan="6" class="text-right">Closing balance</th>
                        <th class="text-right">{{ balance_label($type, $account['ledger']['closing']) }}</th>
                    </tr></tfoot>
                </table>
            </div>
            <small class="text-muted">
                {{ $isCustomer ? 'Customer: Dr = customer owes us, Cr = customer credit/advance.' : 'Supplier: Cr = we owe the supplier, Dr = supplier advance.' }}
                Ledger entries are append-only; corrections appear as reversals.
            </small>
        </div>

        {{-- Pay --}}
        @can($payPermission)
        <div class="tab-pane fade" id="tab-pay">
            <form method="POST" action="{{ $payRoute }}">
                @csrf
                <div class="form-row">
                    <div class="form-group col-md-3"><label>Date *</label>
                        <input type="date" class="form-control" name="date" required value="{{ now()->format('Y-m-d') }}"></div>
                    <div class="form-group col-md-3"><label>Amount *</label>
                        <input type="number" step="0.01" min="0.01" class="form-control" name="amount" required></div>
                    <div class="form-group col-md-3"><label>Method *</label>
                        <select class="form-control" name="payment_method" required>
                            @foreach(['Cash','Credit Card','Bank Transfer','Cheque','Other'] as $m)<option>{{ $m }}</option>@endforeach
                        </select></div>
                    <div class="form-group col-md-3"><label>Reference</label>
                        <input type="text" class="form-control" name="reference" placeholder="Auto if empty"></div>
                </div>
                <div class="form-group"><label>Note</label><textarea class="form-control" name="note" rows="2"></textarea></div>

                @if($account['invoices']->count())
                    <div class="custom-control custom-checkbox mb-2">
                        <input type="checkbox" class="custom-control-input" id="auto_allocate" name="auto_allocate" value="1" checked>
                        <label class="custom-control-label" for="auto_allocate">Auto-allocate to the oldest outstanding documents (otherwise use the amounts below)</label>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead><tr><th>Reference</th><th>Due Date</th><th class="text-right">Due</th><th style="width:160px;">Allocate</th></tr></thead>
                            <tbody>
                            @foreach($account['invoices'] as $inv)
                                <tr><td>{{ $inv->reference }}</td>
                                    <td>{{ $inv->due_date ? \Illuminate\Support\Carbon::parse($inv->due_date)->format('d M, Y') : '-' }}</td>
                                    <td class="text-right">{{ format_minor($inv->due) }}</td>
                                    <td><input type="number" step="0.01" min="0" max="{{ $inv->due / 100 }}" class="form-control form-control-sm" name="allocations[{{ $inv->id }}]"></td></tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <p class="text-muted">Any part of the amount that is not allocated is kept as an advance and can be allocated later.</p>
                <button class="btn btn-primary">{{ $payLabel }} <i class="bi bi-check"></i></button>
            </form>
        </div>
        @endcan

        @can('manage_opening_balances')
        <div class="tab-pane fade" id="tab-opening">
            <form method="POST" action="{{ $openingRoute }}">
                @csrf
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Opening balance *</label>
                        <input type="number" step="0.01" class="form-control" name="opening_balance" required value="{{ $party->opening_balance / 100 }}">
                        <small class="text-muted">
                            {{ $isCustomer ? 'Positive = customer owes us (debit). Negative = customer credit.' : 'Positive = we owe the supplier (credit). Negative = supplier advance.' }}
                            Changing it posts a reversal and a new entry.
                        </small>
                    </div>
                    <div class="form-group col-md-4"><label>As of date *</label>
                        <input type="date" class="form-control" name="date" required value="{{ $party->opening_balance_date ?: now()->format('Y-m-d') }}"></div>
                </div>
                <button class="btn btn-primary">Save Opening Balance</button>
            </form>
        </div>
        @endcan
    </div>
</div>

@push('page_scripts')
<script>
    $(function () {
        $('.allocate-form').on('submit', function () {
            var f = $(this), id = f.find('.allocate-invoice').val();
            f.append($('<input type="hidden">').attr('name', 'allocations[' + id + ']').val(f.find('.allocate-amount').val()));
        });
        $('.void-form').on('submit', function (e) {
            var reason = prompt('Reason for voiding this payment? (its allocations are released and the ledger is reversed)');
            if (!reason) { e.preventDefault(); return; }
            $(this).find('input[name=reason]').val(reason);
        });
    });
</script>
@endpush
