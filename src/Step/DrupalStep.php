<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Step;

use Manifesto\Mfd\Shell\CommandFailed;

/**
 * DDEV project, Drupal codebase, development tooling and site install. Every
 * command is guarded or idempotent, so rerunning after a failure resumes.
 */
final class DrupalStep implements Step
{
    /**
     * Composer plugins drupal/coder and phpstan use; allowed first so
     * --no-interaction never stops on the prompt.
     */
    private const ALLOWED_PLUGINS = ['dealerdirect/phpcodesniffer-composer-installer', 'phpstan/extension-installer'];

    /** PHPUnit comes from drupal/core-dev, which matches core. Never pin it directly. */
    private const DEV_PACKAGES = [
        'drupal/core-dev',
        'weitzman/drupal-test-traits',
        'mglaman/phpstan-drupal',
        'phpstan/extension-installer',
        'drupal/coder',
    ];

    /**
     * Temporary: twig/twig 3.30.0 (released 2026-09-25) breaks Drupal 11.4.7 rendering with
     * `TypeError: Twig\Runtime\EscaperRuntime::escape(): Argument #4 ($autoescape) must be of
     * type bool, null given`. drupal/core-recommended 11.4.7 does not pin twig/twig and
     * drupal/core only requires `^3.28.0`, so every fresh project resolves the broken 3.30.0.
     * A Composer `conflict` entry (added below, since `composer config` cannot set `conflict`)
     * makes Composer resolve a fixed 3.30.x instead. Remove once Drupal or Twig ships a fix.
     */
    private const BROKEN_PACKAGES = ['twig/twig' => '3.30.0'];

    private const GITIGNORE_MARKER = '# drupal-starter';

    private const GITIGNORE = <<<'TXT'
        # drupal-starter: dependencies, generated files and local secrets.
        # web/sites/default/settings.php is committed; DDEV keeps credentials and the
        # hash salt in settings.ddev.php.
        /vendor/
        /web/core/
        /web/modules/contrib/
        /web/themes/contrib/
        /web/profiles/contrib/
        /web/libraries/
        /web/sites/*/files/
        /web/sites/*/settings.ddev.php
        /web/sites/*/settings.local.php
        /web/sites/simpletest/
        /node_modules/
        /storybook-static/
        /.phpunit.cache/
        *.sql
        *.sql.*
        *.mysql
        /.env
        /.env.*
        !/.env.example
        /.ddev/.env
        /private/

        TXT;

    private const SETTINGS_MARKER = 'drupal-starter: config sync';

    private const SETTINGS_BLOCK = <<<'PHP'

        // drupal-starter: config sync directory, committed with the project.
        $settings['config_sync_directory'] = '../config/sync';

        PHP;

    /** Where the pre-install snapshot of settings.php lives while site:install runs. */
    private const SNAPSHOT_RELATIVE = '.ddev/mfd/settings.php.snapshot';

    public function name(): string
    {
        return 'drupal';
    }

    public function plan(Context $context): array
    {
        $c = $context->config;

        return [
            sprintf('ddev config --project-name=%s --project-type=drupal11 --docroot=web; ddev start', $c->project),
            'ddev composer create-project drupal/recommended-project:^11',
            'block twig/twig 3.30.0 in composer.json (temporary, see DrupalStep::BROKEN_PACKAGES) '
                . 'and ddev composer update --no-interaction twig/twig',
            'ddev composer require drush/drush',
            'ddev composer require --dev --with-all-dependencies ' . implode(' ', self::DEV_PACKAGES),
            'write .gitignore (Drupal rules) and settings.php (config sync directory ../config/sync)',
            'create config/sync (mkdir -p)',
            'snapshot settings.php, then restore and verify it holds no secrets whatever site:install does',
            sprintf('ddev drush site:install standard --site-name="%s"', $c->siteName),
        ];
    }

