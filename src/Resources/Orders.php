<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Resources;

use Generator;
use SolarJuice\PartnerApi\Internal\Uuid;
use SolarJuice\PartnerApi\Result\CreatedOrder;
use SolarJuice\PartnerApi\Result\FetchedOrder;

/**
 * Orders placed against this channel's trade account.
 *
 * Acceptance is asynchronous: a create returns `received`, and the order moves
 * on from there. Poll one order with `get()` and its ETag, or poll them all in
 * a single request with `list(updatedSince: ...)`.
 */
final class Orders extends ApiResource
{
    private const PATH = '/v1/orders';

    /**
     * Place an order.
     *
     * An Idempotency-Key header is always sent, generated here when the caller
     * does not supply one. It is what makes the request safe to retry after a
     * timeout, and it is returned on the result so it can be logged next to the
     * order id. Note that the API's own idempotency turns on `client_reference`
     * in the body; the header is recorded for your reconciliation.
     *
     * @param array<string, mixed> $order The OrderRequest body.
     * @param string|null $idempotencyKey Your own key, or null to generate a UUID v4.
     */
    public function create(array $order, ?string $idempotencyKey = null): CreatedOrder
    {
        $key = $idempotencyKey ?? Uuid::v4();

        $response = $this->requester->send(
            'POST',
            self::PATH,
            [],
            $order,
            ['Idempotency-Key' => $key],
        );

        return new CreatedOrder($response->data(), $key);
    }

    /**
     * One page of orders, newest first.
     *
     * @param string|null $status Filter to one OrderStatus value.
     * @param string|null $clientReference Return the order with this reference, if any.
     *
     * @return array<string, mixed> The envelope: as_of, items, next_cursor.
     */
    public function list(
        ?int $limit = null,
        ?string $cursor = null,
        ?string $updatedSince = null,
        ?string $status = null,
        ?string $clientReference = null,
    ): array {
        return $this->requester
            ->send('GET', self::PATH, self::query($limit, $cursor, $updatedSince, $status, $clientReference))
            ->data();
    }

    /**
     * Every order across every page, one at a time.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function autoPage(
        ?int $limit = null,
        ?string $cursor = null,
        ?string $updatedSince = null,
        ?string $status = null,
        ?string $clientReference = null,
    ): Generator {
        yield from $this->walk(self::PATH, self::query($limit, $cursor, $updatedSince, $status, $clientReference));
    }

    /**
     * One order with its full event history.
     *
     * Pass the ETag from the previous fetch as `$ifNoneMatch` when polling. A
     * 304 comes back as a result with `notModified` set, not as an exception,
     * because nothing has gone wrong. It still costs rate limit allowance, so
     * poll no faster than every 30 seconds.
     */
    public function get(string $id, ?string $ifNoneMatch = null): FetchedOrder
    {
        $response = $this->requester->send(
            'GET',
            self::PATH . '/' . rawurlencode($id),
            [],
            null,
            $ifNoneMatch === null ? [] : ['If-None-Match' => $ifNoneMatch],
        );

        $etag = $response->header('etag') ?? $ifNoneMatch;

        if ($response->status === 304) {
            return new FetchedOrder(null, true, $etag);
        }

        return new FetchedOrder($response->data(), false, $etag);
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function query(
        ?int $limit,
        ?string $cursor,
        ?string $updatedSince,
        ?string $status,
        ?string $clientReference,
    ): array {
        return [
            'limit' => $limit,
            'cursor' => $cursor,
            'updated_since' => $updatedSince,
            'status' => $status,
            'client_reference' => $clientReference,
        ];
    }
}
