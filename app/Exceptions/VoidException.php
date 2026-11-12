<?php

namespace App\Exceptions;

use RuntimeException;

// A void that was refused for a reason the owner should see (for example: the sale is already voided).
// The message is shown on the Sales Records page as it is, so keep it short and friendly.
class VoidException extends RuntimeException
{
}
