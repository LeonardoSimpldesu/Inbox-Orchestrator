<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The message is only the error type (e.g. http_429), so it is safe to log.
 */
class ProviderUnavailable extends RuntimeException {}
