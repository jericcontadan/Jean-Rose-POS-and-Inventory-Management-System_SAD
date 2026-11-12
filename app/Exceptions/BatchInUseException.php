<?php

namespace App\Exceptions;

use RuntimeException;

// A batch delete that was refused because the batch already has sales or inventory history.
// The message is shown on the Inventory page as it is.
class BatchInUseException extends RuntimeException
{
}
