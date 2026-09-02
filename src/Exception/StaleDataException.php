<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Exception;

/**
 * 503 STALE_DATA.
 *
 * The catalogue or inventory behind the request is older than the safety
 * threshold, so the operation cannot be served safely.
 */
final class StaleDataException extends ApiException
{
}
