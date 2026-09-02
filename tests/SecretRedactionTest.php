<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Tests;

use PHPUnit\Framework\TestCase;
use SolarJuice\PartnerApi\ApiKey;
use SolarJuice\PartnerApi\Client;
use SolarJuice\PartnerApi\Exception\ConfigurationException;
use SolarJuice\PartnerApi\Tests\Support\ClientFactory;
use SolarJuice\PartnerApi\Tests\Support\StubTransport;
use Throwable;

/**
 * A live key that reaches a debug page, a log aggregator or an issue tracker
 * has to be rotated, so none of the ordinary ways a PHP object gets inspected
 * may print one.
 */
final class SecretRedactionTest extends TestCase
{
    private const LIVE_KEY = 'sj_live_k7m3qp2xr9vt_9f2c4b1e7a6d8c3f5e0b2a4d6c8e1f3a';
    private const SECRET = '9f2c4b1e7a6d8c3f5e0b2a4d6c8e1f3a';

    public function testPrintRDoesNotExposeTheKey(): void
    {
        $client = (new ClientFactory())->client(apiKey: self::LIVE_KEY);

        self::assertStringNotContainsString(self::SECRET, print_r($client, true));
    }

    public function testVarDumpDoesNotExposeTheKey(): void
    {
        $client = (new ClientFactory())->client(apiKey: self::LIVE_KEY);

        ob_start();
        var_dump($client);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString(self::SECRET, $dump);
    }

    public function testVarExportDoesNotExposeTheKey(): void
    {
        $client = (new ClientFactory())->client(apiKey: self::LIVE_KEY);

        self::assertStringNotContainsString(self::SECRET, var_export($client, true));
    }

    public function testSerialisingTheClientNeverWritesTheKey(): void
    {
        $client = (new ClientFactory())->client(apiKey: self::LIVE_KEY);

        try {
            $serialised = serialize($client);
        } catch (Throwable $refusal) {
            // A client holds the transport and the backoff hooks, so PHP will
            // not serialise one at all. What matters is that the refusal does
            // not quote the key either.
            self::assertStringNotContainsString(self::SECRET, $refusal->getMessage());

            return;
        }

        self::assertStringNotContainsString(self::SECRET, $serialised);
    }

    public function testJsonEncodingTheClientDoesNotExposeTheKey(): void
    {
        $client = (new ClientFactory())->client(apiKey: self::LIVE_KEY);

        self::assertStringNotContainsString(self::SECRET, (string) json_encode($client));
    }

    public function testDumpingAResourceGroupDoesNotExposeTheKey(): void
    {
        // Resource groups hold the requester, which is where the key lives, and
        // dd($client->orders) is a normal thing for a partner to do.
        $client = (new ClientFactory())->client(apiKey: self::LIVE_KEY);

        self::assertStringNotContainsString(self::SECRET, print_r($client->orders, true));
        self::assertStringNotContainsString(self::SECRET, print_r($client->catalogue, true));
    }

    public function testAConstructorFailureDoesNotCarryTheKeyInTheTrace(): void
    {
        // zend.exception_ignore_args defaults to off, so every live frame's
        // arguments are recorded on the exception. Whoops, Ignition and Sentry
        // all render them.
        try {
            new Client(apiKey: self::LIVE_KEY, timeout: 0.0, transport: new StubTransport());
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $exception) {
            self::assertStringNotContainsString(self::SECRET, self::renderTrace($exception));
        }
    }

    public function testAConstructorFailureDoesNotCarryTheKeyWhenItIsWrapped(): void
    {
        try {
            new Client(apiKey: new ApiKey(self::LIVE_KEY), maxRetries: -1, transport: new StubTransport());
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $exception) {
            self::assertStringNotContainsString(self::SECRET, self::renderTrace($exception));
        }
    }

    public function testARequestFailureDoesNotCarryTheKeyInTheTrace(): void
    {
        $factory = new ClientFactory();
        $factory->transport->push(new \SolarJuice\PartnerApi\Http\Response(404, [], ''));

        try {
            $factory->client(apiKey: self::LIVE_KEY, maxRetries: 0)->catalogue->get('GW-5000-DNS-99');
            self::fail('Expected a NotFoundException.');
        } catch (Throwable $exception) {
            self::assertStringNotContainsString(self::SECRET, self::renderTrace($exception));
        }
    }

    public function testTheKeyObjectPrintsAPlaceholderButStillRevealsTheValue(): void
    {
        $key = new ApiKey(self::LIVE_KEY);

        self::assertSame(self::LIVE_KEY, $key->reveal());
        self::assertSame('[redacted]', (string) $key);
        self::assertStringNotContainsString(self::SECRET, print_r($key, true));
        self::assertStringNotContainsString(self::SECRET, var_export($key, true));
        self::assertStringNotContainsString(self::SECRET, serialize($key));
    }

    public function testTheKeyObjectKeepsThePublicPrefixVisibleForSupport(): void
    {
        // Only sj_<env>_<keyid> is visible after a key is issued, so it is the
        // one part that is safe to show in a dump.
        self::assertSame('sj_live_k7m3qp2xr9vt_...', (new ApiKey(self::LIVE_KEY))->masked());
    }

    public function testTheKeyStillReachesTheAuthorizationHeader(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['status' => 'ok']);

        $factory->client(apiKey: self::LIVE_KEY)->health();

        self::assertSame(
            'Bearer ' . self::LIVE_KEY,
            $factory->transport->lastRequest()->headers['Authorization'],
        );
    }

    public function testAKeyCanBeSuppliedAsAClosureForSecretsManagers(): void
    {
        $transport = new StubTransport();
        $transport->pushJson(200, ['status' => 'ok']);

        (new Client(apiKey: static fn (): string => self::LIVE_KEY, transport: $transport))->health();

        self::assertSame('Bearer ' . self::LIVE_KEY, $transport->lastRequest()->headers['Authorization']);
    }

    public function testAnEmptyWrappedKeyIsAConfigurationError(): void
    {
        $this->expectException(ConfigurationException::class);

        new ApiKey('   ');
    }

    /**
     * The trace as the debug tooling renders it: the printable form, plus a
     * full dump of the arguments recorded for this SDK's own frames. Frames
     * from PHPUnit are left out because the harness holds the earlier
     * assertions' output, which is this test's own fixture rather than
     * anything the SDK leaked.
     */
    private static function renderTrace(Throwable $exception): string
    {
        $rendered = $exception->getTraceAsString();

        foreach ($exception->getTrace() as $frame) {
            if (!str_starts_with((string) ($frame['class'] ?? ''), 'SolarJuice\\PartnerApi\\')) {
                continue;
            }

            $rendered .= ' ' . var_export($frame['args'] ?? [], true);
        }

        return $rendered;
    }
}
