# Solar Juice Partner API for PHP

Official PHP client for the [Solar Juice Partner API](https://dev.solarjuice.com.au).

The Partner API is for approved sales channels: businesses with a Solar Juice
trade account that sell Solar Juice stock through their own storefront. It gives
you your own price list, the sellable inventory the Solar Outlet storefront sells
from, the specials granted to you, freight quotes from the engine that prices the
Solar Outlet checkout, and order placement against your trade account.

Everything is scoped to your channel. Your prices, your specials, your orders.

- Developer program: <https://dev.solarjuice.com.au>
- Full API reference: <https://dev.solarjuice.com.au/docs>
- The OpenAPI document this package is built against ships in [`spec/openapi.yaml`](spec/openapi.yaml)

## Requirements

- PHP 8.1 or newer
- `ext-curl` and `ext-json`

No runtime dependencies beyond those two extensions.

## Install

```bash
composer require solarjuice/partner-api
```

## Quickstart

```php
use SolarJuice\PartnerApi\Client;

$client = new Client(); // reads SOLARJUICE_API_KEY

foreach ($client->catalogue->autoPage(brand: 'GoodWe') as $product) {
    echo $product['sku'], ' ', $product['price'], PHP_EOL;
}

$quote = $client->shipping->quote([
    'destination' => ['suburb' => 'Parramatta', 'postcode' => '2150', 'state' => 'NSW'],
    'lines' => [['sku' => 'GW-5000-DNS-30', 'quantity' => 1]],
]);
```

## Authentication

Keys look like `sj_live_<keyid>_<secret>` or `sj_test_<keyid>_<secret>` and are
sent as `Authorization: Bearer <key>`. The client reads `SOLARJUICE_API_KEY` from
the environment when you do not pass one, and raises a `ConfigurationException`
when neither is available.

There is one host for both. A `sj_test_` key runs against the same catalogue,
the same prices and the same inventory, and gets real freight quotes; what
differs is that orders placed with it are stored with `sandbox: true` and never
reach operations. Build against a test key, then swap in the live key with no
other change.

Store the full key in a secrets manager. It is shown once when it is issued.

```php
$client = new Client(
    apiKey: $secrets->get('solarjuice_api_key'),
    timeout: 30.0,      // seconds per request, retries excluded
    maxRetries: 3,
    userAgent: 'AcmeSolar/2.1', // appended to solarjuice-php/1.0.0
);
```

## Resources

| Call | Returns |
|---|---|
| `$client->catalogue->list(...)` / `->autoPage(...)` / `->get($sku)` | Products with your channel's price |
| `$client->inventory->list(...)` / `->autoPage(...)` / `->get($sku)` | Sellable quantity per metro |
| `$client->specials->list(...)` / `->autoPage(...)` | Specials granted to your channel |
| `$client->shipping->quote($body)` | A freight quote for a cart and destination |
| `$client->orders->create($body, $key)` | The order receipt |
| `$client->orders->list(...)` / `->autoPage(...)` / `->get($id, $etag)` | Your orders |
| `$client->health()` | Liveness, no key required |

Responses are returned as decoded arrays rather than modelled objects. The API
adds fields within a version, and an array passes new fields through to your code
instead of dropping them on the floor.

## Pagination

`list()` returns the page envelope, so you can read `as_of`, `price_list_version`
and `next_cursor` yourself:

```php
$page = $client->catalogue->list(limit: 500);

$page['as_of'];              // freshest source timestamp behind the page
$page['price_list_version']; // catalogue only
$page['items'];              // the products
$page['next_cursor'];        // null on the last page
```

`autoPage()` is the same query with the paging done for you. It returns a
`Generator`, fetching one page at a time, so a full catalogue sync never holds
more than a page in memory:

```php
foreach ($client->inventory->autoPage() as $item) {
    $store->setStock($item['sku'], $item['total']);
}
```

A cursor is only valid for the query it was issued with, so `autoPage()` keeps
your filters fixed and moves only the cursor. Pass `cursor:` to resume an
interrupted walk.

### Incremental sync

`updated_since` is driven by the service's own change sequence rather than the
product record's edit time, so it is safe to pass back the `as_of` from your last
response without worrying about clock skew:

```php
$page = $client->catalogue->list();
$since = $page['as_of'];

// later
foreach ($client->catalogue->autoPage(updatedSince: $since) as $product) {
    $store->upsert($product);
}
```

Inventory rows that have dropped to zero are still returned by an
`updatedSince` query, with `total: 0`, so you can clear them.

## Placing an order

An order needs a `priced`, unexpired quote for exactly those lines and that
address, the `unit_price` values from the catalogue, and the price list version
they came from:

```php
$version = $client->lastPriceListVersion(); // from the catalogue read

$created = $client->orders->create([
    'client_reference' => 'PO-88213',
    'price_list_version' => $version,
    'quote_id' => $quote['quote_id'],
    'rate_service_code' => 'ALLIED-GENERAL',
    'delivery' => [
        'name' => 'Jane Citizen',
        'phone' => '+61400000000',
        'address1' => '12 Example Street',
        'suburb' => 'Parramatta',
        'postcode' => '2150',
        'state' => 'NSW',
    ],
    'lines' => [
        ['sku' => 'GW-5000-DNS-30', 'quantity' => 1, 'unit_price' => '1110.99'],
    ],
]);

$created->id();              // ord_01J6ZK3M5X8QW2R7Y9V4B1N0PD
$created->status();          // received
$created->idempotencyKey;    // log this next to the order id
```

Money is a decimal string with two places, never a float. Totals on the response
are computed server side and are authoritative.

### Idempotency

Every create sends an `Idempotency-Key` header. Pass your own, or the client
generates a UUID v4 and hands it back on the result so you can log it with the
order id. That pair is what lets you reconcile if a create times out and you do
not know whether it landed.

The key that governs the API's own behaviour is `client_reference` in the body:
the same reference with the same body returns the original receipt, and the same
reference with a different body is refused with `IDEMPOTENCY_CONFLICT`.

### Polling an order

Acceptance is asynchronous. An order comes back `received` and normally reaches
`accepted` within seconds. Poll with the ETag, and a `304` is reported rather
than thrown:

```php
$fetched = $client->orders->get($orderId, $etag);

if ($fetched->notModified) {
    // Nothing has changed. Keep $fetched->etag for the next poll.
} else {
    $status = $fetched->status();
    $etag = $fetched->etag;
}
```

Every poll costs rate limit allowance even when it answers `304`, so poll no
faster than every 30 seconds, or poll everything in one request with
`$client->orders->list(updatedSince: $asOf)`.

## Errors

Every error carries the code, the HTTP status, the details the API listed, and
the request id to quote in a support request:

```php
use SolarJuice\PartnerApi\Exception\ApiException;
use SolarJuice\PartnerApi\Exception\PriceChangedException;

try {
    $client->orders->create($order);
} catch (PriceChangedException $e) {
    $e->details;   // the current price for each line that moved
    refreshCatalogue();
} catch (ApiException $e) {
    $e->errorCode; // PRICE_CHANGED, VALIDATION_FAILED, ...
    $e->statusCode;
    $e->requestId;
}
```

| Exception | Code | HTTP |
|---|---|---|
| `UnauthorizedException` | `UNAUTHORIZED` | 401 |
| `ForbiddenException` | `FORBIDDEN` | 403 |
| `NotFoundException` | `NOT_FOUND` | 404 |
| `ValidationFailedException` | `VALIDATION_FAILED` | 422 |
| `RateLimitedException` | `RATE_LIMITED` | 429 |
| `PriceChangedException` | `PRICE_CHANGED` | 409 |
| `IdempotencyConflictException` | `IDEMPOTENCY_CONFLICT` | 409 |
| `QuoteUnavailableException` | `QUOTE_UNAVAILABLE` | 503 |
| `StaleDataException` | `STALE_DATA` | 503 |
| `InternalException` | `INTERNAL` | 500 |

All of them extend `ApiException`, which is also what you get for a code added
after this release, so a `catch (ApiException)` never stops working. A request
that never reached the API raises `TransportException` instead, and bad client
settings raise `ConfigurationException`. Everything extends
`SolarJuiceException`.

Note that a quote coming back `manual_quote_required` or `unavailable` is a
successful response, not an exception. Check `quote_status` before reading
`rates`.

## Retries

429, 502, 503, 504 and network failures are retried automatically, up to
`maxRetries` (default 3). Backoff starts at 500ms and doubles with full jitter to
a ceiling of 8 seconds, and `Retry-After` is honoured over the computed delay
whenever the API sends one. Other 4xx responses are not retried: they describe
the request, so repeating it would only spend allowance.

Both POST endpoints are safe to retry. Quotes have no side effects, and orders
carry the idempotency key. Set `maxRetries: 0` to handle it yourself.

## Rate limits

The allowance is per key, 600 requests per minute by default, in a sliding
window. The headers from the last response are readable on the client, so a bulk
sync can slow down before the API has to push back:

```php
$rateLimit = $client->lastRateLimit();

$rateLimit->limit;     // 600
$rateLimit->remaining; // 597
$rateLimit->reset;     // seconds until the window resets

$client->lastRequestId();        // X-Request-Id of the last response
$client->lastPriceListVersion(); // X-Price-List-Version of the last catalogue read
```

## Using a different HTTP client

The client talks to a `Transport`, and the curl implementation is only the
default. Implement the interface to route requests through Guzzle, a PSR-18
client, or a recorder in your tests. The package deliberately does not depend on
PSR-18, so installing it never drags in an HTTP client you did not choose.

```php
use SolarJuice\PartnerApi\Http\Request;
use SolarJuice\PartnerApi\Http\Response;
use SolarJuice\PartnerApi\Http\Transport;

final class GuzzleTransport implements Transport
{
    public function __construct(private readonly \GuzzleHttp\Client $guzzle)
    {
    }

    public function send(Request $request): Response
    {
        $psr = $this->guzzle->request($request->method, $request->url, [
            'headers' => $request->headers,
            'body' => $request->body,
            'timeout' => $request->timeout,
            'http_errors' => false, // statuses are the SDK's business
        ]);

        return new Response($psr->getStatusCode(), $psr->getHeaders(), (string) $psr->getBody());
    }
}

$client = new Client(transport: new GuzzleTransport($guzzle));
```

Throw `TransportException` when no response was obtained at all. That is what
tells the client a retry is worth attempting.

## Development

```bash
composer install
composer test    # phpunit, no network access
composer lint    # PSR-12 via phpcs
```

`tests/SpecConformanceTest.php` parses `spec/openapi.yaml` and asserts that every
`operationId` maps to an implemented method, that the SDK claims no operation the
spec has dropped, and that every paginated operation has an `autoPage()`
counterpart. It is what keeps this package honest as the API grows.

## Support

Quote the `X-Request-Id` from the failing response, readable as
`$client->lastRequestId()`, and email <developers@solarjuice.com.au>.

## License

MIT. See [LICENSE](LICENSE).
