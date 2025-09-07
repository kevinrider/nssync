<?php
$oldSecureDomain = getenv('TARGET_NIGHTSCOUT_URL');
$oldHash = sha1(getenv('TARGET_NIGHTSCOUT_API_SECRET'));
$newSecureDomain = getenv('DESTINATION_NIGHTSCOUT_URL');
$hashedSecret = sha1(getenv('DESTINATION_NIGHTSCOUT_API_SECRET'));

$currentDate = new DateTimeImmutable();
$currentDate = $currentDate->modify('-1 week');
$endDate = new DateTimeImmutable();
$endDate = $endDate->modify('+1 day');

syncEndpoint('entries', 'dateString', $currentDate, $endDate, $oldSecureDomain, $oldHash, $newSecureDomain, $hashedSecret);
syncEndpoint('treatments', 'created_at', $currentDate, $endDate, $oldSecureDomain, $oldHash, $newSecureDomain, $hashedSecret);
syncEndpoint('devicestatus', 'created_at', $currentDate, $endDate, $oldSecureDomain, $oldHash, $newSecureDomain, $hashedSecret);
syncSingleton('profile', $oldSecureDomain, $oldHash, $newSecureDomain, $hashedSecret);

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

function syncEndpoint(string $endpoint, string $dateField, DateTimeImmutable $currentDate, DateTimeImmutable $endDate, string $oldSecureDomain, string $oldHash, string $newSecureDomain, string $hashedSecret): void
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
            $oldSecureDomain,
            $endpoint,
            $dateField,
            $loopToDate,
            $dateField,
            $loopFromDate
        );

        $data = _fetchFromNightscout($url, $oldHash);

        if (!empty($data)) {
            _postToNightscout($newSecureDomain . '/api/v1/' . $endpoint, $hashedSecret, $data);
        }
    }
}

function syncSingleton(string $endpoint, string $oldSecureDomain, string $oldHash, string $newSecureDomain, string $hashedSecret): void
{
    $url = sprintf('%s/api/v1/%s.json', $oldSecureDomain, $endpoint);
    $data = _fetchFromNightscout($url, $oldHash);

    if (!empty($data)) {
        _postToNightscout($newSecureDomain . '/api/v1/' . $endpoint, $hashedSecret, $data);
    }
}
