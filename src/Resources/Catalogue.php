<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Resources;

use Generator;

/**
 * Products this channel can buy, priced for this channel.
 *
 * The price on a product is what the channel pays, with any granted special
 * already folded in. Submit it unchanged as `unit_price` on an order, together
 * with the price list version from `$client->lastPriceListVersion()`.
 */
final class Catalogue extends ApiResource
{
    private const PATH = '/v1/catalogue';

    /**
     * One page of the catalogue.
     *
     * @param int|null $limit Page size, 1 to 500. The API defaults to 100.
     * @param string|null $cursor `next_cursor` from the previous page.
     * @param string|null $updatedSince ISO 8601 instant; pass the previous response's `as_of`.
     * @param string|null $brand Exact brand name, for example `GoodWe`.
     * @param string|null $category Exact category, for example `Inverter`.
     *
     * @return array<string, mixed> The envelope: as_of, price_list_version, items, next_cursor.
     */
    public function list(
        ?int $limit = null,
        ?string $cursor = null,
        ?string $updatedSince = null,
        ?string $brand = null,
        ?string $category = null,
    ): array {
        return $this->requester->send('GET', self::PATH, self::query($limit, $cursor, $updatedSince, $brand, $category))
            ->data();
    }

    /**
     * Every product across every page, one at a time.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function autoPage(
        ?int $limit = null,
        ?string $cursor = null,
        ?string $updatedSince = null,
        ?string $brand = null,
        ?string $category = null,
    ): Generator {
        yield from $this->walk(self::PATH, self::query($limit, $cursor, $updatedSince, $brand, $category));
    }

    /**
     * One product by SKU. SKUs are case sensitive.
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
    private static function query(
        ?int $limit,
        ?string $cursor,
        ?string $updatedSince,
        ?string $brand,
        ?string $category,
    ): array {
        return [
            'limit' => $limit,
            'cursor' => $cursor,
            'updated_since' => $updatedSince,
            'brand' => $brand,
            'category' => $category,
        ];
    }
}
