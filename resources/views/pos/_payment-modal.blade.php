{{--
    Payment box (screen 4). Mockup: docs/design/04-payment-receipt.png (left card).
    Needs $order (CartService::summary()) and $payToken.
    The total shown here is only for the cashier. The form sends ONLY the method, the cash and the one-time token;
    the server works out the total again (SaleService::pay()) and checks cash >= total itself.
--}}
<div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentTitle" aria-hidden="true"
     data-reopen="{{ session('reopen_payment') || $errors->has('cash_received') ? '1' : '0' }}">
    <div class="modal-dialog modal-dialog-centered jr-pay-dialog">
        <form method="POST" action="{{ route('pos.pay') }}" class="modal-content jr-modal jr-pay-card" id="paymentForm">
            @csrf
            <input type="hidden" name="pay_token" value="{{ $payToken }}">

            <h2 class="jr-pay-title" id="paymentTitle">Payment</h2>

            <div class="jr-pay-total">
                <span class="jr-pay-label">TOTAL AMOUNT</span>
                <span class="jr-pay-total-amount" id="payTotal" data-total="{{ number_format($order['total'], 2, '.', '') }}">
                    ₱{{ number_format($order['total'], 2) }}
                </span>
            </div>

            <div>
                <label for="payMethod" class="jr-login-label">Payment method</label>
                <select id="payMethod" name="payment_method" class="form-select">
                    <option value="CASH" selected>Cash</option>
                </select>
            </div>

            <div>
                <label for="cashReceived" class="jr-login-label">Cash received *</label>
                <div class="jr-cash-field">
                    <span class="jr-cash-peso" aria-hidden="true">₱</span>
                    <input id="cashReceived" name="cash_received" type="text" inputmode="decimal" autocomplete="off"
                           class="form-control jr-cash-input @error('cash_received') is-invalid @enderror"
                           value="{{ old('cash_received') }}" placeholder="0.00" required aria-describedby="cashHint">
                </div>
                @error('cash_received')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror

                {{-- Quick buttons only fill the box (labels and amounts are set by the script below). --}}
                <div class="jr-quick-cash" role="group" aria-label="Quick cash amounts">
                    <button type="button" class="jr-quick-btn" data-quick="exact">Exact</button>
                    <button type="button" class="jr-quick-btn" data-quick="100">₱100</button>
                    <button type="button" class="jr-quick-btn" data-quick="1000">₱1,000</button>
                </div>
                <p class="jr-pay-hint" id="cashHint">Cash received must be equal to or more than the total.</p>
            </div>

            <div class="jr-change-box" id="changeBox" aria-live="polite">
                <span class="jr-pay-label">CHANGE</span>
                <span class="jr-change-amount" id="changeAmount">₱0.00</span>
            </div>

            <div class="d-flex gap-3">
                <button type="button" class="btn btn-outline-primary jr-pay-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary jr-pay-confirm" id="payConfirm" disabled>Confirm payment</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    // Payment box: quick cash buttons, live change, and one tap only on Confirm.
    // Display only: the server checks everything again when the form is sent.
    (function () {
        const modalEl = document.getElementById('paymentModal');
        const form = document.getElementById('paymentForm');
        const input = document.getElementById('cashReceived');
        const changeBox = document.getElementById('changeBox');
        const changeAmount = document.getElementById('changeAmount');
        const confirmBtn = document.getElementById('payConfirm');
        const total = parseFloat(document.getElementById('payTotal').dataset.total);
        const peso = (n, decimals) => '₱' + n.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });

        // Next round amount ABOVE the total: 558 -> 600 (step 100), 558 -> 1,000 (step 1000). 500 -> 600, not 500.
        const nextUp = (step) => (Math.floor(total / step) + 1) * step;
        const amounts = { exact: total, '100': nextUp(100), '1000': nextUp(1000) };

        modalEl.querySelectorAll('[data-quick]').forEach(function (btn) {
            const amount = amounts[btn.dataset.quick];
            btn.textContent = btn.dataset.quick === 'exact' ? 'Exact ' + peso(amount, 2) : peso(amount, 0);
            btn.addEventListener('click', function () {
                input.value = amount.toFixed(2);
                updateChange();
            });
        });

        // Live change. Not enough cash: show it and keep Confirm disabled.
        function updateChange() {
            const text = input.value.replace(/,/g, '').trim();
            const cash = /^\d+(\.\d{1,2})?$/.test(text) ? parseFloat(text) : NaN;
            const enough = !isNaN(cash) && Math.round(cash * 100) >= Math.round(total * 100);

            changeAmount.textContent = enough ? peso(cash - total, 2) : (text === '' ? '₱0.00' : 'Not enough cash');
            changeBox.classList.toggle('is-short', !enough && text !== '');
            confirmBtn.disabled = !enough;
            modalEl.querySelectorAll('[data-quick]').forEach(function (btn) {
                btn.classList.toggle('active', !isNaN(cash) && Math.abs(amounts[btn.dataset.quick] - cash) < 0.005);
            });
        }
        input.addEventListener('input', updateChange);

        // One tap only: the second tap does nothing (the server also refuses a used token).
        form.addEventListener('submit', function (event) {
            if (form.dataset.sent === '1') {
                event.preventDefault();
                return;
            }
            form.dataset.sent = '1';
            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Saving…';
        });

        modalEl.addEventListener('shown.bs.modal', function () { input.focus(); });
        updateChange();

        // Came back with an error (not enough cash, stock changed): open the box again with the cash typed before.
        if (modalEl.dataset.reopen === '1') {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    })();
</script>
@endpush