    public function run(Context $context): void
    {
        $shell = $context->shell;
        $dir = $context->projectDir;
        $config = $context->config;

        // Resume: a snapshot left behind by an interrupted earlier run must be restored before
        // anything else runs, including the composer calls below.
        $this->restoreSettingsFromSnapshot($context);

        if (!is_file($context->path('.ddev/config.yaml'))) {
            $shell->run(
                ['ddev', 'config', '--project-name=' . $config->project, '--project-type=drupal11', '--docroot=web'],
                $dir,
            );
        }
        $shell->run(['ddev', 'start'], $dir);
        if (!is_file($context->path('composer.json'))) {
            $shell->run(
                ['ddev', 'composer', 'create-project', 'drupal/recommended-project:^11', '--no-interaction'],
                $dir,
            );
        }
        $this->blockBrokenPackages($context);
        foreach (self::ALLOWED_PLUGINS as $plugin) {
            $shell->run(['ddev', 'composer', 'config', '--no-interaction', 'allow-plugins.' . $plugin, 'true'], $dir);
        }
        $shell->run(['ddev', 'composer', 'require', '--no-interaction', 'drush/drush'], $dir);
        // --with-all-dependencies: drupal/core-recommended already locks justinrainbow/json-schema,
        // symfony/error-handler and sebastian/diff to versions newer than the older drupal/core-dev
        // releases allow; without it composer treats this as a partial update and refuses to move them,
        // even down to a version every package (including the newest drupal/core-dev) is happy with.
        $shell->run(
            [
                'ddev', 'composer', 'require', '--dev', '--no-interaction', '--with-all-dependencies',
                ...self::DEV_PACKAGES,
            ],
            $dir,
        );
        // Restart so DDEV's settings management writes settings.php for the new codebase.
        $shell->run(['ddev', 'restart'], $dir);
        $this->writeGitignore($context);
        $this->configureSettings($context);
        if (!is_dir($context->path('config/sync'))) {
            mkdir($context->path('config/sync'), 0777, true);
        }
        if (!$this->isInstalled($context)) {
            // Drupal's installer nondeterministically decides, on its own, whether to run
            // install_settings_form and call drupal_rewrite_settings(), writing a real hash_salt and a
            // real $databases block straight into settings.php. Nothing passed to `drush site:install`
            // reliably prevents that (observed even with --db-url, and even once the database was
            // already accepting connections), so the file is snapshotted first and unconditionally
            // restored afterwards, whether the install succeeds or fails.
            $originalSettings = $this->snapshotSettings($context);
            try {
                $shell->run(
                    ['ddev', 'drush', 'site:install', 'standard', '--site-name=' . $config->siteName, '-y'],
                    $dir,
                );
            } catch (\Throwable $installFailure) {
                // Deliberately not a `finally`: if the restore below throws too, PHP's `finally` would
                // silently discard $installFailure and only the restore's exception would surface.
                // restoreAfterFailedInstall() below embeds this failure's message directly into its
                // own wrapper exception's message text instead, so it is never lost even then.
                $this->restoreAfterFailedInstall($context, $installFailure, $originalSettings);

                throw $installFailure;
            }
            $this->restoreSettingsFromSnapshot($context, $originalSettings);
        }
        $this->verifyNoSecretsInSettings($context);
        $this->deleteSnapshot($context);
    }

    /**
     * Restores settings.php after a failed site:install without ever losing the install's own
     * exception, even if the restore itself also fails. When both fail, the wrapper's own
     * message carries both texts (StepRunner/NewCommand only ever print a caught exception's
     * top-level getMessage(), never walk the chain), with the restore failure as $previous.
     */
    private function restoreAfterFailedInstall(Context $context, \Throwable $installFailure, string $expected): void
    {
        try {
            $this->restoreSettingsFromSnapshot($context, $expected);
        } catch (\Throwable $restoreFailure) {
            throw new \RuntimeException(
                sprintf(
                    'settings.php restore failed (%s) after site:install failed: %s',
                    $restoreFailure->getMessage(),
                    $installFailure->getMessage(),
                ),
                0,
                $restoreFailure,
            );
        }
    }

