<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Exception;

/**
 * The client was constructed with unusable settings, for example no API key.
 *
 * Thrown before any request is attempted, so it always means a deployment or
 * wiring problem rather than anything the API did.
 */
final class ConfigurationException extends SolarJuiceException
{
}
