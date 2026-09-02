<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Tests;

use PHPUnit\Framework\TestCase;
use SolarJuice\PartnerApi\Exception\InternalException;
use SolarJuice\PartnerApi\Exception\NotFoundException;
use SolarJuice\PartnerApi\Exception\RateLimitedException;
use SolarJuice\PartnerApi\Exception\StaleDataException;
use SolarJuice\PartnerApi\Exception\TransportException;
use SolarJuice\PartnerApi\Http\Response;
use SolarJuice\PartnerApi\Internal\Backoff;
use SolarJuice\PartnerApi\Internal\RetryAfter;
use SolarJuice\PartnerApi\Tests\Support\ClientFactory;

final class RetryTest extends TestCase
{
    public function testRetriesA429AndReturnsTheEventualSuccess(): void
    {
        $factory = new ClientFactory();
        $factory->transport
            ->pushJson(429, self::errorBody('RATE_LIMITED'))
            ->pushJson(200, ['status' => 'ok']);

        self::assertSame(['status' => 'ok'], $factory->client()->health());
        self::assertSame(2, $factory->transport->callCount());
    }

    /**
     * @return list<array{int}>
     */
    public static function retryableStatuses(): array
    {
        return [[429], [502], [503], [504]];
    }

    /**
     * @dataProvider retryableStatuses
     */
    public function testRetriesEveryRetryableStatus(int $status): void
    {
        $factory = new ClientFactory();
        $factory->transport
            ->push(new Response($status, [], ''))
            ->pushJson(200, ['status' => 'ok']);

        $factory->client()->health();

        self::assertSame(2, $factory->transport->callCount());
    }

    public function testDoesNotRetryA404(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(404, self::errorBody('NOT_FOUND'));

        $this->expectException(NotFoundException::class);

        try {
            $factory->client()->catalogue->get('GW-5000-DNS-99');
        } finally {
            self::assertSame(1, $factory->transport->callCount());
        }
    }

    public function testRetriesNetworkFailuresThenSucceeds(): void
    {
        $factory = new ClientFactory();
        $factory->transport
            ->push(new TransportException('Connection refused'))
            ->push(new TransportException('Operation timed out'))
            ->pushJson(200, ['status' => 'ok']);

        self::assertSame(['status' => 'ok'], $factory->client()->health());
        self::assertSame(3, $factory->transport->callCount());
    }

    public function testGivesUpAfterMaxRetriesAndRaisesTheApiError(): void
    {
        $factory = new ClientFactory();

        for ($i = 0; $i < 4; $i++) {
            $factory->transport->pushJson(503, self::errorBody('STALE_DATA'));
        }

        $this->expectException(StaleDataException::class);

        try {
            $factory->client(maxRetries: 3)->health();
        } finally {
            // One attempt plus three retries.
            self::assertSame(4, $factory->transport->callCount());
            self::assertCount(3, $factory->pauses);
        }
    }

    public function testDoesNotAutomaticallyRetryA500(): void
    {
        // A 500 is not on the retryable list: it is not known to be transient,
        // and a partner is better placed than the SDK to decide whether to
        // repeat the work. The exception carries the request id to report.
        $factory = new ClientFactory();
        $factory->transport->pushJson(500, self::errorBody('INTERNAL'));

        $this->expectException(InternalException::class);

        try {
            $factory->client(maxRetries: 3)->health();
        } finally {
            self::assertSame(1, $factory->transport->callCount());
        }
    }

    public function testRaisesTheTransportErrorOnceRetriesAreExhausted(): void
    {
        $factory = new ClientFactory();

        for ($i = 0; $i < 3; $i++) {
            $factory->transport->push(new TransportException('Could not resolve host'));
        }

        $this->expectException(TransportException::class);

        try {
            $factory->client(maxRetries: 2)->health();
        } finally {
            self::assertSame(3, $factory->transport->callCount());
        }
    }

    public function testRetryingCanBeDisabled(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(503, self::errorBody('STALE_DATA'));

        $this->expectException(StaleDataException::class);

        try {
            $factory->client(maxRetries: 0)->health();
        } finally {
            self::assertSame(1, $factory->transport->callCount());
            self::assertSame([], $factory->pauses);
        }
    }

    public function testBackoffDoublesUnderFullJitterAndIsCappedAtEightSeconds(): void
    {
        $factory = new ClientFactory();

        for ($i = 0; $i < 7; $i++) {
            $factory->transport->pushJson(502, []);
        }

        $factory->transport->pushJson(200, ['status' => 'ok']);

        // A jitter draw of 1.0 makes each pause the whole ceiling, which is what
        // pins the doubling and the cap in place.
        $factory->client(maxRetries: 7, jitter: 1.0)->health();

        self::assertSame([0.5, 1.0, 2.0, 4.0, 8.0, 8.0, 8.0], $factory->pauses);
    }

