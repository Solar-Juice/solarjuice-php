<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Exception;

/**
 * The client was constructed with unusable settings, for example no API key.
 *
 * Thrown before any request is attempted, so it always means a deployment or
 * wiring problem rather than anything the API did.
 */
final class ConfigurationException extends SolarJuiceException
{
    /**
     * Build the exception without recording the arguments of the frames it was
     * thrown from.
     *
     * The client is constructed with the API key as an argument, and PHP copies
     * every live frame's arguments onto an exception unless
     * `zend.exception_ignore_args` is on, which it is not by default. That is
     * how a key ends up on a Whoops page or in a Sentry issue. Argument capture
     * is suppressed for the instant the exception is built and restored
     * immediately, so nothing else in the process is affected.
     */
    public static function withoutTraceArguments(string $message): self
    {
        $previous = ini_set('zend.exception_ignore_args', '1');
        $exception = new self($message);

        if ($previous !== false) {
            ini_set('zend.exception_ignore_args', $previous);
        }

        return $exception;
    }
}
