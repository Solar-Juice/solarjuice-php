<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Tests;

use PHPUnit\Framework\TestCase;
use SolarJuice\PartnerApi\ErrorCode;
use SolarJuice\PartnerApi\Exception\ErrorFactory;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps the three places an error code appears in step: the spec, the enum, and
 * the exception mapping.
 */
final class ErrorCodeTest extends TestCase
{
    public function testTheEnumCoversExactlyTheCodesInTheSpec(): void
    {
        $spec = Yaml::parseFile(__DIR__ . '/../spec/openapi.yaml');
        $documented = $spec['components']['schemas']['ErrorCode']['enum'];
        $implemented = array_map(static fn (ErrorCode $code): string => $code->value, ErrorCode::cases());

        sort($documented);
        sort($implemented);

        self::assertSame($documented, $implemented);
    }

    public function testEveryEnumCaseHasItsOwnException(): void
    {
        $mapped = ErrorFactory::mappedCodes();

        foreach (ErrorCode::cases() as $code) {
            self::assertContains($code->value, $mapped, $code->value . ' has no dedicated exception.');
        }
    }

    public function testTheMappingClaimsNoCodeTheEnumDoesNotHave(): void
    {
        foreach (ErrorFactory::mappedCodes() as $code) {
            self::assertNotNull(ErrorCode::tryFrom($code), $code . ' is mapped but is not a known code.');
        }
    }
}
