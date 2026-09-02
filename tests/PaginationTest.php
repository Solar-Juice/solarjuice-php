<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Tests;

use PHPUnit\Framework\TestCase;
use SolarJuice\PartnerApi\Exception\PaginationStalledException;
use SolarJuice\PartnerApi\Tests\Support\ClientFactory;

final class PaginationTest extends TestCase
{
    public function testListReturnsTheWholeEnvelopeNotJustTheItems(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, [
            'as_of' => '2026-09-02T04:10:11Z',
            'price_list_version' => '2026-09-02T04:00:00Z',
            'items' => [['sku' => 'GW-5000-DNS-30']],
            'next_cursor' => null,
        ]);

        $page = $factory->client()->catalogue->list();

        self::assertSame('2026-09-02T04:10:11Z', $page['as_of']);
        self::assertSame('2026-09-02T04:00:00Z', $page['price_list_version']);
        self::assertNull($page['next_cursor']);
        self::assertCount(1, $page['items']);
    }

    public function testAutoPageWalksNextCursorAndYieldsEveryItem(): void
    {
        $factory = new ClientFactory();
        $factory->transport
            ->pushJson(200, [
                'as_of' => '2026-09-02T04:10:11Z',
                'items' => [['sku' => 'A'], ['sku' => 'B']],
                'next_cursor' => 'cursor-page-2',
            ])
            ->pushJson(200, [
                'as_of' => '2026-09-02T04:10:11Z',
                'items' => [['sku' => 'C']],
                'next_cursor' => 'cursor-page-3',
            ])
            ->pushJson(200, [
                'as_of' => '2026-09-02T04:10:11Z',
                'items' => [['sku' => 'D']],
                'next_cursor' => null,
            ]);

        $skus = [];

        foreach ($factory->client()->catalogue->autoPage(limit: 2, brand: 'GoodWe') as $product) {
            $skus[] = $product['sku'];
        }

        self::assertSame(['A', 'B', 'C', 'D'], $skus);
        self::assertSame(3, $factory->transport->callCount());
    }

    public function testAutoPageKeepsTheFiltersFixedAndOnlyMovesTheCursor(): void
    {
        $factory = new ClientFactory();
        $factory->transport
            ->pushJson(200, ['items' => [['sku' => 'A']], 'next_cursor' => 'cursor-page-2'])
            ->pushJson(200, ['items' => [['sku' => 'B']], 'next_cursor' => null]);

        iterator_to_array($factory->client()->catalogue->autoPage(limit: 100, brand: 'GoodWe'));

        $first = $factory->transport->requestAt(0)->url;
        $second = $factory->transport->requestAt(1)->url;

        // A cursor is only valid for the query it was issued with, so the filters
        // must be byte for byte the same on the follow up page.
        self::assertStringContainsString('limit=100', $first);
        self::assertStringContainsString('brand=GoodWe', $first);
        self::assertStringNotContainsString('cursor=', $first);

        self::assertStringContainsString('limit=100', $second);
        self::assertStringContainsString('brand=GoodWe', $second);
        self::assertStringContainsString('cursor=cursor-page-2', $second);
    }

    public function testAutoPageCanResumeFromAStoredCursor(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['items' => [['sku' => 'C']], 'next_cursor' => null]);

        iterator_to_array($factory->client()->inventory->autoPage(cursor: 'saved-cursor'));

        self::assertStringContainsString('cursor=saved-cursor', $factory->transport->lastRequest()->url);
    }

    public function testAutoPageIsLazySoALargeSyncNeverHoldsMoreThanAPage(): void
    {
        $factory = new ClientFactory();
        $factory->transport
            ->pushJson(200, ['items' => [['sku' => 'A']], 'next_cursor' => 'cursor-page-2'])
            ->pushJson(200, ['items' => [['sku' => 'B']], 'next_cursor' => null]);

        $pager = $factory->client()->specials->autoPage(active: true);

        // Nothing is fetched until the first item is pulled.
        self::assertSame(0, $factory->transport->callCount());

        $pager->current();

        self::assertSame(1, $factory->transport->callCount());
        self::assertFalse($factory->transport->isDrained());
    }

    public function testAutoPageStopsOnAnEmptyPage(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['items' => [], 'next_cursor' => null]);

        self::assertSame([], iterator_to_array($factory->client()->orders->autoPage(status: 'accepted')));
        self::assertSame(1, $factory->transport->callCount());
    }

    public function testAutoPageRaisesWhenTheApiRepeatsACursor(): void
    {
        $factory = new ClientFactory();
        $factory->transport
            ->pushJson(200, ['items' => [['sku' => 'A']], 'next_cursor' => 'stuck'], [
                'X-Request-Id' => 'req_01J6ZK3M5X8QW2R7Y9V4B1N0PD',
            ])
            ->pushJson(200, ['items' => [['sku' => 'A']], 'next_cursor' => 'stuck'], [
                'X-Request-Id' => 'req_01J6ZK3M5X8QW2R7Y9V4B1N0PD',
            ])
            ->pushJson(200, ['items' => [['sku' => 'A']], 'next_cursor' => 'stuck']);

        try {
            iterator_to_array($factory->client()->catalogue->autoPage());
            self::fail('Expected a PaginationStalledException.');
        } catch (PaginationStalledException $exception) {
            // Two requests, not an unbounded loop burning the allowance.
            self::assertSame(2, $factory->transport->callCount());
            self::assertSame('PAGINATION_STALLED', $exception->errorCode);
            self::assertSame([['cursor' => 'stuck']], $exception->details);
            self::assertSame('req_01J6ZK3M5X8QW2R7Y9V4B1N0PD', $exception->requestId);
        }
    }

    public function testAutoPageRaisesWhenTheFirstPageEchoesAResumedCursor(): void
    {
        $factory = new ClientFactory();
        $factory->transport
            ->pushJson(200, ['items' => [], 'next_cursor' => 'saved-cursor'])
            ->pushJson(200, ['items' => [], 'next_cursor' => 'saved-cursor']);

        $this->expectException(PaginationStalledException::class);

        try {
            iterator_to_array($factory->client()->inventory->autoPage(cursor: 'saved-cursor'));
        } finally {
            self::assertSame(1, $factory->transport->callCount());
        }
    }

    public function testBooleanFiltersAreSentAsLiteralsAndNullsAreOmitted(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['items' => [], 'next_cursor' => null]);

        $factory->client()->specials->list(active: false, sku: 'GW-5000-DNS-30');

        $url = $factory->transport->lastRequest()->url;

        self::assertStringContainsString('active=false', $url);
        self::assertStringContainsString('sku=GW-5000-DNS-30', $url);
        self::assertStringNotContainsString('limit=', $url);
        self::assertStringNotContainsString('updated_since=', $url);
    }

    public function testUpdatedSinceIsSentAsTheDocumentedSnakeCaseParameter(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['items' => [], 'next_cursor' => null]);

        $factory->client()->inventory->list(updatedSince: '2026-09-02T04:10:11Z');

        self::assertStringContainsString(
            'updated_since=2026-09-02T04%3A10%3A11Z',
            $factory->transport->lastRequest()->url,
        );
    }

    public function testASpaceInAQueryValueIsEncodedTheWayTheOtherSdksEncodeIt(): void
    {
        // The Node and Ruby clients send a form encoded space, so PHP does too.
        // The API decodes either, but identical bytes make the three clients
        // comparable in an access log.
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['items' => [], 'next_cursor' => null]);

        $factory->client()->catalogue->list(brand: 'GoodWe Australia');

        self::assertStringContainsString('brand=GoodWe+Australia', $factory->transport->lastRequest()->url);
    }

    public function testASkuIsUrlEncodedInThePath(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['sku' => 'GW/5000 DNS']);

        $factory->client()->catalogue->get('GW/5000 DNS');

        self::assertSame(
            'https://api.solarjuice.com.au/v1/catalogue/GW%2F5000%20DNS',
            $factory->transport->lastRequest()->url,
        );
    }
}
