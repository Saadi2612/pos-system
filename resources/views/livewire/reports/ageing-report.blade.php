<div>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="form-row">
                <div class="form-group col-lg-6">
                    <label>{{ $type === 'customer' ? 'Customer' : 'Supplier' }}</label>
                    <select wire:model.live="party_id" class="form-control">
                        <option value="">All</option>
                        @foreach($parties as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                    </select>
                </div>
                <div class="form-group col-lg-6"><label>As of</label><input type="date" wire:model.live="as_of" class="form-control"></div>
            </div>
            <small class="text-muted">Buckets are days past due date. Documents without a due date are shown as Current / not due.</small>
        </div>
    </div>
    <div class="card border-0 shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-striped mb-0">
                <thead><tr><th>{{ $type === 'customer' ? 'Customer' : 'Supplier' }}</th>
                    @foreach($buckets as $label)<th class="text-right">{{ $label }}</th>@endforeach
                    <th class="text-right">Total</th></tr></thead>
                <tbody>
                @forelse($rows as $r)
                    <tr><td>{{ $r->party_name }}</td>
                        @foreach($buckets as $key => $label)<td class="text-right">{{ format_minor($r->$key) }}</td>@endforeach
                        <td class="text-right"><strong>{{ format_minor($r->total) }}</strong></td></tr>
                @empty
                    <tr><td colspan="{{ count($buckets) + 2 }}" class="text-center text-muted">Nothing outstanding.</td></tr>
                @endforelse
                </tbody>
                @if($rows->isNotEmpty())
                    <tfoot><tr><th>Total</th>
                        @foreach($buckets as $key => $label)<th class="text-right">{{ format_minor($rows->sum($key)) }}</th>@endforeach
                        <th class="text-right">{{ format_minor($rows->sum('total')) }}</th></tr></tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
