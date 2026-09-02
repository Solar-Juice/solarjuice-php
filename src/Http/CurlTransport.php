<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Http;

use SolarJuice\PartnerApi\Exception\TransportException;

/**
 * The default transport, built on ext-curl.
 *
 * A fresh handle per request keeps the transport safe to share between
 * concurrent clients and avoids carrying connection state across calls, which
 * matters more here than the small cost of a new connection: partner traffic is
 * dominated by paged syncs, not by chatty single requests.
 */
final class CurlTransport implements Transport
{
    /**
     * @param float $connectTimeout Seconds allowed for connection setup, inside the request's total timeout.
     */
    public function __construct(private readonly float $connectTimeout = 10.0)
    {
    }

    public function send(Request $request): Response
    {
        $handle = curl_init();

        if ($handle === false) {
            throw new TransportException('Could not initialise a curl handle.');
        }

        $headers = [];

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $request->headerLines(),
            CURLOPT_RETURNTRANSFER => true,
            // A 202 carries a Location header pointing at the new order. Following
            // it would turn one create into a second, unasked for, GET.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => self::milliseconds($request->timeout),
            CURLOPT_CONNECTTIMEOUT_MS => self::milliseconds(min($this->connectTimeout, $request->timeout)),
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$headers): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
                }

                return $length;
            },
        ];

        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }

        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        curl_close($handle);

        if ($errorNumber !== 0 || $body === false) {
            $reason = $errorMessage !== '' ? $errorMessage : 'unknown curl error';

            throw new TransportException(
                sprintf('%s %s failed: %s', $request->method, $request->url, $reason),
                $errorNumber,
            );
        }

        return new Response($status, $headers, (string) $body);
    }

    private static function milliseconds(float $seconds): int
    {
        // curl treats 0 as "no limit", so never round a positive timeout down to it.
        return max(1, (int) round($seconds * 1000));
    }
}