    public function testFullJitterScalesTheWholeCeilingRatherThanASlice(): void
    {
        self::assertSame(0.0, Backoff::delay(0, 0.0));
        self::assertSame(0.25, Backoff::delay(0, 0.5));
        self::assertSame(1.0, Backoff::delay(1, 1.0));
        self::assertSame(Backoff::MAX_SECONDS, Backoff::delay(20, 1.0));
    }

    public function testRetryAfterInSecondsIsPreferredOverTheComputedBackoff(): void
    {
        $factory = new ClientFactory();
        $factory->transport
            ->pushJson(429, self::errorBody('RATE_LIMITED'), ['Retry-After' => '7'])
            ->pushJson(200, ['status' => 'ok']);

        $factory->client()->health();

        self::assertSame([7.0], $factory->pauses);
    }

    public function testRetryAfterAsAnHttpDateIsConvertedToSeconds(): void
    {
        $factory = new ClientFactory();
        $factory->transport
            ->pushJson(503, self::errorBody('QUOTE_UNAVAILABLE'), [
                'Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 5),
            ])
            ->pushJson(200, ['quote_status' => 'priced']);

        $factory->client()->shipping->quote(['destination' => [], 'lines' => []]);

        self::assertCount(1, $factory->pauses);
        // Allow a second either side for the clock ticking during the test.
        self::assertEqualsWithDelta(5.0, $factory->pauses[0], 1.0);
    }

    public function testAPastRetryAfterDateNeverProducesANegativePause(): void
    {
        $factory = new ClientFactory();
        $factory->transport
            ->pushJson(429, self::errorBody('RATE_LIMITED'), [
                'Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() - 60),
            ])
            ->pushJson(200, ['status' => 'ok']);

        $factory->client()->health();

        self::assertSame([0.0], $factory->pauses);
    }

    public function testAnUnparseableRetryAfterFallsBackToTheComputedBackoff(): void
    {
        $factory = new ClientFactory();
        $factory->transport
            ->pushJson(429, self::errorBody('RATE_LIMITED'), ['Retry-After' => 'soon'])
            ->pushJson(200, ['status' => 'ok']);

        $factory->client(jitter: 1.0)->health();

        self::assertSame([Backoff::BASE_SECONDS], $factory->pauses);
    }

    public function testARetryAfterAtTheCeilingIsStillHonoured(): void
    {
        $factory = new ClientFactory();
        $factory->transport
            ->pushJson(429, self::errorBody('RATE_LIMITED'), ['Retry-After' => '60'])
            ->pushJson(200, ['status' => 'ok']);

        $factory->client()->health();

        self::assertSame([RetryAfter::MAX_HONOURED_SECONDS], $factory->pauses);
    }

    public function testARetryAfterAboveTheCeilingIsNotSleptOn(): void
    {
        // An edge proxy can send an hour. Sleeping on it would park a web
        // request for that hour, once per retry, so the caller is handed the
        // error and the real value instead.
        $factory = new ClientFactory();
        $factory->transport->pushJson(429, self::errorBody('RATE_LIMITED'), ['Retry-After' => '3600']);

        try {
            $factory->client(maxRetries: 3)->health();
            self::fail('Expected a RateLimitedException.');
        } catch (RateLimitedException $exception) {
            self::assertSame(1, $factory->transport->callCount());
            self::assertSame([], $factory->pauses);
            self::assertSame(3600, $exception->retryAfter);
        }
    }

    public function testALongRetryAfterDateIsNotSleptOnEither(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(503, self::errorBody('STALE_DATA'), [
            'Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 900),
        ]);

        $this->expectException(StaleDataException::class);

        try {
            $factory->client(maxRetries: 3)->health();
        } finally {
            self::assertSame(1, $factory->transport->callCount());
            self::assertSame([], $factory->pauses);
        }
    }

    public function testTheRetriedRequestIsIdenticalIncludingTheIdempotencyKey(): void
    {
        $factory = new ClientFactory();
        $factory->transport
            ->push(new TransportException('Operation timed out'))
            ->pushJson(202, ['id' => 'ord_01J6ZK3M5X8QW2R7Y9V4B1N0PD', 'status' => 'received']);

        $factory->client()->orders->create(['client_reference' => 'PO-88213']);

        $first = $factory->transport->requestAt(0);
        $second = $factory->transport->requestAt(1);

        self::assertSame($first->headers['Idempotency-Key'], $second->headers['Idempotency-Key']);
        self::assertSame($first->body, $second->body);
    }

    public function testRateLimitedAfterRetriesCarriesRetryAfter(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(429, self::errorBody('RATE_LIMITED'), ['Retry-After' => '30']);

        try {
            $factory->client(maxRetries: 0)->health();
            self::fail('Expected a RateLimitedException.');
        } catch (RateLimitedException $exception) {
            self::assertSame(30, $exception->retryAfter);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorBody(string $code): array
    {
        return [
            'error' => [
                'code' => $code,
                'message' => 'Something to report.',
                'details' => [],
                'request_id' => 'req_01J6ZK3M5X8QW2R7Y9V4B1N0PD',
            ],
        ];
    }
}
