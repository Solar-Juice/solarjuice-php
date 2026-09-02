<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Internal;

/**
 * A successful response, decoded.
 *
 * @internal
 */
final class ApiResponse
{
    /**
     * @param array<string, string> $headers Lower cased header names.
     * @param array<string, mixed>|null $data The decoded JSON body, null for a 304 or an empty body.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        private readonly ?array $data,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * The decoded body. An empty array when there was none, which is the case
     * for a 304 and is why the status is checked rather than the body.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data ?? [];
    }
}
