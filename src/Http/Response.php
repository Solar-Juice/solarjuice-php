<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Http;

/**
 * A raw HTTP response as returned by a {@see Transport}.
 *
 * Header names are lower cased on the way in so that lookups never depend on
 * the casing a server or an alternative transport happened to use.
 */
final class Response
{
    /** @var array<string, string> */
    public readonly array $headers;

    /**
     * @param array<string, string|int|list<string>> $headers
     */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
    ) {
        $normalised = [];

        foreach ($headers as $name => $value) {
            // Repeated headers arrive as a list; join them the way HTTP allows.
            $normalised[strtolower((string) $name)] = is_array($value)
                ? implode(', ', array_map('strval', $value))
                : (string) $value;
        }

        $this->headers = $normalised;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
