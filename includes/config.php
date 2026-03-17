<?php
/**
 * AcadVerify — Site Configuration
 *
 * Change BASE_URL when deploying to InfinityFree or any hosting.
 * Examples:
 *   Local:           '/'
 *   InfinityFree:    '/'   (if files are in htdocs root)
 *   Subdirectory:    '/acadverify/'
 */

define('BASE_URL', '/');

/**
 * Helper: prepend base URL to a path.
 */
function url(string $path = ''): string
{
    return BASE_URL . ltrim($path, '/');
}
