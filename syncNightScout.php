<?php

$requiredEnvVars = [
    'TARGET_NIGHTSCOUT_URL',
    'TARGET_NIGHTSCOUT_API_SECRET',
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
    'url' => getenv('TARGET_NIGHTSCOUT_URL'),
    'secret' => sha1(getenv('TARGET_NIGHTSCOUT_API_SECRET')),
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
    ['profiles', 'startDate', true],
];

foreach($endPointsToSync as $endpoint) {
    [$endpoint, $dateField, $deduplicate] = $endpoint;
    syncEndpoint($endpoint, $dateField, $currentDate, $endDate, $source, $destination, $deduplicate);
};

function _fetchFromNightscout(string $url, string $hash): ?array
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    $headers = ['api-secret: ' . $hash];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        file_put_contents('php://stderr', 'Error:' . curl_error($ch) . PHP_EOL);
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    return json_decode($result, true);
}

function _postToNightscout(string $url, string $hash, array $data): void
{
    $newArray = [];
    foreach ($data as $item) {
        unset($item['_id']);
        $newArray[] = $item;
    }
    $newJSON = json_encode($newArray);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $newJSON);
    $headers = [
        'Content-Type: application/json',
        'api-secret: ' . $hash,
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_exec($ch);
    if (curl_errno($ch)) {
        file_put_contents('php://stderr', 'Error:' . curl_error($ch) . PHP_EOL);
    }
    curl_close($ch);
}

function syncEndpoint(string $endpoint, string $dateField, DateTimeImmutable $currentDate, DateTimeImmutable $endDate, array $source, array $destination, bool $deduplicate = false): void
{
    while ($currentDate < $endDate) {
        $loopFromDate = $currentDate->format('Y-m-d');
        $currentDate = $currentDate->modify('+1 day');
        $loopToDate = $currentDate->format('Y-m-d');

        if ($currentDate > $endDate) {
            $loopToDate = $endDate->format('Y-m-d');
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

        $sourceData = _fetchFromNightscout($url, $source['secret']);

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
            $destinationData = _fetchFromNightscout($destinationUrl, $destination['secret']);

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

        if (!empty($dataToPost)) {
            _postToNightscout($destination['url'] . '/api/v1/' . $endpoint, $destination['secret'], $dataToPost);
        }
    }
}