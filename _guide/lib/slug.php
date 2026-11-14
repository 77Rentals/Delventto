<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** 2 to 40 chars: lowercase letters, digits, single hyphens, starting and ending alphanumeric. */
function slug_valid(string $slug): bool
{
    if (strlen($slug) < 2 || strlen($slug) > 40) {
        return false;
    }
    return preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/D', $slug) === 1;
}

/** True when the slug would collide with an existing top-level path or a fixed word. */
function slug_reserved(string $slug): bool
{
    if (in_array(strtolower($slug), ['api', '_guide', 'images', 'tests'], true)) {
        return true;
    }
    if (!slug_valid($slug)) {
        return true;
    }
    foreach (scandir(WEBROOT) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..' && strtolower($entry) === strtolower($slug)) {
            return true;
        }
    }
    return false;
}