    /**
     * Adds a Composer `conflict` entry for every package in self::BROKEN_PACKAGES, merging with
     * whatever `conflict` entries composer.json already has, then runs `composer update` on
     * those packages so the lock file honours the new constraint before drush/drupal-core-dev
     * are required. `composer config` cannot set `conflict`, so composer.json is read, decoded,
     * amended and written back by hand instead.
     *
     * Always runs, on every call including a resume: merging the same key/value pair into
     * `conflict` again is a no-op, and re-running `composer update` on an already-resolved,
     * already-conflict-free lock file is cheap. That is simpler, and no less idempotent, than
     * inspecting composer.lock first to decide whether to skip it.
     */
    private function blockBrokenPackages(Context $context): void
    {
        $file = $context->path('composer.json');

        try {
            // Decode to stdClass, as PackageMerger does: decoding to an associative array turns
            // an empty JSON object (e.g. "require-dev": {}) into an empty PHP array, which
            // json_encode() then writes back out as `[]`, silently corrupting the file.
            $composer = json_decode($this->readFile($file), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Could not parse ' . $file . ': ' . $e->getMessage(), 0, $e);
        }
        if (!$composer instanceof \stdClass) {
            throw new \RuntimeException($file . ' is not a JSON object');
        }

        $conflict = $composer->conflict ?? new \stdClass();
        if (!$conflict instanceof \stdClass) {
            $conflict = new \stdClass();
        }
        foreach (self::BROKEN_PACKAGES as $package => $constraint) {
            $conflict->{$package} = $constraint;
        }
        $composer->conflict = $conflict;

        try {
            $encoded = json_encode(
                $composer,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $e) {
            throw new \RuntimeException('Could not encode ' . $file . ': ' . $e->getMessage(), 0, $e);
        }
        $this->writeFile($file, $encoded . "\n");

        $context->shell->run(
            ['ddev', 'composer', 'update', '--no-interaction', ...array_keys(self::BROKEN_PACKAGES)],
            $context->projectDir,
        );
    }

    private function writeGitignore(Context $context): void
    {
        $file = $context->path('.gitignore');
        $current = is_file($file) ? (string) file_get_contents($file) : '';
        if (str_contains($current, self::GITIGNORE_MARKER)) {
            return;
        }
        $separator = ($current === '' || str_ends_with($current, "\n")) ? '' : "\n";
        file_put_contents($file, $current . $separator . self::GITIGNORE);
    }

    private function configureSettings(Context $context): void
    {
        $file = $context->path('web/sites/default/settings.php');
        if (!is_file($file)) {
            throw new \RuntimeException(
                $file . " was not created. Check that DDEV settings management is on "
                    . "(disable_settings_management: false in .ddev/config.yaml), run 'ddev restart', then rerun.",
            );
        }
        $settings = (string) file_get_contents($file);
        if (!str_contains($settings, 'settings.ddev.php')) {
            throw new \RuntimeException(
                $file . " does not include settings.ddev.php, so DDEV's database settings are not loaded. "
                    . 'Check disable_settings_management in .ddev/config.yaml.',
            );
        }
        if (!str_contains($settings, self::SETTINGS_MARKER)) {
            file_put_contents($file, self::SETTINGS_BLOCK, FILE_APPEND);
        }
    }

    private function settingsFile(Context $context): string
    {
        return $context->path('web/sites/default/settings.php');
    }

    private function snapshotFile(Context $context): string
    {
        return $context->path(self::SNAPSHOT_RELATIVE);
    }

    /** Saves the exact bytes of settings.php before site:install can touch it, and returns them. */
    private function snapshotSettings(Context $context): string
    {
        $snapshotFile = $this->snapshotFile($context);
        if (!is_dir(dirname($snapshotFile))) {
            mkdir(dirname($snapshotFile), 0777, true);
        }
        $settings = $this->readFile($this->settingsFile($context));
        $this->writeFileAtomically($snapshotFile, $settings);

        return $settings;
    }

    /**
     * Restores settings.php from the snapshot, if one exists. A no-op otherwise (nothing to
     * resume, or the step already cleaned up after a successful install).
     *
     * The restore's real guarantee is enforced here, not left to the caller: once written,
     * settings.php must be byte-identical to what it held before site:install ran, checked with a
     * constant-time comparison of SHA-256 digests. $expected is the bytes snapshotSettings()
     * already captured in memory earlier in the same run, when available, and is what actually
     * gets written here in preference to the on-disk snapshot file: that way, if something
     * tampers with the snapshot file itself in between the snapshot and the restore (a hook in a
     * test, a second process, disk corruption), settings.php still ends up holding the bytes
     * known to be good, not the tampered ones. When that tampering is detected, the on-disk
     * snapshot is also rewritten from $expected before this method fails closed, so a rerun of
     * this same process (which has no in-memory value of its own to fall back on) restores the
     * correct bytes too, rather than repeating the tampered write and then silently agreeing with
     * itself that it matches. Resuming a snapshot left behind by an earlier, now-dead process has
     * no in-memory value to compare against at all, so it trusts the on-disk snapshot content,
     * which is the strongest guarantee available across a process boundary.
     *
     * settings.php is written and verified from $goodBytes before the on-disk snapshot repair is
     * even attempted, deliberately: the repair write (into a possibly different, independently
     * failing directory) is not needed to get settings.php itself right, and if it throws,
     * settings.php must not be left holding whatever it held coming into this method (which, on
     * the tampered-snapshot path, can be leaked secrets from a site:install that already ran).
     */
    private function restoreSettingsFromSnapshot(Context $context, ?string $expected = null): void
    {
        $snapshotFile = $this->snapshotFile($context);
        if (!is_file($snapshotFile)) {
            return;
        }
        $settingsFile = $this->settingsFile($context);
        $snapshot = $this->readFile($snapshotFile);
        $goodBytes = $expected ?? $snapshot;
        $snapshotTampered = $expected !== null
            && !hash_equals(hash('sha256', $expected), hash('sha256', $snapshot));

        if (is_file($settingsFile)) {
            // Drupal may leave settings.php 0444 (and sites/default 0555) after writing to it.
            $this->chmodOrThrow($settingsFile, 0644);
        }
        $this->writeFile($settingsFile, $goodBytes);
        $this->chmodOrThrow($settingsFile, 0644);

        $restored = $this->readFile($settingsFile);
        $settingsMismatch = !hash_equals(hash('sha256', $goodBytes), hash('sha256', $restored));

        if ($snapshotTampered) {
            $this->writeFileAtomically($snapshotFile, $expected);
        }

        if ($snapshotTampered || $settingsMismatch) {
            throw new \RuntimeException(
                $settingsFile . ' does not match its pre-install snapshot after restore; refusing to continue.',
            );
        }
    }

    private function deleteSnapshot(Context $context): void
    {
        $snapshotFile = $this->snapshotFile($context);
        if (is_file($snapshotFile)) {
            $this->unlinkOrThrow($snapshotFile);
        }
    }

    /**
     * The last line of defence: confirms settings.php holds no secrets after the restore above,
     * regardless of which path Drupal's installer took.
     */
    private function verifyNoSecretsInSettings(Context $context): void
    {
        $file = $this->settingsFile($context);
        $settings = $this->readFile($file);

        if (self::containsLeakedSecret($settings)) {
            throw new \RuntimeException(
                $file . ' still contains a real secret (a hash_salt value or a $databases assignment) '
                    . 'after restore; refusing to continue.',
            );
        }
    }

    /**
     * True unless $php's every use of `$databases` and `hash_salt` matches one of a short list
     * of known-safe shapes, read as actual PHP tokens rather than matched against a text pattern.
     * This allow-lists the good shapes instead of denying known-bad ones:
     *
     * - `$databases`: any use of the variable is a leak unless the statement is exactly
     *   `$databases = [];` or `$databases = array();`.
     * - `hash_salt`: any assignment to a `hash_salt` key (`$settings['hash_salt'] = ...`, or
     *   `'hash_salt' => ...` inside an array literal) is a leak unless it is exactly
     *   `$settings['hash_salt'] = '';` or `$settings['hash_salt'] = "";`. A heredoc, a nowdoc, an
     *   interpolated string, a concatenation or a function call as the value is a leak, since none
     *   of those tokenizes as the single, empty `T_CONSTANT_ENCAPSED_STRING` the allow-list wants.
     * - Inline HTML: a `T_INLINE_HTML` token (text after `?>`, or the whole file when it has no
     *   `<?php` tag) mentioning `hash_salt` or `$databases` is a leak.
     *
     * Denying by default means some legitimate-looking code is flagged too: `isset($databases[...])`
     * is a read, not the safe assignment shape, so it is flagged; a hash_salt sourced from
     * `getenv()` is a function call, so it is flagged. Both are accepted false positives, not
     * bugs: this scaffold's own settings.php only ever needs the literal empty-string shape (DDEV
     * supplies the real values separately, via the gitignored settings.ddev.php), and an allow-list
     * of "safe-looking" function names would just be a different, more guessable version of the
     * same problem this rewrite exists to remove.
     *
     * A regex anchored to the start of a line (the pre-tokenizer approach) had two failure modes a
     * tokenizer does not: Drupal core's own default.settings.php ships this exact text as indented
     * example prose inside a documentation comment (e.g. " * $databases['default']['default'] = ["),
     * which an unanchored regex flags on every project; and a real leak from
     * drupal_rewrite_settings() is not guaranteed to land at column 0 on its own line (it can be
     * appended straight after other code with no newline), which an anchored regex then misses.
     * Walking the token stream and skipping comments and whitespace has neither problem. Fails
     * closed: PHP that cannot even be tokenized is treated as a leak, not as a clean file.
     *
     * public so the check can be tested directly, without going through a full step run.
     */
    public static function containsLeakedSecret(string $php): bool
    {
        try {
            $tokens = token_get_all($php, TOKEN_PARSE);
        } catch (\CompileError) {
            // \ParseError extends \CompileError; some invalid code (e.g. a duplicate access-type
            // modifier) makes token_get_all(..., TOKEN_PARSE) throw \CompileError directly rather
            // than its \ParseError subclass, so the broader type is caught to fail closed either way.
            return true;
        }

        /** @var list<array{0: int|null, 1: string}> $significant */
        $significant = [];
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT || $token[0] === T_WHITESPACE) {
                    continue;
                }
                $significant[] = [$token[0], $token[1]];
            } else {
                $significant[] = [null, $token];
            }
        }

