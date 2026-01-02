<?php
// Only logged-in admins can do this
require_once 'auth_check.php';
require 'db_connect.php';

session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = isset($_POST['schoolyear_id']) ? (int)$_POST['schoolyear_id'] : 0;
    $amount = isset($_POST['total_amount']) ? $_POST['total_amount'] : null;

    if ($amount !== null) {
        $amount = str_replace(',', '.', $amount);
    }

    if ($id > 0 && $amount !== null && is_numeric($amount)) {
        $amountFloat = (float)$amount;

        $stmt = $conn->prepare("
            UPDATE SCHOOLYEAR_TAB
            SET total_amount = ?
            WHERE id = ?
        ");

        if ($stmt) {
            $stmt->bind_param('di', $amountFloat, $id);
            $stmt->execute();
            $stmt->close();
        }
    }

    // redirect back to previous page or dashboard
    $target = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
    header('Location: ' . $target);
    exit;
}

// Fallback
header('Location: dashboard.php');
exit;