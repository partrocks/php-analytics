<?php

declare(strict_types=1);

namespace PartRocks\Analytics\Tests;

use PartRocks\Analytics\Client;
use PartRocks\Analytics\PartRocksError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ClientTest extends TestCase
{
    public function testCreateHasNoEnvironmentArgument(): void
    {
        $method = new ReflectionMethod(Client::class, 'create');
        $names = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $method->getParameters(),
        );

        self::assertSame(['apiKey', 'baseUrl', 'transport'], $names);
        self::assertTrue($method->getParameters()[1]->allowsNull());
    }

    public function testDefaultsToHostedOriginWhenBaseUrlOmittedOrBlank(): void
    {
        $urls = [];
        $transport = function (string $method, string $url) use (&$urls): array {
            $urls[] = $url;

            return ['status' => 200, 'body' => '{"metrics":[]}'];
        };

        Client::create('pak_test', null, $transport)->listMetrics();
        Client::create('pak_test', '   ', $transport)->listMetrics();
        Client::create('pak_test', transport: $transport)->listMetrics();

        self::assertSame([
            'https://analytics.part.rocks/api/v1/metrics',
            'https://analytics.part.rocks/api/v1/metrics',
            'https://analytics.part.rocks/api/v1/metrics',
        ], $urls);
    }

    public function testMetricMethodsUseClientPathsHeadersAndBodies(): void
    {
        $captured = [];
        $transport = function (string $method, string $url, array $headers, ?string $body) use (&$captured): array {
            $captured[] = compact('method', 'url', 'headers', 'body');

            return ['status' => 200, 'body' => str_ends_with($url, '/metrics') && 'GET' === $method
                ? '{"metrics":[]}'
                : '{"metric":{"key":"ui.button_clicked"}}'];
        };
        $client = Client::create('pak_test', 'https://analytics.example/', $transport);
        $metric = [
            'key' => 'ui.button_clicked',
            'name' => 'Button clicked',
            'properties' => [
                'buttonId' => ['type' => 'string', 'required' => true],
                'plan' => ['type' => 'enum', 'values' => ['free', 'pro']],
            ],
        ];

        $client->listMetrics();
        $client->createMetric($metric);
        $client->getMetric('ui.button_clicked');
        $client->updateMetric('ui.button_clicked', ['enabled' => false]);

        self::assertSame([
            ['GET', 'https://analytics.example/api/v1/metrics'],
            ['POST', 'https://analytics.example/api/v1/metrics'],
            ['GET', 'https://analytics.example/api/v1/metrics/ui.button_clicked'],
            ['PATCH', 'https://analytics.example/api/v1/metrics/ui.button_clicked'],
        ], array_map(static fn (array $request): array => [$request['method'], $request['url']], $captured));
        foreach ($captured as $request) {
            self::assertSame('application/json', $request['headers']['Accept']);
            self::assertSame('pak_test', $request['headers']['X-API-Key']);
        }
        self::assertSame('application/json', $captured[1]['headers']['Content-Type']);
        self::assertSame(json_encode($metric, JSON_THROW_ON_ERROR), $captured[1]['body']);
        self::assertSame('{"enabled":false}', $captured[3]['body']);
    }

    public function testWrapsSuccessfulEventIngest(): void
    {
        $captured = [];
        $event = [
            'id' => 'evt_1',
            'metric' => 'ui.button_clicked',
            'schemaRevision' => 1,
            'occurredAt' => '2026-09-12T11:00:00+00:00',
            'receivedAt' => '2026-09-12T11:00:01+00:00',
            'metadata' => ['buttonId' => 'cta-hero', 'plan' => 'pro'],
        ];
        $client = Client::create('pak_test', 'https://analytics.example', function (string $method, string $url, array $headers, ?string $body) use (&$captured, $event): array {
            $captured = compact('method', 'url', 'headers', 'body');

            return ['status' => 201, 'body' => json_encode($event, JSON_THROW_ON_ERROR)];
        });
        $payload = [
            'metric' => 'ui.button_clicked',
            'occurredAt' => '2026-09-12T11:00:00Z',
            'metadata' => ['buttonId' => 'cta-hero', 'plan' => 'pro'],
        ];

        $result = $client->recordEvent($payload);

        self::assertSame('POST', $captured['method']);
        self::assertSame('https://analytics.example/api/v1/events', $captured['url']);
        self::assertSame('pak_test', $captured['headers']['X-API-Key']);
        self::assertSame(json_encode($payload, JSON_THROW_ON_ERROR), $captured['body']);
        self::assertSame(['recorded' => true, 'event' => $event], $result);
    }

    public function testFailsOpenForExactDailyQuotaResponse(): void
    {
        $client = Client::create('pak_test', transport: fn (): array => [
            'status' => 429,
            'body' => '{"error":"Daily event quota exceeded.","code":"daily_quota_exceeded","resetsAt":"2026-09-13T00:00:00+00:00"}',
        ]);

        self::assertSame([
            'recorded' => false,
            'code' => 'daily_quota_exceeded',
            'resetsAt' => '2026-09-13T00:00:00+00:00',
        ], $client->recordEvent(['metric' => 'ui.button_clicked']));
    }

    #[DataProvider('throwingEventErrors')]
    public function testEventIngestThrowsOtherErrors(int $status, string $code): void
    {
        $client = Client::create('pak_test', transport: fn (): array => [
            'status' => $status,
            'body' => json_encode(['error' => 'Rejected.', 'code' => $code], JSON_THROW_ON_ERROR),
        ]);

        try {
            $client->recordEvent(['metric' => 'ui.button_clicked']);
            self::fail('Expected PartRocksError');
        } catch (PartRocksError $error) {
            self::assertSame($status, $error->status);
            self::assertSame($code, $error->errorCode);
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function throwingEventErrors(): iterable
    {
        yield 'nonmatching 429' => [429, 'rate_limited'];
        yield 'invalid key' => [401, 'invalid_api_key'];
        yield 'invalid metadata' => [422, 'invalid_metadata'];
    }

    public function testMetricsNeverFailOpenForDailyQuotaResponse(): void
    {
        $client = Client::create('pak_test', transport: fn (): array => [
            'status' => 429,
            'body' => '{"error":"Daily quota exceeded.","code":"daily_quota_exceeded","resetsAt":"2026-09-13T00:00:00+00:00"}',
        ]);

        try {
            $client->listMetrics();
            self::fail('Expected PartRocksError');
        } catch (PartRocksError $error) {
            self::assertSame(429, $error->status);
            self::assertSame('daily_quota_exceeded', $error->errorCode);
        }
    }
}
