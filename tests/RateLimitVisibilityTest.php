<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Tests;

use PHPUnit\Framework\TestCase;
use SolarJuice\PartnerApi\Exception\RateLimitedException;
use SolarJuice\PartnerApi\Http\Response;
use SolarJuice\PartnerApi\Tests\Support\ClientFactory;

final class RateLimitVisibilityTest extends TestCase
{
    public function testNothingIsExposedBeforeTheFirstCall(): void
    {
        $client = (new ClientFactory())->client();

        self::assertNull($client->lastRateLimit());
        self::assertNull($client->lastRequestId());
        self::assertNull($client->lastPriceListVersion());
    }

    public function testTheRateLimitAndRequestIdComeFromTheLastResponse(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['status' => 'ok'], [
            'RateLimit-Limit' => '600',
            'RateLimit-Remaining' => '597',
            'RateLimit-Reset' => '42',
            'X-Request-Id' => 'req_01J6ZK3M5X8QW2R7Y9V4B1N0PD',
        ]);

        $client = $factory->client();
        $client->health();

        $rateLimit = $client->lastRateLimit();

        self::assertNotNull($rateLimit);
        self::assertSame(600, $rateLimit->limit);
        self::assertSame(597, $rateLimit->remaining);
        self::assertSame(42, $rateLimit->reset);
        self::assertSame('req_01J6ZK3M5X8QW2R7Y9V4B1N0PD', $client->lastRequestId());
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $factory = new ClientFactory();
        $factory->transport->push(new Response(200, ['RATELIMIT-REMAINING' => '12'], '{"status":"ok"}'));

        $client = $factory->client();
        $client->health();

        self::assertSame(12, $client->lastRateLimit()?->remaining);
    }

    public function testTheRateLimitIsUpdatedOnEveryCall(): void
    {
        $factory = new ClientFactory();
        $factory->transport
            ->pushJson(200, ['status' => 'ok'], ['RateLimit-Remaining' => '599'])
            ->pushJson(200, ['status' => 'ok'], ['RateLimit-Remaining' => '598']);

        $client = $factory->client();
        $client->health();
        $client->health();

        self::assertSame(598, $client->lastRateLimit()?->remaining);
    }

    public function testTheRateLimitIsAlsoRecordedFromAnErrorResponse(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(429, [], ['RateLimit-Remaining' => '0', 'Retry-After' => '30']);

        $client = $factory->client(maxRetries: 0);

        try {
            $client->health();
        } catch (RateLimitedException) {
            // The point of the test is what the client recorded on the way past.
        }

        self::assertSame(0, $client->lastRateLimit()?->remaining);
    }

    public function testNonNumericRateLimitHeadersAreIgnoredRatherThanCoercedToZero(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['status' => 'ok'], ['RateLimit-Limit' => 'unlimited']);

        $client = $factory->client();
        $client->health();

        self::assertNull($client->lastRateLimit());
    }

    public function testThePriceListVersionIsSurfacedFromCatalogueResponses(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['items' => [], 'next_cursor' => null], [
            'X-Price-List-Version' => '2026-09-02T04:00:00Z',
        ]);

        $client = $factory->client();
        $client->catalogue->list();

        self::assertSame('2026-09-02T04:00:00Z', $client->lastPriceListVersion());
    }

    public function testANonCatalogueCallDoesNotClearThePriceListVersion(): void
    {
        $factory = new ClientFactory();
        $factory->transport
            ->pushJson(200, ['items' => []], ['X-Price-List-Version' => '2026-09-02T04:00:00Z'])
            ->pushJson(200, ['items' => []]);

        $client = $factory->client();
        $client->catalogue->list();
        $client->inventory->list();

        // The version is what an order must be submitted with, so an unrelated
        // call in between must not lose it.
        self::assertSame('2026-09-02T04:00:00Z', $client->lastPriceListVersion());
    }
}
