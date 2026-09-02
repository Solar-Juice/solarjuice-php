<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Exception;

/**
 * 500 INTERNAL.
 *
 * The API failed unexpectedly. Retry with backoff and quote the request id if it persists.
 */
final class InternalException extends ApiException
{
}
