<?php

namespace Nssync;

use GuzzleHttp\Client;
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

                if ($exception instanceof RequestException || ($response && $response->getStatusCode() >= 500)) {
                    file_put_contents('php://stderr', "Request failed, retrying (" . ($retries + 1) . "/" . 3 . ")..." . PHP_EOL);
                    return true;
                }

                return false;
            },
            function ($retries) {
                return 1 * 1000;
            }
        ));
        $this->client = new Client(['handler' => $handlerStack]);
    }

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
            file_put_contents('php://stderr', 'Error: ' . $e->getMessage() . PHP_EOL);
            return null;
        }
    }

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
            file_put_contents('php://stderr', 'Error: ' . $e->getMessage() . PHP_EOL);
        }
    }
}
