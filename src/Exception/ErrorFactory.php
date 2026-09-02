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
     * The code to report when the response carries no error envelope, so that
     * `$e->errorCode === 'RATE_LIMITED'` still works behind an edge proxy that
     * answered with an HTML page.
     *
     * 409 and 503 are absent for the same reason they are absent above: each
     * covers two codes, and a guess would be wrong half the time.
     *
     * @var array<int, string>
     */
    private const CODE_BY_STATUS = [
        401 => 'UNAUTHORIZED',
        403 => 'FORBIDDEN',
        404 => 'NOT_FOUND',
        422 => 'VALIDATION_FAILED',
        429 => 'RATE_LIMITED',
        500 => 'INTERNAL',
    ];

    /**
     * The status to code fallback, exposed so a test can hold it against the
     * status to class mapping above.
     *
     * @return array<int, string>
     */
    public static function synthesisedCodes(): array
    {
        return self::CODE_BY_STATUS;
    }

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

        $reported = is_string($error['code'] ?? null) ? $error['code'] : null;
        $code = $reported ?? self::CODE_BY_STATUS[$response->status] ?? null;
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

        // The reported code picks the class; a synthesised one never does, so an
        // unknown code cannot be mistaken for the status's usual meaning.
        $class = ($reported !== null ? self::BY_CODE[$reported] ?? null : null)
            ?? self::BY_STATUS[$response->status]
            ?? ApiException::class;

        // Retry-After is carried on whatever error the response produced, not
        // only on a 429: an edge proxy sends it with a 503 as readily.
        $retryAfter = RetryAfter::seconds($response->header('retry-after'));

        return new $class(
            $message,
            $code,
            $response->status,
            $details,
            $requestId,
            $retryAfter === null ? null : (int) ceil($retryAfter),
        );
    }

    private static function fallbackMessage(int $status): string
    {
        return sprintf('The Solar Juice Partner API returned HTTP %d with no error envelope.', $status);
    }
}
