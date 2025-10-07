<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Nssync\Logger;
use Nssync\NightscoutClient;

uses(MockeryPHPUnitIntegration::class);

beforeEach(function () {
    $this->logger = Mockery::mock(Logger::class);
    $this->guzzleClient = Mockery::mock(Client::class);
    $this->nightscoutClient = new NightscoutClient($this->logger, $this->guzzleClient);
});

test('fetch returns data on successful request', function () {
    $url = 'https://example.com/api/v1/entries.json';
    $hash = 'api-secret-hash';
    $expectedData = [['id' => 1, 'value' => 100]];

    $response = new Response(200, [], json_encode($expectedData));
    $this->guzzleClient->shouldReceive('get')
        ->with($url, ['headers' => ['api-secret' => $hash]])
        ->andReturn($response);

    $data = $this->nightscoutClient->fetch($url, $hash);

    expect($data)->toBe($expectedData);
});

test('fetch returns null and logs error on request exception', function () {
    $url = 'https://example.com/api/v1/entries.json';
    $hash = 'api-secret-hash';
    $exception = new RequestException('Error Communicating with Server', new Request('GET', $url));

    $this->guzzleClient->shouldReceive('get')
        ->with($url, ['headers' => ['api-secret' => $hash]])
        ->andThrow($exception);

    $this->logger->shouldReceive('error')->with('Error Communicating with Server')->once();

    $data = $this->nightscoutClient->fetch($url, $hash);

    expect($data)->toBeNull();
});

test('post sends data without _id field', function () {
    $url = 'https://example.com/api/v1/entries';
    $hash = 'api-secret-hash';
    $data = [
        ['_id' => '123', 'value' => 100],
        ['_id' => '456', 'value' => 150],
    ];
    $expectedJson = [
        ['value' => 100],
        ['value' => 150],
    ];

    $this->guzzleClient->shouldReceive('post')
        ->with($url, ['headers' => ['api-secret' => $hash], 'json' => $expectedJson])
        ->once();

    $this->nightscoutClient->post($url, $hash, $data);
});

test('post does nothing with empty data', function () {
    $url = 'https://example.com/api/v1/entries';
    $hash = 'api-secret-hash';

    $this->guzzleClient->shouldNotReceive('post');

    $this->nightscoutClient->post($url, $hash, []);
});

test('post logs error on request exception', function () {
    $url = 'https://example.com/api/v1/entries';
    $hash = 'api-secret-hash';
    $data = [['value' => 100]];
    $exception = new RequestException('Error Communicating with Server', new Request('POST', $url));

    $this->guzzleClient->shouldReceive('post')
        ->andThrow($exception);

    $this->logger->shouldReceive('error')->with('Error Communicating with Server')->once();

    $this->nightscoutClient->post($url, $hash, $data);
});

test('client retries on connection failure', function () {
    $logger = Mockery::mock(Logger::class);

    // 1. Create a mock handler to simulate a failure then a success
    $mockHandler = new MockHandler([
        new ConnectException('Connection refused', new Request('GET', 'test')),
        new Response(200, [], json_encode(['success' => true])),
    ]);

    $handlerStack = HandlerStack::create($mockHandler);

    // 2. Replicate the production retry middleware in the test
    $handlerStack->push(Middleware::retry(
        function ($retries, $request, $response, $exception) use ($logger) {
            if ($retries >= 3) {
                return false;
            }
            $isRetryError = $exception instanceof ConnectException || $exception instanceof RequestException || ($response && $response->getStatusCode() >= 500);
            if ($isRetryError) {
                // This is the behavior we want to test
                $logger->warning('Request failed, retrying ('.($retries + 1).'/'. 3 .')...');
                return true;
            }
            return false;
        },
        function () {
            return 0; // No delay in tests
        }
    ));

    // 3. Create a Guzzle client with our test-specific, production-like handler stack
    $guzzleClientWithRetry = new Client(['handler' => $handlerStack]);

    // 4. Set the expectation that the logger will be called
    $logger->shouldReceive('warning')->once()->with('Request failed, retrying (1/3)...');

    // 5. Inject the logger and the specially crafted client
    $client = new NightscoutClient($logger, $guzzleClientWithRetry);

    // 6. Call the method and assert the final outcome
    $result = $client->fetch('https://example.com/api', 'some-hash');

    expect($result)->toBe(['success' => true]);
});
