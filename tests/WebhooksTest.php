<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Tests;

use PHPUnit\Framework\TestCase;
use SolarJuice\PartnerApi\Exception\NotFoundException;
use SolarJuice\PartnerApi\Exception\ValidationFailedException;
use SolarJuice\PartnerApi\Http\Response;
use SolarJuice\PartnerApi\Tests\Support\ClientFactory;

final class WebhooksTest extends TestCase
{
    private const ID = 'wh_01m24fj0js2r8nkr2pwhc6wykm';

    public function testListGetsTheEndpointsInOneRequest(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['endpoints' => [self::endpoint()]]);

        $page = $factory->client()->webhooks->list();

        $request = $factory->transport->lastRequest();

        self::assertSame('GET', $request->method);
        self::assertSame('https://api.solarjuice.com.au/v1/webhooks', $request->url);
        self::assertSame(self::ID, $page['endpoints'][0]['id']);
        self::assertSame(1, $factory->transport->callCount());
    }

    public function testCreatePostsTheBodyAsJsonAndReturnsTheSecret(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(201, self::endpoint() + ['secret' => 'whsec_0123456789abcdef']);

        $body = [
            'url' => 'https://example.partner.com.au/hooks/solarjuice',
            'events' => ['catalogue.updated', 'specials.updated'],
            'description' => 'Production catalogue sync',
        ];

        $endpoint = $factory->client()->webhooks->create($body);

        $request = $factory->transport->lastRequest();

        self::assertSame('POST', $request->method);
        self::assertSame('https://api.solarjuice.com.au/v1/webhooks', $request->url);
        self::assertSame('application/json', $request->headers['Content-Type']);
        self::assertSame($body, json_decode((string) $request->body, true));
        self::assertSame('whsec_0123456789abcdef', $endpoint['secret']);
        // Only orders carry an idempotency key.
        self::assertArrayNotHasKey('Idempotency-Key', $request->headers);
    }

    public function testDeleteSendsADeleteAndAcceptsTheEmpty204(): void
    {
        $factory = new ClientFactory();
        $factory->transport->push(new Response(204, [], ''));

        $factory->client()->webhooks->delete(self::ID);

        $request = $factory->transport->lastRequest();

        self::assertSame('DELETE', $request->method);
        self::assertSame('https://api.solarjuice.com.au/v1/webhooks/' . self::ID, $request->url);
        self::assertNull($request->body);
    }

    public function testDeleteOfAnotherChannelsEndpointRaisesNotFound(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(404, [
            'error' => [
                'code' => 'NOT_FOUND',
                'message' => 'webhook endpoint not found',
                'details' => [],
                'request_id' => 'req_01J6ZK3M5X8QW2R7Y9V4B1N0PD',
            ],
        ]);

        $this->expectException(NotFoundException::class);

        $factory->client(maxRetries: 0)->webhooks->delete('wh_someone_else');
    }

    public function testEnablePostsToTheEnablePathWithNoBodyAndReturnsTheEndpoint(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, self::endpoint());

        $endpoint = $factory->client()->webhooks->enable(self::ID);

        $request = $factory->transport->lastRequest();

        self::assertSame('POST', $request->method);
        self::assertSame('https://api.solarjuice.com.au/v1/webhooks/' . self::ID . '/enable', $request->url);
        self::assertNull($request->body);
        self::assertArrayNotHasKey('Content-Type', $request->headers);
        self::assertSame('active', $endpoint['status']);
        self::assertSame(0, $endpoint['consecutive_failures']);
    }

    public function testTestPostsToTheTestPathAndReturnsTheQueuedReceipt(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(202, ['queued' => true, 'event' => 'webhook.test']);

        $receipt = $factory->client()->webhooks->test(self::ID);

        $request = $factory->transport->lastRequest();

        self::assertSame('POST', $request->method);
        self::assertSame('https://api.solarjuice.com.au/v1/webhooks/' . self::ID . '/test', $request->url);
        self::assertNull($request->body);
        self::assertTrue($receipt['queued']);
        self::assertSame('webhook.test', $receipt['event']);
    }

    public function testTestOfADisabledEndpointRaisesValidationFailed(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(422, [
            'error' => [
                'code' => 'VALIDATION_FAILED',
                'message' => 'endpoint is disabled; enable it first',
                'details' => [],
                'request_id' => 'req_01J6ZK3M5X8QW2R7Y9V4B1N0PD',
            ],
        ]);

        $this->expectException(ValidationFailedException::class);

        $factory->client(maxRetries: 0)->webhooks->test(self::ID);
    }

    public function testEndpointIdsAreUrlEncoded(): void
    {
        $factory = new ClientFactory();
        $factory->transport
            ->push(new Response(204, [], ''))
            ->pushJson(200, self::endpoint())
            ->pushJson(202, ['queued' => true, 'event' => 'webhook.test']);

        $client = $factory->client();
        $client->webhooks->delete('wh/one two');
        $client->webhooks->enable('wh/one two');
        $client->webhooks->test('wh/one two');

        self::assertSame(
            'https://api.solarjuice.com.au/v1/webhooks/wh%2Fone%20two',
            $factory->transport->requestAt(0)->url,
        );
        self::assertSame(
            'https://api.solarjuice.com.au/v1/webhooks/wh%2Fone%20two/enable',
            $factory->transport->requestAt(1)->url,
        );
        self::assertSame(
            'https://api.solarjuice.com.au/v1/webhooks/wh%2Fone%20two/test',
            $factory->transport->requestAt(2)->url,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function endpoint(): array
    {
        return [
            'id' => self::ID,
            'channel_id' => 'ch_01J6ZK3M5X8QW2R7Y9V4B1N0PD',
            'url' => 'https://example.partner.com.au/hooks/solarjuice',
            'events' => ['catalogue.updated', 'specials.updated'],
            'status' => 'active',
            'description' => 'Production catalogue sync',
            'created_at' => '2026-09-11T05:12:40Z',
            'updated_at' => '2026-09-11T05:12:40Z',
            'consecutive_failures' => 0,
        ];
    }
}
