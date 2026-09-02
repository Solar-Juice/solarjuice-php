<?php

declare(strict_types=1);

namespace SolarJuice\PartnerApi\Tests;

use PHPUnit\Framework\TestCase;
use SolarJuice\PartnerApi\ErrorCode;
use SolarJuice\PartnerApi\Exception\ApiException;
use SolarJuice\PartnerApi\Exception\ErrorFactory;
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

final class ErrorMappingTest extends TestCase
{
    /**
     * @return array<string, array{int, string, class-string<ApiException>}>
     */
    public static function documentedErrors(): array
    {
        return [
            'unauthorized' => [401, 'UNAUTHORIZED', UnauthorizedException::class],
            'forbidden' => [403, 'FORBIDDEN', ForbiddenException::class],
            'not found' => [404, 'NOT_FOUND', NotFoundException::class],
            'validation failed' => [422, 'VALIDATION_FAILED', ValidationFailedException::class],
            'rate limited' => [429, 'RATE_LIMITED', RateLimitedException::class],
            'price changed' => [409, 'PRICE_CHANGED', PriceChangedException::class],
            'idempotency conflict' => [409, 'IDEMPOTENCY_CONFLICT', IdempotencyConflictException::class],
            'quote unavailable' => [503, 'QUOTE_UNAVAILABLE', QuoteUnavailableException::class],
            'stale data' => [503, 'STALE_DATA', StaleDataException::class],
            'internal' => [500, 'INTERNAL', InternalException::class],
        ];
    }

    /**
     * @dataProvider documentedErrors
     *
     * @param class-string<ApiException> $expected
     */
    public function testEveryDocumentedCodeMapsToItsOwnException(int $status, string $code, string $expected): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson($status, [
            'error' => [
                'code' => $code,
                'message' => 'Explanation for ' . $code . '.',
                'details' => [['field' => 'lines[0].unit_price', 'current' => '1099.00']],
                'request_id' => 'req_01J6ZK3M5X8QW2R7Y9V4B1N0PD',
            ],
        ]);

