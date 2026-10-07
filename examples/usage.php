<?php
declare(strict_types=1);

require __DIR__ . '/../autoload.php'; // Or require your Composer vendor/autoload.php.

use SubscriberVerify\ApiException;
use SubscriberVerify\Client;

$key = getenv('SUBSCRIBER_VERIFY_API_KEY');
if ($key === false || $key === '') {
    throw new RuntimeException('Set SUBSCRIBER_VERIFY_API_KEY first.');
}
$client = new Client($key);

try {
    $result = $client->lookup('8016041000', [
        'list' => 'spring-campaign',
        'dncState' => true,
        'dncComplainer' => true,
        // 'litigatorFilter' => true,    // Additional credit cost.
        // 'landlineSmsLookup' => true,  // Additional cost for landlines.
        // 'dncNational' => true,        // Requires enabled account and verified SAN.
    ]);
    echo 'API recommendation: ' . $result->action() . PHP_EOL;
    echo 'SMS eligible: ' . ($result->smsEligible() ? 'yes' : 'no') . PHP_EOL;
    echo 'State DNC: ' . var_export($result->dncMatch('state'), true) . PHP_EOL;
    echo 'Complainer DNC: ' . var_export($result->dncMatch('complainer'), true) . PHP_EOL;
    // Apply your campaign's DNC policy separately, including skipped/failed checks.
    // shouldSendSms() summarizes the carrier recommendation; this example sends no SMS.
} catch (ApiException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
