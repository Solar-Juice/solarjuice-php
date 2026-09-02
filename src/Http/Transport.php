<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Http;

use SolarJuice\PartnerApi\Exception\TransportException;

/**
 * The seam between the SDK and whatever actually moves bytes.
 *
 * The default implementation is {@see CurlTransport}, which keeps the package
 * free of runtime dependencies. Implement this interface to route requests
 * through Guzzle, a PSR-18 client, a queue, or a stub in your own tests. The
 * SDK deliberately does not depend on PSR-18 so that installing it never drags
 * in a client you did not choose.
 *
 * An implementation MUST NOT throw on a non 2xx status: statuses are the
 * client's business. It MUST throw {@see TransportException} when no response
 * was obtained at all (DNS failure, connection refused, TLS failure, timeout),
 * because that is what tells the client a retry is worth attempting.
 */
interface Transport
{
    /**
     * @throws TransportException When no HTTP response could be obtained.
     */
    public function send(Request $request): Response;
}
