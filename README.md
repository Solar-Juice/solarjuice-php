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

### The key stays out of your logs

`print_r($client)`, `var_dump`, `var_export`, `serialize` and `dd()` all print
`sj_live_<keyid>_...` and never the secret, and a `ConfigurationException` from
the constructor is built without the frame arguments that would otherwise carry
it onto a Whoops or Ignition page. That covers the ways a key usually escapes.

Passing the key as a plain string still leaves it as an argument of your own
call to `new Client(...)`, which anything dumping *your* stack frames could
read. Wrap it, or hand over a closure, and there is nothing there to read:

```php
use SolarJuice\PartnerApi\ApiKey;

$client = new Client(apiKey: new ApiKey($secrets->get('solarjuice_api_key')));
$client = new Client(apiKey: static fn (): string => $secrets->get('solarjuice_api_key'));
```

The closure is called once, while the client is being built, so a missing key is
still a construction failure rather than a surprise on the first request.

## Resources

| Call | Returns |
|---|---|
| `$client->catalogue->list(...)` / `->autoPage(...)` / `->get($sku)` | Products with your channel's price |
| `$client->inventory->list(...)` / `->autoPage(...)` / `->get($sku)` | Sellable quantity per metro |
| `$client->specials->list(...)` / `->autoPage(...)` | Specials granted to your channel |
| `$client->shipping->quote($body)` | A freight quote for a cart and destination |
| `$client->orders->create($body, $key)` | The order receipt |
| `$client->orders->list(...)` / `->autoPage(...)` / `->get($id, $etag)` | Your orders |
| `$client->orders->cancel($id, $note)` | The order, now `cancelled` |
| `$client->health()` | Liveness, no key required |

Responses are returned as decoded arrays rather than modelled objects. The API
adds fields within a version, and an array passes new fields through to your code
instead of dropping them on the floor.

## Kits

A kit is a bundle sold under one SKU, for example `Kit-14406`, that holds no
stock of its own and is assembled from ordinary catalogue products. Every
catalogue product carries `is_kit`; a kit also carries `components`, a list of
`{sku, quantity}`, which is absent rather than empty on everything else.
Branch on `is_kit` and not on the SKU prefix, which is a naming habit and not
part of the contract.

Three of a kit's figures are worked out differently, and assuming otherwise is
what makes a partner's numbers disagree with ours:

* `price` is the kit's own, set by hand against the kit. Summing the
  components will not reproduce it.
* `weight_kg` is the kit's own too, recorded against the kit exactly as it is
  on any other product. A weight worked out from the components is only a
  fallback for a kit that has none of its own, so do not rebuild it from
  `components`: your figure would not be ours.
* Freight is not priced from `weight_kg`, for a kit or for anything else. The
  quote endpoint plans every consignment from the product's shipping
  specification, its packed dimensions and the weight recorded there, and
  never reads `weight_kg`, which is published for information only. Do not
  pre-estimate freight from it and then reconcile against our quote; the two
  are allowed to differ. Quote the cart and read the rate. Quote and order the
  kit SKU, never its parts.
* Availability is derived. Per metro it is
  `floor(min over components of (component_available / quantity))`, and `total`
  is the sum of those per metro figures, not a minimum taken against national
  component totals. A kit ships from a single metro, so a battery in Perth
  cannot complete a kit in Sydney; if your own arithmetic gives a larger
  number, that is why.

A kit is left out of the catalogue and the inventory feed altogether, rather
than reported as zero, when a component is inactive or missing, when a
component's quantity is not positive, when it has no components at all, or
when its weight cannot be resolved either from itself or from its components.
Withholding is the safer failure: we would rather not list a bundle than list
one we cannot describe accurately.

Being listed means the kit can be ordered. It does not guarantee an automatic
freight rate: publication needs a resolvable weight, while quoting also needs
the full packed dimensions. Every kit publishing today has them, so in
practice what you will meet is a kit quoting `manual_quote_required`, usually
because one unit is heavier than a standard pallet movement allows. That is a
normal outcome rather than a fault, and retrying will not change it: a person
prices the freight instead.

Kit delivery is switched on per channel and is off by default. With it off you
see no kits at all: a kit SKU is indistinguishable from one that does not
exist, and nothing else about the responses changes, so `is_kit` is still on
every product you can see and is simply always false. If you expect kits and
cannot see any, ask your Solar Juice account manager to enable kit delivery
rather than looking for a fault in your client.

