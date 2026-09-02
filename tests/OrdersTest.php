<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Tests;

use PHPUnit\Framework\TestCase;
use SolarJuice\PartnerApi\Exception\ValidationFailedException;
use SolarJuice\PartnerApi\Http\Response;
use SolarJuice\PartnerApi\Tests\Support\ClientFactory;

final class OrdersTest extends TestCase
{
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testCreateAlwaysSendsAnIdempotencyKeyAndItIsAUuidV4(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(202, self::receipt());

        $created = $factory->client()->orders->create(self::orderRequest());

        $sent = $factory->transport->lastRequest()->headers['Idempotency-Key'];

        self::assertMatchesRegularExpression(self::UUID_V4, $sent);
        self::assertSame($sent, $created->idempotencyKey, 'The key used must be readable for logging.');
    }

    public function testGeneratedKeysAreUnique(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(202, self::receipt())->pushJson(202, self::receipt());

        $client = $factory->client();
        $first = $client->orders->create(self::orderRequest());
        $second = $client->orders->create(self::orderRequest());

        self::assertNotSame($first->idempotencyKey, $second->idempotencyKey);
    }

    public function testACallerSuppliedKeyIsUsedUnchanged(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(202, self::receipt());

        $created = $factory->client()->orders->create(self::orderRequest(), 'PO-88213-attempt-1');

        self::assertSame('PO-88213-attempt-1', $factory->transport->lastRequest()->headers['Idempotency-Key']);
        self::assertSame('PO-88213-attempt-1', $created->idempotencyKey);
    }

    public function testCreatePostsTheBodyAsJsonAndReturnsTheReceipt(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(202, self::receipt());

        $created = $factory->client()->orders->create(self::orderRequest());

        $request = $factory->transport->lastRequest();

        self::assertSame('POST', $request->method);
        self::assertSame('https://api.solarjuice.com.au/v1/orders', $request->url);
        self::assertSame('application/json', $request->headers['Content-Type']);
        self::assertSame(self::orderRequest(), json_decode((string) $request->body, true));

        self::assertSame('ord_01J6ZK3M5X8QW2R7Y9V4B1N0PD', $created->id());
        self::assertSame('received', $created->status());
        self::assertSame('PO-88213', $created->order['client_reference']);
    }

    public function testGetSendsIfNoneMatchWhenAnEtagIsGiven(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, self::receipt(), ['ETag' => '"v2"']);

        $factory->client()->orders->get('ord_01J6ZK3M5X8QW2R7Y9V4B1N0PD', '"v1"');

