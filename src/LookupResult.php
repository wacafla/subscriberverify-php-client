<?php
declare(strict_types=1);

namespace SubscriberVerify;

/** Retains every response field, including fields unknown to this client. */
final class LookupResult implements \JsonSerializable
{
    public function __construct(private array $data)
    {
    }

    public function all(): array
    {
        return $this->data;
    }

    public function get(string $field, mixed $default = null): mixed
    {
        return $this->data[$field] ?? $default;
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->data);
    }

    /** Unknown/missing values are null; "false" never casts to true. */
    public function boolean(string $field): ?bool
    {
        return self::parseBoolean($this->get($field));
    }

    public static function parseBoolean(mixed $value): ?bool
    {
        if (in_array($value, [true, 'true', 1, '1'], true)) {
            return true;
        }
        if (in_array($value, [false, 'false', 0, '0'], true)) {
            return false;
        }
        return null;
    }

    public function action(): ?string
    {
        $value = $this->get('action');
        return is_string($value) ? $value : null;
    }

    public function reason(): ?string
    {
        $value = $this->get('reason');
        return is_string($value) ? $value : null;
    }

    public function isError(): bool
    {
        return $this->action() === 'error' || $this->boolean('error') === true;
    }

    public function smsEligible(): bool
    {
        return $this->boolean('smsEligible') === true;
    }

    public function doNotSms(): bool
    {
        return $this->boolean('doNotSms') === true;
    }

    /** Carrier/SMS recommendation only. Evaluate requested DNC checks separately. */
    public function shouldSendSms(): bool
    {
        return !$this->isError()
            && $this->action() === 'send'
            && $this->smsEligible()
            && !$this->doNotSms()
            && $this->boolean('litigator') !== true
            && $this->boolean('blackList') !== true
            && $this->boolean('deliverable') !== false;
    }

    /** null = not run, skipped, forbidden, failed, or malformed; false = checked, no match. */
    public function dncMatch(string $registry): ?bool
    {
        $prefix = match ($registry) {
            'state' => 'dncState',
            'complainer' => 'dncComplainer',
            'national' => 'dncNational',
            default => throw new \InvalidArgumentException('Registry must be state, complainer, or national.'),
        };
        if ($this->boolean($prefix . 'Checked') !== true || !is_string($this->get($prefix . 'Result'))) {
            return null;
        }
        return $this->get($prefix . 'Result') !== '';
    }

    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