```php
$product = $client->catalogue->get('Kit-14406');

if ($product['is_kit']) {
    $parts = array_map(
        static fn (array $c): string => $c['quantity'] . ' x ' . $c['sku'],
        $product['components'],
    );
    printf("%s sells for %s and contains %s\n", $product['sku'], $product['price'], implode(', ', $parts));
}

// How many you can sell is a separate call. Never infer it from the parts.
$stock = $client->inventory->get('Kit-14406');
$stock['available']; // ['Sydney' => 4, 'Melbourne' => 1]
$stock['total'];     // 5
```

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

If the API ever hands back the cursor it was just given, the walk raises
`PaginationStalledException` rather than fetching that page for ever and
spending the whole of your allowance on it.

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

$created->id();              // ord_01j6zk3m5x8qw2r7y9v4b1n0pd
$created->status();          // accepted
$created->idempotencyKey;    // local correlation value only, the API ignores it
```

Money is a decimal string with two places, never a float. Totals on the response
are computed server side and are authoritative.

### Idempotency

`client_reference` in the body is the only idempotency key. The same reference
with the same body returns the order that already exists (`200` rather than the
first call's `202`); the same reference with a different body is refused with
`IDEMPOTENCY_CONFLICT`. Sandbox and live keys have separate reference
namespaces.

Every create also sends an `Idempotency-Key` header, yours or a generated UUID
v4, and hands it back on the result. The API accepts that header and ignores
it: it is not stored, not compared and not returned, so it is a local
correlation value for your own logs. If a create times out, do not look for the
order by that key, look for it by your own reference:

```php
$page = $client->orders()->list(['client_reference' => 'PO-88213']);
```

### Polling an order

Validation and acceptance are synchronous: an order comes back already
`accepted`. What you are polling for is fulfilment, which operations drive.
Poll with the ETag, and a `304` is reported rather than thrown:

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

### Cancelling an order

A partner can cancel while the order is `received`, `accepted` or `on_hold`,
which in practice means before operations key it into the fulfilment system.
After that the API refuses with `VALIDATION_FAILED` and the cancellation has to
go through your account manager. There is no un-cancel:

```php
$order = $client->orders->cancel($orderId, 'Customer changed the panel selection');

$order['status']; // cancelled
```

The note is optional and is recorded on the event; without one the API records
`cancelled by partner`. Read `status` on the order you hold before calling: a
second cancel is refused rather than ignored.

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

`errorCode` is set even when the response is not the documented envelope. An
edge proxy answering a `429` with an HTML page still produces
`errorCode === 'RATE_LIMITED'`, so switching on the code is safe. The two
statuses that cover two codes each, `409` and `503`, are the exception: with no
envelope to read there is nothing to choose between them, so the code is null
and you get the base `ApiException` with the status intact.

`retryAfter` is on every error, in whole seconds, whenever the response carried
a `Retry-After` header, not only on `RateLimitedException`.

A `2xx` whose body is not a JSON object raises too. A captive portal or a
misrouted proxy answering `200` with an HTML page is not a page of products, and
surfacing it here beats a missing key three functions later.

Note that a quote coming back `manual_quote_required` or `unavailable` is a
successful response, not an exception. Check `quote_status` before reading
`rates`.

## Retries

429, 502, 503, 504 and network failures are retried automatically, up to
`maxRetries` (default 3). Backoff starts at 500ms and doubles with full jitter to
a ceiling of 8 seconds, and `Retry-After` is honoured over the computed delay
whenever the API sends one. Other 4xx responses are not retried: they describe
the request, so repeating it would only spend allowance.

An honoured `Retry-After` is capped at 60 seconds. The API's own values are
small, but an edge proxy in front of it is not bound by that, and parking a
synchronous request for the hour one of them asks for is worse than failing. A
longer value is not slept on: the error is raised straight away with the real
value on `retryAfter`, and you decide.

Both POST endpoints are safe to retry. Quotes have no side effects, and orders
are deduplicated by `client_reference`. Set `maxRetries: 0` to handle it
yourself.

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

`tests/fixtures/error-mapping.json` is a byte identical copy of the table the
Node and Ruby clients run: one response in, one decision out. It is what keeps
the three clients answering the same way, so keep the copies in step and add
cases to all three.

## Support

Quote the `X-Request-Id` from the failing response, readable as
`$client->lastRequestId()`, and email <developers@solarjuice.com.au>.

## License

MIT. See [LICENSE](LICENSE).
