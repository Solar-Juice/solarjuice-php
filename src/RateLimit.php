<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi;

/**
 * The rate limit headers from the most recent response.
 *
 * Read it after any call to pace a bulk sync before the API has to push back:
 * dropping to a slower cadence at, say, fifty remaining is cheaper than
 * absorbing 429s and their retries.
 */
final class RateLimit
{
    /**
     * @param int|null $limit Requests allowed per one minute sliding window for this key.
     * @param int|null $remaining Requests left in the current window.
     * @param int|null $reset Seconds until the window resets.
     */
    public function __construct(
        public readonly ?int $limit = null,
        public readonly ?int $remaining = null,
        public readonly ?int $reset = null,
    ) {
    }
}
