<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Http;

/**
 * An outgoing HTTP request, fully resolved.
 *
 * The client builds this and hands it to a {@see Transport}. Everything a
 * transport needs is on the object, so an alternative transport never has to
 * reach back into the client for configuration.
 */
final class Request
{
    /**
     * @param array<string, string> $headers Header name => value, ready to send.
     * @param string|null $body Encoded request body, or null when there is none.
     * @param float $timeout Total time allowed for the request, in seconds.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly float $timeout = 30.0,
    ) {
    }

    /**
     * Headers in the "Name: value" form most HTTP libraries expect.
     *
     * @return list<string>
     */
    public function headerLines(): array
    {
        $lines = [];

        foreach ($this->headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }
}
