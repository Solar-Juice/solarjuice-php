<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Exception;

/**
 * 404 NOT_FOUND.
 *
 * No such SKU, order or route exists for this channel.
 */
final class NotFoundException extends ApiException
{
}
