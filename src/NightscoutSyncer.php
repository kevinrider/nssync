<?php

namespace Nssync;

use DateTimeImmutable;

class NightscoutSyncer
{
    private const PROFILES_ENDPOINT = 'profiles';
    private const PROFILE_ENDPOINT = 'profile';

    private NightscoutClient $client;
    private array $source;
    private array $destination;

    public function __construct(NightscoutClient $client, array $source, array $destination)
    {
        $this->client = $client;
        $this->source = $source;
        $this->destination = $destination;
    }

    public function syncEndpoint(string $endpoint, string $dateField, DateTimeImmutable $currentDate, DateTimeImmutable $endDate, bool $deduplicate = false): void
    {
        while ($currentDate < $endDate) {
            $loopFromDate = $currentDate->format('Y-m-d');
            $currentDate = $currentDate->modify('+1 day');
            $loopToDate = $currentDate->format('Y-m-d');

            if ($currentDate > $endDate) {
                $loopToDate = $endDate->format('Y-m-d');
            }
            if ($loopToDate == $loopFromDate && $loopFromDate == $endDate->format('Y-m-d')) {
                break;
            }

            $url = sprintf(
                '%s/api/v1/%s.json?count=all&find[%s][$lte]=%s&find[%s][$gte]=%s',
                $this->source['url'],
                $endpoint,
                $dateField,
                $loopToDate,
                $dateField,
                $loopFromDate
            );

            $sourceData = $this->client->fetch($url, $this->source['secret']);

            if (empty($sourceData)) {
                continue;
            }

            $dataToPost = $sourceData;

            if ($deduplicate) {
                $destinationUrl = sprintf(
                    '%s/api/v1/%s.json?count=all&find[%s][$lte]=%s&find[%s][$gte]=%s',
                    $this->destination['url'],
                    $endpoint,
                    $dateField,
                    $loopToDate,
                    $dateField,
                    $loopFromDate
                );
                $destinationData = $this->client->fetch($destinationUrl, $this->destination['secret']);

                if (is_null($destinationData)) {
                    continue;
                }

                if (!empty($destinationData)) {
                    $existingKeys = array_map(function ($item) use ($dateField) {
                        return $item[$dateField];
                    }, $destinationData);
                    $existingKeysSet = array_flip($existingKeys);

                    $dataToPost = array_filter($sourceData, function ($item) use ($existingKeysSet, $dateField) {
                        return !isset($existingKeysSet[$item[$dateField]]);
                    });
                }
            }
            $transformedEndpoint = $endpoint == self::PROFILES_ENDPOINT ? self::PROFILE_ENDPOINT : $endpoint;
            if (!empty($dataToPost)) {
                $this->client->post($this->destination['url'] . '/api/v1/' . $transformedEndpoint, $this->destination['secret'], $dataToPost);
            }
        }
    }
}
