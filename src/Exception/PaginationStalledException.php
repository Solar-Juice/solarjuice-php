<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Exception;

/**
 * An auto-pager was handed the same cursor twice in a row.
 *
 * The walk stops here rather than requesting that page for ever: an unmoving
 * cursor is a server side fault, and the loop it causes would spend the whole
 * of the key's rate limit allowance before anyone noticed. Report the request
 * id with the SKU or order you were syncing.
 */
final class PaginationStalledException extends ApiException
{
    public const CODE = 'PAGINATION_STALLED';

    public function __construct(string $cursor, ?string $requestId = null)
    {
        parent::__construct(
            'Pagination stopped making progress: the API returned the same cursor twice.',
            self::CODE,
            0,
            [['cursor' => $cursor]],
            $requestId,
        );
    }
}
