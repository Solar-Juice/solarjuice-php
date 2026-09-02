<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Exception;

use RuntimeException;

/**
 * Base class for everything this SDK throws.
 *
 * Catch this to handle any SDK failure in one place; catch the subclasses when
 * the distinction between "the API said no" and "the call never landed" matters.
 */
abstract class SolarJuiceException extends RuntimeException
{
}
