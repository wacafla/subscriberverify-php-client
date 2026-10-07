<?php
declare(strict_types=1);

namespace SubscriberVerify;

final class Client
{
    private TransportInterface $transport;
    private const BASE_URL = 'https://api2.subscriberverify.com';
    private const FLAGS = ['litigatorFilter', 'landlineSmsLookup', 'dncState', 'dncComplainer', 'dncNational'];

    public function __construct(private string $apiKey, ?TransportInterface $transport = null)
    {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('An API key is required.');
        }
        $this->transport = $transport ?? new CurlTransport();
    }

    /**
     * Options: ip, list, litigatorFilter, landlineSmsLookup, dncState, dncComplainer, dncNational.
     * Phone must be exactly ten digits; no implicit country-code stripping.
     */
    public function lookup(string $phone, array $options = []): LookupResult
    {
        self::validatePhone($phone);
        $options = self::validateOptions($options, array_merge(['ip', 'list'], self::FLAGS));
        $data = $this->request('POST', '/api', ['phone' => $phone] + $options);
        self::validateLookup($data);
        return new LookupResult($data);
    }

    public function credits(): int
    {
        $data = $this->request('POST', '/api', ['credits' => 1]);
        $value = filter_var($data['availableCredits'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($value === false || is_bool($data['availableCredits'] ?? null)) {
            throw new ApiException('Missing or invalid availableCredits in response.');
        }
        return $value;
    }

    /**
     * @param array<int, array{phone: string, ip?: string}> $records 1–1000 records.
     * @param array<string, bool|int> $options Only documented bulk flags are supported.
     */
    public function bulkLookup(array $records, array $options = []): BulkResult
    {
        if (count($records) < 1 || count($records) > 1000) {
            throw new \InvalidArgumentException('Bulk requests require between 1 and 1000 records.');
        }
        $options = self::validateOptions($options, ['litigatorFilter', 'landlineSmsLookup']);
        $normalized = [];
        foreach ($records as $record) {
            if (!is_array($record) || !isset($record['phone']) || !is_string($record['phone'])) {
                throw new \InvalidArgumentException('Every record requires a phone string.');
            }
            self::validatePhone($record['phone']);
            $phone = $record['phone'];
            unset($record['phone']);
            $normalized[] = ['phone' => $phone] + self::validateOptions($record, ['ip']);
        }
        $data = $this->request('POST', '/api-bulk', ['records' => $normalized] + $options);
        if (LookupResult::parseBoolean($data['ok'] ?? null) !== true
            || !isset($data['results']) || !is_array($data['results'])
            || array_keys($data['results']) !== range(0, count($normalized) - 1)) {
            throw new ApiException('Invalid bulk response or result count does not match request.');
        }
        foreach ($data['results'] as $row) {
            if (!is_array($row)) {
                throw new ApiException('Invalid bulk lookup result.');
            }
            self::validateLookup($row);
        }
        return new BulkResult($data);
    }

    /** Sequential batches; coordinate separately if other processes share the API key. */
    public function bulkLookupBatches(iterable $records, array $options = [], int $batchSize = 1000): \Generator
    {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new \InvalidArgumentException('Batch size must be between 1 and 1000.');
        }
        self::validateOptions($options, ['litigatorFilter', 'landlineSmsLookup']);
        $batch = [];
        foreach ($records as $record) {
            $batch[] = $record;
            if (count($batch) === $batchSize) {
                yield $this->bulkLookup($batch, $options);
                $batch = [];
            }
        }
        if ($batch !== []) {
            yield $this->bulkLookup($batch, $options);
        }
    }

    /** Returns downloadUrl, expiresAt, format, and any additional metadata. */
    public function carrierMapDownload(string $format = 'json'): array
    {
        if (!in_array($format, ['json', 'csv'], true)) {
            throw new \InvalidArgumentException('Carrier map format must be json or csv.');
        }
        // The documentation only specifies GET for this endpoint.
        $data = $this->request('GET', '/api/carrier-map', ['format' => $format]);
        if (LookupResult::parseBoolean($data['ok'] ?? null) !== true
            || !is_string($data['downloadUrl'] ?? null)
            || filter_var($data['downloadUrl'], FILTER_VALIDATE_URL) === false
            || parse_url($data['downloadUrl'], PHP_URL_SCHEME) !== 'https') {
            throw new ApiException('Invalid carrier map download response.');
        }
        return $data;
    }

    public function carrierMapStatus(): array
    {
        $data = $this->request('POST', '/api/carrier-map-status', []);
        if (LookupResult::parseBoolean($data['ok'] ?? null) !== true
            || !is_string($data['lastChanged'] ?? null)
            || !is_int($data['carrierCount'] ?? null) || $data['carrierCount'] < 0) {
            throw new ApiException('Invalid carrier map status response.');
        }
        return $data;
    }

    private function request(string $method, string $path, array $parameters): array
    {
        $data = $this->transport->request($method, self::BASE_URL . $path, ['key' => $this->apiKey] + $parameters);
        if (LookupResult::parseBoolean($data['error'] ?? null) === true
            || ($data['action'] ?? null) === 'error'
            || (array_key_exists('ok', $data) && LookupResult::parseBoolean($data['ok']) !== true)) {
            // Redact the configured secret if it is echoed in the API's reason.
            $reason = $data['reason'] ?? $data['message'] ?? $data['error'] ?? 'Unspecified API error.';
            $reason = is_string($reason) ? str_replace($this->apiKey, '[REDACTED]', $reason) : 'Unspecified API error.';
            throw new ApiException('API error: ' . $reason);
        }
        return $data;
    }

    private static function validatePhone(string $phone): void
    {
        if (preg_match('/\A[0-9]{10}\z/', $phone) !== 1) {
            throw new \InvalidArgumentException('Phone must be a string of exactly 10 US digits.');
        }
    }

    private static function validateOptions(array $options, array $allowed): array
    {
        foreach ($options as $name => $value) {
            if (!in_array($name, $allowed, true)) {
                throw new \InvalidArgumentException('Unsupported request option: ' . $name);
            }
            if (in_array($name, self::FLAGS, true)) {
                if (!is_bool($value) && $value !== 0 && $value !== 1) {
                    throw new \InvalidArgumentException($name . ' must be a boolean or 0/1.');
                }
                $options[$name] = (bool) $value;
            } elseif ($name === 'ip') {
                if (!is_string($value) || filter_var($value, FILTER_VALIDATE_IP) === false) {
                    throw new \InvalidArgumentException('ip must be a valid IPv4 or IPv6 address.');
                }
            } elseif ($name === 'list' && (!is_string($value) || trim($value) === '')) {
                throw new \InvalidArgumentException('list must be a nonempty string.');
            }
        }
        return $options;
    }

    private static function validateLookup(array $data): void
    {
        if (!in_array($data['action'] ?? null, ['send', 'unsubscribe', 'blacklist', 'error'], true)) {
            throw new ApiException('Missing or unrecognized lookup action.');
        }
    }
}
