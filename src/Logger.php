<?php

namespace Nssync;

class Logger
{
    /**
     * @param string $message
     * @return void
     */
    public function info(string $message): void
    {
        $this->log('INFO', $message);
    }

    /**
     * @param string $message
     * @return void
     */
    public function warning(string $message): void
    {
        $this->log('WARNING', $message);
    }

    /**
     * @param string $message
     * @return void
     */
    public function error(string $message): void
    {
        $this->log('ERROR', $message);
    }

    /**
     * @param string $level
     * @param string $message
     * @return void
     */
    private function log(string $level, string $message): void
    {
        file_put_contents('php://stderr', "[$level] $message".PHP_EOL);
    }
}
