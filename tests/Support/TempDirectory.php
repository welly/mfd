<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use Symfony\Component\Filesystem\Filesystem;

/** Temporary directories that are removed after each test. */
trait TempDirectory
{
    /** @var list<string> */
    private array $tempDirectories = [];

    protected function newTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/mfd-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $real = (string) realpath($dir);
        $this->tempDirectories[] = $real;

        return $real;
    }

    #[After]
    protected function removeTempDirs(): void
    {
        (new Filesystem())->remove($this->tempDirectories);
        $this->tempDirectories = [];
    }
}
