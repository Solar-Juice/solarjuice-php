<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Exception;

use Throwable;

/**
 * 429 RATE_LIMITED.
 *
 * The key's allowance for the current one minute window is exhausted. The
 * client retries these automatically up to `maxRetries`; seeing this exception
 * means the allowance was still exhausted after those attempts, so back off for
 * at least `retryAfter` seconds before trying again.
 */
final class RateLimitedException extends ApiException
{
    /**
     * @param array<int, array<string, mixed>> $details
     * @param int|null $retryAfter Seconds to wait, from the Retry-After header, or null when it was absent.
     */
    public function __construct(
        string $message,
        ?string $errorCode = null,
        int $statusCode = 429,
        array $details = [],
        ?string $requestId = null,
        ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $statusCode, $details, $requestId, $retryAfter, $previous);
    }
}
