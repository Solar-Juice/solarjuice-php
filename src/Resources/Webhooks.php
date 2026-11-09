<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Resources;

/**
 * Webhook endpoints this channel has registered.
 *
 * A delivery is a notification, not the data: on receipt, re-fetch the
 * catalogue or specials it names. Any key for the channel can manage its
 * endpoints, and the channel is always taken from the key, so one partner can
 * never see or touch another's.
 */
final class Webhooks extends ApiResource
{
    private const PATH = '/v1/webhooks';

    /**
     * Every endpoint this channel has registered, disabled ones included, with
     * delivery health (`last_status`, `last_error`, `consecutive_failures`).
     *
     * The API returns them all in one response, so there is no cursor and no
     * auto-pager.
     *
     * @return array<string, mixed> The body: `endpoints`, a list of WebhookEndpoint.
     */
    public function list(): array
    {
        return $this->requester->send('GET', self::PATH)->data();
    }

    /**
     * Register an HTTPS URL to receive event notifications.
     *
     * The response carries the signing `secret`, and this is the only time the
     * API ever returns it. Store it straight away; to rotate it, delete the
     * endpoint and register a new one.
     *
     * @param array<string, mixed> $endpoint The WebhookCreateRequest body: url, events, and an optional description.
     *
     * @return array<string, mixed> The WebhookEndpoint, plus `secret`.
     */
    public function create(array $endpoint): array
    {
        return $this->requester->send('POST', self::PATH, [], $endpoint)->data();
    }

    /**
     * Remove an endpoint and invalidate its secret.
     *
     * An id that belongs to another channel raises the same not found error as
     * one that does not exist.
     */
    public function delete(string $id): void
    {
        $this->requester->send('DELETE', self::PATH . '/' . rawurlencode($id));
    }

    /**
     * Switch a disabled endpoint back on and clear its failure count.
     *
     * The endpoint keeps its id and its signing secret, which is why this is
     * the way back after an outage rather than deleting and registering again:
     * a new registration issues a new secret your receiver would not know.
     * Calling it on an endpoint that is already active is harmless.
     *
     * @return array<string, mixed> The WebhookEndpoint, now `active`.
     */
    public function enable(string $id): array
    {
        return $this->requester
            ->send('POST', self::PATH . '/' . rawurlencode($id) . '/enable')
            ->data();
    }

    /**
     * Queue one `webhook.test` delivery to this endpoint.
     *
     * It travels the same signing and retry path as a real event, so if it
     * arrives and verifies, real events will too. A disabled endpoint is
     * refused with `VALIDATION_FAILED` rather than queued, because it would
     * never be delivered; enable it first.
     *
     * @return array<string, mixed> The receipt: `queued`, `event`, and an optional `note`.
     */
    public function test(string $id): array
    {
        return $this->requester
            ->send('POST', self::PATH . '/' . rawurlencode($id) . '/test')
            ->data();
    }
}
