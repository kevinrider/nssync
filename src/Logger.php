<?php

namespace Nssync;

class Logger
{
    private const string COLOR_INFO = "\033[0;32m";

    private const string COLOR_WARNING = "\033[1;33m";

    private const string COLOR_ERROR = "\033[0;31m";

    private const string COLOR_RESET = "\033[0m";

    /** @var resource */
    private $stream;

    public function __construct($stream = null)
    {
        if ($stream === null) {
            $stream = fopen('php://stderr', 'w');
        }
        $this->stream = $stream;
    }

    public function info(string $message): void
    {
        $this->log('INFO', $message, self::COLOR_INFO);
    }

    public function warning(string $message): void
    {
        $this->log('WARNING', $message, self::COLOR_WARNING);
    }

    public function error(string $message): void
    {
        $this->log('ERROR', $message, self::COLOR_ERROR);
    }

    private function log(string $level, string $message, string $color): void
    {
        fwrite($this->stream, '['.$color.$level.self::COLOR_RESET."] $message".PHP_EOL);
    }
}
