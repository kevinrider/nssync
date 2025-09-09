<?php
$targetNightscoutUrl = getenv('TARGET_NIGHTSCOUT_URL');
$targetNightscoutApiSecret = sha1(getenv('TARGET_NIGHTSCOUT_API_SECRET'));
$destinationNightscoutUrl = getenv('DESTINATION_NIGHTSCOUT_URL');
$destinationNightscoutApiSecret = sha1(getenv('DESTINATION_NIGHTSCOUT_API_SECRET'));

$currentDate = new DateTimeImmutable();
$currentDate = $currentDate->modify('-1 week');
$endDate = new DateTimeImmutable();
$endDate = $endDate->modify('+1 day');

syncEndpoint('entries', 'dateString', $currentDate, $endDate, $targetNightscoutUrl, $targetNightscoutApiSecret, $destinationNightscoutUrl, $destinationNightscoutApiSecret);
syncEndpoint('treatments', 'created_at', $currentDate, $endDate, $targetNightscoutUrl, $targetNightscoutApiSecret, $destinationNightscoutUrl, $destinationNightscoutApiSecret);
syncEndpoint('devicestatus', 'created_at', $currentDate, $endDate, $targetNightscoutUrl, $targetNightscoutApiSecret, $destinationNightscoutUrl, $destinationNightscoutApiSecret, true);
syncEndpoint('profiles', 'startDate', $currentDate, $endDate, $targetNightscoutUrl, $targetNightscoutApiSecret, $destinationNightscoutUrl, $destinationNightscoutApiSecret, true);

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
        echo 'Error:' . curl_error($ch);
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
        echo 'Error:' . curl_error($ch);
    }
    curl_close($ch);
}

function syncEndpoint(string $endpoint, string $dateField, DateTimeImmutable $currentDate, DateTimeImmutable $endDate, string $targetNightscoutUrl, string $targetNightscoutApiSecret, string $destinationNightscoutUrl, string $destinationNightscoutApiSecret, bool $deduplicate = false): void
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
            $targetNightscoutUrl,
            $endpoint,
            $dateField,
            $loopToDate,
            $dateField,
            $loopFromDate
        );

        $sourceData = _fetchFromNightscout($url, $targetNightscoutApiSecret);

        if (empty($sourceData)) {
            continue;
        }

        $dataToPost = $sourceData;

        if ($deduplicate) {
            $destinationUrl = sprintf(
                '%s/api/v1/%s.json?count=all&find[%s][$lte]=%s&find[%s][$gte]=%s',
                $destinationNightscoutUrl,
                $endpoint,
                $dateField,
                $loopToDate,
                $dateField,
                $loopFromDate
            );
            $destinationData = _fetchFromNightscout($destinationUrl, $destinationNightscoutApiSecret);

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
            _postToNightscout($destinationNightscoutUrl . '/api/v1/' . $endpoint, $destinationNightscoutApiSecret, $dataToPost);
        }
    }
}