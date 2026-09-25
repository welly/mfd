<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Npm;

/**
 * Adds mfd's scripts and devDependencies to a project's package.json without
 * changing anything already there. Works on JSON objects (not PHP arrays) so
 * empty objects stay {} and key order is kept.
 */
final class PackageMerger
{
    private const SECTIONS = ['scripts', 'devDependencies', 'overrides'];

    public function merge(string $packageFile, string $additionsFile): void
    {
        $package = $this->read($packageFile, 'package.json is written by the harness step');
        $additions = $this->read($additionsFile, 'the additions file ships inside mfd; check the mfd installation');

        foreach (self::SECTIONS as $section) {
            if (!isset($additions->{$section}) || !$additions->{$section} instanceof \stdClass) {
                continue;
            }
            if (!isset($package->{$section}) || !$package->{$section} instanceof \stdClass) {
                $package->{$section} = new \stdClass();
            }
            foreach (get_object_vars($additions->{$section}) as $name => $value) {
                if (!property_exists($package->{$section}, $name)) {
                    $package->{$section}->{$name} = $value;
                }
            }
        }

        $json = json_encode(
            $package,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        // npm writes 2-space indentation; PHP's pretty print uses 4.
        $json = (string) preg_replace_callback(
            '/^ +/m',
            static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[0]), 2)),
            $json,
        );
        file_put_contents($packageFile, $json . "\n");
    }

    private function read(string $file, string $notFoundHint): \stdClass
    {
        if (!is_file($file)) {
            throw new \RuntimeException($file . ' not found (' . $notFoundHint . ')');
        }
        try {
            $data = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException($file . ' is not valid JSON', 0, $e);
        }
        if (!$data instanceof \stdClass) {
            throw new \RuntimeException($file . ' is not a JSON object');
        }

        return $data;
    }
}
