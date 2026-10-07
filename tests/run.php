<?php
declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use SubscriberVerify\ApiException;
use SubscriberVerify\Client;
use SubscriberVerify\LookupResult;
use SubscriberVerify\TransportInterface;

final class FakeTransport implements TransportInterface
{
    public array $requests = [];
    public array $responses = [];

    public function request(string $method, string $url, array $parameters): array
    {
        $this->requests[] = [$method, $url, $parameters];
        if ($this->responses === []) {
            throw new RuntimeException('Unexpected request.');
        }
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }
        return $response;
    }
}

$count = 0;
function same(mixed $expected, mixed $actual): void
{
    global $count;
    ++$count;
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true));
    }
}
function throws(string $type, callable $operation): Throwable
{
    global $count;
    ++$count;
    try {
        $operation();
    } catch (Throwable $e) {
        if ($e instanceof $type) {
            return $e;
        }
        throw $e;
    }
    throw new RuntimeException('Expected exception: ' . $type);
}

$http = new FakeTransport();
$client = new Client('test-secret', $http);
$send = ['action' => 'send', 'smsEligible' => 'true', 'deliverable' => 'true'];
$http->responses[] = $send + ['custom' => 'retained'];
$result = $client->lookup('8016041000', ['dncComplainer' => 1, 'list' => 'campaign', 'ip' => '2001:db8::1']);
same(['POST', 'https://api2.subscriberverify.com/api', [
    'key' => 'test-secret', 'phone' => '8016041000', 'dncComplainer' => true,
    'list' => 'campaign', 'ip' => '2001:db8::1',
]], $http->requests[0]);
same(true, $result->shouldSendSms());
same(false, $result->doNotSms());
same('retained', $result->get('custom'));
same(null, $result->boolean('unknown'));
same(false, $result->has('doNotSms'));
same($result->all(), $result->jsonSerialize());
foreach ([false, 'false', 0, '0'] as $value) {
    same(false, (new LookupResult(['litigator' => $value]))->boolean('litigator'));
}
foreach ([true, 'true', 1, '1'] as $value) {
    same(true, (new LookupResult(['litigator' => $value]))->boolean('litigator'));
}
foreach ([['doNotSms' => 'true'], ['litigator' => true], ['blackList' => true], ['deliverable' => 'false'], ['error' => true], ['action' => 'unsubscribe'], ['smsEligible' => 'false']] as $override) {
    same(false, (new LookupResult(array_replace($send, $override)))->shouldSendSms());
}
same(false, (new LookupResult(['action' => 'send']))->shouldSendSms());
same(false, (new LookupResult([]))->shouldSendSms());
$dnc = new LookupResult($send + [
    'dncStateChecked' => 'true', 'dncStateResult' => 'STATE DNC',
    'dncComplainerChecked' => true, 'dncComplainerResult' => '',
    'dncNationalChecked' => 'forbidden',
]);
same(true, $dnc->shouldSendSms());
same(true, $dnc->dncMatch('state'));
same(false, $dnc->dncMatch('complainer'));
same(null, $dnc->dncMatch('national'));
same(null, (new LookupResult([]))->dncMatch('state'));
same(null, (new LookupResult(['dncStateChecked' => 'true']))->dncMatch('state'));
throws(InvalidArgumentException::class, fn () => $dnc->dncMatch('unknown'));

$before = count($http->requests);
throws(InvalidArgumentException::class, fn () => new Client(' ', $http));
foreach (['18016041000', '(801)6041000', "8016041000\n", '801604100', 'abcdefghij'] as $phone) {
    throws(InvalidArgumentException::class, fn () => $client->lookup($phone));
}
foreach ([['ip' => 'bad'], ['list' => ''], ['dncOther' => true], ['key' => 'override'], ['dncState' => 'false']] as $options) {
    throws(InvalidArgumentException::class, fn () => $client->lookup('8016041000', $options));
}
throws(InvalidArgumentException::class, fn () => $client->bulkLookup([]));
throws(InvalidArgumentException::class, fn () => $client->bulkLookup(array_fill(0, 1001, ['phone' => '8016041000'])));
throws(InvalidArgumentException::class, fn () => $client->bulkLookup([['phone' => 8016041000]]));
throws(InvalidArgumentException::class, fn () => $client->bulkLookup([['phone' => '8016041000']], ['dncState' => true]));
throws(InvalidArgumentException::class, fn () => $client->bulkLookup([['phone' => '8016041000', 'list' => 'x']]));
throws(InvalidArgumentException::class, fn () => $client->carrierMapDownload('xml'));
throws(InvalidArgumentException::class, fn () => iterator_to_array($client->bulkLookupBatches([], [], 1001)));
same($before, count($http->requests));

