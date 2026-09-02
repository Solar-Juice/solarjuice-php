<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Resources;

use Generator;
use SolarJuice\PartnerApi\Internal\ApiRequester;

/**
 * Shared behaviour for the resource groups hanging off the client.
 */
abstract class ApiResource
{
    public function __construct(protected readonly ApiRequester $requester)
    {
    }

    /**
     * Walk every page of a cursor paginated endpoint, yielding each item.
     *
     * Pages are fetched lazily, one at a time, so a sync over a large catalogue
     * never holds more than a page in memory. A cursor is only valid for the
     * query it was issued with, which is why the filters are fixed for the whole
     * walk and only the cursor moves.
     *
     * @param array<string, scalar|null> $params
     *
     * @return Generator<int, array<string, mixed>>
     */
    protected function walk(string $path, array $params): Generator
    {
        // Honour a caller supplied cursor so an interrupted sync can resume.
        $cursor = isset($params['cursor']) ? (string) $params['cursor'] : null;
        unset($params['cursor']);

        do {
            $page = $this->requester->send('GET', $path, $params + ['cursor' => $cursor])->data();

            /** @var array<int, array<string, mixed>> $items */
            $items = is_array($page['items'] ?? null) ? $page['items'] : [];

            foreach ($items as $item) {
                yield $item;
            }

            $next = $page['next_cursor'] ?? null;
            $cursor = is_string($next) && $next !== '' ? $next : null;
        } while ($cursor !== null);
    }
}
