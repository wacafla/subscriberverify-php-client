<?php
declare(strict_types=1);

namespace SubscriberVerify;

/** Request, HTTP, JSON, or top-level API failure. No automatic retries. */
class ApiException extends \RuntimeException
{
    public function __construct(string $message, private ?int $httpStatus = null)
    {
        parent::__construct($message);
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }
}
