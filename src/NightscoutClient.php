<?php

namespace Nssync;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

class NightscoutClient
{
    private Client $client;

    private Logger $logger;

    public function __construct(Logger $logger, ?Client $client = null)
    {
        $this->logger = $logger;

        if ($client === null) {
            $handlerStack = HandlerStack::create();
            $handlerStack->push(Middleware::retry(
                function ($retries, $request, $response, $exception) {
                    if ($retries >= 3) {
                        return false;
                    }
                    $isRetryError = $exception instanceof ConnectException || $exception instanceof RequestException || ($response && $response->getStatusCode() >= 500);
                    if ($isRetryError) {
                        $this->logger->warning('Request failed, retrying ('.($retries + 1).'/'. 3 .')...');

                        return true;
                    }

                    return false;
                },
                function (int $retries) {
                    return 1000 * (2 ** $retries);
                }
            ));
            $client = new Client(['handler' => $handlerStack]);
        }

        $this->client = $client;
    }

    /**
     * @throws GuzzleException
     */
    public function fetch(string $url, string $hash): ?array
    {
        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'api-secret' => $hash,
                ],
            ]);

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            $this->logger->error($e->getMessage());

            return null;
        }
    }

    /**
     * @throws GuzzleException
     */
    public function post(string $url, string $hash, array $data): void
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
            $this->client->post($url, [
                'headers' => [
                    'api-secret' => $hash,
                ],
                'json' => $newArray,
            ]);
        } catch (RequestException $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
