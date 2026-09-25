<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Tests\Unit\Config;

use Manifesto\Mfd\Config\Naming;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NamingTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function projectNames(): iterable
    {
        yield 'simple' => ['acme', true];
        yield 'hyphenated' => ['acme-corp', true];
        yield 'digits' => ['acme2', true];
        yield 'uppercase' => ['Acme', false];
        yield 'underscore' => ['acme_corp', false];
        yield 'leading hyphen' => ['-acme', false];
        yield 'trailing hyphen' => ['acme-', false];
        yield 'leading digit' => ['1acme', false];
        yield 'too short' => ['a', false];
        yield 'dot' => ['ab.', false];
        yield 'trailing newline' => ["acme\n", false];
        yield '63 characters' => ['a' . str_repeat('b', 62), true];
        yield '64 characters' => ['a' . str_repeat('b', 63), false];
    }

    #[DataProvider('projectNames')]
    public function testProjectNames(string $name, bool $valid): void
    {
        self::assertSame($valid, Naming::isValidProjectName($name));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function themeNames(): iterable
    {
        yield 'simple' => ['acme', true];
        yield 'underscore' => ['acme_ui', true];
        yield 'hyphen' => ['acme-ui', false];
        yield 'uppercase' => ['Acme', false];
        yield 'leading digit' => ['1acme', false];
        yield 'core module' => ['node', false];
        yield 'core theme' => ['olivero', false];
        yield 'system' => ['system', false];
        yield '50 characters' => [str_repeat('a', 50), true];
        yield '51 characters' => [str_repeat('a', 51), false];
        yield 'trailing newline' => ["acme\n", false];
    }

    #[DataProvider('themeNames')]
    public function testThemeNames(string $name, bool $valid): void
    {
        self::assertSame($valid, Naming::isValidThemeName($name));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function labels(): iterable
    {
        yield 'words' => ['Acme Corp', true];
        yield 'punctuation' => ["O'Brien & Co (UK), Ltd.", true];
        yield 'colon' => ['Acme: Corp', false];
        yield 'hash' => ['Acme #1', false];
        yield 'leading space' => [' Acme', false];
        yield 'empty' => ['', false];
        yield 'trailing newline' => ["Acme\n", false];
    }

    #[DataProvider('labels')]
    public function testLabels(string $label, bool $valid): void
    {
        self::assertSame($valid, Naming::isValidLabel($label));
    }

    public function testDefaults(): void
    {
        self::assertSame('acme_corp', Naming::defaultThemeName('acme-corp'));
        self::assertSame('Acme Corp', Naming::titleCase('acme-corp'));
        self::assertSame('Acme2', Naming::titleCase('acme2'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function labelsToProjectNames(): iterable
    {
        yield 'single word' => ['Something', 'something'];
        yield 'two words' => ['Acme Corp', 'acme-corp'];
        yield 'apostrophe and ampersand' => ["O'Brien & Co", 'obrien-co'];
        yield 'punctuation runs' => ['Acme, Inc. (UK)', 'acme-inc-uk'];
        yield 'already machine style' => ['acme-corp', 'acme-corp'];
        yield 'leading digit kept' => ['3M Company', '3m-company'];
        yield 'long label is cut to 63' => [str_repeat('Abc ', 30), rtrim(substr(str_repeat('abc-', 30), 0, 63), '-')];
    }

    #[DataProvider('labelsToProjectNames')]
    public function testProjectNameFromLabel(string $label, string $expected): void
    {
        self::assertSame($expected, Naming::projectNameFromLabel($label));
    }
}
