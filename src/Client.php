<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi;

use Closure;
use SolarJuice\PartnerApi\Exception\ConfigurationException;
use SolarJuice\PartnerApi\Http\CurlTransport;
use SolarJuice\PartnerApi\Http\Transport;
use SolarJuice\PartnerApi\Internal\ApiRequester;
use SolarJuice\PartnerApi\Resources\Catalogue;
use SolarJuice\PartnerApi\Resources\Inventory;
use SolarJuice\PartnerApi\Resources\Orders;
use SolarJuice\PartnerApi\Resources\Shipping;
use SolarJuice\PartnerApi\Resources\Specials;

/**
 * The entry point to the Solar Juice Partner API.
 *
 * ```php
 * $client = new Client(); // apiKey defaults to the SOLARJUICE_API_KEY environment variable
 *
 * foreach ($client->catalogue->autoPage() as $product) {
 *     echo $product['sku'], ' ', $product['price'], PHP_EOL;
 * }
 * ```
 *
 * One instance is enough for a whole process. It holds no connection state, and
 * the response metadata it records (rate limit, request id, price list version)
 * is per instance, so give each concurrent worker its own client if you read
 * those.
 */
final class Client
{
    public const VERSION = '1.0.0';
    public const DEFAULT_BASE_URL = 'https://api.solarjuice.com.au';
    public const DEFAULT_TIMEOUT = 30.0;
    public const DEFAULT_MAX_RETRIES = 3;
    public const API_KEY_ENV = 'SOLARJUICE_API_KEY';

    public readonly Catalogue $catalogue;
    public readonly Inventory $inventory;
    public readonly Specials $specials;
    public readonly Shipping $shipping;
    public readonly Orders $orders;

    public readonly string $baseUrl;
    public readonly float $timeout;
    public readonly int $maxRetries;
    public readonly string $userAgent;

    private readonly ApiRequester $requester;

    /**
     * @param string|null $apiKey Full key, `sj_live_...` or `sj_test_...`. Falls back to the SOLARJUICE_API_KEY
     *                            environment variable.
     * @param string $baseUrl Override only for a proxy or a recorded fixture server.
     * @param float $timeout Seconds allowed per request, retries excluded.
     * @param int $maxRetries Retries after the first attempt. Zero disables retrying.
     * @param string|null $userAgent Suffix identifying your application, appended to the SDK's own User-Agent.
     * @param Transport|null $transport Swap in Guzzle, a PSR-18 client or a stub. Defaults to curl.
     * @param Closure(float): void|null $sleeper Backoff hook, mainly so tests do not really wait.
     * @param Closure(): float|null $jitter Returns a value in [0, 1) for the backoff draw.
     *
     * @throws ConfigurationException When no API key is available or a setting is out of range.
     */
    public function __construct(
        ?string $apiKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        ?string $userAgent = null,
        ?Transport $transport = null,
        ?Closure $sleeper = null,
        ?Closure $jitter = null,
    ) {
        $key = self::resolveApiKey($apiKey);

        if ($timeout <= 0) {
            throw new ConfigurationException('timeout must be greater than zero seconds.');
        }

        if ($maxRetries < 0) {
            throw new ConfigurationException('maxRetries cannot be negative.');
        }

        $this->baseUrl = rtrim(trim($baseUrl), '/');

        if ($this->baseUrl === '') {
            throw new ConfigurationException('baseUrl cannot be empty.');
        }

        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
        $this->userAgent = self::buildUserAgent($userAgent);

        $this->requester = new ApiRequester(
            $key,
            $this->baseUrl,
            $this->timeout,
            $this->maxRetries,
            $this->userAgent,
            $transport ?? new CurlTransport(),
            $sleeper ?? static function (float $seconds): void {
                if ($seconds > 0) {
                    usleep((int) round($seconds * 1_000_000));
                }
            },
            $jitter ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1),
        );

        $this->catalogue = new Catalogue($this->requester);
        $this->inventory = new Inventory($this->requester);
        $this->specials = new Specials($this->requester);
        $this->shipping = new Shipping($this->requester);
        $this->orders = new Orders($this->requester);
    }

    /**
     * Unauthenticated liveness check.
     *
     * It reports that the API process is up, nothing about data freshness. For
     * that, read `stale` and `as_of` on an inventory response.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->requester->send('GET', '/v1/health')->data();
    }

    /**
     * Rate limit headers from the most recent response, or null before the
     * first call.
     */
    public function lastRateLimit(): ?RateLimit
    {
        return $this->requester->lastRateLimit();
    }

    /**
     * The X-Request-Id of the most recent response. Quote it in support
     * requests; it is what lets Solar Juice find the exact call in the logs.
     */
    public function lastRequestId(): ?string
    {
        return $this->requester->lastRequestId();
    }

    /**
     * The X-Price-List-Version from the most recent catalogue response. Submit
     * it as `price_list_version` when you place an order priced from that
     * catalogue read.
     */
    public function lastPriceListVersion(): ?string
    {
        return $this->requester->lastPriceListVersion();
    }

    private static function resolveApiKey(?string $apiKey): string
    {
        $key = trim((string) ($apiKey ?? getenv(self::API_KEY_ENV) ?: ''));

        if ($key === '') {
            throw new ConfigurationException(sprintf(
                'No Solar Juice API key. Pass apiKey to the client or set the %s environment variable. '
                . 'Keys look like sj_live_<keyid>_<secret> or sj_test_<keyid>_<secret>.',
                self::API_KEY_ENV,
            ));
        }

        return $key;
    }

    private static function buildUserAgent(?string $suffix): string
    {
        $agent = 'solarjuice-php/' . self::VERSION;
        $suffix = $suffix === null ? '' : trim($suffix);

        return $suffix === '' ? $agent : $agent . ' ' . $suffix;
    }
}
