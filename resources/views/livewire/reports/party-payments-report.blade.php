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
                <div class="form-group col-lg-2"><label>From</label><input type="date" wire:model.live="from" class="form-control"></div>
                <div class="form-group col-lg-2"><label>To</label><input type="date" wire:model.live="to" class="form-control"></div>
                <div class="form-group col-lg-3"><label>Method</label>
                    <select wire:model.live="method" class="form-control">
                        <option value="">Any</option>
                        @foreach(['Cash','Credit Card','Bank Transfer','Cheque','Other'] as $m)<option>{{ $m }}</option>@endforeach
                    </select>
                </div>
                <div class="form-group col-lg-2">
                    <label class="d-block">&nbsp;</label>
                    <div class="custom-control custom-checkbox mt-2">
                        <input type="checkbox" class="custom-control-input" id="inc_void_{{ $type }}" wire:model.live="include_voided">
                        <label class="custom-control-label" for="inc_void_{{ $type }}">Show voided</label>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card border-0 shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-striped mb-0">
                <thead><tr><th>Date</th><th>Reference</th><th>{{ $type === 'customer' ? 'Customer' : 'Supplier' }}</th><th>Method</th>
                    <th class="text-right">Amount</th><th class="text-right">Allocated</th><th class="text-right">Advance (unallocated)</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($rows as $r)
                    <tr class="{{ $r->voided_at ? 'text-muted' : '' }}">
                        <td>{{ \Carbon\Carbon::parse($r->date)->format('d M, Y') }}</td><td>{{ $r->reference }}</td><td>{{ $r->party_name }}</td><td>{{ $r->payment_method }}</td>
                        <td class="text-right">{{ format_minor($r->amount) }}</td><td class="text-right">{{ format_minor($r->allocated) }}</td>
                        <td class="text-right">{{ format_minor($r->unallocated) }}</td><td>{{ $r->voided_at ? 'Voided' : 'Active' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted">No payments found.</td></tr>
                @endforelse
                </tbody>
                @if($rows->isNotEmpty())
                    <tfoot><tr><th colspan="4" class="text-right">Total (active)</th><th class="text-right">{{ format_minor($rows->whereNull('voided_at')->sum('amount')) }}</th><th colspan="3"></th></tr></tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
