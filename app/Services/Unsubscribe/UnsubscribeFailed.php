<?php

namespace App\Services\Unsubscribe;

use RuntimeException;

/**
 * The message is a short error code (e.g. http_404, blocked_address), safe to store and log.
 */
class UnsubscribeFailed extends RuntimeException {}
