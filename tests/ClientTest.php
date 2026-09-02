<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Tests;

use PHPUnit\Framework\TestCase;
use SolarJuice\PartnerApi\Client;
use SolarJuice\PartnerApi\Exception\ConfigurationException;
use SolarJuice\PartnerApi\Tests\Support\ClientFactory;
use SolarJuice\PartnerApi\Tests\Support\StubTransport;

final class ClientTest extends TestCase
{
    private ?string $originalKey = null;

    protected function setUp(): void
    {
        $existing = getenv(Client::API_KEY_ENV);
        $this->originalKey = $existing === false ? null : $existing;
        putenv(Client::API_KEY_ENV);
    }

    protected function tearDown(): void
    {
        if ($this->originalKey === null) {
            putenv(Client::API_KEY_ENV);

            return;
        }

        putenv(Client::API_KEY_ENV . '=' . $this->originalKey);
    }

    public function testSendsBearerTokenAndJsonAcceptHeader(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['status' => 'ok']);

        $factory->client()->health();

        $headers = $factory->transport->lastRequest()->headers;

        self::assertSame('Bearer ' . ClientFactory::API_KEY, $headers['Authorization']);
        self::assertSame('application/json', $headers['Accept']);
    }

    public function testUserAgentCarriesTheSdkVersionAndTheCallerSuffix(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['status' => 'ok']);

        $client = $factory->client(userAgent: 'AcmeSolar/2.1');
        $client->health();

        self::assertSame('solarjuice-php/' . Client::VERSION . ' AcmeSolar/2.1', $client->userAgent);
        self::assertSame($client->userAgent, $factory->transport->lastRequest()->headers['User-Agent']);
    }

    public function testUserAgentIsTheSdkAloneWithoutASuffix(): void
    {
        $factory = new ClientFactory();

        self::assertSame('solarjuice-php/' . Client::VERSION, $factory->client()->userAgent);
    }

    public function testVersionMatchesTheComposerPackage(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);

        self::assertSame($composer['version'], Client::VERSION);
    }

    public function testReadsTheApiKeyFromTheEnvironmentWhenNoneIsPassed(): void
    {
        putenv(Client::API_KEY_ENV . '=sj_test_mnopqrstuvwx_0123456789abcdef0123456789abcdef');

        $transport = new StubTransport();
        $transport->pushJson(200, ['status' => 'ok']);

        (new Client(transport: $transport))->health();

        self::assertSame(
            'Bearer sj_test_mnopqrstuvwx_0123456789abcdef0123456789abcdef',
            $transport->lastRequest()->headers['Authorization'],
        );
    }

    public function testExplicitKeyBeatsTheEnvironment(): void
    {
        putenv(Client::API_KEY_ENV . '=sj_test_fromtheenvir_0123456789abcdef0123456789abcdef');

        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['status' => 'ok']);

        $factory->client()->health();

        self::assertSame(
            'Bearer ' . ClientFactory::API_KEY,
            $factory->transport->lastRequest()->headers['Authorization'],
        );
    }

    public function testRaisesAClearConfigurationErrorWithNoKeyAnywhere(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/SOLARJUICE_API_KEY/');

        new Client(transport: new StubTransport());
    }

    public function testTreatsABlankKeyAsNoKey(): void
    {
        $this->expectException(ConfigurationException::class);

        new Client(apiKey: '   ', transport: new StubTransport());
    }

    public function testDefaultsToTheProductionHost(): void
    {
        self::assertSame('https://api.solarjuice.com.au', (new ClientFactory())->client()->baseUrl);
    }

    public function testTrimsATrailingSlashFromTheBaseUrlSoPathsDoNotDouble(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['status' => 'ok']);

        $factory->client(baseUrl: 'https://api.example.test/')->health();

        self::assertSame('https://api.example.test/v1/health', $factory->transport->lastRequest()->url);
    }

    public function testRejectsANonPositiveTimeout(): void
    {
        $this->expectException(ConfigurationException::class);

        new Client(apiKey: ClientFactory::API_KEY, timeout: 0.0, transport: new StubTransport());
    }

    public function testRejectsNegativeRetries(): void
    {
        $this->expectException(ConfigurationException::class);

        new Client(apiKey: ClientFactory::API_KEY, maxRetries: -1, transport: new StubTransport());
    }

    public function testHealthIsSentAsAPlainGetWithNoBody(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(200, ['status' => 'ok']);

        self::assertSame(['status' => 'ok'], $factory->client()->health());

        $request = $factory->transport->lastRequest();

        self::assertSame('GET', $request->method);
        self::assertNull($request->body);
        self::assertArrayNotHasKey('Content-Type', $request->headers);
    }
}
