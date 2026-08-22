<?php

namespace App\Merchants;

use RuntimeException;

/**
 * Raised where a merchant cannot do what was asked of it — a stub driver's
 * everything, or a real driver's genuine gap, like asking the Manual driver
 * for a card-management portal it does not have. The message says which, so
 * a caller showing it to a person has something honest to show.
 */
class UnsupportedMerchantException extends RuntimeException {}
