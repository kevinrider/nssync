<?php

require 'vendor/autoload.php';

use GuzzleHttp\Exception\GuzzleException;
use Nssync\Logger;
use Nssync\NightscoutClient;
use Nssync\NightscoutSyncer;

$logger = new Logger;

$requiredEnvVars = [
    'SOURCE_NIGHTSCOUT_URL',
    'SOURCE_NIGHTSCOUT_API_SECRET',
    'DESTINATION_NIGHTSCOUT_URL',
    'DESTINATION_NIGHTSCOUT_API_SECRET',
];

foreach ($requiredEnvVars as $var) {
    if (getenv($var) === false) {
        $logger->error("Required environment variable $var is not set.");
        exit(1);
    }
}

$source = [
    'url' => getenv('SOURCE_NIGHTSCOUT_URL'),
    'secret' => sha1(getenv('SOURCE_NIGHTSCOUT_API_SECRET')),
];

$destination = [
    'url' => getenv('DESTINATION_NIGHTSCOUT_URL'),
    'secret' => sha1(getenv('DESTINATION_NIGHTSCOUT_API_SECRET')),
];

$currentDate = new DateTimeImmutable;
$currentDate = $currentDate->modify('-1 week');
$endDate = new DateTimeImmutable;
$endDate = $endDate->modify('+1 day');

$endPointsToSync = [
    ['entries', 'dateString', false],
    ['treatments', 'created_at', false],
    ['devicestatus', 'created_at', true],
    ['profiles', 'startDate', true],
];

$client = new NightscoutClient($logger);
$syncer = new NightscoutSyncer($client, $source, $destination);

foreach ($endPointsToSync as $endpoint) {
    [$endpointName, $dateField, $deduplicate] = $endpoint;
    try {
        $syncer->syncEndpoint($endpointName, $dateField, $currentDate, $endDate, $deduplicate);
    } catch (GuzzleException $e) {
        $logger->error('GuzzleException caught, continuing: '.$e->getMessage());
    }
}
