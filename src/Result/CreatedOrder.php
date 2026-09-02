<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Result;

/**
 * The receipt returned by `orders->create()`.
 *
 * The idempotency key is carried alongside the order so it can be logged with
 * the order id. If the create ever fails in a way that leaves you unsure
 * whether it landed, that pair is what lets you reconcile.
 */
final class CreatedOrder
{
    /**
     * @param array<string, mixed> $order The decoded Order, status `received` at this point.
     * @param string $idempotencyKey The value sent as the Idempotency-Key header.
     */
    public function __construct(
        public readonly array $order,
        public readonly string $idempotencyKey,
    ) {
    }

    public function id(): ?string
    {
        $id = $this->order['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    public function status(): ?string
    {
        $status = $this->order['status'] ?? null;

        return is_string($status) ? $status : null;
    }
}
