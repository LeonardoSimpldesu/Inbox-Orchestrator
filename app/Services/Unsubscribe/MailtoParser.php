<?php

namespace App\Services\Unsubscribe;

use Illuminate\Support\Str;

class MailtoParser
{
    private const DEFAULT_TEXT = 'unsubscribe';

    private const SUBJECT_MAX_LENGTH = 200;

    private const BODY_MAX_LENGTH = 1000;

    /**
     * Line breaks are only accepted in the body: anywhere else they would let a
     * sender inject extra headers (e.g. Bcc) into the e-mail we send for the user.
     */
    public function parse(string $mailto): ParsedMailto
    {
        if (! Str::startsWith(Str::lower($mailto), 'mailto:')) {
            throw new UnsubscribeFailed('invalid_mailto');
        }

        [$addresses, $query] = array_pad(explode('?', substr($mailto, 7), 2), 2, '');
        $to = trim(rawurldecode(explode(',', $addresses)[0]));

        $params = [];
        foreach (explode('&', $query) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $params[Str::lower(rawurldecode($key))] ??= rawurldecode($value);
        }

        $subject = $params['subject'] ?? self::DEFAULT_TEXT;

        if (preg_match('/[\r\n]/', $to.$subject) === 1 || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new UnsubscribeFailed('invalid_mailto');
        }

        return new ParsedMailto(
            to: $to,
            subject: mb_substr($subject, 0, self::SUBJECT_MAX_LENGTH),
            body: mb_substr($params['body'] ?? self::DEFAULT_TEXT, 0, self::BODY_MAX_LENGTH),
        );
    }
}
