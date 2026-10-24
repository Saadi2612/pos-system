/* Payment type (full / partial / credit) behaviour shared by sale, purchase and POS forms. Display convenience only:
   the server recomputes the total and ignores the paid amount for full/credit. */
window.initCreditPayment = function (form, getTotal) {
    var $paid = form.find('#paid_amount');

    function apply() {
        var type = form.find('input[name=payment_type]:checked').val();
        var total = parseFloat(getTotal()) || 0;

        if (type === 'full') {
            $paid.prop('readonly', true).maskMoney('mask', total);
        } else if (type === 'credit') {
            $paid.prop('readonly', true).maskMoney('mask', 0);
        } else {
            $paid.prop('readonly', false);
        }

        form.find('.credit-due-wrap').toggle(type !== 'full');
    }

    form.on('change', 'input[name=payment_type]', apply);
    form.on('submit', apply);
    form.find('#getTotalAmount').off('click').on('click', function () {
        $paid.maskMoney('mask', parseFloat(getTotal()) || 0);
    });
    apply();
};
