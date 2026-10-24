<div>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="form-row">
                <div class="form-group col-lg-3">
                    <label>{{ $type === 'customer' ? 'Customer' : 'Supplier' }}</label>
                    <select wire:model.live="party_id" class="form-control">
                        <option value="">All</option>
                        @foreach($parties as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                    </select>
                </div>
                <div class="form-group col-lg-3"><label>Invoice date from</label><input type="date" wire:model.live="invoice_from" class="form-control"></div>
                <div class="form-group col-lg-3"><label>Invoice date to</label><input type="date" wire:model.live="invoice_to" class="form-control"></div>
                <div class="form-group col-lg-3"><label>Ageing as of</label><input type="date" wire:model.live="as_of" class="form-control"></div>
            </div>
            <div class="form-row">
                <div class="form-group col-lg-3"><label>Due date from</label><input type="date" wire:model.live="due_from" class="form-control"></div>
                <div class="form-group col-lg-3"><label>Due date to</label><input type="date" wire:model.live="due_to" class="form-control"></div>
                <div class="form-group col-lg-3"><label>Status</label>
                    <select wire:model.live="status" class="form-control">
                        <option value="">Any</option><option value="Unpaid">Unpaid</option><option value="Partial">Partial</option>
                    </select>
                </div>
                <div class="form-group col-lg-3">
                    <label class="d-block">&nbsp;</label>
                    <div class="custom-control custom-checkbox mt-2">
                        <input type="checkbox" class="custom-control-input" id="overdue_only_{{ $type }}" wire:model.live="overdue_only">
                        <label class="custom-control-label" for="overdue_only_{{ $type }}">Overdue only</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-striped mb-0">
                <thead><tr>
                    <th>Reference</th><th>{{ $type === 'customer' ? 'Customer' : 'Supplier' }}</th><th>Date</th><th>Due Date</th>
                    <th class="text-right">Total</th><th class="text-right">Paid</th><th class="text-right">Outstanding</th><th>Status</th><th>Days overdue</th>
                </tr></thead>
                <tbody>
                @forelse($rows as $r)
                    <tr>
                        <td>{{ $r->reference }}</td><td>{{ $r->party_name }}</td>
                        <td>{{ \Carbon\Carbon::parse($r->date)->format('d M, Y') }}</td>
                        <td>{{ $r->due_date ? \Carbon\Carbon::parse($r->due_date)->format('d M, Y') : '-' }}</td>
                        <td class="text-right">{{ format_minor($r->total) }}</td>
                        <td class="text-right">{{ format_minor($r->paid) }}</td>
                        <td class="text-right">{{ format_minor($r->due) }}</td>
                        <td>{{ $r->payment_status }}</td>
                        <td class="{{ $r->days_overdue > 0 ? 'text-danger' : '' }}">{{ $r->days_overdue ?: '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted">Nothing outstanding.</td></tr>
                @endforelse
                </tbody>
                @if($rows->isNotEmpty())
                    <tfoot><tr><th colspan="6" class="text-right">Total outstanding</th><th class="text-right">{{ format_minor($rows->sum('due')) }}</th><th colspan="2"></th></tr></tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
