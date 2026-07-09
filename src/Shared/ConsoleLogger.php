<?php

declare(strict_types=1);

namespace YtdPhp\Shared;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleLogger
{
    public function __construct(
        private ?OutputInterface $output = null,
    ) {}

    public function setOutput(?OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function info(string $message): void
    {
        $this->write($message);
    }

    public function warning(string $message): void
    {
        $this->write('<comment>' . $message . '</comment>', true);
    }

    public function error(string $message): void
    {
        $this->write('<error>' . $message . '</error>', true);
    }

    public function line(string $message = ''): void
    {
        $this->write($message);
    }

    public function raw(string $chunk): void
    {
        if ($this->output instanceof OutputInterface) {
            $this->output->write($chunk);

            return;
        }

        \fwrite(STDOUT, $chunk);
    }

    private function write(string $message, bool $stderr = false): void
    {
        if ($this->output instanceof OutputInterface) {
            $target = $stderr && $this->output instanceof ConsoleOutputInterface
                ? $this->output->getErrorOutput()
                : $this->output;
            $target->writeln($message);

            return;
        }

        \fwrite($stderr ? STDERR : STDOUT, strip_tags($message) . PHP_EOL);
    }
}
