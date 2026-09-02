<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SolarJuice\PartnerApi\Client;
use SolarJuice\PartnerApi\Resources\Catalogue;
use SolarJuice\PartnerApi\Resources\Inventory;
use SolarJuice\PartnerApi\Resources\Orders;
use SolarJuice\PartnerApi\Resources\Shipping;
use SolarJuice\PartnerApi\Resources\Specials;
use SolarJuice\PartnerApi\Tests\Support\ClientFactory;
use Symfony\Component\Yaml\Yaml;

/**
 * Holds the SDK to the spec.
 *
 * The spec is the authority on the surface, so this test reads it rather than
 * restating it. When the API grows an operation, this test fails until the SDK
 * grows a method for it, which is the whole point: the failure arrives at the
 * next commit rather than in a partner's support ticket.
 */
final class SpecConformanceTest extends TestCase
{
    private const SPEC = __DIR__ . '/../spec/openapi.yaml';

    /**
     * Every operationId in the spec, mapped to the method that implements it.
     *
     * @var array<string, array{class-string, string}>
     */
    private const IMPLEMENTED = [
        'listCatalogue' => [Catalogue::class, 'list'],
        'getCatalogueProduct' => [Catalogue::class, 'get'],
        'listInventory' => [Inventory::class, 'list'],
        'getInventoryItem' => [Inventory::class, 'get'],
        'listSpecials' => [Specials::class, 'list'],
        'createShippingQuote' => [Shipping::class, 'quote'],
        'createOrder' => [Orders::class, 'create'],
        'listOrders' => [Orders::class, 'list'],
        'getOrder' => [Orders::class, 'get'],
        'cancelOrder' => [Orders::class, 'cancel'],
        'getHealth' => [Client::class, 'health'],
    ];

    /**
     * Resource groups reachable from the client, and the property that reaches
     * them.
     *
     * @var array<string, class-string>
     */
    private const RESOURCE_PROPERTIES = [
        'catalogue' => Catalogue::class,
        'inventory' => Inventory::class,
        'specials' => Specials::class,
        'shipping' => Shipping::class,
        'orders' => Orders::class,
    ];

    public function testEveryOperationInTheSpecIsImplemented(): void
    {
        $missing = array_diff(self::specOperationIds(), array_keys(self::IMPLEMENTED));

        self::assertSame([], array_values($missing), 'The spec has operations the SDK does not implement.');
    }

    public function testTheSdkClaimsNoOperationTheSpecDoesNotDefine(): void
    {
        $stale = array_diff(array_keys(self::IMPLEMENTED), self::specOperationIds());

        self::assertSame([], array_values($stale), 'The SDK maps operations that are no longer in the spec.');
    }

    public function testEveryMappedMethodExistsAndIsPublic(): void
    {
        foreach (self::IMPLEMENTED as $operationId => [$class, $method]) {
            self::assertTrue(
                method_exists($class, $method),
                sprintf('%s is mapped to %s::%s(), which does not exist.', $operationId, $class, $method),
            );

            self::assertTrue(
                (new ReflectionMethod($class, $method))->isPublic(),
                sprintf('%s::%s() must be public to implement %s.', $class, $method, $operationId),
            );
        }
    }

    public function testEveryListOperationHasAnAutoPagingCounterpart(): void
    {
        $listOperations = array_filter(
            self::IMPLEMENTED,
            static fn (array $target, string $operationId): bool => str_starts_with($operationId, 'list'),
            ARRAY_FILTER_USE_BOTH,
        );

        self::assertNotEmpty($listOperations);

        foreach ($listOperations as $operationId => [$class]) {
            self::assertTrue(
                method_exists($class, 'autoPage'),
                sprintf('%s is paginated, so %s needs an autoPage() counterpart.', $operationId, $class),
            );

            $returnType = (new ReflectionMethod($class, 'autoPage'))->getReturnType();

            self::assertNotNull($returnType);
            self::assertSame(
                'Generator',
                (string) $returnType,
                sprintf('%s::autoPage() must return a Generator so pages are fetched lazily.', $class),
            );
        }
    }

    public function testAutoPagingIsOnlyOfferedForPaginatedEndpoints(): void
    {
        // Shipping quotes are a single POST, so an auto-pager there would only
        // suggest a page-through that does not exist.
        self::assertFalse(method_exists(Shipping::class, 'autoPage'));
    }

    public function testEveryResourceGroupHangsOffTheClient(): void
    {
        $client = (new ClientFactory())->client();

        foreach (self::RESOURCE_PROPERTIES as $property => $class) {
            self::assertInstanceOf($class, $client->{$property});
        }
    }

    public function testTheSdkVersionMatchesTheSpecVersion(): void
    {
        $spec = self::spec();

        self::assertSame($spec['info']['version'], Client::VERSION);
    }

    public function testTheDefaultBaseUrlIsTheServerTheSpecDeclares(): void
    {
        $spec = self::spec();

        self::assertSame($spec['servers'][0]['url'], Client::DEFAULT_BASE_URL);
    }

    /**
     * @return list<string>
     */
    private static function specOperationIds(): array
    {
        $ids = [];

        foreach (self::spec()['paths'] as $operations) {
            foreach ($operations as $method => $operation) {
                if (!in_array($method, ['get', 'put', 'post', 'delete', 'patch'], true)) {
                    continue;
                }

                self::assertIsString($operation['operationId'] ?? null);
                $ids[] = $operation['operationId'];
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private static function spec(): array
    {
        // Parsed rather than hand transcribed, so the assertions cannot drift
        // from the file that is published to partners.
        return Yaml::parseFile(self::SPEC);
    }
}
