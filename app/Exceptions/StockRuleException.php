<?php

namespace App\Exceptions;

use RuntimeException;

// A stock change that was refused by a business rule, for example restocking an expired batch
// or removing more pieces than are on hand. The message is shown to the owner as it is.
class StockRuleException extends RuntimeException
{
}
