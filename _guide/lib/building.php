<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** Folder of building data files; GUIDE_BUILDINGS_DIR overrides it (tests). */
function guide_buildings_dir(): string
{
    $env = getenv('GUIDE_BUILDINGS_DIR');
    if ($env !== false && $env !== '') {
        return $env;
    }
    return WEBROOT . '/_guide/buildings';
}

/** The building's data from <buildings dir>/<id>.json, or null when unknown or unreadable. */
function guide_building(string $id): ?array
{
    if (preg_match('/^[a-z0-9-]+$/D', $id) !== 1) {
        return null;
    }
    $file = rtrim(guide_buildings_dir(), '/\\') . '/' . $id . '.json';
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : null;
}
