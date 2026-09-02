<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Tests\Support;

use SolarJuice\PartnerApi\Client;

/**
 * Builds clients wired to a stub transport with the clock removed, so backoff
 * is exercised without any test actually waiting.
 */
final class ClientFactory
{
    public const API_KEY = 'sj_test_abcdefghijkl_0123456789abcdef0123456789abcdef';

    /** @var list<float> Seconds each backoff pause asked for, in order. */
    public array $pauses = [];

    public function __construct(public readonly StubTransport $transport = new StubTransport())
    {
    }

    public function client(
        ?string $apiKey = self::API_KEY,
        int $maxRetries = Client::DEFAULT_MAX_RETRIES,
        ?string $userAgent = null,
        string $baseUrl = Client::DEFAULT_BASE_URL,
        float $jitter = 0.5,
    ): Client {
        return new Client(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            maxRetries: $maxRetries,
            userAgent: $userAgent,
            transport: $this->transport,
            sleeper: function (float $seconds): void {
                $this->pauses[] = $seconds;
            },
            jitter: static fn (): float => $jitter,
        );
    }
}
