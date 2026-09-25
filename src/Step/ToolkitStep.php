<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

use Manifesto\Mfd\Shell\CommandFailed;

/**
 * Adds mfd itself to the project's require-dev, so every teammate gets the same
 * locked version through composer install and runs it inside DDEV. If Composer
 * cannot install it (offline, or the package not yet on that branch), this warns
 * with the exact commands to run later instead of failing a complete project.
 */
final class ToolkitStep implements Step
{
    public const PACKAGE = 'manifesto/mfd';
    public const DEFAULT_REPOSITORY = 'https://github.com/welly/mfd';
    public const DEFAULT_VERSION = 'dev-main';

    public function name(): string
    {
        return 'toolkit';
    }

    public function plan(Context $context): array
    {
        [$repository, $version] = self::source();

        return [
            'ddev composer config repositories.mfd vcs ' . $repository,
            sprintf('ddev composer require --dev %s:%s', self::PACKAGE, $version),
        ];
    }

    public function run(Context $context): void
    {
        [$repository, $version] = self::source();
        $dir = $context->projectDir;
        try {
            $context->shell->run(
                ['ddev', 'composer', 'config', '--no-interaction', 'repositories.mfd', 'vcs', $repository],
                $dir,
            );
            $context->shell->run(
                ['ddev', 'composer', 'require', '--dev', '--no-interaction', self::PACKAGE . ':' . $version],
                $dir,
            );
        } catch (CommandFailed $e) {
            $context->warn(sprintf(
                "could not add %s to the project (%s). Once it is available, run in the project:\n"
                . "  ddev composer config repositories.mfd vcs %s\n"
                . '  ddev composer require --dev %s:%s',
                self::PACKAGE,
                self::reason($e),
                $repository,
                self::PACKAGE,
                $version,
            ));
        }
    }

    /**
     * `CommandFailed::getMessage()` is `` `cmd` exited N: `` followed by up to the last 20 lines
     * of stderr. Composer prints its error in a Symfony Console box ("In X.php line N:" then
     * padded, wrapped lines) followed by the command's usage synopsis, so the box is the reason.
     * Without a box, fall back to the last 1-3 non-empty lines, skipping usage synopses.
     */
    private static function reason(CommandFailed $e): string
    {
        $lines = explode("\n", $e->getMessage());
        $header = array_shift($lines);

        $box = self::errorBox($lines);
        if ($box !== '') {
            return $box;
        }
        $stderr = array_values(array_filter(
            $lines,
            static fn (string $line): bool => trim($line) !== '' && !str_contains($line, '[--'),
        ));

        return $stderr === [] ? rtrim($header, ':') : implode('; ', array_map('trim', array_slice($stderr, -3)));
    }

    /**
     * The text of the first Symfony Console error box, unwrapped. A box line whose content fills
     * the box (it ends with exactly the two padding spaces) was cut mid-word, so the next line
     * joins it without a space: "https://github.com" + "/welly/…".
     *
     * @param list<string> $lines
     */
    private static function errorBox(array $lines): string
    {
        $start = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^In \S+ line \d+:$/', trim($line)) === 1) {
                $start = $i + 1;
                break;
            }
        }
        if ($start === null) {
            return '';
        }

        $text = '';
        $glue = '';
        foreach (array_slice($lines, $start) as $line) {
            $content = trim($line);
            if ($content === '') {
                if ($text !== '') {
                    break;
                }
                continue;
            }
            $text .= ($text === '' ? '' : $glue) . $content;
            $glue = preg_match('/\S  $/', $line) === 1 ? '' : ' ';
        }

        return $text;
    }

    /** @return array{string, string} repository URL and version constraint; MFD_REPOSITORY and MFD_VERSION override */
    private static function source(): array
    {
        $repository = getenv('MFD_REPOSITORY');
        $version = getenv('MFD_VERSION');

        return [
            is_string($repository) && $repository !== '' ? $repository : self::DEFAULT_REPOSITORY,
            is_string($version) && $version !== '' ? $version : self::DEFAULT_VERSION,
        ];
    }
}
