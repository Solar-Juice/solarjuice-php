<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Internal;

/**
 * Query string construction.
 *
 * @internal
 */
final class Query
{
    /**
     * Build a query string, dropping nulls so that optional parameters can be
     * passed straight through as named arguments without the caller having to
     * assemble an array.
     *
     * @param array<string, scalar|null> $params
     */
    public static function build(array $params): string
    {
        $pairs = [];

        foreach ($params as $name => $value) {
            if ($value === null) {
                continue;
            }

            // The API reads booleans as the literals true and false, not 1 and 0.
            $pairs[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $pairs === [] ? '' : '?' . http_build_query($pairs, '', '&', PHP_QUERY_RFC3986);
    }
}
