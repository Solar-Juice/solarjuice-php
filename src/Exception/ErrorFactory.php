<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Exception;

use SolarJuice\PartnerApi\Http\Response;
use SolarJuice\PartnerApi\Internal\RetryAfter;

/**
 * Turns an error response into the right exception.
 *
 * @internal
 */
final class ErrorFactory
{
    /**
     * The documented mapping. `error.code` is the primary signal because it is
     * the only thing that separates the two 409s and the two 503s.
     *
     * The keys are literals rather than ErrorCode cases because fetching an
     * enum property inside a constant expression needs PHP 8.2, and this
     * package supports 8.1. ErrorCodeTest keeps the two in step.
     *
     * @var array<string, class-string<ApiException>>
     */
    private const BY_CODE = [
        'UNAUTHORIZED' => UnauthorizedException::class,
        'FORBIDDEN' => ForbiddenException::class,
        'NOT_FOUND' => NotFoundException::class,
        'VALIDATION_FAILED' => ValidationFailedException::class,
        'RATE_LIMITED' => RateLimitedException::class,
        'PRICE_CHANGED' => PriceChangedException::class,
        'IDEMPOTENCY_CONFLICT' => IdempotencyConflictException::class,
        'QUOTE_UNAVAILABLE' => QuoteUnavailableException::class,
        'STALE_DATA' => StaleDataException::class,
        'INTERNAL' => InternalException::class,
    ];

    /**
     * The fallback for responses that are not the documented envelope, for
     * example an HTML error page from a proxy in front of the API.
     *
     * 409 and 503 are deliberately absent: each covers two codes, and guessing
     * would be worse than handing back the base class with the status intact.
     *
     * @var array<int, class-string<ApiException>>
     */
    private const BY_STATUS = [
        401 => UnauthorizedException::class,
        403 => ForbiddenException::class,
        404 => NotFoundException::class,
        422 => ValidationFailedException::class,
        429 => RateLimitedException::class,
        500 => InternalException::class,
    ];

    /**
     * The codes this SDK maps to a dedicated exception.
     *
     * @return list<string>
     */
    public static function mappedCodes(): array
    {
        return array_keys(self::BY_CODE);
    }

    public static function fromResponse(Response $response): ApiException
    {
        $decoded = json_decode($response->body, true);
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];

        $code = is_string($error['code'] ?? null) ? $error['code'] : null;
        $message = is_string($error['message'] ?? null) && $error['message'] !== ''
            ? $error['message']
            : self::fallbackMessage($response->status);
        $details = array_values(array_filter(
            is_array($error['details'] ?? null) ? $error['details'] : [],
            'is_array',
        ));
        $requestId = is_string($error['request_id'] ?? null)
            ? $error['request_id']
            : $response->header('x-request-id');

        $class = ($code !== null ? self::BY_CODE[$code] ?? null : null)
            ?? self::BY_STATUS[$response->status]
            ?? ApiException::class;

        if ($class === RateLimitedException::class) {
            $retryAfter = RetryAfter::seconds($response->header('retry-after'));

            return new RateLimitedException(
                $message,
                $code,
                $response->status,
                $details,
                $requestId,
                $retryAfter === null ? null : (int) ceil($retryAfter),
            );
        }

        return new $class($message, $code, $response->status, $details, $requestId);
    }

    private static function fallbackMessage(int $status): string
    {
        return sprintf('The Solar Juice Partner API returned HTTP %d with no error envelope.', $status);
    }
}
