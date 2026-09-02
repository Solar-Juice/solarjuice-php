<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Exception;

use SolarJuice\PartnerApi\ErrorCode;
use Throwable;

/**
 * The API returned an error response.
 *
 * Every documented error code has its own subclass, so callers can catch the
 * case they care about. This class itself is thrown when the response is not
 * the documented envelope, or when the code is one the SDK does not know yet:
 * the error enumeration is open, and a client from last year must still behave
 * when a new code appears.
 */
class ApiException extends SolarJuiceException
{
    /**
     * @param string|null $errorCode The raw `error.code` string, preserved even when it is not a known code.
     * @param array<int, array<string, mixed>> $details The `error.details` entries, empty when there is nothing to add.
     */
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly int $statusCode = 0,
        public readonly array $details = [],
        public readonly ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        // The HTTP status doubles as the exception code so that logging which only
        // records getCode() still records something useful.
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * The error code as an enum case, or null when the API sent a code this
     * version of the SDK does not know.
     */
    public function code(): ?ErrorCode
    {
        return $this->errorCode === null ? null : ErrorCode::tryFrom($this->errorCode);
    }
}
