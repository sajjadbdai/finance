<?php
/**
 * THEME & PIN SYSTEM
 * Include this at the top of every page (after session_start)
 * File: dashboard/theme_system.php
 */

// Handle theme toggle AJAX
if (isset($_POST['action']) && $_POST['action'] === 'set_theme') {
    $_SESSION['theme'] = $_POST['theme'] === 'light' ? 'light' : 'dark';
    echo json_encode(['ok' => true, 'theme' => $_SESSION['theme']]);
    exit;
}

// Handle PIN setup/verify AJAX
if (isset($_POST['action']) && $_POST['action'] === 'set_pin') {
    $pin = preg_replace('/\D/', '', $_POST['pin'] ?? '');
    if (strlen($pin) === 4) {
        $_SESSION['balance_pin'] = password_hash($pin, PASSWORD_DEFAULT);
        $_SESSION['balance_hidden'] = true;
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'PIN must be 4 digits']);
    }
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'verify_pin') {
    $pin = preg_replace('/\D/', '', $_POST['pin'] ?? '');
    if (isset($_SESSION['balance_pin']) && password_verify($pin, $_SESSION['balance_pin'])) {
        $_SESSION['balance_hidden'] = false;
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Wrong PIN']);
    }
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'hide_balance') {
    $_SESSION['balance_hidden'] = true;
    echo json_encode(['ok' => true]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'remove_pin') {
    unset($_SESSION['balance_pin']);
    $_SESSION['balance_hidden'] = false;
    echo json_encode(['ok' => true]);
    exit;
}

// Defaults
if (!isset($_SESSION['theme'])) $_SESSION['theme'] = 'dark';
if (!isset($_SESSION['balance_hidden'])) $_SESSION['balance_hidden'] = false;

$current_theme = $_SESSION['theme'];
$balance_hidden = $_SESSION['balance_hidden'];
$has_pin = isset($_SESSION['balance_pin']);
