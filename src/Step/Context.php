<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

use Manifesto\Mfd\Config\ProjectConfig;
use Manifesto\Mfd\Harness\HarnessPaths;
use Manifesto\Mfd\Shell\Shell;
use Symfony\Component\Console\Output\OutputInterface;

/** Everything a step needs. */
final readonly class Context
{
    public function __construct(
        public ProjectConfig $config,
        public Shell $shell,
        public OutputInterface $output,
        public string $projectDir,
        public string $packageRoot,
        public HarnessPaths $harness,
        public Notices $notices = new Notices(),
    ) {
    }

    public function path(string $relative): string
    {
        return $this->projectDir . '/' . $relative;
    }

    public function log(string $line): void
    {
        $this->output->writeln('    ' . $line);
    }

    /** Print a warning now and repeat it in the summary. */
    public function warn(string $message): void
    {
        $this->output->writeln('<comment>warning:</comment> ' . $message);
        $this->notices->add($message);
    }
}
