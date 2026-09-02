<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Internal;

/**
 * Retry-After header parsing.
 *
 * @internal
 */
final class RetryAfter
{
    /**
     * RFC 9110 allows either a delay in seconds or an HTTP date. Both forms are
     * reduced to seconds from now, so callers never have to care which was sent.
     *
     * @return float|null Null when the header is absent or unparseable.
     */
    public static function seconds(?string $header): ?float
    {
        if ($header === null) {
            return null;
        }

        $value = trim($header);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (float) $value;
        }

        $when = strtotime($value);

        if ($when === false) {
            return null;
        }

        // A date already in the past means retry now, not retry in the past.
        return max(0.0, (float) ($when - time()));
    }
}
