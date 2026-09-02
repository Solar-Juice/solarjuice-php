<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi;

/**
 * The documented `error.code` values.
 *
 * The enumeration is open on the wire: Solar Juice may add codes with notice.
 * Anything unknown is preserved as a raw string on the exception and reaches
 * the caller as a plain {@see Exception\ApiException}, so an older SDK keeps
 * working when a new code appears.
 */
enum ErrorCode: string
{
    case Unauthorized = 'UNAUTHORIZED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case ValidationFailed = 'VALIDATION_FAILED';
    case RateLimited = 'RATE_LIMITED';
    case PriceChanged = 'PRICE_CHANGED';
    case IdempotencyConflict = 'IDEMPOTENCY_CONFLICT';
    case QuoteUnavailable = 'QUOTE_UNAVAILABLE';
    case StaleData = 'STALE_DATA';
    case Internal = 'INTERNAL';
}
