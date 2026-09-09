<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Resources;

use Generator;

/**
 * Sellable quantity per metro, the same figures the Solar Outlet storefront
 * sells from.
 *
 * Nothing here reserves stock. Check the `stale` flag before trusting the
 * numbers for anything a customer will see, and remember that an
 * `updatedSince` query returns SKUs that have fallen to zero so you can clear
 * them from your own store.
 */
final class Inventory extends ApiResource
{
    private const PATH = '/v1/inventory';

    /**
     * One page of inventory.
     *
     * @param string|null $state Scope the answer to one state: `available` is then keyed by the
     *                           state and `total` is that state's stock, not the national figure.
     *                           `QLD` is Brisbane plus Townsville. Case is ignored and `Victoria`
     *                           works as well as `VIC`. `NT`, `TAS` and `ACT` have no warehouse
     *                           and are a 400, as is anything else that is not a state.
     *
     * @return array<string, mixed> The envelope: as_of, as_of_oldest, stale, locations, items, next_cursor.
     */
    public function list(
        ?int $limit = null,
        ?string $cursor = null,
        ?string $updatedSince = null,
        ?string $state = null,
    ): array {
        return $this->requester->send('GET', self::PATH, self::query($limit, $cursor, $updatedSince, $state))->data();
    }

    /**
     * Every inventory row across every page, one at a time.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function autoPage(
        ?int $limit = null,
        ?string $cursor = null,
        ?string $updatedSince = null,
        ?string $state = null,
    ): Generator {
        yield from $this->walk(self::PATH, self::query($limit, $cursor, $updatedSince, $state));
    }

    /**
     * Inventory for one SKU. A catalogue SKU with no stock anywhere returns
     * `total: 0` rather than a 404.
     *
     * @return array<string, mixed>
     */
    public function get(string $sku, ?string $state = null): array
    {
        return $this->requester
            ->send('GET', self::PATH . '/' . rawurlencode($sku), ['state' => $state])
            ->data();
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function query(?int $limit, ?string $cursor, ?string $updatedSince, ?string $state): array
    {
        return [
            'limit' => $limit,
            'cursor' => $cursor,
            'updated_since' => $updatedSince,
            'state' => $state,
        ];
    }
}
