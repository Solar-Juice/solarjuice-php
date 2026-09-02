<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi;

use Closure;
use LogicException;
use SolarJuice\PartnerApi\Exception\ConfigurationException;
use Stringable;

/**
 * An API key that does not leak into debug output.
 *
 * A live key printed onto an error page or into a log aggregator has to be
 * rotated, and PHP hands one out very readily: `print_r($client)` walks private
 * properties, `var_export` and `serialize` walk them too, and an exception
 * records the arguments of every live frame. So the secret is not held in a
 * property at all. It lives inside a closure, where none of those reflection
 * based dumpers can reach it, and every debug hook here answers with a
 * placeholder.
 *
 * `reveal()` is deliberately the only way to the real value.
 */
final class ApiKey implements Stringable
{
    public const REDACTED = '[redacted]';

    /** @var Closure(): string */
    private readonly Closure $secret;

    /**
     * @throws ConfigurationException When the key is blank.
     */
    public function __construct(string $key)
    {
        $key = trim($key);

        if ($key === '') {
            throw ConfigurationException::withoutTraceArguments('An API key cannot be blank.');
        }

        $this->secret = static fn (): string => $key;
    }

    /**
     * The real key, for the Authorization header and nothing else.
     */
    public function reveal(): string
    {
        return ($this->secret)();
    }

    /**
     * The part of the key that is not a secret.
     *
     * Only the `sj_<env>_<keyid>` prefix stays visible after a key is issued,
     * so it is the one part that is safe to print, and it is enough to tell a
     * live key from a test key in a support conversation.
     */
    public function masked(): string
    {
        $parts = explode('_', $this->reveal());

        return count($parts) >= 4 ? implode('_', array_slice($parts, 0, 3)) . '_...' : self::REDACTED;
    }

    public function __toString(): string
    {
        return self::REDACTED;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['key' => $this->masked()];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        return ['key' => self::REDACTED];
    }

    /**
     * @param array<string, string> $data
     */
    public function __unserialize(array $data): void
    {
        // Failing loudly beats restoring a client that would send the literal
        // placeholder as its bearer token and get a puzzling 401.
        throw new LogicException(
            'A Solar Juice API key is not serialisable. Build the client again from your secrets manager.',
        );
    }
}
