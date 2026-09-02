<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Result;

/**
 * The result of `orders->get()`.
 *
 * A conditional request that comes back 304 is a normal, useful answer rather
 * than a failure, so it is reported here instead of thrown: `notModified` is
 * true, `order` is null, and the ETag is the one you sent, ready for the next
 * poll.
 */
final class FetchedOrder
{
    /**
     * @param array<string, mixed>|null $order The decoded Order, or null when not modified.
     * @param string|null $etag The ETag to send as If-None-Match on the next poll.
     */
    public function __construct(
        public readonly ?array $order,
        public readonly bool $notModified = false,
        public readonly ?string $etag = null,
    ) {
    }

    public function status(): ?string
    {
        $status = $this->order['status'] ?? null;

        return is_string($status) ? $status : null;
    }
}