        $count = count($significant);
        for ($i = 0; $i < $count; $i++) {
            [$id, $text] = $significant[$i];

            if ($id === T_INLINE_HTML && (str_contains($text, 'hash_salt') || str_contains($text, '$databases'))) {
                return true;
            }
            if (
                $id === T_VARIABLE
                && $text === '$databases'
                && !self::isSafeEmptyDatabasesStatement($significant, $i)
            ) {
                return true;
            }
            if (
                $id === T_CONSTANT_ENCAPSED_STRING
                && self::stripQuotes($text) === 'hash_salt'
                && !self::isSafeEmptyHashSaltAssignment($significant, $i)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * True if, starting at the `$databases` token at $i, the significant tokens spell out exactly
     * `$databases = [];` or `$databases = array();` (the only two allow-listed shapes). Anything
     * else, including `$databases['x'] = ...`, `$databases = [...]` with content, or `$databases`
     * used as a plain read, is left for the caller to treat as a leak.
     *
     * @param list<array{0: int|null, 1: string}> $significant
     */
    private static function isSafeEmptyDatabasesStatement(array $significant, int $i): bool
    {
        $next = static fn (int $offset): ?string => $significant[$i + $offset][1] ?? null;

        if ($next(1) === '=' && $next(2) === '[' && $next(3) === ']' && $next(4) === ';') {
            return true;
        }

        return $next(1) === '=' && strtolower((string) $next(2)) === 'array'
            && $next(3) === '(' && $next(4) === ')' && $next(5) === ';';
    }

    /**
     * True if the `hash_salt` key token at $i is part of exactly `$settings['hash_salt'] = '';`
     * or `$settings['hash_salt'] = "";` (the only allow-listed shape). Anything else, including a
     * non-empty value, a heredoc/nowdoc/interpolated/concatenated value, a function call, or
     * `'hash_salt' => ...` inside an array literal, is left for the caller to treat as a leak.
     *
     * @param list<array{0: int|null, 1: string}> $significant
     */
    private static function isSafeEmptyHashSaltAssignment(array $significant, int $i): bool
    {
        $before = static fn (int $offset): ?string => $significant[$i - $offset][1] ?? null;
        $beforeId = static fn (int $offset): ?int => $significant[$i - $offset][0] ?? null;
        $after = static fn (int $offset): ?string => $significant[$i + $offset][1] ?? null;
        $afterId = static fn (int $offset): ?int => $significant[$i + $offset][0] ?? null;

        return $beforeId(2) === T_VARIABLE && $before(2) === '$settings'
            && $before(1) === '['
            && $after(1) === ']'
            && $after(2) === '='
            && $afterId(3) === T_CONSTANT_ENCAPSED_STRING
            && self::stripQuotes((string) $after(3)) === ''
            && $after(4) === ';';
    }

    /** Strips quotes by taking the first and last character off the token, never with trim(). */
    private static function stripQuotes(string $token): string
    {
        return substr($token, 1, -1);
    }

    /** file_get_contents() that throws instead of silently treating a failed read as an empty file. */
    private function readFile(string $file): string
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            throw new \RuntimeException('Could not read ' . $file . '.');
        }

        return $contents;
    }

