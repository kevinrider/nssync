<?php

use Nssync\Logger;

test('logs messages with correct level and color', function (string $method, string $message, string $expected) {
    $stream = fopen('php://memory', 'w');
    $logger = new Logger($stream);

    $logger->{$method}($message);

    rewind($stream);
    $output = stream_get_contents($stream);
    fclose($stream);

    expect($output)->toContain($expected);
})->with([
    'info' => ['info', 'This is an info message.', "[\033[0;32mINFO\033[0m] This is an info message."],
    'warning' => ['warning', 'This is a warning message.', "[\033[1;33mWARNING\033[0m] This is a warning message."],
    'error' => ['error', 'This is an error message.', "[\033[0;31mERROR\033[0m] This is an error message."],
]);
