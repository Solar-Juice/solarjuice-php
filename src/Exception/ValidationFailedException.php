<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Exception;

/**
 * 422 VALIDATION_FAILED.
 *
 * The request did not validate. Every problem found is listed in $details.
 */
final class ValidationFailedException extends ApiException
{
}
