<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Internal;

use Closure;
use JsonException;
use SolarJuice\PartnerApi\Exception\ApiException;
use SolarJuice\PartnerApi\Exception\ErrorFactory;
use SolarJuice\PartnerApi\Exception\TransportException;
use SolarJuice\PartnerApi\Http\Request;
use SolarJuice\PartnerApi\Http\Response;
use SolarJuice\PartnerApi\Http\Transport;
use SolarJuice\PartnerApi\RateLimit;

/**
 * Everything that happens around a request: headers, retries, error mapping and
 * the response metadata the client exposes afterwards.
 *
 * Resource classes hold one of these and stay free of HTTP concerns.
 *
 * @internal
 */
final class ApiRequester
{
    /**
     * Statuses worth trying again. 429 is the API asking us to slow down, and
     * 502, 503 and 504 are almost always a transient hop failure. Every other
     * 4xx describes the request itself, so repeating it would only waste
     * allowance.
     */
    private const RETRYABLE_STATUSES = [429, 502, 503, 504];

    private ?RateLimit $lastRateLimit = null;
    private ?string $lastRequestId = null;
    private ?string $lastPriceListVersion = null;

    /**
     * @param Closure(float): void $sleeper Pauses for the given number of seconds.
     * @param Closure(): float $jitter Returns a value in [0, 1) for the backoff draw.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly float $timeout,
        private readonly int $maxRetries,
        private readonly string $userAgent,
        private readonly Transport $transport,
        private readonly Closure $sleeper,
        private readonly Closure $jitter,
    ) {
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function send(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        array $headers = [],
    ): ApiResponse {
        $request = new Request(
            $method,
            $this->baseUrl . $path . Query::build($query),
            $this->headers($body !== null, $headers),
            $body === null ? null : self::encode($body),
            $this->timeout,
        );

        $attempt = 0;

        while (true) {
            try {
                $response = $this->transport->send($request);
            } catch (TransportException $exception) {
                if ($attempt >= $this->maxRetries) {
                    throw $exception;
                }

                $this->pause($attempt++, null);
                continue;
            }

            $this->remember($response);

            // 304 is a successful conditional request, not an error.
            if ($response->status < 300 || $response->status === 304) {
                return new ApiResponse($response->status, $response->headers, self::decode($response));
            }

            if ($attempt < $this->maxRetries && in_array($response->status, self::RETRYABLE_STATUSES, true)) {
                $this->pause($attempt++, RetryAfter::seconds($response->header('retry-after')));
                continue;
            }

            throw ErrorFactory::fromResponse($response);
        }
    }

    public function lastRateLimit(): ?RateLimit
    {
        return $this->lastRateLimit;
    }

    public function lastRequestId(): ?string
    {
        return $this->lastRequestId;
    }

    public function lastPriceListVersion(): ?string
    {
        return $this->lastPriceListVersion;
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function headers(bool $hasBody, array $extra): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];

        if ($hasBody) {
            $headers['Content-Type'] = 'application/json';
        }

        // Caller supplied headers win, which is what makes Idempotency-Key and
        // If-None-Match work without special casing them here.
        return array_merge($headers, $extra);
    }

    private function pause(int $attempt, ?float $retryAfter): void
    {
        // The API knows when its window resets; our own curve is only a guess.
        ($this->sleeper)($retryAfter ?? Backoff::delay($attempt, ($this->jitter)()));
    }

    private function remember(Response $response): void
    {
        $limit = self::intHeader($response, 'ratelimit-limit');
        $remaining = self::intHeader($response, 'ratelimit-remaining');
        $reset = self::intHeader($response, 'ratelimit-reset');

        if ($limit !== null || $remaining !== null || $reset !== null) {
            $this->lastRateLimit = new RateLimit($limit, $remaining, $reset);
        }

        $this->lastRequestId = $response->header('x-request-id') ?? $this->lastRequestId;

        // Only catalogue responses carry this, so an inventory call must not
        // clear the version a caller is about to submit with an order.
        $this->lastPriceListVersion = $response->header('x-price-list-version') ?? $this->lastPriceListVersion;
    }

    private static function intHeader(Response $response, string $name): ?int
    {
        $value = $response->header($name);

        return $value !== null && preg_match('/^-?\d+$/', trim($value)) === 1 ? (int) trim($value) : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function encode(array $body): string
    {
        // Unescaped slashes keep image and Location URLs readable in logs.
        return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(Response $response): ?array
    {
        if (trim($response->body) === '') {
            return null;
        }

        try {
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ApiException(
                'The API returned a body that is not valid JSON.',
                null,
                $response->status,
                [],
                $response->header('x-request-id'),
                $exception,
            );
        }

        return is_array($decoded) ? $decoded : null;
    }
}
