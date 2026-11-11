<?php

namespace App\Exceptions;

use RuntimeException;

// A payment that was refused for a reason the cashier can fix (stock changed, not enough cash, empty order).
// The message is shown on the POS screen as it is, so keep it short and friendly.
class PaymentException extends RuntimeException
{
}
