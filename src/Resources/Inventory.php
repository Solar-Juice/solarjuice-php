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
     * @return array<string, mixed> The envelope: as_of, as_of_oldest, stale, locations, items, next_cursor.
     */
    public function list(?int $limit = null, ?string $cursor = null, ?string $updatedSince = null): array
    {
        return $this->requester->send('GET', self::PATH, self::query($limit, $cursor, $updatedSince))->data();
    }

    /**
     * Every inventory row across every page, one at a time.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function autoPage(?int $limit = null, ?string $cursor = null, ?string $updatedSince = null): Generator
    {
        yield from $this->walk(self::PATH, self::query($limit, $cursor, $updatedSince));
    }

    /**
     * Inventory for one SKU. A catalogue SKU with no stock anywhere returns
     * `total: 0` rather than a 404.
     *
     * @return array<string, mixed>
     */
    public function get(string $sku): array
    {
        return $this->requester->send('GET', self::PATH . '/' . rawurlencode($sku))->data();
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function query(?int $limit, ?string $cursor, ?string $updatedSince): array
    {
        return [
            'limit' => $limit,
            'cursor' => $cursor,
            'updated_since' => $updatedSince,
        ];
    }
}
