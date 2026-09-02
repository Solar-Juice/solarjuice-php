<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Resources;

/**
 * Freight quotes from the engine that prices the Solar Outlet checkout.
 *
 * A quote is required to place an order, is bound to the exact lines and
 * destination it was requested with, and expires quickly. Request a fresh one
 * whenever the cart or the address changes.
 */
final class Shipping extends ApiResource
{
    private const PATH = '/v1/shipping/quotes';

    /**
     * Price delivery of a cart to an Australian address.
     *
     * Check `quote_status` before reading `rates`: it is `priced`,
     * `manual_quote_required` or `unavailable`, and `rates` is empty for the
     * last two. Those are successful responses, not errors. A 503 with code
     * QUOTE_UNAVAILABLE means the engine itself was unreachable and is retried
     * automatically.
     *
     * @param array<string, mixed> $request Body with `destination`, `lines` and optionally `origin_metro`.
     *
     * @return array<string, mixed>
     */
    public function quote(array $request): array
    {
        return $this->requester->send('POST', self::PATH, [], $request)->data();
    }
}
