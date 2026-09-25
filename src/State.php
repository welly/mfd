<?php

declare(strict_types=1);

namespace Manifesto\Mfd;

use Manifesto\Mfd\Config\ProjectConfig;

/** Resume state for `mfd new`, in .ddev/mfd/ under the project. The directory ignores itself. */
final class State
{
    public const DIR = '.ddev/mfd';

    public function __construct(private readonly string $projectDir)
    {
    }

    /** The inputs an earlier run recorded, or null when there was no earlier run. */
    public function recordedInputs(): ?string
    {
        $file = $this->file('inputs');

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    public function init(ProjectConfig $config): void
    {
        if (!is_dir($this->file('done'))) {
            mkdir($this->file('done'), 0777, true);
        }
        file_put_contents($this->file('.gitignore'), "*\n");
        if ($this->recordedInputs() === null) {
            file_put_contents($this->file('inputs'), $config->inputsRecord());
        }
    }

    public function isDone(string $step): bool
    {
        return is_file($this->file('done/' . $step));
    }

    public function markDone(string $step): void
    {
        touch($this->file('done/' . $step));
    }

    private function file(string $relative): string
    {
        return $this->projectDir . '/' . self::DIR . '/' . $relative;
    }
}