foreach ([['action' => 'error', 'reason' => 'Out of credits: test-secret'], ['error' => 'true'], ['ok' => false], []] as $failure) {
    $http->responses[] = $failure;
    $error = throws(ApiException::class, fn () => $client->lookup('8016041000'));
    same(false, strpos($error->getMessage(), 'test-secret') !== false);
}
$http->responses[] = new ApiException('HTTP failure', 429);
same(429, throws(ApiException::class, fn () => $client->credits())->httpStatus());
foreach ([0, 435000, '12'] as $credits) {
    $http->responses[] = ['availableCredits' => $credits];
    same((int) $credits, $client->credits());
}
foreach ([[], ['availableCredits' => -1], ['availableCredits' => true], ['availableCredits' => 'bad']] as $failure) {
    $http->responses[] = $failure;
    throws(ApiException::class, fn () => $client->credits());
}

$http->responses[] = ['ok' => true, 'lookups' => 2, 'results' => [$send, ['action' => 'error', 'reason' => 'Lookup failed']]];
$batch = $client->bulkLookup([7 => ['phone' => '8016041000'], 9 => ['phone' => '3345050009']], ['litigatorFilter' => true]);
same(true, $batch->results()[0]->shouldSendSms());
same(true, $batch->results()[1]->isError());
same('Lookup failed', $batch->results()[1]->reason());
$last = $http->requests[count($http->requests) - 1];
same('/api-bulk', parse_url($last[1], PHP_URL_PATH));
same([0, 1], array_keys($last[2]['records']));
same(true, $last[2]['litigatorFilter']);
foreach ([['ok' => true, 'results' => []], ['ok' => true, 'results' => ['bad']], ['ok' => true, 'results' => [[]]]] as $failure) {
    $http->responses[] = $failure;
    throws(ApiException::class, fn () => $client->bulkLookup([['phone' => '8016041000']]));
}

$http->responses[] = ['ok' => true, 'results' => [$send, $send]];
$http->responses[] = ['ok' => true, 'results' => [$send]];
$before = count($http->requests);
$generator = $client->bulkLookupBatches(array_fill(0, 3, ['phone' => '8016041000']), [], 2);
same($before, count($http->requests)); // Lazy: nothing billed until iteration.
same(2, count($generator->current()->results()));
same($before + 1, count($http->requests));
$generator->next();
same(1, count($generator->current()->results()));
same($before + 2, count($http->requests));
$generator->next();
same(false, $generator->valid());
same([], iterator_to_array($client->bulkLookupBatches([])));

$metadata = ['ok' => true, 'downloadUrl' => 'https://example.com/signed?token=test', 'format' => 'csv', 'expiresAt' => '2026-10-06T00:00:00Z'];
$http->responses[] = $metadata;
same($metadata, $client->carrierMapDownload('csv'));
same(['GET', 'https://api2.subscriberverify.com/api/carrier-map', ['key' => 'test-secret', 'format' => 'csv']], $http->requests[count($http->requests) - 1]);
$http->responses[] = ['ok' => true, 'downloadUrl' => 'http://example.com/file'];
throws(ApiException::class, fn () => $client->carrierMapDownload());
$status = ['ok' => true, 'lastChanged' => '2026-10-05T00:00:00Z', 'carrierCount' => 1965];
$http->responses[] = $status;
same($status, $client->carrierMapStatus());
same([], $http->responses);
echo 'Passed ' . $count . ' assertions.' . PHP_EOL;
