<?php
declare(strict_types=1);

namespace SubscriberVerify;

/** Per-record errors stay in results; their positions always match input order. */
final class BulkResult implements \JsonSerializable
{
    /** @var LookupResult[] */
    private array $results;

    public function __construct(private array $data)
    {
        $this->results = array_map(static fn (array $row): LookupResult => new LookupResult($row), $data['results']);
    }

    /** @return LookupResult[] */
    public function results(): array
    {
        return $this->results;
    }

    public function all(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
