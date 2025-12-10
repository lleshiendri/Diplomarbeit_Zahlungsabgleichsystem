<?php
/**
 * Authentication Check Module
 * 
 * This file provides centralized authentication checking for all protected pages.
 * It ensures that only logged-in users can access internal pages of the application.
 * 
 * Usage: Include this file at the very top of any PHP page that requires authentication:
 * require_once 'auth_check.php';
 */

// Start session to access session variables
session_start();

// Check if user is logged in by verifying the presence of user_id in session
if (!isset($_SESSION['user_id'])) {
    // User is not logged in - redirect to login page
    header("Location: login/login.php");
    exit; // Stop script execution to prevent any further output
}


$role = $_SESSION['role'] ?? 'Reader';

// 🔴 Pages that Reader-role users must not access
$restrictedPages = [
    'add_students.php',
    'add_transactions.php',
    'unconfirmed.php',
    'import_files.php'
];

// Get the current page name
$currentPage = basename($_SERVER['PHP_SELF']);

// 🔴 If Reader tries to open a restricted page → redirect
if ($role === 'Reader' && in_array($currentPage, $restrictedPages, true)) {
    header("Location: dashboard.php");
    exit;
}

// If we reach this point, the user is authenticated and can proceed
// No additional action needed - the protected page will continue loading
?>
