<?php
declare(strict_types=1);

// Webroot is the repo root (this file lives in _guide/lib/).
if (!defined('WEBROOT')) {
    define('WEBROOT', dirname(__DIR__, 2));
}

/** Folder where guide data lives, outside the webroot. */
function guide_data_dir(): string
{
    $env = getenv('GUIDE_DATA_DIR');
    if ($env !== false && $env !== '') {
        return $env;
    }
    return dirname(WEBROOT) . '/delventto-guides';
}

/** Shared signing secret, never stored in the repo. */
function guide_secret(): string
{
    $env = getenv('GUIDE_SECRET');
    if ($env !== false && $env !== '') {
        $secret = $env;
    } else {
        $file = dirname(WEBROOT) . '/delventto-private/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('Guide secret is not configured');
        }
        $cfg = require $file;
        $secret = is_array($cfg) && isset($cfg['secret']) ? (string) $cfg['secret'] : '';
    }
    // A secret pasted with a trailing newline must sign the same as the bot's copy.
    $secret = trim($secret);
    if (strlen($secret) < 32) {
        throw new RuntimeException('Guide secret is missing or too short');
    }
    return $secret;
}

/**
 * Bearer token for the review routine, or null when unset or shorter than 32 characters.
 * Null turns every review route off (404). Private config key `review_token`, env GUIDE_REVIEW_TOKEN.
 */
function guide_review_token(): ?string
{
    $env = getenv('GUIDE_REVIEW_TOKEN');
    if ($env !== false && $env !== '') {
        $token = $env;
    } else {
        $file = dirname(WEBROOT) . '/delventto-private/config.php';
        if (!is_file($file)) {
            return null;
        }
        $cfg = require $file;
        $token = is_array($cfg) && isset($cfg['review_token']) && is_string($cfg['review_token']) ? $cfg['review_token'] : '';
    }
    $token = trim($token);
    return strlen($token) >= 32 ? $token : null;
}
