<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Exception\RequestException;

const MAX_RETRIES = 3;
const RETRY_DELAY = 1; // in seconds
const PROFILES_ENDPOINT = 'profiles';
const PROFILE_ENDPOINT = 'profile';

$requiredEnvVars = [
    'SOURCE_NIGHTSCOUT_URL',
    'SOURCE_NIGHTSCOUT_API_SECRET',
    'DESTINATION_NIGHTSCOUT_URL',
    'DESTINATION_NIGHTSCOUT_API_SECRET',
];

foreach ($requiredEnvVars as $var) {
    if (getenv($var) === false) {
        file_put_contents('php://stderr', "Error: Required environment variable $var is not set." . PHP_EOL);
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

$currentDate = new DateTimeImmutable();
$currentDate = $currentDate->modify('-1 week');
$endDate = new DateTimeImmutable();
$endDate = $endDate->modify('+1 day');

$endPointsToSync = [
    ['entries', 'dateString', false],
    ['treatments', 'created_at', false],
    ['devicestatus', 'created_at', true],
    [PROFILES_ENDPOINT, 'startDate', true],
];

// Create a Guzzle client with retry middleware
$handlerStack = HandlerStack::create();
$handlerStack->push(Middleware::retry(
    function ($retries, $request, $response, $exception) {
        // Retry up to MAX_RETRIES times
        if ($retries >= MAX_RETRIES) {
            return false;
        }

        // Retry on server errors or connection exceptions
        if ($exception instanceof RequestException || ($response && $response->getStatusCode() >= 500)) {
            file_put_contents('php://stderr', "Request failed, retrying (" . ($retries + 1) . "/" . MAX_RETRIES . ")..." . PHP_EOL);
            return true;
        }

        return false;
    },
    function ($retries) {
        // Delay between retries
        return RETRY_DELAY * 1000;
    }
));
$client = new Client(['handler' => $handlerStack]);


foreach($endPointsToSync as $endpoint) {
    [$endpoint, $dateField, $deduplicate] = $endpoint;
    syncEndpoint($endpoint, $dateField, $currentDate, $endDate, $source, $destination, $client, $deduplicate);
};

function _fetchFromNightscout(string $url, string $hash, Client $client): ?array
{
    try {
        $response = $client->get($url, [
            'headers' => [
                'api-secret' => $hash,
            ],
        ]);
        return json_decode($response->getBody(), true);
    } catch (RequestException $e) {
        file_put_contents('php://stderr', 'Error: ' . $e->getMessage() . PHP_EOL);
        return null;
    }
}

function _postToNightscout(string $url, string $hash, array $data, Client $client): void
{
    $newArray = [];
    foreach ($data as $item) {
        unset($item['_id']);
        $newArray[] = $item;
    }

    if (empty($newArray)) {
        return;
    }

    try {
        $client->post($url, [
            'headers' => [
                'api-secret' => $hash,
            ],
            'json' => $newArray,
        ]);
    } catch (RequestException $e) {
        file_put_contents('php://stderr', 'Error: ' . $e->getMessage() . PHP_EOL);
    }
}

function syncEndpoint(string $endpoint, string $dateField, DateTimeImmutable $currentDate, DateTimeImmutable $endDate, array $source, array $destination, Client $client, bool $deduplicate = false): void
{
    while ($currentDate < $endDate) {
        $loopFromDate = $currentDate->format('Y-m-d');
        $currentDate = $currentDate->modify('+1 day');
        $loopToDate = $currentDate->format('Y-m-d');

        if ($currentDate > $endDate) {
            $loopToDate = $endDate->format('Y-m-d');
        }
        if($loopToDate == $loopFromDate && $loopFromDate == $endDate->format('Y-m-d')) {
            break;
        }

        $url = sprintf(
            '%s/api/v1/%s.json?count=all&find[%s][$lte]=%s&find[%s][$gte]=%s',
            $source['url'],
            $endpoint,
            $dateField,
            $loopToDate,
            $dateField,
            $loopFromDate
        );

        $sourceData = _fetchFromNightscout($url, $source['secret'], $client);

        if (empty($sourceData)) {
            continue;
        }

        $dataToPost = $sourceData;

        if ($deduplicate) {
            $destinationUrl = sprintf(
                '%s/api/v1/%s.json?count=all&find[%s][$lte]=%s&find[%s][$gte]=%s',
                $destination['url'],
                $endpoint,
                $dateField,
                $loopToDate,
                $dateField,
                $loopFromDate
            );
            $destinationData = _fetchFromNightscout($destinationUrl, $destination['secret'], $client);

            if (is_null($destinationData)) {
                continue;
            }

            if (!empty($destinationData)) {
                $existingKeys = array_map(function($item) use ($dateField) {
                    return $item[$dateField];
                }, $destinationData);
                $existingKeysSet = array_flip($existingKeys);

                $dataToPost = array_filter($sourceData, function($item) use ($existingKeysSet, $dateField) {
                    return !isset($existingKeysSet[$item[$dateField]]);
                });
            }
        }
        $transformedEndpoint = $endpoint == PROFILES_ENDPOINT ? PROFILE_ENDPOINT : $endpoint;
        if (!empty($dataToPost)) {
            _postToNightscout($destination['url'] . '/api/v1/' . $transformedEndpoint, $destination['secret'], $dataToPost, $client);
        }
    }
}
