<?php

namespace Nssync;

use DateTimeImmutable;
use Exception;
use GuzzleHttp\Exception\GuzzleException;

class NightscoutSyncer
{
    private const string PROFILES_ENDPOINT = 'profiles';
    private const string PROFILE_ENDPOINT = 'profile';
    private const string TREATMENTS_ENDPOINT = 'treatments';
    private static string $OVERRIDE_CACHE_FILE = __DIR__.'/../.cache/active_overrides.json';

    private NightscoutClient $client;

    private array $source;

    private array $destination;

    public function __construct(NightscoutClient $client, array $source, array $destination)
    {
        $this->client = $client;
        $this->source = $source;
        $this->destination = $destination;
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
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

            if ($endpoint === self::TREATMENTS_ENDPOINT) {
                $this->cacheActiveOverrides($sourceData);
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

                if (! empty($destinationData)) {
                    $existingKeys = array_map(function ($item) use ($dateField) {
                        return $item[$dateField];
                    }, $destinationData);
                    $existingKeysSet = array_flip($existingKeys);

                    $dataToPost = array_filter($sourceData, function ($item) use ($existingKeysSet, $dateField) {
                        return ! isset($existingKeysSet[$item[$dateField]]);
                    });
                }
            }
            $transformedEndpoint = $endpoint == self::PROFILES_ENDPOINT ? self::PROFILE_ENDPOINT : $endpoint;
            if (! empty($dataToPost)) {
                $this->client->post($this->destination['url'].'/api/v1/'.$transformedEndpoint, $this->destination['secret'], $dataToPost);
            }
        }
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function syncCachedOverrides(): void
    {
        $cachedOverrides = $this->readFromCache();
        if (empty($cachedOverrides)) {
            return;
        }

        $updatedCache = $cachedOverrides;
        foreach ($cachedOverrides as $index => $override) {
            $sourceOverrideUrl = sprintf(
                '%s/api/v1/treatments.json?find[_id]=%s',
                $this->source['url'],
                $override['_id']
            );
            $sourceOverrideData = $this->client->fetch($sourceOverrideUrl, $this->source['secret']);
            $sourceOverride = $sourceOverrideData[0] ?? null;

            if (empty($sourceOverride)) {
                unset($updatedCache[$index]);
                continue;
            }

            if (! $this->isOverrideActive($sourceOverride)) {
                // Nightscout's API handles POST as an update if a matching record exists.
                $this->client->post(
                    $this->destination['url'].'/api/v1/'.self::TREATMENTS_ENDPOINT,
                    $this->destination['secret'],
                    [$sourceOverride]
                );

                // Remove from cache as it's now completed
                unset($updatedCache[$index]);
            }
        }

        $this->writeToCache(array_values($updatedCache));
    }

    /**
     * @throws Exception
     */
    private function cacheActiveOverrides(array $sourceData): void
    {
        $cachedOverrides = $this->readFromCache();
        $existingIds = array_column($cachedOverrides, '_id');

        foreach ($sourceData as $treatment) {
            if (isset($treatment['eventType']) && $treatment['eventType'] === 'Temporary Override') {
                if ($this->isOverrideActive($treatment) && ! in_array($treatment['_id'], $existingIds)) {
                    $cachedOverrides[] = [
                        '_id' => $treatment['_id'],
                        'created_at' => $treatment['created_at'],
                    ];
                }
            }
        }

        $this->writeToCache(array_values(array_unique($cachedOverrides, SORT_REGULAR)));
    }

    /**
     * @throws Exception
     */
    private function isOverrideActive(array $treatment): bool
    {
        // An override is active if its durationType is 'indefinite'
        if (isset($treatment['durationType']) && $treatment['durationType'] === 'indefinite') {
            return true;
        }
        // Or if it does not have a duration set yet
        if (!isset($treatment['duration'])) {
            return true;
        }
        // Or if the duration has not expired yet
        if (isset($treatment['created_at'])) {
            $createdAt = new DateTimeImmutable($treatment['created_at']);
            $endTime = $createdAt->modify('+'.(int)$treatment['duration'].' minutes');
            if ($endTime > new DateTimeImmutable()) {
                return true;
            }
        }
        return false;
    }

    private function readFromCache(): array
    {
        if (! file_exists(self::$OVERRIDE_CACHE_FILE)) {
            return [];
        }
        $content = file_get_contents(self::$OVERRIDE_CACHE_FILE);
        return json_decode($content, true) ?: [];
    }

    private function writeToCache(array $data): void
    {
        file_put_contents(self::$OVERRIDE_CACHE_FILE, json_encode($data, JSON_PRETTY_PRINT));
    }
}
