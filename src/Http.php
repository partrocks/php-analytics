<?php

declare(strict_types=1);

namespace PartRocks\Analytics;

final class Http
{
    /** @var callable(string, string, array<string, string>, ?string): array{status: int, body: string} */
    private $transport;

    /**
     * @param null|callable(string, string, array<string, string>, ?string): array{status: int, body: string} $transport
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? self::curlTransport(...);
    }

    public function request(
        string $method,
        string $path,
        ?array $body = null,
        bool $failOpenDailyQuota = false,
    ): mixed {
        $headers = [
            'Accept' => 'application/json',
            'X-API-Key' => $this->apiKey,
        ];

        $encoded = null;
        if (null !== $body) {
            $headers['Content-Type'] = 'application/json';
            $encoded = json_encode($body, JSON_THROW_ON_ERROR);
        }

        $response = ($this->transport)($method, $this->baseUrl.$path, $headers, $encoded);
        if (204 === $response['status']) {
            return null;
        }

        $data = '' === $response['body'] ? null : json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            if (
                $failOpenDailyQuota
                && 429 === $response['status']
                && is_array($data)
                && 'daily_quota_exceeded' === ($data['code'] ?? null)
            ) {
                return $data;
            }

            $message = is_array($data) && is_string($data['error'] ?? null)
                ? $data['error']
                : 'Request failed with status '.$response['status'];
            $code = is_array($data) && is_string($data['code'] ?? null) ? $data['code'] : 'request_failed';
            throw new PartRocksError($message, $code, $response['status']);
        }

        return $data;
    }

    /** @param array<string, string> $headers */
    private static function curlTransport(string $method, string $url, array $headers, ?string $body): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        $curl = curl_init($url);
        if (false === $curl) {
            throw new \RuntimeException('Unable to start HTTP request.');
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $body,
        ]);

        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (false === $raw) {
            throw new \RuntimeException('HTTP request failed.');
        }

        return ['status' => $status, 'body' => $raw];
    }
}
