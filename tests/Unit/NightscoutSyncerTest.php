<?php

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Nssync\NightscoutClient;
use Nssync\NightscoutSyncer;
use org\bovigo\vfs\vfsStream;

uses(MockeryPHPUnitIntegration::class);

beforeEach(function () {
    $this->client = Mockery::mock(NightscoutClient::class);
    $this->source = ['url' => 'https://source.com', 'secret' => 'source-secret'];
    $this->destination = ['url' => 'https://destination.com', 'secret' => 'destination-secret'];

    // Setup virtual filesystem
    $this->root = vfsStream::setup();
    $this->cacheFile = vfsStream::url('root/active_overrides.json');

    $this->syncer = new NightscoutSyncer($this->client, $this->source, $this->destination);

    // Use reflection to modify the private static property for the cache file path
    $reflection = new ReflectionClass($this->syncer);
    $property = $reflection->getProperty('OVERRIDE_CACHE_FILE');
    $property->setValue(null, $this->cacheFile); // Set the static property value
});

afterEach(function () {
    Mockery::close();
});

test('syncCachedOverrides updates and removes completed override from cache', function () {
    $cachedOverride = ['_id' => 'override1', 'created_at' => '2023-01-01T12:00:00Z'];
    file_put_contents($this->cacheFile, json_encode([$cachedOverride]));

    $sourceOverride = [
        '_id' => 'override1',
        'eventType' => 'Temporary Override',
        'created_at' => '2023-01-01T12:00:00Z',
        'duration' => 60, // 60 minutes, ended in the past
    ];

    $fetchUrl = 'https://source.com/api/v1/treatments.json?find[_id][$eq]=override1';
    $this->client->shouldReceive('fetch')->with($fetchUrl, 'source-secret')->andReturn([$sourceOverride]);

    $postUrl = 'https://destination.com/api/v1/treatments';
    $this->client->shouldReceive('post')->with($postUrl, 'destination-secret', [$sourceOverride])->once();

    $this->syncer->syncCachedOverrides();

    expect(file_get_contents($this->cacheFile))->toBe('[]');
});

test('syncCachedOverrides keeps active override in cache', function () {
    $cachedOverride = ['_id' => 'override2', 'created_at' => (new DateTimeImmutable())->format('c')];
    file_put_contents($this->cacheFile, json_encode([$cachedOverride]));

    $sourceOverride = [
        '_id' => 'override2',
        'eventType' => 'Temporary Override',
        'created_at' => (new DateTimeImmutable())->format('c'),
        'duration' => 120, // 120 minutes, still active
    ];

    $fetchUrl = 'https://source.com/api/v1/treatments.json?find[_id][$eq]=override2';
    $this->client->shouldReceive('fetch')->with($fetchUrl, 'source-secret')->andReturn([$sourceOverride]);

    $this->client->shouldNotReceive('post');

    $this->syncer->syncCachedOverrides();

    $expectedCache = json_encode([$cachedOverride], JSON_PRETTY_PRINT);
    expect(file_get_contents($this->cacheFile))->toBe($expectedCache);
});

