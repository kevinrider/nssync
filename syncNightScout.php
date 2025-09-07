<?php
$oldSecureDomain = getenv('TARGET_NIGHTSCOUT_URL');
$oldHash = sha1(getenv('TARGET_NIGHTSCOUT_API_SECRET'));
$newSecureDomain = getenv('DESTINATION_NIGHTSCOUT_URL');
$hashedSecret = sha1(getenv('DESTINATION_NIGHTSCOUT_API_SECRET'));

$currentDate = new DateTimeImmutable();
$currentDate = $currentDate->modify('-1 week');
$endDate = new DateTimeImmutable();
$endDate = $endDate->modify('+1 day');

syncEntries($currentDate, $endDate, $oldSecureDomain, $oldHash, $newSecureDomain, $hashedSecret);
syncTreatments($currentDate, $endDate, $oldSecureDomain, $oldHash, $newSecureDomain, $hashedSecret);
syncDeviceStatus($currentDate, $endDate, $oldSecureDomain, $oldHash, $newSecureDomain, $hashedSecret);
syncProfile($oldSecureDomain, $oldHash, $newSecureDomain, $hashedSecret);

function syncEntries(DateTimeImmutable $currentDate, DateTimeImmutable $endDate, string $oldSecureDomain, string $oldHash, string $newSecureDomain, string $hashedSecret): void {
    while ($currentDate < $endDate) {
        $loopFromDate = $currentDate->format('Y-m-d');
        $currentDate = $currentDate->modify('+1 day');
        $loopToDate = $currentDate->format('Y-m-d');

        if (new DateTimeImmutable($loopToDate) > $endDate) {
            $loopToDate = $endDate->format('Y-m-d');
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $oldSecureDomain .'/api/v1/entries.json?count=all&find[dateString][$lte]=' . $loopToDate . '&find[dateString][$gte]=' . $loopFromDate);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        $headers = [];
        $headers[] = 'api-secret: ' . $oldHash;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }
        curl_close($ch);

        $arr = json_decode($result, true);
        if (!empty($arr)) {
            $newArray = [];
            foreach($arr as $item) {
                unset($item['_id']);
                $newArray[] = $item;
            }
            $newJSON = json_encode($newArray);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $newSecureDomain . '/api/v1/entries');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $newJSON);
            $headers = array();
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'api-secret: ' . $hashedSecret;
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_exec($ch);
            if (curl_errno($ch)) {
                echo 'Error:' . curl_error($ch);
            }
            curl_close($ch);
        }
    }
}

function syncTreatments(DateTimeImmutable $currentDate, DateTimeImmutable $endDate, string $oldSecureDomain, string $oldHash, string $newSecureDomain, string $hashedSecret): void {
    while ($currentDate < $endDate) {
        $loopFromDate = $currentDate->format('Y-m-d');
        $currentDate = $currentDate->modify('+1 day');
        $loopToDate = $currentDate->format('Y-m-d');
        if (new DateTimeImmutable($loopToDate) > $endDate) {
            $loopToDate = $endDate->format('Y-m-d');
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $oldSecureDomain . '/api/v1/treatments.json?count=all&find[created_at][$lte]=' . $loopToDate . '&find[created_at][$gte]=' . $loopFromDate);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        $headers = array();
        $headers[] = 'api-secret: ' . $oldHash;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }
        curl_close($ch);

        $arr = json_decode($result, true);
        if (!empty($arr)) {
            $newArray = [];
            foreach ($arr as $item) {
                unset($item['_id']);
                $newArray[] = $item;
            }
            $newJSON = json_encode($newArray);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $newSecureDomain . '/api/v1/treatments');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $newJSON);

            $headers = array();
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'api-secret: ' . $hashedSecret;
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_exec($ch);
            if (curl_errno($ch)) {
                echo 'Error:' . curl_error($ch);
            }
            curl_close($ch);
        }
    }
}

function syncDeviceStatus(DateTimeImmutable $currentDate, DateTimeImmutable $endDate, string $oldSecureDomain, string $oldHash, string $newSecureDomain, string $hashedSecret): void {
    while ($currentDate < $endDate) {
        $loopFromDate = $currentDate->format('Y-m-d');
        $currentDate = $currentDate->modify('+1 day');
        $loopToDate = $currentDate->format('Y-m-d');
        if (new DateTimeImmutable($loopToDate) > $endDate) {
            $loopToDate = $endDate->format('Y-m-d');
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $oldSecureDomain . '/api/v1/devicestatus.json?count=all&find[created_at][$lte]=' . $loopToDate . '&find[created_at][$gte]=' . $loopFromDate);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        $headers = array();
        $headers[] = 'api-secret: ' . $oldHash;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }

        curl_close($ch);
        $arr = json_decode($result, true);
        if (!empty($arr)) {
            $newArray = [];
            foreach ($arr as $item) {
                unset($item['_id']);
                $newArray[] = $item;
            }

            $newJSON = json_encode($newArray);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $newSecureDomain . '/api/v1/devicestatus');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $newJSON);
            $headers = array();
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'api-secret: ' . $hashedSecret;
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_exec($ch);
            if (curl_errno($ch)) {
                echo 'Error:' . curl_error($ch);
            }
            curl_close($ch);
        }
    }
}

function syncProfile(string $oldSecureDomain, string $oldHash, string $newSecureDomain, string $hashedSecret): void {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $oldSecureDomain .'/api/v1/profile.json');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    $headers = array();
    $headers[] = 'api-secret: ' . $oldHash;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }
    curl_close($ch);

    $arr = json_decode($result);
    if(!empty($arr->message) && $arr->message = 'Unauthorized') {
        echo 'Error: ' . $arr->message;
    }
    $arr2 = json_decode($result, true);

    $newArray = [];
    foreach($arr2 as $item) {
        unset($item['_id']);
        $newArray[] = $item;
    }

    $newJSON = json_encode($newArray);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $newSecureDomain . '/api/v1/profile');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $newJSON);

    $headers = [];
    $headers[] = 'Content-Type: application/json';
    $headers[] = 'api-secret: ' . $hashedSecret;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }
    curl_close($ch);
}
