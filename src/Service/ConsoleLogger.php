<?php

declare(strict_types=1);

namespace YtdPhp\Service;

use Symfony\Component\Console\Output\OutputInterface;

use function fwrite;

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

        fwrite(STDOUT, $chunk);
    }

    private function write(string $message, bool $stderr = false): void
    {
        if ($this->output instanceof OutputInterface) {
            $this->output->writeln($message, $stderr ? OutputInterface::OUTPUT_NORMAL : OutputInterface::OUTPUT_NORMAL);

            return;
        }

        fwrite($stderr ? STDERR : STDOUT, strip_tags($message) . PHP_EOL);
    }
}
