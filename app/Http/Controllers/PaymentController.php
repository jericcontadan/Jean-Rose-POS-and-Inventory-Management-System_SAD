<?php

namespace App\Http\Controllers;

use App\Exceptions\PaymentException;
use App\Http\Requests\PayRequest;
use App\Services\CartService;
use App\Services\SaleService;
use Illuminate\Http\RedirectResponse;
use Throwable;

// Payment (screen 4, FR-04 / FR-05). Thin controller: the Pay Now rules live in SaleService.
class PaymentController extends Controller
{
    public function __construct(
        private CartService $cart,
        private SaleService $sales,
    ) {
    }

    // POST /pos/pay : "Confirm payment" in the payment box.
    public function store(PayRequest $request): RedirectResponse
    {
        $token = $request->validated('pay_token');

        // Double tap: this form was already paid, so show that receipt instead of paying again.
        if ($saleId = $this->cart->usedTokenSaleId($token)) {
            return redirect()->route('receipts.show', $saleId)->with('success', 'This payment was already saved.');
        }

        // An old form (for example from another tab) must not pay for the current order.
        if (! $this->cart->isCurrentToken($token)) {
            return redirect()->route('pos.index')->with('error', 'This payment form is out of date. Please check the order and pay again.');
        }

        try {
            $sale = $this->sales->pay($request->user(), $this->cart->items(), (float) $request->validated('cash_received'));
        } catch (PaymentException $e) {
            // Something the cashier can fix: nothing was saved, the cart is kept, the payment box opens again.
            return redirect()->route('pos.index')->withInput()->with('error', $e->getMessage())->with('reopen_payment', true);
        } catch (Throwable $e) {
            report($e); // write the real error to the log for us, show a simple message to the cashier
            return redirect()->route('pos.index')->with('error', 'Payment failed. Nothing was saved. Please try again.');
        }

        $this->cart->finishPayment($token, $sale->sale_id);

        return redirect()->route('receipts.show', $sale)->with('success', 'Payment saved.');
    }
}
