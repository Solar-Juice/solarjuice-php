<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Exception;

/**
 * 401 UNAUTHORIZED.
 *
 * The API key is missing, malformed, revoked, or from the wrong environment.
 */
final class UnauthorizedException extends ApiException
{
}
