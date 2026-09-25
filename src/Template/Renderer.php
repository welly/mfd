<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Template;

/**
 * Copies a template tree into a project, filling in its placeholders.
 *
 * {{PROJECT}}, {{THEME}}, {{THEME_LABEL}} and {{SITE_NAME}} are replaced in UTF-8
 * files in one pass, so values are never re-expanded; __THEME__ is replaced in
 * paths. Task's own {{.NAME}} templates do not match and pass through. Files
 * that already exist are kept, never overwritten.
 */
final class Renderer
{
    public const KEYS = ['PROJECT', 'THEME', 'THEME_LABEL', 'SITE_NAME'];

    /**
     * @param array<string, string> $values
     * @return list<string> "wrote <path>" or "kept existing <path>" for each file
     */
    public function render(string $source, string $target, array $values): array
    {
        foreach (self::KEYS as $key) {
            if (!isset($values[$key])) {
                throw new \InvalidArgumentException('missing template value ' . $key);
            }
        }
        $pattern = '/\{\{(' . implode('|', self::KEYS) . ')\}\}/';

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        $lines = [];
        foreach ($files as $path) {
            $relative = str_replace('__THEME__', $values['THEME'], substr($path, strlen($source) + 1));
            $destination = $target . '/' . $relative;
            if (file_exists($destination)) {
                $lines[] = 'kept existing ' . $relative;
                continue;
            }
            if (!is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0777, true);
            }
            $data = (string) file_get_contents($path);
            if (preg_match('//u', $data) === 1) {
                $data = (string) preg_replace_callback(
                    $pattern,
                    static fn (array $m): string => $values[$m[1]],
                    $data
                );
            }
            file_put_contents($destination, $data);
            chmod($destination, fileperms($path) & 0777);
            $lines[] = 'wrote ' . $relative;
        }

        return $lines;
    }
}