test('syncEndpoint caches various types of active overrides', function () {
    $endpoint = 'treatments';
    $dateField = 'created_at';
    $currentDate = new DateTimeImmutable('2023-01-01');
    $endDate = new DateTimeImmutable('2023-01-02');
    $now = (new DateTimeImmutable())->format('c');
    $sourceData = [
        // Active: Indefinite
        ['eventType' => 'Temporary Override', '_id' => 'active1', 'created_at' => '2023-01-01T10:00:00Z', 'durationType' => 'indefinite'],
        // Active: No duration yet
        ['eventType' => 'Temporary Override', '_id' => 'active2', 'created_at' => '2023-01-01T11:00:00Z'],
        // Active: Duration not expired
        ['eventType' => 'Temporary Override', '_id' => 'active3', 'created_at' => $now, 'duration' => 60],
        // Inactive: Expired duration
        ['eventType' => 'Temporary Override', '_id' => 'inactive1', 'created_at' => '2023-01-01T12:00:00Z', 'duration' => 30],
        // Not an override
        ['eventType' => 'Note', '_id' => 'note1', 'created_at' => '2023-01-01T13:00:00Z'],
    ];

    $fetchUrl = 'https://source.com/api/v1/treatments.json?count=all&find[created_at][$lte]=2023-01-02&find[created_at][$gte]=2023-01-01';
    $this->client->shouldReceive('fetch')->with($fetchUrl, 'source-secret')->andReturn($sourceData);
    $this->client->shouldReceive('post'); // Allow post for the sync itself

    $this->syncer->syncEndpoint($endpoint, $dateField, $currentDate, $endDate);

    $expectedCache = [
        ['_id' => 'active1', 'created_at' => '2023-01-01T10:00:00Z'],
        ['_id' => 'active2', 'created_at' => '2023-01-01T11:00:00Z'],
        ['_id' => 'active3', 'created_at' => $now],
    ];

    $cachedData = json_decode(file_get_contents($this->cacheFile), true);
    expect($cachedData)->toBe($expectedCache);
});

test('syncs data without deduplication', function () {
    $endpoint = 'treatments';
    $dateField = 'created_at';
    $currentDate = new DateTimeImmutable('2023-01-01');
    $endDate = new DateTimeImmutable('2023-01-02');

    $sourceData = [
        ['created_at' => '2023-01-01T10:00:00Z', 'value' => 1],
        ['created_at' => '2023-01-01T11:00:00Z', 'value' => 2],
    ];

    $fetchUrl = 'https://source.com/api/v1/treatments.json?count=all&find[created_at][$lte]=2023-01-02&find[created_at][$gte]=2023-01-01';
    $this->client->shouldReceive('fetch')->with($fetchUrl, 'source-secret')->andReturn($sourceData);

    $postUrl = 'https://destination.com/api/v1/treatments';
    $this->client->shouldReceive('post')->with($postUrl, 'destination-secret', $sourceData)->once();

    $this->syncer->syncEndpoint($endpoint, $dateField, $currentDate, $endDate);
});

test('syncs data with deduplication', function () {
    $endpoint = 'entries';
    $dateField = 'dateString';
    $currentDate = new DateTimeImmutable('2023-01-01');
    $endDate = new DateTimeImmutable('2023-01-02');

    $sourceData = [
        ['dateString' => '2023-01-01T10:00:00Z', 'sgv' => 100],
        ['dateString' => '2023-01-01T11:00:00Z', 'sgv' => 110],
    ];

    $destinationData = [
        ['dateString' => '2023-01-01T10:00:00Z', 'sgv' => 100],
    ];

    $dataToPost = [
        ['dateString' => '2023-01-01T11:00:00Z', 'sgv' => 110],
    ];

    $sourceFetchUrl = 'https://source.com/api/v1/entries.json?count=all&find[dateString][$lte]=2023-01-02&find[dateString][$gte]=2023-01-01';
    $this->client->shouldReceive('fetch')->with($sourceFetchUrl, 'source-secret')->andReturn($sourceData);

    $destinationFetchUrl = 'https://destination.com/api/v1/entries.json?count=all&find[dateString][$lte]=2023-01-02&find[dateString][$gte]=2023-01-01';
    $this->client->shouldReceive('fetch')->with($destinationFetchUrl, 'destination-secret')->andReturn($destinationData);

    $postUrl = 'https://destination.com/api/v1/entries';
    $this->client->shouldReceive('post')->with($postUrl, 'destination-secret', Mockery::on(function ($arg) use ($dataToPost) {
        return array_values($arg) === $dataToPost;
    }))->once();

    $this->syncer->syncEndpoint($endpoint, $dateField, $currentDate, $endDate, true);
});

