<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Exception;

/**
 * 403 FORBIDDEN.
 *
 * The key lacks the scope for this operation, or the channel is suspended.
 */
final class ForbiddenException extends ApiException
{
}
