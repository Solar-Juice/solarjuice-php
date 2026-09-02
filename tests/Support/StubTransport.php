<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Tests\Support;

use LogicException;
use SolarJuice\PartnerApi\Exception\TransportException;
use SolarJuice\PartnerApi\Http\Request;
use SolarJuice\PartnerApi\Http\Response;
use SolarJuice\PartnerApi\Http\Transport;

/**
 * A transport that replays queued responses and records what it was asked to
 * send. No test in this suite touches the network.
 */
final class StubTransport implements Transport
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var list<Response|TransportException> */
    private array $queue = [];

    /**
     * @param array<string, mixed> $body
     * @param array<string, string|int> $headers
     */
    public function pushJson(int $status, array $body, array $headers = []): self
    {
        return $this->push(new Response(
            $status,
            $headers + ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        ));
    }

    public function push(Response|TransportException $next): self
    {
        $this->queue[] = $next;

        return $this;
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;

        if ($this->queue === []) {
            throw new LogicException(sprintf(
                'StubTransport ran out of queued responses at request %d (%s %s).',
                count($this->requests),
                $request->method,
                $request->url,
            ));
        }

        $next = array_shift($this->queue);

        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next;
    }

    public function callCount(): int
    {
        return count($this->requests);
    }

    public function lastRequest(): Request
    {
        $last = end($this->requests);

        if ($last === false) {
            throw new LogicException('No request has been sent.');
        }

        return $last;
    }

    public function requestAt(int $index): Request
    {
        if (!isset($this->requests[$index])) {
            throw new LogicException(sprintf('No request at index %d.', $index));
        }

        return $this->requests[$index];
    }

    public function isDrained(): bool
    {
        return $this->queue === [];
    }
}
