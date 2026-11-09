<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Exception;

/**
 * 409 INSUFFICIENT_STOCK.
 *
 * A line asks for more than is currently sellable. $details carries `sku`,
 * `requested` and `available`. The request itself is fine: read inventory
 * again, adjust the quantity, and resubmit.
 */
final class InsufficientStockException extends ApiException
{
}
