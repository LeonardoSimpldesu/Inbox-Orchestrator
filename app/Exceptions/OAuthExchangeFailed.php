<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The message is only the provider's error type, so it is safe to log.
 */
class OAuthExchangeFailed extends RuntimeException {}
