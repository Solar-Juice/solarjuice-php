<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Exception;

/**
 * 409 IDEMPOTENCY_CONFLICT.
 *
 * The same client_reference was submitted with a different body. Choose a new
 * reference, or fetch the order that already exists.
 */
final class IdempotencyConflictException extends ApiException
{
}
