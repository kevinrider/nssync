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

    public function __construct()
    {
        $handlerStack = HandlerStack::create();
        $handlerStack->push(Middleware::retry(
            function ($retries, $request, $response, $exception) {
                if ($retries >= 3) {
                    return false;
                }
                $isRetryError = $exception instanceof ConnectException || $exception instanceof RequestException || ($response && $response->getStatusCode() >= 500);
                if ($isRetryError) {
                    file_put_contents('php://stderr', 'Request failed, retrying ('.($retries + 1).'/'. 3 .')...'.PHP_EOL);

                    return true;
                }

                return false;
            },
            function (int $retries) {
                return 1000 * (2 ** $retries);
            }
        ));
        $this->client = new Client(['handler' => $handlerStack]);
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
            file_put_contents('php://stderr', 'Error: '.$e->getMessage().PHP_EOL);

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
            file_put_contents('php://stderr', 'Error: '.$e->getMessage().PHP_EOL);
        }
    }
}
