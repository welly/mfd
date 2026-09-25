<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Config;

/** The naming rules for projects, themes and labels. */
final class Naming
{
    /** Machine names Drupal 11 core uses (modules, themes, profiles), plus names that would confuse Drupal. */
    public const RESERVED = [
        'core', 'drupal', 'system', 'stark', 'olivero', 'claro', 'starterkit_theme', 'standard', 'minimal',
        'demo_umami', 'testing', 'action', 'announcements_feed', 'automated_cron', 'ban', 'basic_auth',
        'big_pipe', 'block', 'block_content', 'book', 'breakpoint', 'ckeditor5', 'comment', 'config',
        'config_translation', 'contact', 'content_moderation', 'content_translation', 'contextual', 'datetime',
        'datetime_range', 'dblog', 'dynamic_page_cache', 'editor', 'field', 'field_layout', 'field_ui', 'file',
        'filter', 'forum', 'help', 'history', 'image', 'inline_form_errors', 'jsonapi', 'language',
        'layout_builder', 'layout_discovery', 'link', 'locale', 'media', 'media_library', 'menu_link_content',
        'menu_ui', 'migrate', 'migrate_drupal', 'migrate_drupal_ui', 'mysql', 'navigation', 'node', 'options',
        'package_manager', 'page_cache', 'path', 'path_alias', 'pgsql', 'phpass', 'responsive_image', 'rest',
        'sdc', 'search', 'serialization', 'settings_tray', 'shortcut', 'sqlite', 'statistics', 'syslog',
        'taxonomy', 'telephone', 'text', 'toolbar', 'tour', 'tracker', 'update', 'user', 'views', 'views_ui',
        'workflows', 'workspaces', 'workspaces_ui',
    ];

    /** The DDEV project name, so it must be a valid hostname label. */
    public static function isValidProjectName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9-]{1,61}[a-z0-9]$/D', $name) === 1;
    }

    public static function isReserved(string $name): bool
    {
        return in_array($name, self::RESERVED, true);
    }

    public static function isValidThemeName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*$/D', $name) === 1
            && strlen($name) <= 50
            && !self::isReserved($name);
    }

    /** Labels reach YAML (theme .info.yml) and command arguments: only characters safe in a plain YAML scalar. */
    public static function isValidLabel(string $label): bool
    {
        return preg_match("/^[A-Za-z0-9][A-Za-z0-9 .,&'()-]{0,98}$/D", $label) === 1;
    }

    /** isValidLabel (and every other naming rule here) rejects any character outside this range. */
    public static function isAscii(string $value): bool
    {
        return preg_match('/[^\x00-\x7F]/', $value) !== 1;
    }

    public static function defaultThemeName(string $project): string
    {
        return str_replace('-', '_', $project);
    }

    public static function titleCase(string $project): string
    {
        return ucwords(str_replace('-', ' ', $project));
    }

    /**
     * A DDEV project name from a human label: "O'Brien & Co" -> "obrien-co". May still be invalid;
     * validate it. Labels are ASCII-only (isValidLabel rejects anything else before this ever
     * runs), so only the plain apostrophe needs stripping here.
     */
    public static function projectNameFromLabel(string $label): string
    {
        $slug = str_replace("'", '', strtolower($label));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');

        return rtrim(substr($slug, 0, 63), '-');
    }
}
