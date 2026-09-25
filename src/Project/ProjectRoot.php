<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Project;

use Manifesto\Mfd\Config\Naming;
use Manifesto\Mfd\Exception\UserError;

/** A project made by `mfd new`: the directory holding .mfd.json, and what that file records. */
final readonly class ProjectRoot
{
    public const MARKER = '.mfd.json';

    /** @param array<string, mixed> $marker */
    private function __construct(public string $dir, private array $marker)
    {
    }

    /** Walk up from $from to the nearest directory containing .mfd.json. */
    public static function find(string $from): self
    {
        $dir = $from;
        while (true) {
            $file = $dir . '/' . self::MARKER;
            if (is_file($file)) {
                try {
                    $marker = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new UserError($file . ' is not valid JSON: ' . $e->getMessage(), 0, $e);
                }
                if (!is_array($marker)) {
                    throw new UserError($file . ' is not a JSON object');
                }

                return new self($dir, $marker);
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                throw new UserError(sprintf(
                    'not inside an mfd project: no %s found in %s or above it',
                    self::MARKER,
                    $from,
                ));
            }
            $dir = $parent;
        }
    }

    public function theme(): string
    {
        $theme = $this->marker['theme'] ?? null;
        if (!is_string($theme) || !Naming::isValidThemeName($theme)) {
            throw new UserError(sprintf('%s/%s records no valid theme; pass --theme', $this->dir, self::MARKER));
        }

        return $theme;
    }

    public function path(string $relative): string
    {
        return $this->dir . '/' . $relative;
    }
}
