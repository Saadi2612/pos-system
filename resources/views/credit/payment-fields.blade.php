{{-- Payment type / amount / due date block shared by the sale and purchase create forms.
     Expects $label ("Amount Received" | "Amount Paid"). Keeps #paid_amount and #getTotalAmount ids used by the page scripts. --}}
<div class="form-row">
    <div class="col-lg-4">
        <div class="form-group">
            <label>Payment Type <span class="text-danger">*</span></label>
            <div>
                @foreach(['full' => 'Full payment', 'partial' => 'Partial', 'credit' => 'Full credit'] as $value => $text)
                    <div class="custom-control custom-radio custom-control-inline">
                        <input type="radio" id="payment_type_{{ $value }}" name="payment_type" value="{{ $value }}"
                               class="custom-control-input" {{ old('payment_type', 'full') === $value ? 'checked' : '' }}>
                        <label class="custom-control-label" for="payment_type_{{ $value }}">{{ $text }}</label>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="form-group">
            <label for="paid_amount">{{ $label }}</label>
            <div class="input-group">
                <input id="paid_amount" type="text" class="form-control" name="paid_amount" value="{{ old('paid_amount', 0) }}">
                <div class="input-group-append">
                    <button id="getTotalAmount" class="btn btn-primary" type="button" title="Use total">
                        <i class="bi bi-check-square"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4 credit-due-wrap">
        <div class="form-group">
            <label for="due_date">Due Date (optional)</label>
            <input type="date" class="form-control" name="due_date" id="due_date" value="{{ old('due_date') }}">
        </div>
    </div>
</div>
