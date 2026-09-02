<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Resources;

use Generator;

/**
 * Time boxed prices granted to this channel.
 *
 * Granted specials are already reflected in catalogue prices, so this resource
 * is for display and for knowing when a price is about to change: badges,
 * countdowns, campaign pages.
 */
final class Specials extends ApiResource
{
    private const PATH = '/v1/specials';

    /**
     * One page of specials.
     *
     * @param bool|null $active True to return only specials live right now.
     * @param string|null $sku Restrict to specials for one SKU.
     *
     * @return array<string, mixed> The envelope: as_of, items, next_cursor.
     */
    public function list(
        ?int $limit = null,
        ?string $cursor = null,
        ?string $updatedSince = null,
        ?bool $active = null,
        ?string $sku = null,
    ): array {
        return $this->requester->send('GET', self::PATH, self::query($limit, $cursor, $updatedSince, $active, $sku))
            ->data();
    }

    /**
     * Every special across every page, one at a time.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function autoPage(
        ?int $limit = null,
        ?string $cursor = null,
        ?string $updatedSince = null,
        ?bool $active = null,
        ?string $sku = null,
    ): Generator {
        yield from $this->walk(self::PATH, self::query($limit, $cursor, $updatedSince, $active, $sku));
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function query(
        ?int $limit,
        ?string $cursor,
        ?string $updatedSince,
        ?bool $active,
        ?string $sku,
    ): array {
        return [
            'limit' => $limit,
            'cursor' => $cursor,
            'updated_since' => $updatedSince,
            'active' => $active,
            'sku' => $sku,
        ];
    }
}
