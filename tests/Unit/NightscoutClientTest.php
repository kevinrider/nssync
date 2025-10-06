<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Nssync\Logger;
use Nssync\NightscoutClient;

uses(MockeryPHPUnitIntegration::class);

beforeEach(function () {
    // Mock the Logger and Guzzle Client
    $this->logger = Mockery::mock(Logger::class);
    $this->guzzleClient = Mockery::mock(Client::class);

    // Use reflection to inject the mocked Guzzle client
    $this->nightscoutClient = new NightscoutClient($this->logger);
    $reflector = new ReflectionClass(NightscoutClient::class);
    $property = $reflector->getProperty('client');
    $property->setValue($this->nightscoutClient, $this->guzzleClient);
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
    $url = 'http://example.com/api/v1/entries';
    $hash = 'api-secret-hash';

    $this->guzzleClient->shouldNotReceive('post');

    $this->nightscoutClient->post($url, $hash, []);
});

test('post logs error on request exception', function () {
    $url = 'http://example.com/api/v1/entries';
    $hash = 'api-secret-hash';
    $data = [['value' => 100]];
    $exception = new RequestException('Error Communicating with Server', new Request('POST', $url));

    $this->guzzleClient->shouldReceive('post')
        ->andThrow($exception);

    $this->logger->shouldReceive('error')->with('Error Communicating with Server')->once();

    $this->nightscoutClient->post($url, $hash, $data);
});