        try {
            $factory->client(maxRetries: 0)->catalogue->list();
            self::fail('Expected ' . $expected . '.');
        } catch (ApiException $exception) {
            self::assertInstanceOf($expected, $exception);
            self::assertSame($code, $exception->errorCode);
            self::assertSame(ErrorCode::from($code), $exception->code());
            self::assertSame($status, $exception->statusCode);
            self::assertSame('Explanation for ' . $code . '.', $exception->getMessage());
            self::assertSame('req_01J6ZK3M5X8QW2R7Y9V4B1N0PD', $exception->requestId);
            self::assertSame([['field' => 'lines[0].unit_price', 'current' => '1099.00']], $exception->details);
        }
    }

    public function testTheTwoNineOhNineCodesAreDistinguishedByCodeNotStatus(): void
    {
        self::assertNotSame(
            PriceChangedException::class,
            IdempotencyConflictException::class,
            'The 409 codes must not collapse into one class.',
        );
    }

    public function testAnUnknownCodeStillRaisesTheBaseExceptionWithTheRawCodeIntact(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(418, [
            'error' => [
                'code' => 'SOME_FUTURE_CODE',
                'message' => 'Added after this SDK shipped.',
                'details' => [],
                'request_id' => 'req_01J6ZK3M5X8QW2R7Y9V4B1N0PD',
            ],
        ]);

        try {
            $factory->client(maxRetries: 0)->catalogue->list();
            self::fail('Expected an ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(ApiException::class, $exception::class);
            self::assertSame('SOME_FUTURE_CODE', $exception->errorCode);
            self::assertNull($exception->code());
            self::assertSame(418, $exception->statusCode);
        }
    }

    public function testFallsBackToTheStatusWhenTheBodyIsNotTheDocumentedEnvelope(): void
    {
        $factory = new ClientFactory();
        $factory->transport->push(new Response(403, ['Content-Type' => 'text/html'], '<html>Blocked</html>'));

        try {
            $factory->client(maxRetries: 0)->inventory->list();
            self::fail('Expected a ForbiddenException.');
        } catch (ApiException $exception) {
            self::assertInstanceOf(ForbiddenException::class, $exception);
            // The code is synthesised from the status so that a partner
            // switching on errorCode still works behind an edge proxy.
            self::assertSame('FORBIDDEN', $exception->errorCode);
            self::assertSame(403, $exception->statusCode);
            self::assertStringContainsString('403', $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{int, string, class-string<ApiException>}>
     */
    public static function unambiguousStatuses(): array
    {
        return [
            '401' => [401, 'UNAUTHORIZED', UnauthorizedException::class],
            '403' => [403, 'FORBIDDEN', ForbiddenException::class],
            '404' => [404, 'NOT_FOUND', NotFoundException::class],
            '422' => [422, 'VALIDATION_FAILED', ValidationFailedException::class],
            '429' => [429, 'RATE_LIMITED', RateLimitedException::class],
            '500' => [500, 'INTERNAL', InternalException::class],
        ];
    }

    /**
     * @dataProvider unambiguousStatuses
     *
     * @param class-string<ApiException> $expected
     */
    public function testABodylessErrorStillCarriesACode(int $status, string $code, string $expected): void
    {
        $factory = new ClientFactory();
        $factory->transport->push(new Response($status, ['Content-Type' => 'text/html'], '<html>Edge page</html>'));

        try {
            $factory->client(maxRetries: 0)->catalogue->list();
            self::fail('Expected ' . $expected . '.');
        } catch (ApiException $exception) {
            self::assertInstanceOf($expected, $exception);
            self::assertSame($code, $exception->errorCode);
            self::assertSame(ErrorCode::from($code), $exception->code());
        }
    }

    public function testASynthesisedCodeAgreesWithTheClassTheStatusPicks(): void
    {
        // Two mappings from status, one to a class and one to a code. If they
        // ever disagree the SDK would report a code its own exception class
        // contradicts.
        foreach (ErrorFactory::synthesisedCodes() as $status => $code) {
            $factory = new ClientFactory();
            $factory->transport->push(new Response($status, [], ''));

            try {
                $factory->client(maxRetries: 0)->catalogue->list();
                self::fail('Expected an ApiException for ' . $status . '.');
            } catch (ApiException $exception) {
                self::assertSame($code, $exception->errorCode);
                self::assertContains($code, ErrorFactory::mappedCodes());
            }
        }
    }

    public function testAnAmbiguousStatusIsNotGivenASynthesisedCode(): void
    {
        // 409 and 503 each cover two codes. Reporting either would be a guess,
        // and a caller acting on it would act on the wrong one half the time.
        foreach ([409, 503] as $status) {
            $factory = new ClientFactory();
            $factory->transport->push(new Response($status, [], ''));

            try {
                $factory->client(maxRetries: 0)->catalogue->list();
                self::fail('Expected an ApiException for ' . $status . '.');
            } catch (ApiException $exception) {
                self::assertNull($exception->errorCode);
                self::assertSame(ApiException::class, $exception::class);
            }
        }
    }

    public function testAmbiguousStatusesWithNoCodeStayOnTheBaseException(): void
    {
        // 409 covers PRICE_CHANGED and IDEMPOTENCY_CONFLICT, so with no code to
        // read, guessing one of them would mislead the caller.
        $factory = new ClientFactory();
        $factory->transport->push(new Response(409, [], ''));

        try {
            $factory->client(maxRetries: 0)->catalogue->list();
            self::fail('Expected an ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(ApiException::class, $exception::class);
            self::assertSame(409, $exception->statusCode);
        }
    }

    public function testTheRequestIdFallsBackToTheResponseHeader(): void
    {
        $factory = new ClientFactory();
        $factory->transport->push(new Response(
            500,
            ['X-Request-Id' => 'req_01J6ZK3M5X8QW2R7Y9V4B1N0PD'],
            'upstream failure',
        ));

        try {
            $factory->client(maxRetries: 0)->catalogue->list();
            self::fail('Expected an InternalException.');
        } catch (ApiException $exception) {
            self::assertSame('req_01J6ZK3M5X8QW2R7Y9V4B1N0PD', $exception->requestId);
        }
    }

    public function testTheHttpStatusIsAlsoTheExceptionCodeForLoggers(): void
    {
        $factory = new ClientFactory();
        $factory->transport->pushJson(404, [
            'error' => ['code' => 'NOT_FOUND', 'message' => 'No such SKU.', 'details' => [], 'request_id' => 'req_x'],
        ]);

        try {
            $factory->client(maxRetries: 0)->inventory->get('GW-5000-DNS-99');
            self::fail('Expected a NotFoundException.');
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->getCode());
        }
    }

    public function testASuccessfulResponseThatIsNotAJsonObjectIsReported(): void
    {
        // A captive portal or a misrouted proxy can answer 200 with valid JSON
        // that is not a page. Passing it back would surface as a missing key
        // somewhere far away from the cause.
        $factory = new ClientFactory();
        $factory->transport->push(new Response(200, ['Content-Type' => 'application/json'], '[1,2,3]'));

        try {
            $factory->client(maxRetries: 0)->catalogue->list();
            self::fail('Expected an ApiException.');
        } catch (ApiException $exception) {
            self::assertSame('The API returned a body that is not a JSON object.', $exception->getMessage());
            self::assertSame(200, $exception->statusCode);
        }
    }

    public function testASuccessfulResponseWithAnUnparseableBodyIsReported(): void
    {
        $factory = new ClientFactory();
        $factory->transport->push(new Response(200, ['Content-Type' => 'application/json'], '{not json'));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('The API returned a body that is not valid JSON.');

        $factory->client(maxRetries: 0)->catalogue->list();
    }
}
