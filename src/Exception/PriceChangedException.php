<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Exception;

/**
 * 409 PRICE_CHANGED.
 *
 * Submitted line prices or price list version no longer match. $details carries
 * the current values; refresh the catalogue and resubmit.
 */
final class PriceChangedException extends ApiException
{
}