        self::assertSame('"v1"', $factory->transport->lastRequest()->headers['If-None-Match']);
    }

    public function testGetOmitsIfNoneMatchWhenNoEtagIsGiven(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, self::receipt(), ['ETag' => '"v2"']);

        $fetched = $factory->client()->orders->get('ord_01J6ZK3M5X8QW2R7Y9V4B1N0PD');

        self::assertArrayNotHasKey('If-None-Match', $factory->transport->lastRequest()->headers);
        self::assertFalse($fetched->notModified);
        self::assertSame('"v2"', $fetched->etag);
        self::assertSame('received', $fetched->status());
    }

    public function testA304IsReportedAsNotModifiedRatherThanThrown(): void
    {
        $factory = new ClientFactory();
        $factory->transport->push(new Response(304, ['ETag' => '"v1"'], ''));

        $fetched = $factory->client()->orders->get('ord_01J6ZK3M5X8QW2R7Y9V4B1N0PD', '"v1"');

        self::assertTrue($fetched->notModified);
        self::assertNull($fetched->order);
        self::assertNull($fetched->status());
        self::assertSame('"v1"', $fetched->etag);
    }

    public function testA304WithNoEtagHeaderKeepsTheOneWeSentSoPollingCanContinue(): void
    {
        $factory = new ClientFactory();
        $factory->transport->push(new Response(304, [], ''));

        $fetched = $factory->client()->orders->get('ord_01J6ZK3M5X8QW2R7Y9V4B1N0PD', '"v1"');

        self::assertTrue($fetched->notModified);
        self::assertSame('"v1"', $fetched->etag);
    }

    public function testA304IsNotRetried(): void
    {
        $factory = new ClientFactory();
        $factory->transport->push(new Response(304, ['ETag' => '"v1"'], ''));

        $factory->client()->orders->get('ord_01J6ZK3M5X8QW2R7Y9V4B1N0PD', '"v1"');

        self::assertSame(1, $factory->transport->callCount());
        self::assertSame([], $factory->pauses);
    }

    public function testCancelPostsToTheCancelPathAndReturnsTheUpdatedOrder(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['id' => 'ord_01J6ZK3M5X8QW2R7Y9V4B1N0PD', 'status' => 'cancelled']);

        $order = $factory->client()->orders->cancel('ord_01J6ZK3M5X8QW2R7Y9V4B1N0PD');

        $request = $factory->transport->lastRequest();

        self::assertSame('POST', $request->method);
        self::assertSame(
            'https://api.solarjuice.com.au/v1/orders/ord_01J6ZK3M5X8QW2R7Y9V4B1N0PD/cancel',
            $request->url,
        );
        self::assertSame('cancelled', $order['status']);
    }

    public function testCancelSendsTheNoteWhenOneIsGiven(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['id' => 'ord_01J6ZK3M5X8QW2R7Y9V4B1N0PD', 'status' => 'cancelled']);

        $factory->client()->orders->cancel(
            'ord_01J6ZK3M5X8QW2R7Y9V4B1N0PD',
            'Customer changed the panel selection',
        );

        $request = $factory->transport->lastRequest();

        self::assertSame('application/json', $request->headers['Content-Type']);
        self::assertSame(
            ['note' => 'Customer changed the panel selection'],
            json_decode((string) $request->body, true),
        );
    }

    public function testCancelSendsNoBodyAtAllWithoutANote(): void
    {
        // The body is optional, and an empty object is not the same request.
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['id' => 'ord_01J6ZK3M5X8QW2R7Y9V4B1N0PD', 'status' => 'cancelled']);

        $factory->client()->orders->cancel('ord_01J6ZK3M5X8QW2R7Y9V4B1N0PD');

        $request = $factory->transport->lastRequest();

        self::assertNull($request->body);
        self::assertArrayNotHasKey('Content-Type', $request->headers);
    }

    public function testCancelUrlEncodesTheOrderId(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['status' => 'cancelled']);

        $factory->client()->orders->cancel('ord/one two');

        self::assertSame(
            'https://api.solarjuice.com.au/v1/orders/ord%2Fone%20two/cancel',
            $factory->transport->lastRequest()->url,
        );
    }

    public function testCancelRaisesWhenTheOrderIsPastThePartnerCancellationWindow(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(422, [
            'error' => [
                'code' => 'VALIDATION_FAILED',
                'message' => 'order is already processing and cannot be cancelled by the partner',
                'details' => [],
                'request_id' => 'req_01J6ZK3M5X8QW2R7Y9V4B1N0PD',
            ],
        ]);

        $this->expectException(ValidationFailedException::class);

        $factory->client(maxRetries: 0)->orders->cancel('ord_01J6ZK3M5X8QW2R7Y9V4B1N0PD');
    }

    public function testShippingQuotePostsTheCartAndReturnsTheQuote(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, [
            'quote_id' => 'qte_01J6ZK3M5X8QW2R7Y9V4B1N0PD',
            'quote_status' => 'priced',
            'expires_at' => '2026-09-02T04:45:00Z',
            'origin_metro' => 'Sydney',
            'rates' => [['service_code' => 'ALLIED-GENERAL', 'total_price' => '123.45']],
        ]);

        $quote = $factory->client()->shipping->quote([
            'destination' => ['suburb' => 'Parramatta', 'postcode' => '2150', 'state' => 'NSW'],
            'lines' => [['sku' => 'GW-5000-DNS-30', 'quantity' => 1]],
        ]);

        self::assertSame('priced', $quote['quote_status']);
        self::assertSame('POST', $factory->transport->lastRequest()->method);
        self::assertSame(
            'https://api.solarjuice.com.au/v1/shipping/quotes',
            $factory->transport->lastRequest()->url,
        );
        // A quote is never sent with an idempotency key: every call is meant to
        // produce a new quote id.
        self::assertArrayNotHasKey('Idempotency-Key', $factory->transport->lastRequest()->headers);
    }

    /**
     * @return array<string, mixed>
     */
    private static function orderRequest(): array
    {
        return [
            'client_reference' => 'PO-88213',
            'price_list_version' => '2026-09-02T04:00:00Z',
            'quote_id' => 'qte_01J6ZK3M5X8QW2R7Y9V4B1N0PD',
            'rate_service_code' => 'ALLIED-GENERAL',
            'delivery' => [
                'name' => 'Jane Citizen',
                'phone' => '+61400000000',
                'address1' => '12 Example Street',
                'suburb' => 'Parramatta',
                'postcode' => '2150',
                'state' => 'NSW',
            ],
            'lines' => [
                ['sku' => 'GW-5000-DNS-30', 'quantity' => 1, 'unit_price' => '1110.99'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function receipt(): array
    {
        return [
            'id' => 'ord_01J6ZK3M5X8QW2R7Y9V4B1N0PD',
            'client_reference' => 'PO-88213',
            'status' => 'received',
            'total' => '2928.68',
        ];
    }
}
