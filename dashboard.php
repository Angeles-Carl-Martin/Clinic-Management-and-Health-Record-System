<?php
/* =====================================================
   INITIALIZATION
   ===================================================== */
session_start();
require "db.php";
require_staff_login();

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];

/* =====================================================
   FETCH USER INFO
   ===================================================== */
$stmt = $conn->prepare("SELECT full_name, role FROM staff WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$user      = $stmt->get_result()->fetch_assoc();
$full_name = $user['full_name'];
$role      = $user['role'];

$_SESSION['role'] = $role;

/* =====================================================
   FETCH DASHBOARD METRICS
   ===================================================== */
// BEFORE — counts everything including soft-deleted
$total_patients     = $conn->query("SELECT COUNT(*) AS total FROM patients")->fetch_assoc()['total'];
$total_staff        = $conn->query("SELECT COUNT(*) AS total FROM staff")->fetch_assoc()['total'];
$total_medicines    = $conn->query("SELECT COUNT(*) AS total FROM medicines")->fetch_assoc()['total'];
$total_appointments = $conn->query("SELECT COUNT(*) AS total FROM appointments")->fetch_assoc()['total'];

$today_appointments = $conn->query("
    SELECT COUNT(*) AS total FROM appointments
    WHERE DATE(appointment_date) = CURDATE()
")->fetch_assoc()['total'];

$pending_appointments = $conn->query("
    SELECT COUNT(*) AS total FROM appointments
    WHERE status = 'Pending'
")->fetch_assoc()['total'];

$today_consultations = $conn->query("
    SELECT COUNT(*) AS total FROM consultations
    WHERE visit_date = CURDATE()
")->fetch_assoc()['total'];

$low_stock_count = $conn->query("
    SELECT COUNT(*) AS total FROM medicines
    WHERE quantity <= 10 AND quantity > 0
")->fetch_assoc()['total'];

// AFTER — all queries exclude soft-deleted rows
$total_patients     = $conn->query("SELECT COUNT(*) AS total FROM patients     WHERE status = 1")->fetch_assoc()['total'];
$total_staff        = $conn->query("SELECT COUNT(*) AS total FROM staff         WHERE status = 1")->fetch_assoc()['total'];
$total_medicines    = $conn->query("SELECT COUNT(*) AS total FROM medicines     WHERE status = 1")->fetch_assoc()['total'];
$total_appointments = $conn->query("SELECT COUNT(*) AS total FROM appointments  WHERE status1 = 1")->fetch_assoc()['total'];
//                                                                                      ↑ appointments uses status1

$today_appointments = $conn->query("
    SELECT COUNT(*) AS total FROM appointments
    WHERE DATE(appointment_date) = CURDATE()
      AND status1 = 1
")->fetch_assoc()['total'];

$pending_appointments = $conn->query("
    SELECT COUNT(*) AS total FROM appointments
    WHERE status = 'Pending'
      AND status1 = 1
")->fetch_assoc()['total'];

$today_consultations = $conn->query("
    SELECT COUNT(*) AS total FROM consultations
    WHERE visit_date = CURDATE()
      AND status = 1
")->fetch_assoc()['total'];

$low_stock_count = $conn->query("
    SELECT COUNT(*) AS total FROM medicines
    WHERE quantity <= 10 AND quantity > 0
      AND status = 1
")->fetch_assoc()['total'];

// BEFORE
$low_stock_result = $conn->query("
    SELECT medicine_name, category, quantity FROM medicines
    WHERE quantity <= 10 ORDER BY quantity ASC LIMIT 5
");

$today_apt_result = $conn->query("
    SELECT a.appointment_time, a.reason, a.status, p.full_name
    FROM appointments a JOIN patients p ON a.patient_id = p.patient_id
    WHERE DATE(a.appointment_date) = CURDATE()
    ORDER BY a.appointment_time ASC LIMIT 5
");

$recent_cons_result = $conn->query("
    SELECT c.visit_date, c.diagnosis, p.full_name
    FROM consultations c JOIN patients p ON c.patient_id = p.patient_id
    ORDER BY c.visit_date DESC LIMIT 5
");

// AFTER
$low_stock_result = $conn->query("
    SELECT medicine_name, category, quantity FROM medicines
    WHERE quantity <= 10
      AND status = 1
    ORDER BY quantity ASC LIMIT 5
");

$today_apt_result = $conn->query("
    SELECT a.appointment_time, a.reason, a.status, p.full_name
    FROM appointments a JOIN patients p ON a.patient_id = p.patient_id
    WHERE DATE(a.appointment_date) = CURDATE()
      AND a.status1 = 1
    ORDER BY a.appointment_time ASC LIMIT 5
");

$recent_cons_result = $conn->query("
    SELECT c.visit_date, c.diagnosis, p.full_name
    FROM consultations c JOIN patients p ON c.patient_id = p.patient_id
    WHERE c.status = 1
    ORDER BY c.visit_date DESC LIMIT 5
");

/* =====================================================
   AJAX CHECK
   When fetched via loadPage(), only return the inner
   content fragment — NOT the full HTML shell.
   ===================================================== */
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!$is_ajax):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SPIST Clinic | Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="SPISTLOGOPNG.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>

        /* =====================================================
           ROOT & RESET
           ===================================================== */
        :root {
            --primary:   #006633;
            --secondary: #00a651;
            --accent:    #20c997;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            background:
                linear-gradient(135deg, rgba(0,102,51,0.5), rgba(0,166,81,0.5), rgba(0,102,51,0.5)) no-repeat,
                url("clinic background.jpg") no-repeat center center fixed;
            background-size: 400% 400%, cover;
            animation: gradientShift 15s ease infinite;
        }

        @keyframes gradientShift {
            0%   { background-position: 0% 50%, center center; }
            50%  { background-position: 100% 50%, center center; }
            100% { background-position: 0% 50%, center center; }
        }

        /* =====================================================
           NAVBAR
           ===================================================== */
        .navbar {
            width: 100%;
            padding: 15px 40px;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(12px);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
            top: 0;
            z-index: 200;
        }

        .nav-brand { display: flex; align-items: center; gap: 15px; }

        .nav-brand img {
            width: 50px;
            height: auto;
            border-radius: 50%;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }

        .nav-brand h2 {
            margin: 0;
            font-size: 22px;
            letter-spacing: 1px;
            color: white;
            text-shadow: 0 1px 2px rgba(0,0,0,0.5), 0 0 10px rgba(0,166,81,0.3);
        }

        .nav-right { display: flex; align-items: center; gap: 20px; font-size: 14px; }

        .user-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(255,255,255,0.1);
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.18);
            backdrop-filter: blur(8px);
        }

        .user-avatar {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: grid; place-items: center;
            color: white; font-size: 18px;
        }

        .user-details { text-align: left; line-height: 1.3; }
        .user-name { font-weight: 700; font-size: 15px; text-shadow: 0 1px 2px rgba(0,0,0,0.3); }
        .user-role { font-size: 13px; opacity: 0.85; color: #e6f7ff; text-shadow: 0 1px 2px rgba(0,0,0,0.3); }

        .logout-btn {
            background: #ff4d4d;
            padding: 10px 16px;
            border-radius: 10px;
            text-decoration: none;
            color: white;
            font-weight: bold;
            box-shadow: 0 6px 18px rgba(255,77,77,0.22);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s, transform 0.2s;
        }

        .logout-btn:hover { background: #cc0000; transform: translateY(-2px); }

        /* =====================================================
           LAYOUT
           ===================================================== */
        .container { display: flex; min-height: calc(100vh - 70px); }

        /* =====================================================
           SIDEBAR
           ===================================================== */
        .sidebar {
            width: 260px;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(12px);
            padding: 30px 20px;
            box-shadow: 5px 0 20px rgba(0,0,0,0.15);
            border-right: 1px solid rgba(0,166,81,0.1);
            z-index: 100;
            display: flex;
            flex-direction: column;
        }

        .sidebar h3 {
            color: var(--primary);
            text-align: center;
            margin-bottom: 30px;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.05em;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 18px;
            margin-bottom: 6px;
            text-decoration: none;
            color: #333;
            border-radius: 12px;
            transition: all 0.25s;
            cursor: pointer;
            font-weight: 500;
            font-size: 14px;
        }

        .sidebar a i { width: 20px; text-align: center; color: var(--primary); font-size: 16px; }

        .sidebar a:hover {
            background: linear-gradient(135deg, var(--secondary), #00c853);
            color: white;
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0,166,81,0.3);
        }

        .sidebar a:hover i, .sidebar a.active i { color: white; }

        .sidebar a.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 4px 12px rgba(0,102,51,0.3);
        }

        .sidebar-divider { height: 1px; background: rgba(0,102,51,0.1); margin: 10px 0 16px; }

        /* =====================================================
           MAIN CONTENT
           ===================================================== */
        .main { flex: 1; padding: 36px 40px; color: white; overflow-y: auto; }

        /* =====================================================
           PAGE HEADER
           ===================================================== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 30px;
            border-bottom: 1px solid rgba(255,255,255,0.15);
            padding-bottom: 20px;
            gap: 20px;
            flex-wrap: wrap;
        }

        .page-title    { font-size: 26px; font-weight: 800; margin-bottom: 6px; }
        .page-subtitle { font-size: 14px; opacity: 0.7; }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, var(--secondary), var(--accent));
            padding: 9px 16px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(0,166,81,0.3);
            white-space: nowrap;
        }

        /* =====================================================
           STAT CARDS
           ===================================================== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: rgba(255,255,255,0.12);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 20px;
            padding: 24px 22px;
            cursor: pointer;
            transition: transform 0.25s, box-shadow 0.25s, border-color 0.25s;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; right: 0;
            width: 120px; height: 120px;
            border-radius: 50%;
            opacity: 0.15;
            pointer-events: none;
        }

        .stat-card::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 4px;
            border-radius: 0 0 20px 20px;
        }

        /* Unique designs for each card */
        .stat-card.green {
            background: linear-gradient(135deg, rgba(47,158,68,0.25), rgba(32,201,151,0.15));
            border: 1.5px solid rgba(32,201,151,0.35);
        }
        .stat-card.green::before { background: #20c997; }
        .stat-card.green::after  { background: linear-gradient(90deg, #2f9e44, #20c997); }

        .stat-card.blue {
            background: linear-gradient(135deg, rgba(59,130,246,0.25), rgba(96,165,250,0.15));
            border: 1.5px solid rgba(96,165,250,0.35);
            border-left: 8px solid #3b82f6;
        }
        .stat-card.blue::before { background: #3b82f6; }
        .stat-card.blue::after   { background: linear-gradient(90deg, #3b82f6, #60a5fa); }

        .stat-card.teal {
            background: linear-gradient(135deg, rgba(6,182,212,0.25), rgba(34,211,238,0.15));
            border-top: 3px dashed rgba(34,211,238,0.4);
            border-bottom: 3px dashed rgba(34,211,238,0.4);
            border-left: 1px solid rgba(34,211,238,0.2);
            border-right: 1px solid rgba(34,211,238,0.2);
            border-radius: 15px;
        }
        .stat-card.teal::before { background: #22d3ee; }
        .stat-card.teal::after   { background: linear-gradient(90deg, #06b6d4, #22d3ee); }

        .stat-card.purple {
            background: linear-gradient(135deg, rgba(139,92,246,0.3), rgba(167,139,250,0.15));
            border: 2px solid rgba(167,139,250,0.4);
            box-shadow: inset 0 0 30px rgba(167,139,250,0.1);
        }
        .stat-card.purple::before { background: #8b5cf6; }
        .stat-card.purple::after { background: linear-gradient(90deg, #8b5cf6, #a78bfa); }

        .stat-card.yellow {
            background: linear-gradient(135deg, rgba(245,158,11,0.28), rgba(251,191,36,0.15));
            border: 1.5px solid rgba(251,191,36,0.4);
            border-right: 6px solid #f59e0b;
        }
        .stat-card.yellow::before { background: #f59e0b; }
        .stat-card.yellow::after { background: linear-gradient(90deg, #f59e0b, #fbbf24); }

        .stat-card.orange {
            background: linear-gradient(135deg, rgba(249,115,22,0.28), rgba(251,146,60,0.15));
            border: none;
            box-shadow: 0 0 0 1.5px rgba(249,115,22,0.35), inset 0 1px 0 rgba(255,255,255,0.2);
            border-radius: 18px;
        }
        .stat-card.orange::before { background: #f97316; }
        .stat-card.orange::after { background: linear-gradient(90deg, #f97316, #fb923c); }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 45px rgba(0,0,0,0.3);
            border-color: rgba(255,255,255,0.3);
        }

        .stat-card.blue:hover {
            box-shadow: 0 20px 45px rgba(0,0,0,0.3), -8px 0 20px rgba(59,130,246,0.25);
            border-left-width: 12px;
        }

        .stat-card.teal:hover {
            border-top: 3px solid rgba(34,211,238,0.6);
            border-bottom: 3px solid rgba(34,211,238,0.6);
            box-shadow: 0 20px 45px rgba(0,0,0,0.3);
        }

        .stat-card.yellow:hover {
            box-shadow: 0 20px 45px rgba(0,0,0,0.3), 8px 0 20px rgba(245,158,11,0.25);
            border-right-width: 10px;
        }

        .stat-card.purple:hover {
            box-shadow: inset 0 0 50px rgba(167,139,250,0.15), 0 20px 45px rgba(0,0,0,0.3);
        }

        .stat-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; position: relative; z-index: 1; }

        .stat-icon {
            width: 52px; height: 52px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .stat-icon.green  { background: linear-gradient(135deg, #2f9e44, #20c997); color: white; }
        .stat-icon.blue   { background: linear-gradient(135deg, #3b82f6, #60a5fa); color: white; }
        .stat-icon.teal   { background: linear-gradient(135deg, #06b6d4, #22d3ee); color: white; }
        .stat-icon.purple { background: linear-gradient(135deg, #8b5cf6, #a78bfa); color: white; }
        .stat-icon.yellow { background: linear-gradient(135deg, #f59e0b, #fbbf24); color: white; }
        .stat-icon.orange { background: linear-gradient(135deg, #f97316, #fb923c); color: white; }

        .stat-arrow { font-size: 14px; opacity: 0.3; transition: opacity 0.2s, transform 0.2s; position: relative; z-index: 1; }
        .stat-card:hover .stat-arrow { opacity: 1; transform: translateX(5px); }

        .stat-label {
            font-size: 11px; font-weight: 700;
            color: rgba(255,255,255,0.65);
            text-transform: uppercase; letter-spacing: 0.08em;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }

        .stat-value { 
            font-size: 38px; font-weight: 900; color: white; line-height: 1; margin-bottom: 6px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
            position: relative;
            z-index: 1;
        }
        
        .stat-sub   { 
            font-size: 12px; color: rgba(255,255,255,0.5); font-weight: 600;
            position: relative;
            z-index: 1;
        }

        /* =====================================================
           PANELS GRID
           ===================================================== */
        .panels-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .panel {
            background: rgba(255,255,255,0.97);
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0,0,0,0.18);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 20px 14px;
            border-bottom: 1.5px solid #f0f4f0;
            background: #fff;
        }

        .panel-title {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 14px;
            font-weight: 700;
            color: #1a3d28;
        }

        .panel-title-icon {
            width: 32px; height: 32px;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; flex-shrink: 0;
        }

        .icon-blue { background: #e8f1fd; color: #2563eb; }
        .icon-teal { background: #e0f7ef; color: #0d9488; }
        .icon-red  { background: #fee2e2; color: #dc2626; }

        .panel-count {
            font-size: 11px; font-weight: 700;
            background: #eef2ff; color: #4338ca;
            padding: 3px 10px; border-radius: 20px;
        }

        .view-all-btn {
            font-size: 12px; font-weight: 700; color: #16a34a;
            cursor: pointer; border: none; background: none;
            display: flex; align-items: center; gap: 5px;
            padding: 6px 0; transition: color 0.15s;
        }

        .view-all-btn:hover { color: #15803d; }

        .panel-body { padding: 12px 16px 16px; background: #f9fbf9; }

        /* =====================================================
           APPOINTMENTS LIST
           ===================================================== */
        .apt-item {
            display: flex;
            align-items: stretch;
            background: #fff;
            border: 1.5px solid #e8f0eb;
            border-radius: 12px;
            margin-bottom: 8px;
            overflow: hidden;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .apt-item:last-child { margin-bottom: 0; }
        .apt-item:hover { border-color: #86efac; box-shadow: 0 2px 10px rgba(22,163,74,0.08); }

        .apt-time-col {
            width: 68px;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            background: linear-gradient(180deg, #f0fdf4, #dcfce7);
            border-right: 1.5px solid #e8f0eb;
            padding: 10px 4px; flex-shrink: 0;
        }

        .apt-time-hour { font-size: 15px; font-weight: 800; color: #15803d; line-height: 1; }
        .apt-time-ampm { font-size: 10px; font-weight: 700; color: #4ade80; letter-spacing: 0.04em; margin-top: 2px; }

        .apt-details {
            flex: 1; padding: 10px 12px;
            display: flex; flex-direction: column; justify-content: center; gap: 2px;
        }

        .apt-name   { font-size: 13px; font-weight: 700; color: #1a3d28; line-height: 1.2; }
        .apt-reason { font-size: 11px; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 150px; }

        .apt-badge-col { display: flex; align-items: center; padding: 10px 12px; flex-shrink: 0; }

        /* =====================================================
           STATUS BADGES
           ===================================================== */
        .badge {
            font-size: 10px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.06em;
            padding: 4px 10px; border-radius: 20px;
            white-space: nowrap; display: inline-block;
        }

        .badge-pending   { background: #fef9c3; color: #a16207; border: 1px solid #fde68a; }
        .badge-approved  { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .badge-cancelled { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }

        /* =====================================================
           CONSULTATIONS LIST
           ===================================================== */
        .cons-item {
            display: flex; align-items: center; gap: 12px;
            background: #fff;
            border: 1.5px solid #e8f0eb;
            border-radius: 12px;
            padding: 10px 14px; margin-bottom: 8px;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .cons-item:last-child { margin-bottom: 0; }
        .cons-item:hover { border-color: #6ee7b7; box-shadow: 0 2px 10px rgba(13,148,136,0.08); }

        .cons-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 800; color: #0f766e;
            background: linear-gradient(135deg, #ccfbf1, #99f6e4);
            flex-shrink: 0;
        }

        .cons-info { flex: 1; min-width: 0; }
        .cons-name { font-size: 13px; font-weight: 700; color: #1a3d28; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cons-diag { font-size: 11px; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .cons-date-col { text-align: right; flex-shrink: 0; }
        .cons-date { font-size: 12px; font-weight: 700; color: #9ca3af; display: block; }
        .cons-dot  { width: 6px; height: 6px; border-radius: 50%; background: #6ee7b7; margin: 4px auto 0; }

        /* =====================================================
           LOW STOCK PANEL
           ===================================================== */
        .low-stock-panel {
            background: #fff; border-radius: 18px; overflow: hidden;
            box-shadow: 0 4px 24px rgba(0,0,0,0.12);
            border: 1.5px solid #fee2e2; margin-bottom: 24px;
        }

        .low-stock-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 18px 22px 14px;
            background: linear-gradient(135deg, #fff5f5, #fff);
            border-bottom: 1.5px solid #fee2e2;
        }

        .low-stock-title {
            display: flex; align-items: center; gap: 10px;
            font-size: 14px; font-weight: 700; color: #7f1d1d;
        }

        .alert-count-badge {
            font-size: 11px; font-weight: 700;
            background: #fee2e2; color: #b91c1c;
            border: 1px solid #fca5a5;
            padding: 3px 10px; border-radius: 20px;
        }

        .manage-btn {
            font-size: 12px; font-weight: 700; color: #dc2626;
            background: none; border: none; cursor: pointer;
            display: flex; align-items: center; gap: 5px; transition: color 0.15s;
        }

        .manage-btn:hover { color: #991b1b; }

        .low-stock-body { padding: 14px 16px; }

        .ls-table { width: 100%; border-collapse: separate; border-spacing: 0; }

        .ls-table thead th {
            padding: 8px 14px; text-align: left;
            font-size: 10px; font-weight: 800; color: #9ca3af;
            text-transform: uppercase; letter-spacing: 0.08em;
            background: #f9fafb; border-bottom: 1.5px solid #f3f4f6;
        }

        .ls-table thead th:last-child { text-align: right; }

        .ls-table tbody tr td {
            padding: 10px 14px; font-size: 13px; color: #1f2937;
            border-bottom: 1px solid #f3f4f6; vertical-align: middle;
        }

        .ls-table tbody tr:last-child td { border-bottom: none; }
        .ls-table tbody tr:hover td { background: #fff9f9; }

        .ls-med-name { font-weight: 700; }
        .ls-category { color: #9ca3af; font-size: 12px; }

        .stock-badge {
            display: inline-flex; align-items: center; gap: 5px;
            color: #fff; font-size: 12px; font-weight: 800;
            padding: 4px 12px; border-radius: 20px;
        }

        .stock-badge.critical { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .stock-badge.warning  { background: linear-gradient(135deg, #f59e0b, #d97706); }

        .update-btn {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: #fff; font-size: 11px; font-weight: 700;
            padding: 6px 14px; border-radius: 20px; border: none; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; float: right;
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .update-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(22,163,74,0.25); }

        /* =====================================================
           EMPTY STATE
           ===================================================== */
        .empty-state { text-align: center; padding: 28px 16px; color: #9ca3af; font-size: 13px; }
        .empty-state i { display: block; font-size: 28px; margin-bottom: 10px; color: #d1d5db; }

        @media (max-width: 900px) {
            .navbar {
                padding: 12px 16px;
                align-items: flex-start;
                gap: 12px;
                flex-wrap: wrap;
            }

            .nav-brand { gap: 10px; min-width: 0; }
            .nav-brand img { width: 42px; }
            .nav-brand h2 { font-size: 16px; line-height: 1.2; }

            .nav-right {
                width: 100%;
                justify-content: space-between;
                gap: 10px;
            }

            .user-chip {
                min-width: 0;
                flex: 1;
                padding: 8px 10px;
            }

            .user-avatar { width: 34px; height: 34px; font-size: 15px; }
            .user-name { font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .user-role { font-size: 11px; }
            .logout-btn { padding: 9px 12px; font-size: 13px; }

            .container {
                display: block;
                min-height: auto;
            }

            .sidebar {
                width: 100%;
                padding: 12px 14px;
                flex-direction: row;
                gap: 8px;
                overflow-x: auto;
                border-right: 0;
                border-bottom: 1px solid rgba(0,166,81,0.14);
                box-shadow: 0 4px 16px rgba(0,0,0,0.14);
            }

            .sidebar h3,
            .sidebar-divider {
                display: none;
            }

            .sidebar a,
            #nav-bin {
                flex: 0 0 auto;
                margin-bottom: 0;
                padding: 10px 12px;
                border-radius: 10px;
                white-space: nowrap;
                font-size: 13px;
            }

            .sidebar a:hover {
                transform: none;
            }

            .main {
                padding: 22px 14px 28px;
                overflow-x: hidden;
            }

            .page-header {
                align-items: flex-start;
                margin-bottom: 20px;
            }

            .page-title { font-size: 22px; }
            .status-pill { width: 100%; justify-content: center; }
            .stats-grid,
            .panels-grid {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 18px 16px;
                border-radius: 16px;
            }

            .stat-value { font-size: 32px; }
            .panel-header,
            .low-stock-header {
                align-items: flex-start;
                gap: 10px;
                flex-wrap: wrap;
            }

            .apt-item {
                flex-wrap: wrap;
            }

            .apt-details {
                min-width: 0;
            }

            .apt-reason {
                max-width: none;
            }

            .apt-badge-col {
                width: 100%;
                padding-top: 0;
                justify-content: flex-end;
            }

            .low-stock-body {
                overflow-x: auto;
            }
        }

    </style>
</head>

<body>

<!-- NAVBAR -->
<div class="navbar">
    <div class="nav-brand">
        <img src="SPISTLOGOPNG.png" alt="SPIST Logo">
        <h2>SPIST CLINIC DASHBOARD</h2>
    </div>
    <div class="nav-right">
        <div class="user-chip">
            <div class="user-avatar"><i class="fa-solid fa-user-nurse"></i></div>
            <div class="user-details">
                <p class="user-name"><?= htmlspecialchars($full_name) ?></p>
                <p class="user-role"><?= ucfirst($role) ?></p>
            </div>
        </div>
        <a href="logout.php" class="logout-btn">
            <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
    </div>
</div>

<!-- LAYOUT -->
<div class="container">

  <!-- SIDEBAR -->
<div class="sidebar">
    <h3>MENU</h3>

    <a id="nav-dashboard" class="active" onclick="setActive('nav-dashboard'); loadPage('dashboard.php')">
        <i class="fa-solid fa-house"></i> Dashboard
    </a>
    <a id="nav-patients" onclick="setActive('nav-patients'); loadPage('patients.php')">
        <i class="fa-solid fa-users"></i> Patients
    </a>
    <a id="nav-records" onclick="setActive('nav-records'); loadPage('consultations.php')">
        <i class="fa-solid fa-notes-medical"></i> Medical Records
    </a>
    <a id="nav-appointments" onclick="setActive('nav-appointments'); loadPage('appointments.php')">
        <i class="fa-solid fa-calendar-check"></i> Appointments
    </a>
    <a id="nav-medicines" onclick="setActive('nav-medicines'); loadPage('medicines.php')">
        <i class="fa-solid fa-pills"></i> Medicines
    </a>
    <a id="nav-reports" onclick="setActive('nav-reports'); loadPage('reports.php')">
        <i class="fa-solid fa-chart-line"></i> Reports
    </a>

    <!-- ADMIN ONLY SECTION -->
    <?php if ($role === 'admin'): ?>
        <div class="sidebar-divider"></div>
        
        <a id="nav-staff" onclick="setActive('nav-staff'); loadPage('manage_staff.php')">
            <i class="fa-solid fa-user-gear"></i> Manage Staff
        </a>

        <!-- Dito na ang Recycle Bin, pang Admin lang -->
        <a id="nav-bin" onclick="setActive('nav-bin'); loadPage('bin.php')" style="display: flex; justify-content: space-between; align-items: center;">
            <span><i class="fa-solid fa-trash-can"></i> Recycle Bin</span>
        
        </a>
    <?php endif; ?>
</div>

    <!-- MAIN CONTENT -->
    <div class="main" id="main-content">

<?php endif; /* end full-page shell — content fragment starts here (shared by both paths) */ ?>

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h2 class="page-title">Welcome back, <?= htmlspecialchars(explode(' ', $full_name)[0]) ?>! 👋</h2>
                <p class="page-subtitle">Clinic Overview &mdash; <strong><?= date('l, F j, Y') ?></strong></p>
            </div>
            <span class="status-pill">
                <i class="fa-solid fa-circle-check"></i> SYSTEM ACTIVE
            </span>
        </div>

        <!-- STAT CARDS: 3 top, 3 bottom -->
        <div class="stats-grid">

            <!-- Row 1 -->
            <div class="stat-card green" onclick="setActive('nav-patients'); loadPage('patients.php')">
                <div class="stat-top">
                    <div class="stat-icon green"><i class="fa-solid fa-users"></i></div>
                    <i class="fa-solid fa-chevron-right stat-arrow"></i>
                </div>
                <div class="stat-label">Total Patients</div>
                <div class="stat-value"><?= $total_patients ?></div>
                <div class="stat-sub">Registered records</div>
            </div>

            <div class="stat-card blue" onclick="setActive('nav-appointments'); loadPage('appointments.php')">
                <div class="stat-top">
                    <div class="stat-icon blue"><i class="fa-solid fa-calendar-day"></i></div>
                    <i class="fa-solid fa-chevron-right stat-arrow"></i>
                </div>
                <div class="stat-label">Today's Appointments</div>
                <div class="stat-value"><?= $today_appointments ?></div>
                <div class="stat-sub"><?= $pending_appointments ?> pending</div>
            </div>

            <div class="stat-card teal" onclick="setActive('nav-records'); loadPage('consultations.php')">
                <div class="stat-top">
                    <div class="stat-icon teal"><i class="fa-solid fa-stethoscope"></i></div>
                    <i class="fa-solid fa-chevron-right stat-arrow"></i>
                </div>
                <div class="stat-label">Today's Consultations</div>
                <div class="stat-value"><?= $today_consultations ?></div>
                <div class="stat-sub">Visits today</div>
            </div>

            <!-- Row 2 -->
            <div class="stat-card purple" onclick="setActive('nav-medicines'); loadPage('medicines.php')">
                <div class="stat-top">
                    <div class="stat-icon purple"><i class="fa-solid fa-pills"></i></div>
                    <i class="fa-solid fa-chevron-right stat-arrow"></i>
                </div>
                <div class="stat-label">Medicine Items</div>
                <div class="stat-value"><?= $total_medicines ?></div>
                <div class="stat-sub"><?= $low_stock_count ?> low stock</div>
            </div>

            <div class="stat-card yellow" onclick="setActive('nav-appointments'); loadPage('appointments.php')">
                <div class="stat-top">
                    <div class="stat-icon yellow"><i class="fa-solid fa-calendar-check"></i></div>
                    <i class="fa-solid fa-chevron-right stat-arrow"></i>
                </div>
                <div class="stat-label">Total Appointments</div>
                <div class="stat-value"><?= $total_appointments ?></div>
                <div class="stat-sub">All time</div>
            </div>

            <?php if ($role === 'admin'): ?>
            <div class="stat-card orange" onclick="setActive('nav-staff'); loadPage('manage_staff.php')">
                <div class="stat-top">
                    <div class="stat-icon orange"><i class="fa-solid fa-user-nurse"></i></div>
                    <i class="fa-solid fa-chevron-right stat-arrow"></i>
                </div>
                <div class="stat-label">Clinic Staff</div>
                <div class="stat-value"><?= $total_staff ?></div>
                <div class="stat-sub">Active accounts</div>
            </div>
            <?php else: ?>
            <div class="stat-card orange" onclick="setActive('nav-reports'); loadPage('reports.php')">
                <div class="stat-top">
                    <div class="stat-icon orange"><i class="fa-solid fa-chart-line"></i></div>
                    <i class="fa-solid fa-chevron-right stat-arrow"></i>
                </div>
                <div class="stat-label">Reports</div>
                <div class="stat-value"><i class="fa-solid fa-arrow-right" style="font-size:24px;"></i></div>
                <div class="stat-sub">View analytics</div>
            </div>
            <?php endif; ?>

        </div>

        <!-- TWO-COLUMN PANELS -->
        <div class="panels-grid">

            <!-- Today's Appointments -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <div class="panel-title-icon icon-blue"><i class="fa-solid fa-calendar-day"></i></div>
                        Today's Appointments
                        <span class="panel-count"><?= $today_appointments ?> scheduled</span>
                    </div>
                    <button class="view-all-btn" onclick="setActive('nav-appointments'); loadPage('appointments.php')">
                        View all <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </div>
                <div class="panel-body">
                    <?php if ($today_apt_result && $today_apt_result->num_rows > 0): ?>
                        <?php while ($row = $today_apt_result->fetch_assoc()):
                            $hour = date('g:i', strtotime($row['appointment_time']));
                            $ampm = date('A',   strtotime($row['appointment_time']));
                            $s    = strtolower($row['status']);
                        ?>
                        <div class="apt-item">
                            <div class="apt-time-col">
                                <div class="apt-time-hour"><?= $hour ?></div>
                                <div class="apt-time-ampm"><?= $ampm ?></div>
                            </div>
                            <div class="apt-details">
                                <div class="apt-name"><?= htmlspecialchars($row['full_name']) ?></div>
                                <div class="apt-reason"><?= htmlspecialchars($row['reason']) ?></div>
                            </div>
                            <div class="apt-badge-col">
                                <span class="badge badge-<?= $s ?>"><?= htmlspecialchars($row['status']) ?></span>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-calendar-xmark"></i>
                            No appointments scheduled for today.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Consultations -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <div class="panel-title-icon icon-teal"><i class="fa-solid fa-stethoscope"></i></div>
                        Recent Consultations
                    </div>
                    <button class="view-all-btn" onclick="setActive('nav-records'); loadPage('consultations.php')">
                        View all <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </div>
                <div class="panel-body">
                    <?php if ($recent_cons_result && $recent_cons_result->num_rows > 0): ?>
                        <?php while ($row = $recent_cons_result->fetch_assoc()):
                            $parts    = explode(' ', $row['full_name']);
                            $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                        ?>
                        <div class="cons-item">
                            <div class="cons-avatar"><?= $initials ?></div>
                            <div class="cons-info">
                                <div class="cons-name"><?= htmlspecialchars($row['full_name']) ?></div>
                                <div class="cons-diag"><?= htmlspecialchars($row['diagnosis']) ?></div>
                            </div>
                            <div class="cons-date-col">
                                <span class="cons-date"><?= date('M d', strtotime($row['visit_date'])) ?></span>
                                <div class="cons-dot"></div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-file-circle-xmark"></i>
                            No recent consultations found.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- LOW STOCK PANEL -->
        <div class="low-stock-panel">
            <div class="low-stock-header">
                <div class="low-stock-title">
                    <div class="icon-red"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    Low Stock Alerts
                    <?php if ($low_stock_count > 0): ?>
                        <span class="alert-count-badge">
                            <?= $low_stock_count ?> item<?= $low_stock_count !== 1 ? 's' : '' ?>
                        </span>
                    <?php endif; ?>
                </div>
                <button class="manage-btn" onclick="setActive('nav-medicines'); loadPage('medicines.php')">
                    Manage medicines <i class="fa-solid fa-arrow-right"></i>
                </button>
            </div>
            <div class="low-stock-body">
                <table class="ls-table">
                    <thead>
                        <tr>
                            <th>Medicine name</th>
                            <th>Category</th>
                            <th style="text-align:center;">Remaining stock</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($low_stock_result && $low_stock_result->num_rows > 0):
                            while ($row = $low_stock_result->fetch_assoc()):
                                $severity = $row['quantity'] <= 5 ? 'critical' : 'warning';
                        ?>
                        <tr>
                            <td><div class="ls-med-name"><?= htmlspecialchars($row['medicine_name']) ?></div></td>
                            <td><div class="ls-category"><?= htmlspecialchars($row['category']) ?></div></td>
                            <td style="text-align:center;">
                                <span class="stock-badge <?= $severity ?>">
                                    <i class="fa-solid fa-box-open"></i> <?= $row['quantity'] ?>
                                </span>
                            </td>
                            <td style="text-align:right;">
                                <button class="update-btn" onclick="setActive('nav-medicines'); loadPage('medicines.php')">
                                    <i class="fa-solid fa-pen"></i> Update
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td colspan="4">
                                <div class="empty-state">
                                    <i class="fa-solid fa-circle-check" style="color:#86efac;"></i>
                                    All medicines are well-stocked.
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

<?php if (!$is_ajax): ?>
    </div><!-- end .main -->
</div><!-- end .container -->
<script>
    /* =====================================================
       GLOBAL STATE
       ===================================================== */
    // Isang beses lang idinedeklara rito para sa buong dashboard.
    window.isDeleting = false; 
    window.currentDeleteId = null;

    /* =====================================================
       PAGE LOADER (AJAX) - FIXED VERSION
       ===================================================== */
    function loadPage(page) {
        const main = document.getElementById('main-content');
        
        // Magpakita ng Loading state
        main.innerHTML = `
            <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 300px; color: white;">
                <i class="fa-solid fa-circle-notch fa-spin" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                <p>Loading, please wait...</p>
            </div>
        `;

        fetch(page, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => {
            if (!res.ok) throw new Error('Network response was not ok');
            return res.text();
        })
        .then(data => {
            // 1. Ipasok ang HTML content
            main.innerHTML = data;

            // 2. Hanapin at i-execute ang mga scripts mula sa nalo-load na page
            const scripts = main.querySelectorAll('script');
            scripts.forEach(oldScript => {
                if (oldScript.src) {
                    // Para sa external scripts (halimbawa: dataTables.js)
                    const newScript = document.createElement('script');
                    newScript.src = oldScript.src;
                    document.body.appendChild(newScript);
                    document.body.removeChild(newScript);
                } else {
                    /**
                     * SMART SCRIPT EXECUTION:
                     * Ginagamit natin ang window.eval() para ang mga functions gaya ng 
                     * prepareAdd() ay maging GLOBAL at matawag ng onclick sa HTML.
                     */
                    try {
                        let scriptContent = oldScript.textContent;

                        /**
                         * AUTO-CLEANUP:
                         * Hahanapin natin ang anumang 'var isDeleting', 'let isDeleting', 
                         * o 'const isDeleting' sa nalo-load na script at papalitan 
                         * natin ito ng direct assignment para iwas sa SyntaxError.
                         */
                        const cleanedCode = scriptContent.replace(/(var|let|const)\s+isDeleting/g, 'window.isDeleting');

                        // I-execute ang code sa global context
                        window.eval(cleanedCode);
                    } catch (err) {
                        console.error("Script Execution Error sa " + page + ":", err);
                    }
                }
            });
        })
        .catch(error => {
            console.error('Error:', error);
            main.innerHTML = `
                <div style="text-align: center; padding: 50px; color: white;">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 3rem; color: #ff4d4d;"></i>
                    <h2 style="margin-top: 15px;">Error loading page</h2>
                    <p style="opacity: 0.8;">Hindi ma-access ang pahina. Pakisuri ang koneksyon.</p>
                    <button onclick="loadPage('${page}')" style="margin-top: 20px; padding: 10px 20px; cursor: pointer; border-radius: 8px; border: none; background: #20c997; color: white;">
                        Subukan muli
                    </button>
                </div>
            `;
        });
    }

    /* =====================================================
       SIDEBAR NAVIGATION
       ===================================================== */
    function setActive(id) {
        // Alisin ang 'active' class sa lahat ng links
        document.querySelectorAll('.sidebar a').forEach(a => a.classList.remove('active'));
        
        // Idagdag sa pinindot na link
        const el = document.getElementById(id);
        if (el) el.classList.add('active');
    }

    // Default Page Load (Opsyonal)
    document.addEventListener('DOMContentLoaded', () => {
        // loadPage('medicine.php'); 
    });
</script>

</body>
</html>
<?php endif; ?>
