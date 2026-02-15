<?php

/**
 * Minimal bootstrap for dev/admin tools.
 * Centralizes error reporting, session, DB connection, and auth.
 *
 * NOTE: In Phase 1 this is only used by diagnostics and other internal tools.
 * Frontend pages keep their existing includes to avoid behavior changes.
 */

// Error reporting: strict in dev, but do not display errors to end-users.
if (!defined('ENV_DEBUG')) {
    define('ENV_DEBUG', false);
}

error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', ENV_DEBUG ? '1' : '0');

// Ensure session is started once.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Timezone alignment – prefer explicit, fall back to PHP default.
// Adjust this if your deployment uses a different timezone.
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Europe/Vienna');
}

// Core includes: DB + auth.
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../auth_check.php';

