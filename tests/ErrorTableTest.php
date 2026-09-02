<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Tests;

use PHPUnit\Framework\TestCase;
use SolarJuice\PartnerApi\Exception\ApiException;
use SolarJuice\PartnerApi\Exception\ForbiddenException;
use SolarJuice\PartnerApi\Exception\IdempotencyConflictException;
use SolarJuice\PartnerApi\Exception\InternalException;
use SolarJuice\PartnerApi\Exception\NotFoundException;
use SolarJuice\PartnerApi\Exception\PriceChangedException;
use SolarJuice\PartnerApi\Exception\QuoteUnavailableException;
use SolarJuice\PartnerApi\Exception\RateLimitedException;
use SolarJuice\PartnerApi\Exception\StaleDataException;
use SolarJuice\PartnerApi\Exception\UnauthorizedException;
use SolarJuice\PartnerApi\Exception\ValidationFailedException;
use SolarJuice\PartnerApi\Http\Response;
use SolarJuice\PartnerApi\Tests\Support\ClientFactory;

/**
 * The shared response mapping table, replayed.
 *
 * `tests/fixtures/error-mapping.json` is a byte identical copy of the table the
 * Node and Ruby clients also run: one response in, one decision out. It is what
 * stops the three clients drifting apart on the responses partners actually
 * hit, which are rarely the documented envelope: edge pages with no envelope at
 * all, statuses that cover two codes, and an hour long Retry-After.
 */
final class ErrorTableTest extends TestCase
{
    private const TABLE = __DIR__ . '/fixtures/error-mapping.json';

    /**
     * The table's language neutral names, resolved to this SDK's classes.
     *
     * @var array<string, class-string<ApiException>>
     */
    private const CLASSES = [
        'Base' => ApiException::class,
        'Unauthorized' => UnauthorizedException::class,
        'Forbidden' => ForbiddenException::class,
        'NotFound' => NotFoundException::class,
        'ValidationFailed' => ValidationFailedException::class,
        'RateLimited' => RateLimitedException::class,
        'PriceChanged' => PriceChangedException::class,
        'IdempotencyConflict' => IdempotencyConflictException::class,
        'QuoteUnavailable' => QuoteUnavailableException::class,
        'StaleData' => StaleDataException::class,
        'Internal' => InternalException::class,
    ];

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function tableCases(): array
    {
        $table = json_decode((string) file_get_contents(self::TABLE), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];

        foreach ($table['cases'] as $case) {
            $cases[$case['name']] = [$case];
        }

        return $cases;
    }

    public function testTheCopyInThisRepoIsTheSharedTable(): void
    {
        $table = json_decode((string) file_get_contents(self::TABLE), true, 512, JSON_THROW_ON_ERROR);

        // Every name the table uses has to resolve here, or a case would be
        // asserting nothing.
        foreach ($table['error_classes'] as $name => $meaning) {
            if ($name === 'null') {
                continue;
            }

            self::assertArrayHasKey($name, self::CLASSES, $name . ' is in the shared table but not mapped.');
        }

        self::assertNotEmpty($table['cases']);
    }

    /**
     * @dataProvider tableCases
     *
     * @param array<string, mixed> $case
     */
    public function testTheResponseMapsToTheAgreedErrorAndCode(array $case): void
    {
        $expected = $case['expect'];
        $factory = new ClientFactory();
        $factory->transport->push(self::response($case));

        try {
            // Retries are off so that every case reaches the mapping, including
            // the ones the retry loop would otherwise absorb.
            $factory->client(maxRetries: 0)->health();

            self::assertNull($expected['error'], $case['name'] . ' must raise ' . ($expected['error'] ?? '') . '.');
        } catch (ApiException $exception) {
            self::assertNotNull($expected['error'], $case['name'] . ' must not raise.');
            self::assertSame(self::CLASSES[$expected['error']], $exception::class);
            self::assertSame($expected['code'], $exception->errorCode);
            self::assertSame($case['status'], $exception->statusCode);

            if (array_key_exists('retry_after_seconds', $expected)) {
                self::assertSame($expected['retry_after_seconds'], $exception->retryAfter);
            }
        }
    }

    /**
     * @dataProvider tableCases
     *
     * @param array<string, mixed> $case
     */
    public function testTheRetryDecisionMatchesTheTable(array $case): void
    {
        $expected = $case['expect'];
        $factory = new ClientFactory();
        $factory->transport->push(self::response($case))->pushJson(200, ['status' => 'ok']);

        try {
            $factory->client(maxRetries: 3)->health();
        } catch (ApiException) {
            // A case the table says is not retried lands here. The call count
            // below is what actually proves the decision either way.
        }

        self::assertSame(
            $expected['retried'] ? 2 : 1,
            $factory->transport->callCount(),
            $case['name'] . ($expected['retried'] ? ' must be retried.' : ' must not be retried.'),
        );
    }

    /**
     * @param array<string, mixed> $case
     */
    private static function response(array $case): Response
    {
        return new Response($case['status'], $case['headers'], $case['body']);
    }
}
