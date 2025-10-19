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

// If we reach this point, the user is authenticated and can proceed
// No additional action needed - the protected page will continue loading
?>
