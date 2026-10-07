# SubscriberVerify PHP 8 client

PHP 8.0+ client built from the supplied API documentation. Requires cURL and JSON extensions; no third-party dependencies. Covers single lookups, bulk lookups, sequential batching, credits, carrier map download links, and carrier map status.

## Installation

Copy this directory into your project and require `autoload.php`, or run `composer install` here and require `vendor/autoload.php`. For use as a Composer path package, the package name is `local/subscriber-verify-client`.

Configure your key in the environment. No account credential is included in this package.

```sh
export SUBSCRIBER_VERIFY_API_KEY='your-api-key'
php examples/usage.php
```

Running the example makes one billable lookup. The offline tests never make network requests.

## Single lookup

```php
<?php
declare(strict_types=1);
require 'autoload.php';

use SubscriberVerify\Client;

$client = new Client(getenv('SUBSCRIBER_VERIFY_API_KEY') ?: '');
$result = $client->lookup('8016041000', [
    'ip' => '136.38.145.14',
    'list' => 'campaign-2026',
    'litigatorFilter' => true,
    'landlineSmsLookup' => true,
    'dncState' => true,
    'dncComplainer' => true,
    // 'dncNational' => true, // Requires account enablement and verified SAN.
]);

$action = $result->action();           // send, unsubscribe, blacklist
$eligible = $result->smsEligible();    // Handles true and "true".
$carrier = $result->get('dipCarrier');
$raw = $result->all();                // Every original field and value preserved.
```

Phone numbers must contain exactly 10 ASCII digits. IP accepts IPv4 and IPv6. Flags accept booleans or integer 0/1. Unsupported options are rejected, including deprecated aliases. `list` is a reusable reporting/campaign ID: the API limits accounts to 1,000 unique lists. The client cannot track the account-wide total.

The documentation uses both `dnccomplainer` and `dncComplainer`; this client uses `dncComplainer`, matching its examples. Live behavior has not been verified.

## Interpreting responses

`boolean($field)` returns true, false, or null (missing/unrecognized), avoiding PHP's incorrect-for-this-API `(bool) "false"` conversion. `smsEligible()` and `doNotSms()` return false when their respective summary field is absent.

`shouldSendSms()` requires `action=send` and explicit SMS eligibility, and rejects reported errors, do-not-SMS, litigator, blacklist, or explicit nondeliverability. It represents the API's carrier/SMS recommendation. **DNC matches do not change the API action or this helper.** Evaluate DNC results separately for your application.

```php
$state = $result->dncMatch('state');
$complainer = $result->dncMatch('complainer');
$national = $result->dncMatch('national');
// true: checked and matched; false: checked and no match;
// null: not requested, skipped, forbidden, failed, or malformed.
$status = $result->get('dncNationalChecked');
$error = $result->get('dncNationalError');
```

An absent State DNC check is not a negative result. The supplied docs specify skipped states: CO, FL, IN, LA, MA, MS, MO, OK, PA, TN, TX, WY. Individual DNC failures remain accessible in the result without discarding the carrier lookup.

## Bulk and sequential batching

```php
$batch = $client->bulkLookup([
    ['phone' => '8016041000', 'ip' => '136.38.145.14'],
    ['phone' => '3345050009'],
], ['litigatorFilter' => true, 'landlineSmsLookup' => false]);

foreach ($batch->results() as $index => $result) {
    if ($result->isError()) {
        // Handle this record's reason(); other records remain available.
        continue;
    }
    // $index matches the submitted record's position.
}

// $records can be an array or generator. Each yielded BulkResult is a completed request.
foreach ($client->bulkLookupBatches($records, [], 1000) as $batch) {
    foreach ($batch->results() as $result) {
        // Persist each completed batch before proceeding.
    }
}
```

Each bulk request contains 1–1,000 records and preserves order. Bulk options are intentionally limited to the documented top-level `litigatorFilter` and `landlineSmsLookup`; bulk records support `phone` and optional `ip`. The supplied bulk docs do not establish DNC or list support.

The batching generator sends requests sequentially. **Only one bulk request may be active per API key**, so coordinate workers/processes externally if they share credentials. The API documents at most 30 concurrent individual lookups. This client is synchronous.

Validation of a later batch can fail after earlier batches have completed and consumed credits. Do not restart a partially completed stream without tracking completed records.

## Credits and carrier map

```php
$balance = $client->credits();
$status = $client->carrierMapStatus(); // lastChanged, carrierCount; API caches for 1 minute.
$link = $client->carrierMapDownload('json'); // Or 'csv'.
$url = $link['downloadUrl'];
$expires = $link['expiresAt'];
// Fetch $url with your HTTP downloader; it is valid for 24 hours.
// The signed URL authenticates the download: do not append the API key.
```

`carrierMapDownload()` obtains metadata and a signed download URL; it does not save the file. Treat signed URLs as credentials. The carrier map link endpoint uses documented GET (key in query); other endpoints use JSON POST. Avoid logging authenticated URLs or request bodies.

## Errors and timeouts

```php
use SubscriberVerify\ApiException;
use SubscriberVerify\CurlTransport;

$client = new Client($key, new CurlTransport(timeoutSeconds: 180, connectTimeoutSeconds: 10));
try {
    $balance = $client->credits();
} catch (ApiException $e) {
    $httpStatus = $e->httpStatus(); // null for transport and client-detected API failures.
    // Decide how your application should handle the failure.
}
```

Transport failures, non-2xx responses, invalid JSON, malformed expected response structures, and top-level API errors throw `ApiException`. Invalid request input throws `InvalidArgumentException`. Bulk record errors remain in `LookupResult` so successful records are retained. API error reasons may include subscriber information: handle logs accordingly.

Defaults: 120-second overall timeout, 10-second connection timeout. TLS verification is enabled, redirects are disabled, and the transport reuses its cURL handle. No automatic retries: a timed-out lookup may already have consumed credits. No caching: the supplied docs call for a lookup before every SMS.

## Tests

```sh
php tests/run.php
# Or: composer test
find src examples tests -name '*.php' -exec php -l {} \;
```

Tests use an injected fake transport to cover request construction, validation, mixed boolean values, DNC semantics, API failures, partial bulk errors, batching, and carrier map methods. They do not exercise live HTTP or use account credits.
