<?php

declare(strict_types=1);

namespace PartRocks\Analytics;

/**
 * @phpstan-type ReportTimeRange array{last: '7d'|'30d'|'90d'}|array{since: string, until: string}
 * @phpstan-type ReportFilter array{metadata: string, op: 'exists'}|array{metadata: string, op: 'eq'|'ne'|'gt'|'gte'|'lt'|'lte', value: string|int|float|bool}|array{metadata: string, op: 'in', value: non-empty-list<string|int|float|bool>}
 * @phpstan-type ReportMeasure array{op: 'count', as: string}|array{op: 'countDistinct'|'sum'|'avg'|'min'|'max', field: string, as: string}
 * @phpstan-type ReportCorrelation array{from: string, to: string, on: string, window: string, unmatched: 'include'|'exclude'}
 * @phpstan-type ReportCreate array{key: string, name: string, metrics: array{string}|array{string, string}, correlate?: ReportCorrelation, timeRange: ReportTimeRange, timeBucket: 'hour'|'day'|'week'|'month'|'none', filters?: list<ReportFilter>, groupBy?: list<string>, measures: non-empty-list<ReportMeasure>}
 * @phpstan-type ReportAdHoc array{key?: string, name: string, metrics: array{string}|array{string, string}, correlate?: ReportCorrelation, timeRange: ReportTimeRange, timeBucket: 'hour'|'day'|'week'|'month'|'none', filters?: list<ReportFilter>, groupBy?: list<string>, measures: non-empty-list<ReportMeasure>}
 * @phpstan-type ReportUpdate array{name?: string, metrics?: array{string}|array{string, string}, correlate?: ReportCorrelation|null, timeRange?: ReportTimeRange, timeBucket?: 'hour'|'day'|'week'|'month'|'none', filters?: list<ReportFilter>, groupBy?: list<string>, measures?: non-empty-list<ReportMeasure>}
 * @phpstan-type Report array{key: string, name: string, metrics: array{string}|array{string, string}, correlate?: ReportCorrelation, timeRange: ReportTimeRange, timeBucket: 'hour'|'day'|'week'|'month'|'none', filters: list<ReportFilter>, groupBy: list<string>, measures: non-empty-list<ReportMeasure>, createdAt: string, updatedAt: string}
 * @phpstan-type ReportResult array{generatedAt: string, columns: list<string>, rows: list<array<string, string|int|float|bool|null>>}
 */
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

    /** @return array{reports: list<Report>} */
    public function listReports(): array
    {
        return $this->http->request('GET', '/api/v1/reports');
    }

    /**
     * @param ReportCreate $body
     *
     * @return array{report: Report}
     */
    public function createReport(array $body): array
    {
        return $this->http->request('POST', '/api/v1/reports', $body);
    }

    /** @return array{report: Report} */
    public function getReport(string $key): array
    {
        return $this->http->request('GET', '/api/v1/reports/'.rawurlencode($key));
    }

    /**
     * @param ReportUpdate $body
     *
     * @return array{report: Report}
     */
    public function updateReport(string $key, array $body): array
    {
        return $this->http->request('PATCH', '/api/v1/reports/'.rawurlencode($key), $body);
    }

    public function deleteReport(string $key): void
    {
        $this->http->request('DELETE', '/api/v1/reports/'.rawurlencode($key));
    }

    /**
     * The optional key is ignored; this runs the document without persisting it.
     *
     * @param ReportAdHoc $body
     *
     * @return ReportResult
     */
    public function runReportAdHoc(array $body): array
    {
        return $this->http->request('POST', '/api/v1/reports/run', $body);
    }

    /** @return ReportResult */
    public function runReport(string $key): array
    {
        return $this->http->request('POST', '/api/v1/reports/'.rawurlencode($key).'/run');
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