    /**
     * file_put_contents() that throws unless every byte was written, so a failed or partial write
     * (a permission error, a vanished directory, a full disk) is never silently ignored.
     */
    private function writeFile(string $file, string $content): void
    {
        $written = @file_put_contents($file, $content);
        if ($written === false || $written < strlen($content)) {
            throw new \RuntimeException('Could not write ' . $file . '.');
        }
    }

    /**
     * Writes atomically: the content lands in a temp file in the same directory first, then
     * rename() swaps it into place. A crash or a second process reading mid-write never sees a
     * partial file this way, unlike writeFile()'s plain file_put_contents().
     */
    private function writeFileAtomically(string $file, string $content): void
    {
        $temp = $file . '.tmp-' . bin2hex(random_bytes(8));
        try {
            $this->writeFile($temp, $content);
        } catch (\Throwable $writeFailure) {
            // writeFile() can throw after already creating $temp with a partial write (a full
            // disk, an interrupted write); never leave that partial temp file behind.
            @unlink($temp);
            throw $writeFailure;
        }
        if (!@rename($temp, $file)) {
            @unlink($temp);
            throw new \RuntimeException('Could not write ' . $file . '.');
        }
    }

    /** chmod() that throws instead of silently leaving the wrong mode in place. */
    private function chmodOrThrow(string $file, int $mode): void
    {
        if (!@chmod($file, $mode)) {
            throw new \RuntimeException('Could not change permissions on ' . $file . '.');
        }
    }

    /** unlink() that throws instead of silently leaving the file in place. */
    private function unlinkOrThrow(string $file): void
    {
        if (!@unlink($file)) {
            throw new \RuntimeException('Could not delete ' . $file . '.');
        }
    }

    private function isInstalled(Context $context): bool
    {
        try {
            $status = $context->shell->capture(['ddev', 'drush', 'status', '--field=bootstrap'], $context->projectDir);

            return $status === 'Successful';
        } catch (CommandFailed) {
            return false;
        }
    }
}
