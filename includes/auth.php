<?php
/**
 * AcadVerify — Auth Guard
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/auth.php';
 *   requireLogin();              // any logged-in user
 *   requireLogin('main');        // professor only
 *   requireLogin('assistant');   // assistant only
 *   requireLogin('student');     // student only
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if a user is logged in and optionally matches a required role.
 *
 * @param string|null $required_role  'main', 'assistant', 'student', or null for any role
 */
function requireLogin(?string $required_role = null): void
{
    // Not logged in at all
    if (!isset($_SESSION['user_id'])) {
        header('Location: /acadverify/index.php');
        exit;
    }

    // Role mismatch
    if ($required_role !== null && $_SESSION['role'] !== $required_role) {
        http_response_code(403);
        exit('Access denied.');
    }
}
