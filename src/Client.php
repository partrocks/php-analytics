<?php

declare(strict_types=1);

namespace PartRocks\Analytics;

final class Client
{
    public function __construct(private readonly Http $http)
    {
    }

    /**
     * @param null|callable(string, string, array<string, string>, ?string): array{status: int, body: string} $transport
     */
    public static function create(string $apiKey, ?string $baseUrl = null, ?callable $transport = null): self
    {
        $trimmed = null === $baseUrl ? '' : trim($baseUrl);
        $origin = '' === $trimmed ? 'https://analytics.part.rocks' : $trimmed;

        return new self(new Http(rtrim($origin, '/'), $apiKey, $transport));
    }

    public function listMetrics(): mixed
    {
        return $this->http->request('GET', '/api/v1/metrics');
    }

    /**
     * @param array{
     *   key: string,
     *   name: string,
     *   description?: string|null,
     *   enabled?: bool,
     *   properties: array<string, array{type: 'string'|'integer'|'number'|'boolean'|'datetime'|'enum', required?: bool, values?: list<string>}>
     * } $body
     */
    public function createMetric(array $body): mixed
    {
        return $this->http->request('POST', '/api/v1/metrics', $body);
    }

    public function getMetric(string $key): mixed
    {
        return $this->http->request('GET', '/api/v1/metrics/'.rawurlencode($key));
    }

    /**
     * @param array{
     *   name?: string,
     *   description?: string|null,
     *   enabled?: bool,
     *   properties?: array<string, array{type: 'string'|'integer'|'number'|'boolean'|'datetime'|'enum', required?: bool, values?: list<string>}>
     * } $body
     */
    public function updateMetric(string $key, array $body): mixed
    {
        return $this->http->request('PATCH', '/api/v1/metrics/'.rawurlencode($key), $body);
    }

    /**
     * @param array{metric: string, occurredAt?: string, metadata?: array<string, string|int|float|bool>} $body
     *
     * @return array{recorded: true, event: array<string, mixed>}|array{recorded: false, code: 'daily_quota_exceeded', resetsAt: string}
     */
    public function recordEvent(array $body): array
    {
        $response = $this->http->request('POST', '/api/v1/events', $body, true);
        if (
            is_array($response)
            && 'daily_quota_exceeded' === ($response['code'] ?? null)
        ) {
            return [
                'recorded' => false,
                'code' => 'daily_quota_exceeded',
                'resetsAt' => (string) ($response['resetsAt'] ?? ''),
            ];
        }

        return ['recorded' => true, 'event' => $response];
    }
}
