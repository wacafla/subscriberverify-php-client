<?php
declare(strict_types=1);

namespace SubscriberVerify;

final class CurlTransport implements TransportInterface
{
    private \CurlHandle $handle;

    public function __construct(private int $timeoutSeconds = 120, private int $connectTimeoutSeconds = 10)
    {
        if ($timeoutSeconds < 1 || $connectTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('Timeouts must be positive.');
        }
        if (!extension_loaded('curl')) {
            throw new \LogicException('The PHP cURL extension is required.');
        }
        $handle = curl_init();
        if ($handle === false) {
            throw new ApiException('Could not initialize cURL.');
        }
        $this->handle = $handle;
    }

    public function request(string $method, string $url, array $parameters): array
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new \InvalidArgumentException('Only HTTPS is supported.');
        }
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new \InvalidArgumentException('Only GET and POST are supported.');
        }
        curl_reset($this->handle);
        $headers = ['Accept: application/json'];
        $body = null;
        if ($method === 'GET') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        } else {
            try {
                $body = json_encode($parameters, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new ApiException('Request could not be encoded as JSON.');
            }
            $headers[] = 'Content-Type: application/json';
        }
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'subscriber-verify-php/1.0',
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        } else {
            $options[CURLOPT_HTTPGET] = true;
        }
        if (!curl_setopt_array($this->handle, $options)) {
            throw new ApiException('Could not configure HTTP request.');
        }
        $response = curl_exec($this->handle);
        if ($response === false) {
            // Avoid including URLs, API keys, phone numbers, or response bodies in exceptions.
            throw new ApiException('HTTP transport failed (cURL code ' . curl_errno($this->handle) . ').');
        }
        $status = (int) curl_getinfo($this->handle, CURLINFO_HTTP_CODE);
        if ($status < 200 || $status >= 300) {
            throw new ApiException('API returned HTTP ' . $status . '.', $status);
        }
        try {
            $decoded = json_decode($response, false, 512, JSON_THROW_ON_ERROR);
            if (!$decoded instanceof \stdClass) {
                throw new ApiException('Expected a JSON object.', $status);
            }
            return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiException('API returned invalid JSON.', $status);
        }
    }
}