test('does not post when source data is empty', function () {
    $endpoint = 'treatments';
    $dateField = 'created_at';
    $currentDate = new DateTimeImmutable('2023-01-01');
    $endDate = new DateTimeImmutable('2023-01-02');

    $fetchUrl = 'https://source.com/api/v1/treatments.json?count=all&find[created_at][$lte]=2023-01-02&find[created_at][$gte]=2023-01-01';
    $this->client->shouldReceive('fetch')->with($fetchUrl, 'source-secret')->andReturn([]);

    $this->client->shouldNotReceive('post');

    $this->syncer->syncEndpoint($endpoint, $dateField, $currentDate, $endDate);
});

test('transforms profiles endpoint to profile for posting', function () {
    $endpoint = 'profiles';
    $dateField = 'created_at';
    $currentDate = new DateTimeImmutable('2023-01-01');
    $endDate = new DateTimeImmutable('2023-01-02');

    $sourceData = [['created_at' => '2023-01-01T10:00:00Z', 'profile' => 'test']];

    $fetchUrl = 'https://source.com/api/v1/profiles.json?count=all&find[created_at][$lte]=2023-01-02&find[created_at][$gte]=2023-01-01';
    $this->client->shouldReceive('fetch')->with($fetchUrl, 'source-secret')->andReturn($sourceData);

    $postUrl = 'https://destination.com/api/v1/profile';
    $this->client->shouldReceive('post')->with($postUrl, 'destination-secret', $sourceData)->once();

    $this->syncer->syncEndpoint($endpoint, $dateField, $currentDate, $endDate);
});

test('handles null destination data during deduplication', function () {
    $endpoint = 'entries';
    $dateField = 'dateString';
    $currentDate = new DateTimeImmutable('2023-01-01');
    $endDate = new DateTimeImmutable('2023-01-02');

    $sourceData = [['dateString' => '2023-01-01T10:00:00Z', 'sgv' => 100]];

    $sourceFetchUrl = 'https://source.com/api/v1/entries.json?count=all&find[dateString][$lte]=2023-01-02&find[dateString][$gte]=2023-01-01';
    $this->client->shouldReceive('fetch')->with($sourceFetchUrl, 'source-secret')->andReturn($sourceData);

    $destinationFetchUrl = 'https://destination.com/api/v1/entries.json?count=all&find[dateString][$lte]=2023-01-02&find[dateString][$gte]=2023-01-01';
    $this->client->shouldReceive('fetch')->with($destinationFetchUrl, 'destination-secret')->andReturn(null);

    $this->client->shouldNotReceive('post');

    $this->syncer->syncEndpoint($endpoint, $dateField, $currentDate, $endDate, true);
});

test('syncs all data when destination data is empty during deduplication', function () {
    $endpoint = 'entries';
    $dateField = 'dateString';
    $currentDate = new DateTimeImmutable('2023-01-01');
    $endDate = new DateTimeImmutable('2023-01-02');

    $sourceData = [
        ['dateString' => '2023-01-01T10:00:00Z', 'sgv' => 100],
        ['dateString' => '2023-01-01T11:00:00Z', 'sgv' => 110],
    ];

    $sourceFetchUrl = 'https://source.com/api/v1/entries.json?count=all&find[dateString][$lte]=2023-01-02&find[dateString][$gte]=2023-01-01';
    $this->client->shouldReceive('fetch')->with($sourceFetchUrl, 'source-secret')->andReturn($sourceData);

    $destinationFetchUrl = 'https://destination.com/api/v1/entries.json?count=all&find[dateString][$lte]=2023-01-02&find[dateString][$gte]=2023-01-01';
    $this->client->shouldReceive('fetch')->with($destinationFetchUrl, 'destination-secret')->andReturn([]);

    $postUrl = 'https://destination.com/api/v1/entries';
    $this->client->shouldReceive('post')->with($postUrl, 'destination-secret', $sourceData)->once();

    $this->syncer->syncEndpoint($endpoint, $dateField, $currentDate, $endDate, true);
});
