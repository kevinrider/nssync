<?php

use Nssync\Logger;

test('logs info messages', function () {
    ob_start();
    $logger = new Logger();
    $logger->info('This is an info message.');
    $output = ob_get_clean();

    expect($output)->toContain("\033[0;32m[INFO] This is an info message.\033[0m");
});

test('logs warning messages', function () {
    ob_start();
    $logger = new Logger();
    $logger->warning('This is a warning message.');
    $output = ob_get_clean();

    expect($output)->toContain("\033[1;33m[WARNING] This is a warning message.\033[0m");
});

test('logs error messages', function () {
    ob_start();
    $logger = new Logger();
    $logger->error('This is an error message.');
    $output = ob_get_clean();

    expect($output)->toContain("\033[0;31m[ERROR] This is an error message.\033[0m");
});
