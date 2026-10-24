@if($data->voided_at)
    <span class="badge badge-secondary" title="{{ $data->void_reason }}">Voided</span>
@else
    @if(Gate::allows('access_purchase_return_payments') && Gate::allows('void_payments'))
        <form id="void{{ $data->id }}" action="{{ route('purchase-return-payments.destroy', $data->id) }}" method="POST" class="d-inline"
              onsubmit="var r = prompt('Reason for voiding this payment? The balance is restored and the ledger reversed.'); if (!r) { return false; } this.reason.value = r; return true;">
            @csrf
            @method('delete')
            <input type="hidden" name="reason">
            <button type="submit" class="btn btn-danger btn-sm" title="Void payment">
                <i class="bi bi-x-circle"></i> Void
            </button>
        </form>
    @endif
@endif
