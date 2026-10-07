<?php
declare(strict_types=1);

namespace SubscriberVerify;

interface TransportInterface
{
    /**
     * Return a decoded JSON object as an associative array.
     * Implementations must throw ApiException on transport, HTTP, or JSON errors.
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function request(string $method, string $url, array $parameters): array;
}
