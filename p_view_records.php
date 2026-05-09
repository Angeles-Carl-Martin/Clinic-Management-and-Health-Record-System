<?php
/* =====================================================
   p_view_records.php — GUEST ACCESS (Read-Only)
   Login: ID Number + Full Name (no account needed)
   ===================================================== */
session_start();
require "db.php";
require_patient_login('guest');

/* PROTECT — must be logged in as guest */
if (!isset($_SESSION['patient_id']) || ($_SESSION['role'] ?? '') !== 'guest') {
    header("Location: login.php");
    exit();
}

$patient_id = $_SESSION['patient_id'];

/* =====================================================
   FETCH DATA
   ===================================================== */

// Patient profile info
$stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();

// All appointments ordered by newest first
$appointments = $conn->prepare("
    SELECT appointment_id, appointment_date, appointment_time, reason, status
    FROM appointments
    WHERE patient_id = ?
    ORDER BY appointment_date DESC
");
$appointments->bind_param("i", $patient_id);
$appointments->execute();
$appointment_result = $appointments->get_result();

// Full consultation history
$history = $conn->prepare("
    SELECT visit_date, symptoms, diagnosis, treatment
    FROM consultations
    WHERE patient_id = ?
    ORDER BY visit_date DESC
");
$history->bind_param("i", $patient_id);
$history->execute();
$history_result = $history->get_result();

// Stat: total appointments
$q = $conn->prepare("SELECT COUNT(*) as total FROM appointments WHERE patient_id = ?");
$q->bind_param("i", $patient_id);
$q->execute();
$total_apt = $q->get_result()->fetch_assoc()['total'];

// Stat: pending appointments
$q = $conn->prepare("SELECT COUNT(*) as total FROM appointments WHERE patient_id = ? AND status = 'Pending'");
$q->bind_param("i", $patient_id);
$q->execute();
$pending_apt = $q->get_result()->fetch_assoc()['total'];

// Stat: total consultations
$q = $conn->prepare("SELECT COUNT(*) as total FROM consultations WHERE patient_id = ?");
$q->bind_param("i", $patient_id);
$q->execute();
$total_cons = $q->get_result()->fetch_assoc()['total'];

// Last visit date
$q = $conn->prepare("SELECT visit_date FROM consultations WHERE patient_id = ? ORDER BY visit_date DESC LIMIT 1");
$q->bind_param("i", $patient_id);
$q->execute();
$last_visit_row = $q->get_result()->fetch_assoc();
$last_visit     = $last_visit_row ? date('M d, Y', strtotime($last_visit_row['visit_date'])) : 'No visits yet';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Guest View | SPIST Clinic</title>
    <link rel="icon" type="image/png" href="SPISTLOGOPNG.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        :root {
            --primary:    #006633;
            --secondary:  #00a651;
            --accent:     #20c997;
            --dark-glass: rgba(0, 0, 0, 0.45);
            --light-glass: rgba(255, 255, 255, 0.08);
            --border:     rgba(255, 255, 255, 0.12);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            background:
                linear-gradient(135deg, rgba(0,60,30,0.82), rgba(0,120,60,0.72), rgba(0,60,30,0.82)),
                url("clinic background.jpg") no-repeat center center fixed;
            background-size: cover;
            color: #fff;
            min-height: 100vh;
        }

        /* ── NAVBAR ── */
        .navbar {
            width: 100%;
            padding: 15px 40px;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(12px);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-sizing: border-box;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
            top: 0;
            z-index: 200;
        }

        .nav-brand { display: flex; align-items: center; gap: 15px; }

        .nav-brand img {
            width: 50px; height: auto;
            border-radius: 50%;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }

        .nav-brand h1 {
            margin: 0; font-size: 22px; letter-spacing: 1px; color: white;
            text-shadow: 0 1px 2px rgba(0,0,0,0.5), 0 0 10px rgba(0,166,81,0.3);
        }

        .nav-right { display: flex; align-items: center; gap: 14px; font-size: 14px; }

        .nav-patient-name {
            display: inline-flex; align-items: center; gap: 10px;
            background: rgba(255,255,255,0.1);
            padding: 10px 14px; border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.18);
            backdrop-filter: blur(8px);
        }

        .nav-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            display: grid; place-items: center;
            color: white; font-size: 18px; flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(245,158,11,0.35);
        }

        .nav-patient-info { text-align: left; line-height: 1.3; }
        .nav-patient-fullname { font-weight: 700; font-size: 15px; display: block; text-shadow: 0 1px 2px rgba(0,0,0,0.3); }
        .nav-patient-label {
            font-size: 11px; display: inline-flex; align-items: center; gap: 5px;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white; font-weight: 700;
            padding: 2px 8px; border-radius: 20px; margin-top: 2px;
            text-transform: uppercase; letter-spacing: 0.06em;
        }

        .logout-btn {
            background: #ff4d4d; padding: 10px 16px; border-radius: 10px;
            text-decoration: none; color: white; font-weight: bold;
            box-shadow: 0 6px 18px rgba(255,77,77,0.22);
            display: inline-flex; align-items: center; gap: 8px;
            transition: background 0.2s ease, transform 0.2s ease;
        }
        .logout-btn:hover { background: #cc0000; transform: translateY(-2px); }

        /* ── CONTAINER ── */
        .container { padding: 32px 40px; max-width: 1200px; margin: 0 auto; }

        /* ── GUEST BANNER ── */
        .guest-banner {
            background: linear-gradient(135deg, rgba(245,158,11,0.25), rgba(217,119,6,0.15));
            border: 1px solid rgba(245,158,11,0.4);
            border-radius: 16px;
            padding: 18px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            backdrop-filter: blur(8px);
        }

        .guest-banner-left { display: flex; align-items: center; gap: 14px; }

        .guest-banner-icon {
            width: 46px; height: 46px; border-radius: 12px;
            background: rgba(245,158,11,0.2);
            display: grid; place-items: center;
            color: #fbbf24; font-size: 20px; flex-shrink: 0;
        }

        .guest-banner h3 { font-size: 15px; font-weight: 700; color: #fbbf24; margin-bottom: 3px; }
        .guest-banner p  { font-size: 12px; color: rgba(255,255,255,0.6); }

        .btn-register {
            background: linear-gradient(135deg, #006633, #00a651);
            color: white; padding: 10px 20px; border-radius: 10px;
            text-decoration: none; font-weight: 700; font-size: 13px;
            display: inline-flex; align-items: center; gap: 8px;
            box-shadow: 0 4px 14px rgba(0,166,81,0.3);
            transition: transform 0.2s, box-shadow 0.2s;
            white-space: nowrap;
        }
        .btn-register:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,166,81,0.4); }

        /* ── WELCOME BANNER ── */
        .welcome-banner {
            background: linear-gradient(135deg, rgba(0,100,50,0.6), rgba(0,166,81,0.4));
            border: 1px solid rgba(0,166,81,0.3);
            border-radius: 20px; padding: 24px 28px; margin-bottom: 24px;
            display: flex; justify-content: space-between; align-items: center;
            backdrop-filter: blur(10px); box-shadow: 0 8px 24px rgba(0,0,0,0.2);
            flex-wrap: wrap; gap: 16px;
        }

        .welcome-banner h2 { margin: 0 0 6px; font-size: 22px; font-weight: 800; }
        .welcome-banner p  { margin: 0; font-size: 13px; color: rgba(255,255,255,0.65); }

        .welcome-banner .last-visit {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15);
            padding: 10px 16px; border-radius: 12px;
            font-size: 13px; font-weight: 600; white-space: nowrap;
        }
        .welcome-banner .last-visit i { color: var(--accent); }

        /* ── STAT CARDS ── */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px; margin-bottom: 24px;
        }

        .stat-card {
            background: var(--dark-glass); backdrop-filter: blur(12px);
            border: 1px solid var(--border); border-radius: 18px;
            padding: 22px 20px; display: flex; align-items: center; gap: 16px;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            position: relative; overflow: hidden;
        }
        .stat-card::after {
            content: ''; position: absolute;
            bottom: 0; left: 0; right: 0; height: 3px;
            border-radius: 0 0 18px 18px;
        }
        .stat-card.green::after  { background: linear-gradient(90deg, #2f9e44, #20c997); }
        .stat-card.yellow::after { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .stat-card.blue::after   { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
        .stat-card.purple::after { background: linear-gradient(90deg, #8b5cf6, #a78bfa); }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 14px 32px rgba(0,0,0,0.25); }

        .stat-icon {
            width: 54px; height: 54px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; flex-shrink: 0;
        }
        .stat-icon.green  { background: rgba(32,201,151,0.18);  color: #20c997; }
        .stat-icon.yellow { background: rgba(251,191,36,0.18);  color: #fbbf24; }
        .stat-icon.blue   { background: rgba(59,130,246,0.18);  color: #60a5fa; }
        .stat-icon.purple { background: rgba(139,92,246,0.18);  color: #a78bfa; }

        .stat-info p  { font-size: 11px; color: rgba(255,255,255,0.5); font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; margin-bottom: 5px; }
        .stat-info h2 { font-size: 32px; font-weight: 900; color: white; line-height: 1; }

        /* ── CARDS ── */
        .card {
            background: var(--dark-glass); backdrop-filter: blur(14px);
            border: 1px solid var(--border); border-radius: 22px;
            padding: 28px; margin-bottom: 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .card-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 22px; padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }

        .card-header h3 { font-size: 17px; font-weight: 700; color: white; display: flex; align-items: center; gap: 10px; }
        .card-header h3 i { color: var(--secondary); }

        .count-pill {
            background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.18);
            padding: 4px 14px; border-radius: 20px;
            font-size: 12px; font-weight: 700; color: rgba(255,255,255,0.8);
        }

        /* ── PROFILE GRID ── */
        .profile-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
        .profile-item.wide { grid-column: span 3; text-align: center; }
        .profile-item.wide span { justify-content: center; }

        .profile-item {
            background: linear-gradient(135deg, rgba(0,166,81,0.08), rgba(255,255,255,0.05));
            border: 1px solid rgba(0,166,81,0.2); border-radius: 16px;
            padding: 18px 20px; display: flex; flex-direction: column; gap: 8px;
            position: relative; overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .profile-item::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
            background: linear-gradient(180deg, var(--secondary), var(--accent));
            border-radius: 16px 0 0 16px;
        }
        .profile-item:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.2); border-color: rgba(0,166,81,0.4); }

        .profile-item span { font-size: 10px; font-weight: 700; color: var(--secondary); text-transform: uppercase; letter-spacing: 0.1em; display: flex; align-items: center; gap: 6px; }
        .profile-item span i { font-size: 11px; opacity: 0.9; }
        .profile-item strong { font-size: 15px; font-weight: 600; color: white; word-break: break-word; padding-left: 4px; }

        /* ── TABLES ── */
        .table-responsive { overflow-x: auto; border-radius: 16px; }

        table { width: 100%; border-collapse: separate; border-spacing: 0; border-radius: 16px; overflow: hidden; }

        th {
            background: linear-gradient(135deg, #003d1f, #005c2e); color: white;
            padding: 15px 16px; font-size: 12px; font-weight: 700;
            text-align: left; text-transform: uppercase; letter-spacing: 0.06em;
            position: sticky; top: 0;
        }

        td {
            padding: 14px 16px; border-bottom: 1px solid rgba(255,255,255,0.05);
            font-size: 13px; color: rgba(255,255,255,0.88);
            background: rgba(255,255,255,0.04); vertical-align: middle;
        }

        tbody tr:nth-child(even) td { background: rgba(255,255,255,0.07); }
        tbody tr:hover td { background: rgba(0,166,81,0.1); transition: background 0.2s ease; }

        .empty-row td { text-align: center; color: rgba(255,255,255,0.35); padding: 36px 20px; font-size: 14px; }
        .empty-row td i { display: block; font-size: 28px; margin-bottom: 10px; color: rgba(255,255,255,0.2); }

        /* ── BADGES ── */
        .badge { padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; display: inline-flex; align-items: center; gap: 5px; }
        .badge-pending   { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; box-shadow: 0 2px 8px rgba(245,158,11,0.35); }
        .badge-approved  { background: linear-gradient(135deg, #22c55e, #15803d); color: white; box-shadow: 0 2px 8px rgba(34,197,94,0.35); }
        .badge-cancelled { background: linear-gradient(135deg, #ef4444, #991b1b); color: white; box-shadow: 0 2px 8px rgba(239,68,68,0.35); }

        /* ── LOCKED FEATURE BOX ── */
        .locked-feature {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 12px; padding: 40px 20px;
            background: rgba(0,0,0,0.2); border: 1px dashed rgba(255,255,255,0.15);
            border-radius: 16px; color: rgba(255,255,255,0.4); text-align: center;
        }
        .locked-feature i  { font-size: 36px; color: rgba(255,255,255,0.2); }
        .locked-feature p  { font-size: 13px; line-height: 1.6; max-width: 300px; }
        .locked-feature a  {
            background: linear-gradient(135deg, #006633, #00a651);
            color: white; padding: 10px 22px; border-radius: 10px;
            text-decoration: none; font-weight: 700; font-size: 13px;
            display: inline-flex; align-items: center; gap: 8px;
            box-shadow: 0 4px 14px rgba(0,166,81,0.3);
            transition: transform 0.2s;
        }
        .locked-feature a:hover { transform: translateY(-2px); }

        /* ── FOOTER ── */
        .portal-footer { text-align: center; padding: 24px 0 10px; font-size: 12px; color: rgba(255,255,255,0.3); letter-spacing: 0.04em; }

    </style>
</head>
<body>

<!-- ── NAVBAR ── -->
<div class="navbar">
    <div class="nav-brand">
        <img src="SPISTLOGOPNG.png" alt="SPIST Logo">
        <h1>SPIST PATIENT PORTAL</h1>
    </div>
    <div class="nav-right">
        <div class="nav-patient-name">
            <div class="nav-avatar">
                <i class="fa-solid fa-user-clock"></i>
            </div>
            <div class="nav-patient-info">
                <span class="nav-patient-fullname"><?= htmlspecialchars($patient['full_name'] ?? 'Guest') ?></span>
                <span class="nav-patient-label"><i class="fa-solid fa-eye"></i> Guest</span>
            </div>
        </div>
        <a href="logout.php" class="logout-btn">
            <i class="fa-solid fa-right-from-bracket"></i> Exit
        </a>
    </div>
</div>


<!-- ── MAIN CONTENT ── -->
<div class="container">

    <!-- Guest notice banner -->
    <div class="guest-banner">
        <div class="guest-banner-left">
            <div class="guest-banner-icon">
                <i class="fa-solid fa-circle-info"></i>
            </div>
            <div>
                <h3>You are viewing as a Guest</h3>
                <p>Your records are read-only. Register for a full account to book appointments, edit your profile, and more.</p>
            </div>
        </div>
        <a href="register.php" class="btn-register">
            <i class="fa-solid fa-user-plus"></i> Create Account
        </a>
    </div>


    <!-- Welcome banner -->
    <div class="welcome-banner">
        <div>
            <h2>👋 Hello, <?= htmlspecialchars(explode(' ', $patient['full_name'] ?? 'Guest')[0]) ?>!</h2>
            <p>Here is a summary of your health records at SPIST Clinic.</p>
        </div>
        <div class="last-visit">
            <i class="fa-solid fa-clock-rotate-left"></i>
            Last visit: <strong><?= $last_visit ?></strong>
        </div>
    </div>


    <!-- Stat cards -->
    <div class="stat-grid">
        <div class="stat-card green">
            <div class="stat-icon green"><i class="fa-solid fa-calendar-check"></i></div>
            <div class="stat-info"><p>Total Appointments</p><h2><?= $total_apt ?></h2></div>
        </div>
        <div class="stat-card yellow">
            <div class="stat-icon yellow"><i class="fa-solid fa-hourglass-half"></i></div>
            <div class="stat-info"><p>Pending</p><h2><?= $pending_apt ?></h2></div>
        </div>
        <div class="stat-card blue">
            <div class="stat-icon blue"><i class="fa-solid fa-stethoscope"></i></div>
            <div class="stat-info"><p>Consultations</p><h2><?= $total_cons ?></h2></div>
        </div>
        <div class="stat-card purple">
            <div class="stat-icon purple"><i class="fa-solid fa-clock-rotate-left"></i></div>
            <div class="stat-info">
                <p>Last Visit</p>
                <h2 style="font-size:15px; padding-top:4px;"><?= $last_visit ?></h2>
            </div>
        </div>
    </div>


    <!-- Profile (read-only, no edit button) -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-circle-user"></i> My Profile</h3>
            <span class="count-pill"><i class="fa-solid fa-lock" style="font-size:10px;"></i> Read-only</span>
        </div>
        <div class="profile-grid">
            <div class="profile-item">
                <span><i class="fa-solid fa-id-badge"></i> Patient ID</span>
                <strong><?= htmlspecialchars($patient['id_number'] ?? 'N/A') ?></strong>
            </div>
            <div class="profile-item">
                <span><i class="fa-solid fa-user"></i> Full Name</span>
                <strong><?= htmlspecialchars($patient['full_name'] ?? 'N/A') ?></strong>
            </div>
            <div class="profile-item">
                <span><i class="fa-solid fa-venus-mars"></i> Gender</span>
                <strong><?= htmlspecialchars($patient['gender'] ?? 'N/A') ?></strong>
            </div>
            <div class="profile-item">
                <span><i class="fa-solid fa-calendar"></i> Birthdate</span>
                <strong><?= isset($patient['birthdate']) ? date('M d, Y', strtotime($patient['birthdate'])) : 'N/A' ?></strong>
            </div>
            <div class="profile-item">
                <span><i class="fa-solid fa-tag"></i> Category</span>
                <strong><?= htmlspecialchars($patient['category'] ?? 'N/A') ?></strong>
            </div>
            <div class="profile-item">
                <span><i class="fa-solid fa-phone"></i> Contact Number</span>
                <strong><?= htmlspecialchars($patient['contact_number'] ?? 'N/A') ?></strong>
            </div>
            <div class="profile-item wide">
                <span><i class="fa-solid fa-map-marker-alt"></i> Address</span>
                <strong><?= htmlspecialchars($patient['address'] ?? 'N/A') ?></strong>
            </div>
        </div>
    </div>


    <!-- Locked features row -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; margin-bottom: 24px;">

        <!-- Locked: Book Appointment -->
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header">
                <h3><i class="fa-solid fa-calendar-plus"></i> Request Appointment</h3>
                <span class="count-pill" style="color:#fbbf24; border-color:rgba(245,158,11,0.3); background:rgba(245,158,11,0.1);">
                    <i class="fa-solid fa-lock" style="font-size:10px;"></i> Locked
                </span>
            </div>
            <div class="locked-feature">
                <i class="fa-solid fa-calendar-xmark"></i>
                <p>Booking appointments requires a full patient account.</p>
                <a href="register.php"><i class="fa-solid fa-user-plus"></i> Register to Book</a>
            </div>
        </div>

        <!-- Locked: Security Codes -->
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header">
                <h3><i class="fa-solid fa-key"></i> Security Codes</h3>
                <span class="count-pill" style="color:#fbbf24; border-color:rgba(245,158,11,0.3); background:rgba(245,158,11,0.1);">
                    <i class="fa-solid fa-lock" style="font-size:10px;"></i> Locked
                </span>
            </div>
            <div class="locked-feature">
                <i class="fa-solid fa-shield-halved"></i>
                <p>Recovery codes and account security features are only available to registered patients.</p>
                <a href="register.php"><i class="fa-solid fa-user-plus"></i> Register Now</a>
            </div>
        </div>

    </div>


    <!-- Appointments (read-only, no actions column) -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-calendar-check"></i> My Appointments</h3>
            <span class="count-pill">
                <?= $appointment_result->num_rows ?> record<?= $appointment_result->num_rows !== 1 ? 's' : '' ?>
            </span>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Reason</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($appointment_result->num_rows > 0): ?>
                        <?php while ($row = $appointment_result->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?= date('M d, Y', strtotime($row['appointment_date'])) ?></strong></td>
                            <td><?= date('h:i A', strtotime($row['appointment_time'])) ?></td>
                            <td><?= htmlspecialchars($row['reason']) ?></td>
                            <td>
                                <?php $status = strtolower($row['status']); ?>
                                <span class="badge badge-<?= $status ?>">
                                    <?php
                                        if ($status === 'pending')   echo '<i class="fa-solid fa-hourglass-half"></i> ';
                                        if ($status === 'approved')  echo '<i class="fa-solid fa-circle-check"></i> ';
                                        if ($status === 'cancelled') echo '<i class="fa-solid fa-circle-xmark"></i> ';
                                    ?>
                                    <?= htmlspecialchars($row['status']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr class="empty-row">
                            <td colspan="4">
                                <i class="fa-solid fa-calendar-xmark"></i>
                                No appointments found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>


    <!-- Consultation History (read-only) -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-file-medical"></i> Consultation History</h3>
            <span class="count-pill">
                <?= $history_result->num_rows ?> visit<?= $history_result->num_rows !== 1 ? 's' : '' ?>
            </span>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Visit Date</th>
                        <th>Symptoms</th>
                        <th>Diagnosis</th>
                        <th>Treatment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($history_result->num_rows > 0): ?>
                        <?php while ($row = $history_result->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?= date('M d, Y', strtotime($row['visit_date'])) ?></strong></td>
                            <td><?= htmlspecialchars($row['symptoms']) ?></td>
                            <td><?= htmlspecialchars($row['diagnosis']) ?></td>
                            <td><?= htmlspecialchars($row['treatment']) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr class="empty-row">
                            <td colspan="4">
                                <i class="fa-solid fa-file-circle-xmark"></i>
                                No consultation history available yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>


    <div class="portal-footer">
        &copy; <?= date('Y') ?> SPIST Clinic &mdash; Guest View &mdash; All rights reserved.
    </div>

</div>

</body>
</html>
