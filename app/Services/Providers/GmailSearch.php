<?php

namespace App\Services\Providers;

use InvalidArgumentException;

/**
 * Pieces of Gmail search queries built from data the app doesn't control.
 */
final class GmailSearch
{
    /** Mail the app never moves: starred, sent and drafts. */
    public const PROTECTED = '-is:starred -in:sent -in:drafts';

    /** Characters that could end a quoted search term or a whole address. */
    public const UNSAFE_ADDRESS_PATTERN = '/[\s"(){}<>,;\\\\\[\]]|\p{Cc}/u';

    /**
     * Sender addresses come from e-mail headers anyone can write, so they always
     * go inside quotes, and anything that could leave the quotes is refused.
     */
    public static function from(string $address): string
    {
        if (preg_match(self::UNSAFE_ADDRESS_PATTERN, $address) === 1) {
            throw new InvalidArgumentException('Unsafe sender address.');
        }

        return 'from:"'.$address.'"';
    }
}
