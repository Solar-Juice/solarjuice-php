<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Internal;

/**
 * Exponential backoff with full jitter.
 *
 * @internal
 */
final class Backoff
{
    public const BASE_SECONDS = 0.5;
    public const MAX_SECONDS = 8.0;

    /**
     * Full jitter, that is a uniform draw from zero to the current ceiling,
     * rather than the ceiling itself. Many partner processes are cron driven
     * and start on the same minute boundary, so retries that all wait exactly
     * 500ms then 1s then 2s would keep colliding for the whole sequence.
     *
     * @param int $attempt Zero for the first retry, one for the second, and so on.
     * @param float $jitter A value in [0, 1).
     */
    public static function delay(int $attempt, float $jitter): float
    {
        $ceiling = min(self::MAX_SECONDS, self::BASE_SECONDS * (2 ** $attempt));

        return $ceiling * $jitter;
    }
}
