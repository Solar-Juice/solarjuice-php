<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Exception;

/**
 * No HTTP response was obtained: DNS failure, refused connection, TLS failure
 * or timeout.
 *
 * Kept separate from {@see ApiException} because the two call for different
 * handling: this one says nothing about whether the request reached the API, so
 * a create that fails this way may or may not have been recorded. That is
 * exactly why orders carry an idempotency key.
 */
final class TransportException extends SolarJuiceException
{
}
