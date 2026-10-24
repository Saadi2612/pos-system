<div>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="form-row">
                <div class="form-group col-lg-4">
                    <label>{{ $type === 'customer' ? 'Customer' : 'Supplier' }} <span class="text-danger">*</span></label>
                    <select wire:model.live="party_id" class="form-control">
                        <option value="">Select...</option>
                        @foreach($parties as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                    </select>
                </div>
                <div class="form-group col-lg-4"><label>From</label><input type="date" wire:model.live="from" class="form-control"></div>
                <div class="form-group col-lg-4"><label>To</label><input type="date" wire:model.live="to" class="form-control"></div>
            </div>
        </div>
    </div>

    @if($ledger)
        <div class="card border-0 shadow-sm">
            <div class="card-body table-responsive">
                <table class="table table-bordered table-sm mb-0">
                    <thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Description</th><th class="text-right">Debit</th><th class="text-right">Credit</th><th class="text-right">Balance</th></tr></thead>
                    <tbody>
                    <tr class="table-secondary"><td colspan="6" class="text-right">Opening balance</td><td class="text-right">{{ balance_label($type, $ledger['opening']) }}</td></tr>
                    @forelse($ledger['rows'] as $e)
                        <tr class="{{ $e->entry_type === 'reversal' ? 'text-muted' : '' }}">
                            <td>{{ \Carbon\Carbon::parse($e->entry_date)->format('d M, Y') }}</td><td>{{ ucfirst($e->entry_type) }}</td>
                            <td>{{ $e->reference }}</td><td>{{ $e->description }}</td>
                            <td class="text-right">{{ $e->debit ? format_minor($e->debit) : '' }}</td>
                            <td class="text-right">{{ $e->credit ? format_minor($e->credit) : '' }}</td>
                            <td class="text-right">{{ balance_label($type, $e->running) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted">No entries in this period.</td></tr>
                    @endforelse
                    </tbody>
                    <tfoot><tr><th colspan="6" class="text-right">Closing balance</th><th class="text-right">{{ balance_label($type, $ledger['closing']) }}</th></tr></tfoot>
                </table>
                <small class="text-muted">{{ $type === 'customer' ? 'Dr = customer owes us; Cr = customer credit/advance.' : 'Cr = we owe the supplier; Dr = supplier advance.' }}</small>
            </div>
        </div>
    @else
        <div class="alert alert-info">Select a {{ $type }} to view the ledger.</div>
    @endif
</div>
