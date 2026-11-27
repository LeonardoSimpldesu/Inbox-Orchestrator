<?php

namespace App\Services\Sync;

use App\Services\Providers\GmailSearch;
use Illuminate\Support\Str;

final class HeaderParser
{
    private const SUBJECT_MAX_LENGTH = 255;

    public static function sender(?string $from): ?ParsedSender
    {
        $from = trim((string) $from);

        if (preg_match('/^(.*)<([^>]*)>\s*$/s', $from, $matches) === 1) {
            $name = trim($matches[1], " \t\"'");
            $address = trim($matches[2]);
        } else {
            $name = '';
            $address = $from;
        }

        $at = strrpos($address, '@');

        if ($at === false || $at === 0 || $at === strlen($address) - 1
            || preg_match(GmailSearch::UNSAFE_ADDRESS_PATTERN, $address) === 1) {
            return null;
        }

        $address = Str::lower($address);

        return new ParsedSender(
            email: $address,
            domain: substr($address, $at + 1),
            name: $name === '' ? null : $name,
        );
    }

    /**
     * Only https links are kept for unsubscribe: one-click (RFC 8058) requires
     * https and a plain http request could be intercepted.
     */
    public static function unsubscribe(?string $listUnsubscribe, ?string $listUnsubscribePost): ParsedUnsubscribe
    {
        preg_match_all('/<([^>]+)>/', (string) $listUnsubscribe, $matches);

        $mailto = null;
        $url = null;

        foreach ($matches[1] as $target) {
            $target = trim($target);

            if ($mailto === null && Str::startsWith(Str::lower($target), 'mailto:')) {
                $mailto = $target;
            } elseif ($url === null && Str::startsWith(Str::lower($target), 'https://')) {
                $url = $target;
            }
        }

        $oneClick = $url !== null && Str::contains((string) $listUnsubscribePost, 'List-Unsubscribe=One-Click', ignoreCase: true);

        return new ParsedUnsubscribe($mailto, $url, $oneClick);
    }

    public static function subject(?string $subject): ?string
    {
        return $subject === null ? null : mb_substr($subject, 0, self::SUBJECT_MAX_LENGTH);
    }
}
