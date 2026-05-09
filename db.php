<?php

$host = "localhost";
$user = "root";
$password = "";
$database = "clinic_db";

/* CREATE CONNECTION */

$conn = new mysqli($host, $user, $password, $database);

/* CHECK CONNECTION */

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

/* OPTIONAL: SET CHARSET */

$conn->set_charset("utf8");

function is_ajax_request(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function require_staff_login(?string $required_role = null): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['username'])) {
        if (is_ajax_request()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Login required.']);
        } else {
            header("Location: login.php");
        }
        exit();
    }

    if ($required_role !== null && ($_SESSION['role'] ?? '') !== $required_role) {
        if (is_ajax_request()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
        } else {
            header("Location: dashboard.php");
        }
        exit();
    }
}

function require_patient_login(?string $required_role = null): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['patient_id']) || ($required_role !== null && ($_SESSION['role'] ?? '') !== $required_role)) {
        if (is_ajax_request()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Login required.']);
        } else {
            header("Location: login.php");
        }
        exit();
    }
}

function redirect_direct_fragment_access(string $target = 'dashboard.php'): void {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET) && !is_ajax_request()) {
        header("Location: {$target}");
        exit();
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!empty($_SESSION['username'])) {
        header("Location: dashboard.php");
    } elseif (!empty($_SESSION['patient_id'])) {
        header("Location: p_dashboard.php");
    } else {
        header("Location: login.php");
    }
    exit();
}

?>
