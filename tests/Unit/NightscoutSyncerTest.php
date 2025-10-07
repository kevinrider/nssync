<?php

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Nssync\NightscoutClient;
use Nssync\NightscoutSyncer;

uses(MockeryPHPUnitIntegration::class);

beforeEach(function () {
    $this->client = Mockery::mock(NightscoutClient::class);
    $this->source = ['url' => 'https://source.com', 'secret' => 'source-secret'];
    $this->destination = ['url' => 'https://destination.com', 'secret' => 'destination-secret'];
    $this->syncer = new NightscoutSyncer($this->client, $this->source, $this->destination);
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
