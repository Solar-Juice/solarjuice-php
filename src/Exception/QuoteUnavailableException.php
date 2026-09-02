<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Exception;

/**
 * 503 QUOTE_UNAVAILABLE.
 *
 * The freight engine is temporarily unreachable. This is not the same as a
 * quote coming back with status `unavailable`, which is a successful response.
 */
final class QuoteUnavailableException extends ApiException
{
}
