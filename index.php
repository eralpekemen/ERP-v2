<?php
// index.php
session_start();
require_once 'config.php';
require_once 'template.php';
require_once 'functions/common.php';
require_once 'functions/pos.php';

// Authentication check
if (!isset($_SESSION['personnel_id']) || !isset($_SESSION['personnel_type'])) {
    header("Location: login.php");
    exit;
}

$branch_id = get_current_branch();
$personnel_id = $_SESSION['personnel_id'];
$personnel_type = $_SESSION['personnel_type'];

// Redirect based on personnel type
if ($personnel_type == 'cashier') {
    // Check for active shift
    $shift = get_active_shift($branch_id, $personnel_id);
    if (!$shift && !is_on_break($personnel_id)) {
        header("Location: dashboard.php");
        exit;
    } elseif (is_on_break($personnel_id)) {
        header("Location: lock_screen.php");
        exit;
    } else {
        header("Location: pos.php");
        exit;
    }
} else {
    // For non-cashiers (e.g., admin, manager), redirect to a generic dashboard
    header("Location: admin_dashboard.php");
    exit;
}
?>