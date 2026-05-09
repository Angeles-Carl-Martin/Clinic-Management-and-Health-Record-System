<?php
/* =====================================================
   INITIALIZATION
   - Start session and connect to the database
   ===================================================== */
session_start();
require "db.php";
require_patient_login();

function validateAppointmentDateTime(string $date, string $time): ?string {
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    $dateErrors = DateTime::getLastErrors();
    if (!$dateObj || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $dateObj->format('Y-m-d') !== $date) {
        return 'invalid_date';
    }

    $timeObj = DateTime::createFromFormat('H:i', substr($time, 0, 5));
    $timeErrors = DateTime::getLastErrors();
    if (!$timeObj || ($timeErrors && ($timeErrors['warning_count'] || $timeErrors['error_count']))) {
        return 'invalid_time';
    }

    $hour = (int) $timeObj->format('H');
    $minute = (int) $timeObj->format('i');
    if ($minute !== 0 || $hour < 8 || $hour > 16) {
        return 'invalid_time';
    }

    $selected = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $timeObj->format('H:i'));
    if ($selected <= new DateTime()) {
        return 'past_datetime';
    }

    return null;
}

/* =====================================================
   AJAX: GET AVAILABLE SLOTS (para sa slot picker)
   ===================================================== */
if (isset($_GET['action']) && $_GET['action'] === 'get_slots') {
    if(ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $date = $_GET['date'] ?? date('Y-m-d');
    $limit_per_slot = 5;

    $sql = "SELECT appointment_time, COUNT(*) as total
            FROM appointments
            WHERE appointment_date = ? 
              AND (status1 = '1' OR status1 = 'Active' OR status1 IS NULL) 
              AND status != 'Cancelled'
            GROUP BY appointment_time";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $result = $stmt->get_result();

    $booked = [];
    while($row = $result->fetch_assoc()) {
        $time_key = date("H:00", strtotime($row['appointment_time']));
        $booked[$time_key] = $row['total'];
    }

    $slots = [];
    for($h = 8; $h <= 16; $h++) {
        $t = sprintf("%02d:00", $h);
        $count = $booked[$t] ?? 0;
        $slotDateTime = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $t);
        $isPast = $slotDateTime && $slotDateTime <= new DateTime();
        $slots[] = [
            'time'    => $t,
            'display' => date("h:i A", strtotime($t)),
            'taken'   => $count,
            'limit'   => $limit_per_slot,
            'is_full' => ($count >= $limit_per_slot) || $isPast,
            'is_past' => $isPast
        ];
    }
    echo json_encode($slots);
    exit();
}

/* PROTECT PAGE — must be logged in as a registered patient (not guest) */
if (!isset($_SESSION['patient_id'])) {
    header("Location: login.php");
    exit();
}
if (($_SESSION['role'] ?? '') === 'guest') {
    header("Location: p_view_records.php");
    exit();
}

$patient_id = $_SESSION['patient_id'];


/* =====================================================
   HANDLE: BOOK NEW APPOINTMENT
   ===================================================== */
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['book_apt'])) {
    $date   = $_POST['apt_date'];
    $time   = $_POST['apt_time'];
    $reason = trim($_POST['apt_reason']);

    $validation_error = validateAppointmentDateTime($date, $time);
    if ($validation_error) {
        $_SESSION['flash_msg']  = $validation_error;
        $_SESSION['flash_type'] = 'warning';
        header("Location: p_dashboard.php");
        exit();
    }

    // Guard: huwag tanggapin kung 5/5 na ang slot
    $limit_per_slot = 5;
    $chk = $conn->prepare("SELECT COUNT(*) as total FROM appointments 
                           WHERE appointment_date = ? AND appointment_time = ? 
                             AND (status1 = '1' OR status1 = 'Active' OR status1 IS NULL) 
                             AND status != 'Cancelled'");
    $chk->bind_param("ss", $date, $time);
    $chk->execute();
    $chk_count = $chk->get_result()->fetch_assoc()['total'];

    if ($chk_count >= $limit_per_slot) {
        $_SESSION['flash_msg']  = 'slot_full';
        $_SESSION['flash_type'] = 'warning';
        header("Location: p_dashboard.php");
        exit();
    }

    $ins = $conn->prepare("
        INSERT INTO appointments (patient_id, appointment_date, appointment_time, reason, status, status1)
        VALUES (?, ?, ?, ?, 'Pending', '1')
    ");
    $ins->bind_param("isss", $patient_id, $date, $time, $reason);

    if ($ins->execute()) {
        $_SESSION['flash_msg']  = 'booked';
        $_SESSION['flash_type'] = 'success';
        header("Location: p_dashboard.php");
        exit();
    }
}


/* =====================================================
   HANDLE: RESCHEDULE APPOINTMENT
   ===================================================== */
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_date'])) {
    $new_date   = $_POST['new_date'];
    $new_time   = $_POST['new_time'] ?? null;
    $apt_id     = $_POST['apt_id'];
    $new_reason = trim($_POST['new_reason'] ?? '');

    if ($new_time) {
        $validation_error = validateAppointmentDateTime($new_date, $new_time);
        if ($validation_error) {
            $_SESSION['flash_msg']  = $validation_error;
            $_SESSION['flash_type'] = 'warning';
            header("Location: p_dashboard.php");
            exit();
        }
        // Check slot limit (exclude current appointment)
        $limit_per_slot = 5;
        $chk2 = $conn->prepare("SELECT COUNT(*) as total FROM appointments 
                               WHERE appointment_date = ? AND appointment_time = ? 
                                 AND appointment_id != ?
                                 AND (status1 = '1' OR status1 = 'Active' OR status1 IS NULL) 
                                 AND status != 'Cancelled'");
        $chk2->bind_param("ssi", $new_date, $new_time, $apt_id);
        $chk2->execute();
        $chk2_count = $chk2->get_result()->fetch_assoc()['total'];

        if ($chk2_count >= $limit_per_slot) {
            $_SESSION['flash_msg']  = 'slot_full';
            $_SESSION['flash_type'] = 'warning';
            header("Location: p_dashboard.php");
            exit();
        }

        $upd = $conn->prepare("
            UPDATE appointments
            SET appointment_date = ?, appointment_time = ?, reason = ?
            WHERE appointment_id = ? AND patient_id = ? AND status = 'Pending'
        ");
        $upd->bind_param("sssii", $new_date, $new_time, $new_reason, $apt_id, $patient_id);
    } else {
        $upd = $conn->prepare("
            UPDATE appointments
            SET appointment_date = ?, reason = ?
            WHERE appointment_id = ? AND patient_id = ? AND status = 'Pending'
        ");
        $upd->bind_param("ssii", $new_date, $new_reason, $apt_id, $patient_id);
    }

    if ($upd->execute()) {
        $_SESSION['flash_msg']  = 'rescheduled';
        $_SESSION['flash_type'] = 'success';
        header("Location: p_dashboard.php");
        exit();
    }
}


/* =====================================================
   HANDLE: CANCEL APPOINTMENT
   - Only pending appointments can be cancelled
   ===================================================== */
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['cancel_apt'])) {
    $apt_id = $_POST['apt_id'];

    $cancel = $conn->prepare("
        UPDATE appointments
        SET status = 'Cancelled'
        WHERE appointment_id = ? AND patient_id = ? AND status = 'Pending'
    ");
    $cancel->bind_param("ii", $apt_id, $patient_id);

    if ($cancel->execute()) {
        $_SESSION['flash_msg']  = 'cancelled';
        $_SESSION['flash_type'] = 'warning';
        header("Location: p_dashboard.php");
        exit();
    }
}

/* =====================================================
   HANDLE: GENERATE 3 RECOVERY CODES
   ===================================================== */
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_code'])) {
    // Kunin muna ang pinakabagong data ng patient para sa validation
    $check_stmt = $conn->prepare("SELECT last_code_change FROM patients WHERE patient_id = ?");
    $check_stmt->bind_param("i", $patient_id);
    $check_stmt->execute();
    $res = $check_stmt->get_result();
    $p_data = $res->fetch_assoc();
    
    $last_change = $p_data['last_code_change'] ?? null;
    
    if ($last_change && (time() - strtotime($last_change) < 86400)) {
        $_SESSION['flash_msg'] = 'code_limit';
        $_SESSION['flash_type'] = 'warning';
        header("Location: p_dashboard.php");
        exit();
    } else {
        $codes = [];
        // Line 87 should look exactly like this:
        for ($i = 0; $i < 3; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }
        
        $codes_json = json_encode($codes);
        $now = date('Y-m-d H:i:s');

        $upd = $conn->prepare("UPDATE patients SET recovery_code = ?, last_code_change = ? WHERE patient_id = ?");
        $upd->bind_param("ssi", $codes_json, $now, $patient_id);

        if ($upd->execute()) {
            $_SESSION['flash_msg'] = 'code_generated';
            $_SESSION['flash_type'] = 'success';
            header("Location: p_dashboard.php");
            exit();
        }
    }
}
/* =====================================================
   HANDLE: UPDATE PROFILE
   ===================================================== */
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $contact   = trim($_POST['contact_number']);
    $address   = trim($_POST['address']);

    $upd_prof = $conn->prepare("
        UPDATE patients
        SET full_name = ?, contact_number = ?, address = ?
        WHERE patient_id = ?
    ");
    $upd_prof->bind_param("sssi", $full_name, $contact, $address, $patient_id);

    if ($upd_prof->execute()) {
        $_SESSION['flash_msg']  = 'profile_updated';
        $_SESSION['flash_type'] = 'success';
        header("Location: p_dashboard.php");
        exit();
    }
}


/* =====================================================
   PASSWORD VALIDATION HELPER (same rules as register.php)
   ===================================================== */
function validatePassword(string $password): ?string {
    if (strlen($password) < 8)
        return 'pw_short';
    if (!preg_match('/[A-Z]/', $password))
        return 'pw_no_upper';
    if (!preg_match('/[a-z]/', $password))
        return 'pw_no_lower';
    if (!preg_match('/[0-9]/', $password))
        return 'pw_no_number';
    if (!preg_match('/[!@#$%^&*]/', $password))
        return 'pw_no_special';
    return null;
}

/* =====================================================
   HANDLE: CHANGE PASSWORD
   ===================================================== */
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['change_password'])) {
    $current_pw  = $_POST['current_password']  ?? '';
    $new_pw      = $_POST['new_password']       ?? '';
    $confirm_pw  = $_POST['confirm_password']   ?? '';

    // Fetch current hash
    $pw_stmt = $conn->prepare("SELECT password FROM patients WHERE patient_id = ?");
    $pw_stmt->bind_param("i", $patient_id);
    $pw_stmt->execute();
    $pw_row = $pw_stmt->get_result()->fetch_assoc();

    $pw_strength_error = validatePassword($new_pw);

    if (!password_verify($current_pw, $pw_row['password'])) {
        $_SESSION['flash_msg']  = 'pw_wrong';
        $_SESSION['flash_type'] = 'warning';
    } elseif ($pw_strength_error) {
        $_SESSION['flash_msg']  = $pw_strength_error;
        $_SESSION['flash_type'] = 'warning';
    } elseif ($new_pw !== $confirm_pw) {
        $_SESSION['flash_msg']  = 'pw_mismatch';
        $_SESSION['flash_type'] = 'warning';
    } elseif (password_verify($new_pw, $pw_row['password'])) {
        $_SESSION['flash_msg']  = 'pw_same';
        $_SESSION['flash_type'] = 'warning';
    } else {
        $new_hash = password_hash($new_pw, PASSWORD_DEFAULT);
        $upd_pw = $conn->prepare("UPDATE patients SET password = ? WHERE patient_id = ?");
        $upd_pw->bind_param("si", $new_hash, $patient_id);
        if ($upd_pw->execute()) {
            $_SESSION['flash_msg']  = 'pw_changed';
            $_SESSION['flash_type'] = 'success';
        }
    }
    header("Location: p_dashboard.php");
    exit();
}


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
    SELECT c.visit_date, c.symptoms, c.diagnosis, c.treatment,
           COALESCE(s.full_name, 'N/A') AS nurse_name
    FROM consultations c
    LEFT JOIN staff s ON c.nurse_id = s.staff_id
    WHERE c.patient_id = ? AND c.status = 1
    ORDER BY c.visit_date DESC
");
$history->bind_param("i", $patient_id);
$history->execute();
$history_result = $history->get_result();

// Stat card: total appointments
$q = $conn->prepare("SELECT COUNT(*) as total FROM appointments WHERE patient_id = ?");
$q->bind_param("i", $patient_id);
$q->execute();
$total_apt = $q->get_result()->fetch_assoc()['total'];

// Stat card: pending appointments
$q = $conn->prepare("SELECT COUNT(*) as total FROM appointments WHERE patient_id = ? AND status = 'Pending'");
$q->bind_param("i", $patient_id);
$q->execute();
$pending_apt = $q->get_result()->fetch_assoc()['total'];

// Stat card: total consultations
$q = $conn->prepare("SELECT COUNT(*) as total FROM consultations WHERE patient_id = ?");
$q->bind_param("i", $patient_id);
$q->execute();
$total_cons = $q->get_result()->fetch_assoc()['total'];

// Most recent consultation for the "Last Visit" display
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
    <title>Patient Portal | SPIST Clinic</title>
    <link rel="icon" type="image/png" href="SPISTLOGOPNG.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>

        /* =====================================================
           ROOT VARIABLES & RESET
           ===================================================== */
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

        /* =====================================================
        NAVBAR — matches the main dashboard style
        ===================================================== */
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

        /* Left: Logo + clinic name */
        .nav-brand {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .nav-brand img {
            width: 50px;
            height: auto;
            border-radius: 50%;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        .nav-brand h1 {
            margin: 0;
            font-size: 22px;
            letter-spacing: 1px;
            color: white;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5), 0 0 10px rgba(0, 166, 81, 0.3);
        }

        /* Right side wrapper */
        .nav-right {
            display: flex;
            align-items: center;
            gap: 20px;
            font-size: 14px;
        }

        /* Patient chip — matches the admin user-chip style */
        .nav-patient-name {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.1);
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            backdrop-filter: blur(8px);
        }

        /* Circular avatar — retained as requested */
        .nav-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary), var(--accent));
            display: grid;
            place-items: center;
            color: white;
            font-size: 18px;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(0, 166, 81, 0.35);
        }

        /* Two-line name + role label */
        .nav-patient-info {
            text-align: left;
            line-height: 1.3;
        }

        .nav-patient-fullname {
            font-weight: 700;
            font-size: 15px;
            display: block;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
        }

        .nav-patient-label {
            font-size: 13px;
            opacity: 0.85;
            display: block;
            color: #e6f7ff;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
        }

        /* Logout button */
        .logout-btn {
            background: #ff4d4d;
            padding: 10px 16px;
            border-radius: 10px;
            text-decoration: none;
            color: white;
            font-weight: bold;
            box-shadow: 0 6px 18px rgba(255, 77, 77, 0.22);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s ease, transform 0.2s ease;
        }

        .logout-btn:hover {
            background: #cc0000;
            transform: translateY(-2px);
        }

        /* =====================================================
           MAIN CONTAINER
           ===================================================== */
        .container {
            padding: 32px 40px;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* =====================================================
           WELCOME BANNER — greeting at the top
           ===================================================== */
        .welcome-banner {
            background: linear-gradient(135deg, rgba(0,100,50,0.6), rgba(0,166,81,0.4));
            border: 1px solid rgba(0,166,81,0.3);
            border-radius: 20px;
            padding: 24px 28px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
            flex-wrap: wrap;
            gap: 16px;
        }

        .welcome-banner h2 {
            margin: 0 0 6px;
            font-size: 22px;
            font-weight: 800;
        }

        .welcome-banner p {
            margin: 0;
            font-size: 13px;
            color: rgba(255,255,255,0.65);
        }

        .welcome-banner .last-visit {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.15);
            padding: 10px 16px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
        }

        .welcome-banner .last-visit i { color: var(--accent); }

        /* =====================================================
           FLASH ALERT — shown after form actions
           ===================================================== */
        .flash-alert {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px 20px;
            border-radius: 14px;
            margin-bottom: 22px;
            font-weight: 700;
            font-size: 14px;
            animation: slideDown 0.4s ease;
            box-shadow: 0 6px 20px rgba(0,0,0,0.2);
        }

        .flash-alert.success { background: linear-gradient(135deg, #2f9e44, #20c997); }
        .flash-alert.warning { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .flash-alert i { font-size: 18px; flex-shrink: 0; }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* =====================================================
           STAT CARDS — quick summary numbers
           ===================================================== */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--dark-glass);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 22px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        /* Subtle glow at the bottom of each stat card */
        .stat-card::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 3px;
            border-radius: 0 0 18px 18px;
        }

        .stat-card.green::after  { background: linear-gradient(90deg, #2f9e44, #20c997); }
        .stat-card.yellow::after { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .stat-card.blue::after   { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
        .stat-card.purple::after { background: linear-gradient(90deg, #8b5cf6, #a78bfa); }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 14px 32px rgba(0,0,0,0.25);
        }

        .stat-icon {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }

        .stat-icon.green  { background: rgba(32,201,151,0.18);  color: #20c997; }
        .stat-icon.yellow { background: rgba(251,191,36,0.18);  color: #fbbf24; }
        .stat-icon.blue   { background: rgba(59,130,246,0.18);  color: #60a5fa; }
        .stat-icon.purple { background: rgba(139,92,246,0.18);  color: #a78bfa; }

        .stat-info p {
            font-size: 11px;
            color: rgba(255,255,255,0.5);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            margin-bottom: 5px;
        }

        .stat-info h2 {
            font-size: 32px;
            font-weight: 900;
            color: white;
            line-height: 1;
        }

        /* =====================================================
           SECTION CARDS — main content containers
           ===================================================== */
        .card {
            background: var(--dark-glass);
            backdrop-filter: blur(14px);
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        /* Card header with title and optional right element */
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 22px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }

        .card-header h3 {
            font-size: 17px;
            font-weight: 700;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header h3 i { color: var(--secondary); }

        /* Small count pill in card headers */
        .count-pill {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.18);
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            color: rgba(255,255,255,0.8);
        }

        /* -----------------------------------------------------
        PROFILE GRID — equal balanced layout
        ----------------------------------------------------- */
        .profile-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
        }

        /* Address spans full width at the bottom */
        .profile-item.wide {
            grid-column: span 3;
            text-align: center;
        }

        .profile-item.wide span {
            justify-content: center;
        }

        .profile-item {
            background: linear-gradient(135deg, rgba(0,166,81,0.08), rgba(255,255,255,0.05));
            border: 1px solid rgba(0, 166, 81, 0.2);
            border-radius: 16px;
            padding: 18px 20px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }

        /* Green left accent bar */
        .profile-item::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 4px;
            background: linear-gradient(180deg, var(--secondary), var(--accent));
            border-radius: 16px 0 0 16px;
        }

        .profile-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
            border-color: rgba(0, 166, 81, 0.4);
        }

        /* Label row — icon + text */
        .profile-item span {
            font-size: 10px;
            font-weight: 700;
            color: var(--secondary);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .profile-item span i {
            font-size: 11px;
            opacity: 0.9;
        }

        /* Value text */
        .profile-item strong {
            font-size: 15px;
            font-weight: 600;
            color: white;
            word-break: break-word;
            padding-left: 4px;
        }

        /* =====================================================
           FORM INPUTS — dark-themed input fields
           ===================================================== */
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .form-group label {
            font-size: 11px;
            font-weight: 700;
            color: rgba(255,255,255,0.6);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-group label i { color: var(--secondary); }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 11px 14px;
            border: 1px solid rgba(255,255,255,0.18);
            border-radius: 12px;
            background: rgba(255,255,255,0.08);
            color: white;
            font-size: 14px;
            outline: none;
            width: 100%;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: rgba(255,255,255,0.3);
        }

        /* Highlight on focus */
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--secondary);
            background: rgba(255,255,255,0.12);
            box-shadow: 0 0 0 4px rgba(0,166,81,0.15);
        }

        /* Disabled/locked fields */
        .form-group input:disabled {
            background: rgba(255,255,255,0.04);
            color: rgba(255,255,255,0.3);
            cursor: not-allowed;
            border-style: dashed;
        }

        /* Two-column form layout */
        .form-grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        /* =====================================================
           BUTTONS
           ===================================================== */
        .btn {
            padding: 11px 22px;
            border: none;
            border-radius: 11px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease;
            color: white;
            text-decoration: none;
        }

        .btn:hover  { transform: translateY(-2px); }
        .btn:active { transform: translateY(0); opacity: 0.85; }

        .btn-green  { background: linear-gradient(135deg, #2f9e44, #20c997); box-shadow: 0 5px 16px rgba(32,201,151,0.28); }
        .btn-gray   { background: linear-gradient(135deg, #6c757d, #495057); box-shadow: 0 5px 16px rgba(73,80,87,0.25); }
        .btn-red    { background: linear-gradient(135deg, #e74c3c, #8b0000); box-shadow: 0 5px 16px rgba(139,0,0,0.28); }
        .btn-yellow { background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 5px 16px rgba(245,158,11,0.28); color: white; }
        .btn-small  { padding: 7px 14px; font-size: 12px; border-radius: 9px; }

        /* =====================================================
           TABLES
           ===================================================== */
        .table-responsive {
            overflow-x: auto;
            border-radius: 16px;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            border-radius: 16px;
            overflow: hidden;
        }

        /* Dark green sticky header */
        th {
            background: linear-gradient(135deg, #003d1f, #005c2e);
            color: white;
            padding: 15px 16px;
            font-size: 12px;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            position: sticky;
            top: 0;
        }

        td {
            padding: 14px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            font-size: 13px;
            color: rgba(255,255,255,0.88);
            background: rgba(255,255,255,0.04);
            vertical-align: middle;
        }

        tbody tr:nth-child(even) td { background: rgba(255,255,255,0.07); }

        tbody tr:hover td {
            background: rgba(0,166,81,0.1);
            transition: background 0.2s ease;
        }

        /* Centered empty state */
        .empty-row td {
            text-align: center;
            color: rgba(255,255,255,0.35);
            padding: 36px 20px;
            font-size: 14px;
        }

        .empty-row td i {
            display: block;
            font-size: 28px;
            margin-bottom: 10px;
            color: rgba(255,255,255,0.2);
        }

        /* =====================================================
           STATUS BADGES
           ===================================================== */
        .badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .badge-pending   { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; box-shadow: 0 2px 8px rgba(245,158,11,0.35); }
        .badge-approved  { background: linear-gradient(135deg, #22c55e, #15803d); color: white; box-shadow: 0 2px 8px rgba(34,197,94,0.35); }
        .badge-cancelled { background: linear-gradient(135deg, #ef4444, #991b1b); color: white; box-shadow: 0 2px 8px rgba(239,68,68,0.35); }

        /* =====================================================
           RESCHEDULE FORM — inline in appointment rows
           ===================================================== */
        /* Reschedule row — date picker + buttons in one line */
        .reschedule-form {
            display: inline-flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            margin-right: 6px; /* space between reschedule and cancel forms */
        }
        .reschedule-form input[type="date"] {
            padding: 7px 10px;
            border-radius: 9px;
            border: 1px solid rgba(255,255,255,0.2);
            background: rgba(255,255,255,0.08);
            color: white;
            font-size: 12px;
            outline: none;
            width: 140px;
            transition: border-color 0.2s ease;
        }

        .reschedule-form input[type="date"]:focus {
            border-color: var(--secondary);
        }

        /* Locked/read-only status */
        .locked-text {
            color: rgba(255,255,255,0.25);
            font-size: 12px;
            font-style: italic;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* =====================================================
           BOOKING FORM GRID
           ===================================================== */
        .booking-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 16px;
            align-items: end;
        }

        /* =====================================================
           SLOT GRID — time slot picker sa booking form
           ===================================================== */
        .slot-grid-label {
            font-size: 11px;
            font-weight: 700;
            color: rgba(255,255,255,0.6);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 10px;
        }
        .slot-grid-label i { color: var(--secondary); }

        /* slot grid styles are defined below with security/booking row */

        .pd-slot-item b {
            font-size: 13px;
            color: #20c997;
            margin-bottom: 3px;
            display: block;
            font-weight: 700;
        }

        .pd-slot-item span {
            font-size: 10px;
            color: rgba(255,255,255,0.5);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .pd-slot-item:hover:not(.pd-slot-full) {
            background: rgba(0,166,81,0.2);
            border-color: #00a651;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,166,81,0.2);
        }

        .pd-slot-item.pd-slot-selected {
            background: linear-gradient(135deg, #2f9e44, #20c997) !important;
            border-color: transparent !important;
            box-shadow: 0 4px 14px rgba(32,201,151,0.4);
        }

        .pd-slot-item.pd-slot-selected b,
        .pd-slot-item.pd-slot-selected span {
            color: #ffffff !important;
        }

        .pd-slot-item.pd-slot-full {
            background: rgba(255,255,255,0.04);
            border-color: rgba(255,255,255,0.08);
            cursor: not-allowed;
            opacity: 0.45;
        }

        .pd-slot-item.pd-slot-full b,
        .pd-slot-item.pd-slot-full span {
            color: rgba(255,255,255,0.3);
        }

        .pd-slot-placeholder {
            grid-column: 1/-1;
            text-align: center;
            font-size: 12px;
            color: rgba(255,255,255,0.35);
            font-style: italic;
            padding: 10px 0;
        }

        /* =====================================================
           FOOTER
           ===================================================== */
        .portal-footer {
            text-align: center;
            padding: 24px 0 10px;
            font-size: 12px;
            color: rgba(255,255,255,0.3);
            letter-spacing: 0.04em;
        }

        /* =====================================================
           PASSWORD STRENGTH METER (Change Password)
           ===================================================== */
        .cp-strength-bar {
            flex: 1;
            height: 5px;
            border-radius: 3px;
            background: rgba(255,255,255,0.1);
            transition: background 0.3s ease;
        }
        .cp-strength-bar.active-weak    { background: #e53935; }
        .cp-strength-bar.active-fair    { background: #fb8c00; }
        .cp-strength-bar.active-good    { background: #fdd835; }
        .cp-strength-bar.active-strong  { background: #43a047; }
        .cp-strength-bar.active-vstrong { background: #1b5e20; }

        .cp-req {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 2px 0;
            transition: color 0.25s;
        }
        .cp-req.unmet { color: rgba(255,255,255,0.35); }
        .cp-req.met   { color: #20c997; }
        .cp-req i { font-size: 10px; flex-shrink: 0; }

        /* =====================================================
           SECURITY + BOOKING ROW — responsive fix
           ===================================================== */
        .security-booking-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin-bottom: 24px;
        }

        @media (max-width: 900px) {
            .security-booking-row {
                grid-template-columns: 1fr;
            }
        }

        /* Full-width appointment booking card layout */
        .appointment-booking-inner {
            display: grid;
            grid-template-columns: 220px 1fr 220px;
            gap: 20px;
            align-items: start;
        }

        @media (max-width: 900px) {
            .appointment-booking-inner {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .card {
                padding: 16px;
                border-radius: 16px;
            }

            .card-header {
                align-items: flex-start;
                gap: 10px;
                flex-direction: column;
            }

            .form-grid-2 {
                grid-template-columns: 1fr;
            }

            input,
            select,
            textarea {
                min-height: 44px;
                font-size: 16px !important;
            }

            .appointment-booking-inner {
                gap: 12px;
            }

            #slotGrid,
            [id^="edit-slot-grid-"] {
                grid-template-columns: 1fr !important;
                min-width: 0 !important;
            }

            .table-responsive {
                overflow-x: auto;
            }

            .edit-cell input[type="date"],
            .edit-cell input[type="text"] {
                width: 160px !important;
            }

            .btn {
                min-height: 42px;
                justify-content: center;
            }
        }

        /* Appointment booking card — slot grid always contained */
        .booking-card-inner {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }

        #pdSlotGrid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            margin-bottom: 4px;
            width: 100%;
            box-sizing: border-box;
        }

        .pd-slot-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 10px 6px;
            border-radius: 10px;
            border: 1px solid rgba(0,166,81,0.35);
            background: rgba(0,166,81,0.08);
            cursor: pointer;
            transition: all 0.2s ease;
            min-width: 0; /* prevent overflow */
            overflow: hidden;
        }

    </style>
</head>
<body>

<!-- =====================================================
     NAVBAR
     ===================================================== -->
<div class="navbar">

    <!-- Left: Logo and clinic name -->
    <div class="nav-brand">
        <img src="SPISTLOGOPNG.png" alt="SPIST Logo">
        <h1>SPIST PATIENT PORTAL</h1>
    </div>

    <!-- Right: Patient chip + logout -->
    <div class="nav-right">
        <div class="nav-patient-name">
            <!-- Avatar icon — retained as requested -->
            <div class="nav-avatar">
                <i class="fa-solid fa-user"></i>
            </div>
            <!-- Two-line: full name on top, role label below -->
            <div class="nav-patient-info">
                <span class="nav-patient-fullname"><?= htmlspecialchars($patient['full_name'] ?? 'Patient') ?></span>
                <span class="nav-patient-label">Patient</span>
            </div>
        </div>
        <a href="logout.php" class="logout-btn">
            <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
    </div>

</div>


<!-- =====================================================
     MAIN CONTENT
     ===================================================== -->
<div class="container">

    <!-- ─── FLASH ALERT ────────────────────────────── -->
    <?php if (isset($_SESSION['flash_msg'])): ?>
        <?php
            $msg      = $_SESSION['flash_msg'];
            $msgType  = $_SESSION['flash_type'] ?? 'success';
          $msgTexts = [
                'booked'          => ['fa-circle-check',   'Appointment successfully booked!'],
                'rescheduled'     => ['fa-calendar-pen',   'Appointment date updated!'],
                'cancelled'       => ['fa-circle-xmark',   'Appointment has been cancelled.'],
                'profile_updated' => ['fa-user-check',     'Profile updated successfully!'],
                'code_generated'  => ['fa-key',            'New recovery code has been generated!'],
                'code_limit'      => ['fa-clock',          'You can only change your code every 24 hours.'],
                'slot_full'       => ['fa-triangle-exclamation', 'That time slot is already full (5/5). Please choose another.'],
                'invalid_date'    => ['fa-triangle-exclamation', 'Please choose a valid appointment date.'],
                'invalid_time'    => ['fa-triangle-exclamation', 'Please choose an hourly slot from 8:00 AM to 4:00 PM.'],
                'past_datetime'   => ['fa-triangle-exclamation', 'Please choose a future appointment date and time.'],
                'pw_changed'      => ['fa-lock',            'Password changed successfully!'],
                'pw_wrong'        => ['fa-circle-xmark',    'Current password is incorrect.'],
                'pw_mismatch'     => ['fa-circle-xmark',    'New passwords do not match.'],
                'pw_short'        => ['fa-circle-xmark',    'Password must be at least 8 characters long.'],
                'pw_no_upper'     => ['fa-circle-xmark',    'Password must include at least one uppercase letter (A–Z).'],
                'pw_no_lower'     => ['fa-circle-xmark',    'Password must include at least one lowercase letter (a–z).'],
                'pw_no_number'    => ['fa-circle-xmark',    'Password must include at least one number (0–9).'],
                'pw_no_special'   => ['fa-circle-xmark',    'Password must include at least one special character (!@#$%^&*).'],
                'pw_same'         => ['fa-circle-xmark',    'New password cannot be the same as your current password.'],
            ];
            $icon = $msgTexts[$msg][0] ?? 'fa-circle-check';
            $text = $msgTexts[$msg][1] ?? 'Action completed.';
        ?>
        <div class="flash-alert <?= $msgType ?>" id="flashAlert">
            <i class="fa-solid <?= $icon ?>"></i>
            <?= $text ?>
        </div>
        <?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>
    <?php endif; ?>


    <!-- ─── WELCOME BANNER ─────────────────────────── -->
    <div class="welcome-banner">
        <div>
            <h2>👋 Welcome back, <?= htmlspecialchars(explode(' ', $patient['full_name'] ?? 'Patient')[0]) ?>!</h2>
            <p>Here's a summary of your health activity at SPIST Clinic.</p>
        </div>
        <div class="last-visit">
            <i class="fa-solid fa-clock-rotate-left"></i>
            Last visit: <strong><?= $last_visit ?></strong>
        </div>
    </div>


    <!-- ─── STAT CARDS ─────────────────────────────── -->
    <div class="stat-grid">

        <!-- Total appointments -->
        <div class="stat-card green">
            <div class="stat-icon green">
                <i class="fa-solid fa-calendar-check"></i>
            </div>
            <div class="stat-info">
                <p>Total Appointments</p>
                <h2><?= $total_apt ?></h2>
            </div>
        </div>

        <!-- Pending appointments -->
        <div class="stat-card yellow">
            <div class="stat-icon yellow">
                <i class="fa-solid fa-hourglass-half"></i>
            </div>
            <div class="stat-info">
                <p>Pending</p>
                <h2><?= $pending_apt ?></h2>
            </div>
        </div>

        <!-- Total consultations -->
        <div class="stat-card blue">
            <div class="stat-icon blue">
                <i class="fa-solid fa-stethoscope"></i>
            </div>
            <div class="stat-info">
                <p>Consultations</p>
                <h2><?= $total_cons ?></h2>
            </div>
        </div>

        <!-- Last visit date -->
        <div class="stat-card purple">
            <div class="stat-icon purple">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <div class="stat-info">
                <p>Last Visit</p>
                <h2 style="font-size:15px; padding-top:4px;"><?= $last_visit ?></h2>
            </div>
        </div>

    </div>


    <!-- ─── MY PROFILE ──────────────────────────────── -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-circle-user"></i> My Profile</h3>
            <button onclick="toggleEdit()" id="editBtn" class="btn btn-gray btn-small">
                <i class="fa-solid fa-pen-to-square"></i> Edit Profile
            </button>
        </div>

        <!-- Read-only profile info grid -->
            <div id="profileView" class="profile-grid">

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

                <!-- Address spans all 3 columns for a clean bottom row -->
                <div class="profile-item wide">
                    <span><i class="fa-solid fa-map-marker-alt"></i> Address</span>
                    <strong><?= htmlspecialchars($patient['address'] ?? 'N/A') ?></strong>
                </div>

            </div>

        <!-- Edit profile form (hidden by default) -->
        <form id="profileEdit" method="POST" style="display:none; margin-top:4px;">
            <div class="form-grid-2">

                <div class="form-group">
                    <label><i class="fa-solid fa-user"></i> Full Name</label>
                    <input type="text" name="full_name"
                           value="<?= htmlspecialchars($patient['full_name'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label><i class="fa-solid fa-phone"></i> Contact Number</label>
                    <input type="text" id="contact_number" name="contact_number" inputmode="numeric" maxlength="11" pattern="^09[0-9]{9}$"
                           value="<?= htmlspecialchars($patient['contact_number'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label><i class="fa-solid fa-map-marker-alt"></i> Address</label>
                    <input type="text" name="address"
                           value="<?= htmlspecialchars($patient['address'] ?? '') ?>" required>
                </div>

                <!-- These fields are locked — admin manages them -->
                <div class="form-group">
                    <label><i class="fa-solid fa-lock"></i> Gender (Locked)</label>
                    <input type="text" value="<?= htmlspecialchars($patient['gender'] ?? '') ?>" disabled>
                </div>

                <div class="form-group">
                    <label><i class="fa-solid fa-lock"></i> Birthdate (Locked)</label>
                    <input type="text"
                           value="<?= isset($patient['birthdate']) ? date('M d, Y', strtotime($patient['birthdate'])) : '' ?>"
                           disabled>
                </div>

            </div>

            <div style="display:flex; gap:12px; flex-wrap:wrap;">
                <button type="submit" name="update_profile" class="btn btn-green">
                    <i class="fa-solid fa-save"></i> Save Changes
                </button>
                <button type="button" onclick="toggleEdit()" class="btn btn-gray">
                    <i class="fa-solid fa-times"></i> Cancel
                </button>
            </div>
        </form>
    </div>


    <!-- ─── SECURITY & BOOKING ROW ─── -->
<div class="security-booking-row">

    <!-- Left side: Recovery Codes -->
    <div class="card" style="margin-bottom: 0; display: flex; flex-direction: column;">
        <div class="card-header">
            <h3><i class="fa-solid fa-key"></i> Security Codes</h3>
        </div>
        
        <div style="background: rgba(0,0,0,0.2); padding: 18px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.1); flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between; min-height: 250px;">
            <div>
                <p style="font-size: 12px; color: #94a3b8; margin-bottom: 12px;">
                    <i class="fa-solid fa-user-shield"></i> Hidden for privacy. Click "Reveal" to see.
                </p>

                <div id="codes-container" style="display: none; margin-bottom: 15px;">
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;">
                        <?php 
                        $my_codes = json_decode($patient['recovery_code'] ?? '[]', true);
                        if (!empty($my_codes)): 
                            foreach($my_codes as $c): ?>
                                <div style="background: #1e293b; padding: 8px; border: 1px solid var(--secondary); border-radius: 8px; text-align: center;">
                                    <span style="font-family: monospace; font-weight: bold; color: var(--accent); font-size: 13px;"><?= $c ?></span>
                                </div>
                            <?php endforeach; 
                        else: ?>
                            <p style="color: #64748b; font-size: 12px;">No codes found.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 10px;">
                <div style="display: flex; gap: 8px;">
                    <button type="button" onclick="toggleCodes()" id="btn-reveal" class="btn btn-small" style="background: #475569; flex: 1; justify-content: center;">
                        <i class="fa-solid fa-eye"></i> Reveal
                    </button>

                    <form method="POST" style="margin:0; flex: 1;">
                        <?php 
                            $last_time = !empty($patient['last_code_change']) ? strtotime($patient['last_code_change']) : 0;
                            $is_locked = (time() - $last_time < 86400);
                        ?>
                        <button type="submit" name="generate_code" class="btn btn-yellow btn-small" <?= $is_locked ? 'disabled' : '' ?>
                                style="width: 100%; justify-content: center; <?= $is_locked ? 'opacity: 0.5; cursor: not-allowed;' : '' ?>">
                            <i class="fa-solid fa-arrows-rotate"></i> Refresh
                        </button>
                    </form>
                </div>
                <?php if ($is_locked): ?>
                    <span style="font-size: 10px; color: #fbbf24; text-align: center;">
                        <i class="fa-solid fa-lock"></i> Locked: <?= round((86400 - (time() - $last_time)) / 3600, 1) ?>h remaining
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right side: Change Password -->
    <div class="card" style="margin-bottom: 0; display: flex; flex-direction: column;">
        <div class="card-header">
            <h3><i class="fa-solid fa-lock"></i> Change Password</h3>
        </div>
        <div style="background: rgba(0,0,0,0.2); padding: 18px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.1); flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between; min-height: 250px;">
            <form method="POST" id="changePasswordForm" onsubmit="return validateCpForm()">
                <div style="display: flex; flex-direction: column; gap: 12px;">

                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="font-size:12px; color:#94a3b8; margin-bottom:5px; display:block;">
                            <i class="fa-solid fa-lock"></i> Current Password
                        </label>
                        <div style="position:relative;">
                            <input type="password" name="current_password" id="cpCurrent" required
                                   oninput="cpCheckSameAsCurrent()"
                                   style="width:100%; padding:9px 38px 9px 12px; border-radius:8px; border:1px solid rgba(255,255,255,0.15); background:rgba(0,0,0,0.3); color:white; font-size:13px;">
                            <i class="fa-solid fa-eye" onclick="togglePwVis('cpCurrent', this)"
                               style="position:absolute; right:10px; top:50%; transform:translateY(-50%); cursor:pointer; color:#64748b; font-size:13px;"></i>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="font-size:12px; color:#94a3b8; margin-bottom:5px; display:block;">
                            <i class="fa-solid fa-key"></i> New Password
                        </label>
                        <div style="position:relative;">
                            <input type="password" name="new_password" id="cpNew" required
                                   oninput="cpCheckStrength(this); cpCheckSameAsCurrent()"
                                   style="width:100%; padding:9px 38px 9px 12px; border-radius:8px; border:1px solid rgba(255,255,255,0.15); background:rgba(0,0,0,0.3); color:white; font-size:13px;">
                            <i class="fa-solid fa-eye" onclick="togglePwVis('cpNew', this)"
                               style="position:absolute; right:10px; top:50%; transform:translateY(-50%); cursor:pointer; color:#64748b; font-size:13px;"></i>
                        </div>
                        <!-- Same-as-current warning -->
                        <div id="cpSameWarning" style="display:none; margin-top:6px; padding:8px 12px; background:rgba(239,68,68,0.12); border-left:3px solid #ef4444; border-radius:0 7px 7px 0; font-size:11.5px; color:#fca5a5; display:none; align-items:center; gap:7px;">
                            <i class="fa-solid fa-circle-exclamation" style="font-size:13px; color:#ef4444; flex-shrink:0;"></i>
                            <span>Same password — please choose a different one.</span>
                        </div>
                        <!-- Strength bars -->
                        <div id="cp_strength" style="display:none; margin-top:8px;">
                            <div style="display:flex; gap:4px; margin-bottom:4px;">
                                <div id="cp_bar1" class="cp-strength-bar"></div>
                                <div id="cp_bar2" class="cp-strength-bar"></div>
                                <div id="cp_bar3" class="cp-strength-bar"></div>
                                <div id="cp_bar4" class="cp-strength-bar"></div>
                                <div id="cp_bar5" class="cp-strength-bar"></div>
                                <span id="cp_strength_label" style="font-size:11px; font-weight:700; margin-left:6px; align-self:center;"></span>
                            </div>
                            <!-- Requirements checklist -->
                            <div id="cp_requirements" style="display:flex; flex-direction:column; gap:3px; font-size:11px;">
                                <div id="cp_req_length"  class="cp-req unmet"><i class="fa-solid fa-circle-xmark"></i> At least 8 characters</div>
                                <div id="cp_req_upper"   class="cp-req unmet"><i class="fa-solid fa-circle-xmark"></i> One uppercase letter (A–Z)</div>
                                <div id="cp_req_lower"   class="cp-req unmet"><i class="fa-solid fa-circle-xmark"></i> One lowercase letter (a–z)</div>
                                <div id="cp_req_number"  class="cp-req unmet"><i class="fa-solid fa-circle-xmark"></i> One number (0–9)</div>
                                <div id="cp_req_special" class="cp-req unmet"><i class="fa-solid fa-circle-xmark"></i> One special character (!@#$%^&*)</div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="font-size:12px; color:#94a3b8; margin-bottom:5px; display:block;">
                            <i class="fa-solid fa-key"></i> Confirm New Password
                        </label>
                        <div style="position:relative;">
                            <input type="password" name="confirm_password" id="cpConfirm" required
                                   style="width:100%; padding:9px 38px 9px 12px; border-radius:8px; border:1px solid rgba(255,255,255,0.15); background:rgba(0,0,0,0.3); color:white; font-size:13px;">
                            <i class="fa-solid fa-eye" onclick="togglePwVis('cpConfirm', this)"
                               style="position:absolute; right:10px; top:50%; transform:translateY(-50%); cursor:pointer; color:#64748b; font-size:13px;"></i>
                        </div>
                    </div>

                    <button type="submit" name="change_password" class="btn btn-green btn-small"
                            style="width:100%; justify-content:center; margin-top:4px;">
                        <i class="fa-solid fa-floppy-disk"></i> Update Password
                    </button>

                </div>
            </form>
        </div>
    </div>

</div>
<!-- END security-booking-row -->


    <!-- ─── REQUEST APPOINTMENT (Full Width) ───────────── -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header">
            <h3><i class="fa-solid fa-calendar-plus"></i> Request Appointment</h3>
        </div>
        <form method="POST" id="pdBookingForm">
            <div class="appointment-booking-inner">

                <!-- DATE PICKER -->
                <div class="form-group">
                    <label><i class="fa-solid fa-calendar"></i> Date</label>
                    <input type="date" name="apt_date" id="pdAptDate" required min="<?= date('Y-m-d') ?>"
                           oninput="pdLoadSlots(this.value)">
                </div>

                <!-- SLOT GRID -->
                <div>
                    <div class="slot-grid-label">
                        <i class="fa-solid fa-clock"></i> Select Time Slot (8AM – 5PM)
                    </div>
                    <div id="pdSlotGrid">
                        <p class="pd-slot-placeholder">Please select a date first to see available slots...</p>
                    </div>
                    <input type="hidden" name="apt_time" id="pdAptTime" required>
                </div>

                <!-- REASON + SUBMIT -->
                <div style="display:flex; flex-direction:column; gap:14px; justify-content:flex-end;">
                    <div class="form-group">
                        <label><i class="fa-solid fa-notes-medical"></i> Reason for Visit</label>
                        <input type="text" name="apt_reason" placeholder="e.g. Cough, Check-up" required>
                    </div>
                    <button type="submit" name="book_apt" class="btn btn-green" style="width:100%; justify-content:center;">
                        <i class="fa-solid fa-paper-plane"></i> Book Appointment
                    </button>
                </div>

            </div>
        </form>
    </div>

    <!-- ─── MY APPOINTMENTS ─────────────────────────── -->
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
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($appointment_result->num_rows > 0): ?>
                        <?php while ($row = $appointment_result->fetch_assoc()): ?>
                        <tr id="apt-row-<?= $row['appointment_id'] ?>">
                            <!-- View mode cells -->
                            <td class="view-cell" data-field="date">
                                <strong><?= date('M d, Y', strtotime($row['appointment_date'])) ?></strong>
                            </td>
                            <td class="view-cell" data-field="time">
                                <?= date('h:i A', strtotime($row['appointment_time'])) ?>
                            </td>
                            <td class="view-cell" data-field="reason">
                                <?= htmlspecialchars($row['reason']) ?>
                            </td>
                            <td class="view-cell" data-field="status">
                                <?php $status = strtolower($row['status']); ?>
                                <span class="badge badge-<?= $status ?>">
                                    <?php
                                        // Add icon based on status
                                        if ($status === 'pending')   echo '<i class="fa-solid fa-hourglass-half"></i> ';
                                        if ($status === 'approved')  echo '<i class="fa-solid fa-circle-check"></i> ';
                                        if ($status === 'cancelled') echo '<i class="fa-solid fa-circle-xmark"></i> ';
                                    ?>
                                    <?= htmlspecialchars($row['status']) ?>
                                </span>
                            </td>
                            <td class="view-cell" data-field="actions">
                                <?php if ($row['status'] === 'Pending'): ?>
                                    <div style="display:inline-flex; gap:7px; align-items:center; flex-wrap:wrap;">
                                        <!-- Edit button for inline editing -->
                                        <button type="button" class="btn btn-green btn-small"
                                            onclick="enableInlineEdit(<?= $row['appointment_id'] ?>, '<?= $row['appointment_date'] ?>', '<?= date('H:00', strtotime($row['appointment_time'])) ?>', <?= htmlspecialchars(json_encode($row['reason']), ENT_QUOTES) ?>)">
                                            <i class="fa-solid fa-pen-to-square"></i> Edit
                                        </button>
                                        <!-- Cancel appointment -->
                                        <form method="POST" style="margin:0;"
                                            onsubmit="return confirm('Are you sure you want to cancel this appointment?')">
                                            <input type="hidden" name="apt_id" value="<?= $row['appointment_id'] ?>">
                                            <button type="submit" name="cancel_apt" class="btn btn-red btn-small">
                                                <i class="fa-solid fa-circle-xmark"></i> Cancel
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="locked-text">
                                        <i class="fa-solid fa-lock"></i> Locked
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Edit mode cells (hidden by default) -->
                            <td class="edit-cell" data-field="date" style="display:none;">
                                <input type="date" name="edit_date" id="edit-date-<?= $row['appointment_id'] ?>" 
                                       value="<?= $row['appointment_date'] ?>" min="<?= date('Y-m-d') ?>"
                                       onchange="loadEditSlots(<?= $row['appointment_id'] ?>, this.value)"
                                       style="padding:6px 8px; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:rgba(255,255,255,0.1); color:white; font-size:12px; width:120px;">
                            </td>
                            <td class="edit-cell" data-field="time" style="display:none;">
                                <div id="edit-slot-grid-<?= $row['appointment_id'] ?>" style="display:grid; grid-template-columns: repeat(3, 1fr); gap:4px; min-width:150px;">
                                    <!-- Slots will be loaded here -->
                                </div>
                                <input type="hidden" name="edit_time" id="edit-time-<?= $row['appointment_id'] ?>">
                            </td>
                            <td class="edit-cell" data-field="reason" style="display:none;">
                                <input type="text" name="edit_reason" id="edit-reason-<?= $row['appointment_id'] ?>" 
                                       value="<?= htmlspecialchars($row['reason']) ?>"
                                       placeholder="Reason for visit"
                                       style="padding:6px 8px; border-radius:6px; border:1px solid rgba(255,255,255,0.2); background:rgba(255,255,255,0.1); color:white; font-size:12px; width:150px;">
                            </td>
                            <td class="edit-cell" data-field="status" style="display:none;">
                                <span class="badge badge-pending">
                                    <i class="fa-solid fa-hourglass-half"></i> Pending
                                </span>
                            </td>
                            <td class="edit-cell" data-field="actions" style="display:none;">
                                <div style="display:inline-flex; gap:6px; align-items:center;">
                                    <button type="button" class="btn btn-green btn-small" 
                                            onclick="updateAppointment(<?= $row['appointment_id'] ?>)">
                                        <i class="fa-solid fa-save"></i> Update
                                    </button>
                                    <button type="button" class="btn btn-gray btn-small" 
                                            onclick="cancelInlineEdit(<?= $row['appointment_id'] ?>)">
                                        <i class="fa-solid fa-times"></i> Cancel
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr class="empty-row">
                            <td colspan="5">
                                <i class="fa-solid fa-calendar-xmark"></i>
                                No appointments found. Book one above!
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>


    <!-- ─── CONSULTATION HISTORY ────────────────────── -->
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
                        <th>Consulted By</th>
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
                            <td><i class="fa-solid fa-user-nurse" style="color:#20c997; margin-right:5px;"></i><?= htmlspecialchars($row['nurse_name']) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr class="empty-row">
                            <td colspan="5">
                                <i class="fa-solid fa-file-circle-xmark"></i>
                                No consultation history available yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>


    <!-- ─── FOOTER ──────────────────────────────────── -->
    <div class="portal-footer">
        &copy; <?= date('Y') ?> SPIST Clinic &mdash; Patient Portal &mdash; All rights reserved.
    </div>

</div>



<!-- =====================================================
     JAVASCRIPT
     ===================================================== -->
<script>
    /* -----------------------------------------------------
       CHANGE PASSWORD: TOGGLE SHOW/HIDE
       ----------------------------------------------------- */
    function togglePwVis(inputId, iconEl) {
        var input = document.getElementById(inputId);
        if (input.type === 'password') {
            input.type = 'text';
            iconEl.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            iconEl.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }

    /* -----------------------------------------------------
       CHANGE PASSWORD: WARN IF SAME AS CURRENT
       ----------------------------------------------------- */
    function cpCheckSameAsCurrent() {
        var currPw  = document.getElementById('cpCurrent').value;
        var newPw   = document.getElementById('cpNew').value;
        var warning = document.getElementById('cpSameWarning');
        var newInput = document.getElementById('cpNew');
        if (currPw.length > 0 && newPw.length > 0 && newPw === currPw) {
            warning.style.display = 'flex';
            newInput.style.borderColor = 'rgba(239,68,68,0.6)';
        } else {
            warning.style.display = 'none';
            newInput.style.borderColor = 'rgba(255,255,255,0.15)';
        }
    }

    /* -----------------------------------------------------
       CHANGE PASSWORD: STRENGTH METER (same as register.php)
       ----------------------------------------------------- */
    function cpCheckStrength(input) {
        var password    = input.value;
        var strengthDiv = document.getElementById('cp_strength');
        var reqDiv      = document.getElementById('cp_requirements');

        if (password.length === 0) {
            strengthDiv.style.display = 'none';
            return;
        }
        strengthDiv.style.display = 'block';

        var checks = {
            length:  password.length >= 8,
            upper:   /[A-Z]/.test(password),
            lower:   /[a-z]/.test(password),
            number:  /[0-9]/.test(password),
            special: /[!@#$%^&*]/.test(password)
        };

        cpUpdateReq('cp_req_length',  checks.length);
        cpUpdateReq('cp_req_upper',   checks.upper);
        cpUpdateReq('cp_req_lower',   checks.lower);
        cpUpdateReq('cp_req_number',  checks.number);
        cpUpdateReq('cp_req_special', checks.special);

        var score = Object.values(checks).filter(Boolean).length;
        if (password.length >= 12) score = Math.min(score + 1, 5);

        var levels = [
            { label: '',           barClass: '' },
            { label: 'Very Weak',  barClass: 'active-weak' },
            { label: 'Weak',       barClass: 'active-weak' },
            { label: 'Fair',       barClass: 'active-fair' },
            { label: 'Good',       barClass: 'active-good' },
            { label: 'Strong',     barClass: 'active-strong' },
        ];

        var allMet    = Object.values(checks).every(Boolean);
        var barClass  = levels[Math.min(score, 5)].barClass;
        var labelText = levels[Math.min(score, 5)].label;

        if (allMet && password.length >= 12) {
            labelText = 'Very Strong';
            barClass  = 'active-vstrong';
        }

        for (var i = 1; i <= 5; i++) {
            var bar = document.getElementById('cp_bar' + i);
            bar.className = 'cp-strength-bar' + (i <= score ? ' ' + barClass : '');
        }

        var colorMap = {
            'active-weak':    '#e53935',
            'active-fair':    '#fb8c00',
            'active-good':    '#fdd835',
            'active-strong':  '#43a047',
            'active-vstrong': '#1b5e20'
        };
        var labelEl = document.getElementById('cp_strength_label');
        labelEl.textContent = labelText;
        labelEl.style.color = colorMap[barClass] || '#888';
    }

    function cpUpdateReq(id, met) {
        var el   = document.getElementById(id);
        var icon = el.querySelector('i');
        if (met) {
            el.classList.add('met'); el.classList.remove('unmet');
            icon.className = 'fa-solid fa-circle-check';
        } else {
            el.classList.remove('met'); el.classList.add('unmet');
            icon.className = 'fa-solid fa-circle-xmark';
        }
    }

    /* -----------------------------------------------------
       CHANGE PASSWORD: CLIENT-SIDE VALIDATION BEFORE SUBMIT
       ----------------------------------------------------- */
    function validateCpForm() {
        var currPw = document.getElementById('cpCurrent').value;
        var newPw  = document.getElementById('cpNew').value;
        var conf   = document.getElementById('cpConfirm').value;

        if (newPw.length < 8)                        { alert('Password must be at least 8 characters long.');                         return false; }
        if (!/[A-Z]/.test(newPw))                    { alert('Password must include at least one uppercase letter (A–Z).');           return false; }
        if (!/[a-z]/.test(newPw))                    { alert('Password must include at least one lowercase letter (a–z).');           return false; }
        if (!/[0-9]/.test(newPw))                    { alert('Password must include at least one number (0–9).');                     return false; }
        if (!/[!@#$%^&*]/.test(newPw))               { alert('Password must include at least one special character (!@#$%^&*).');     return false; }
        if (newPw === currPw)                         { alert('New password cannot be the same as your current password.');            return false; }
        if (newPw !== conf)                           { alert('New passwords do not match.');                                          return false; }
        return true;
    }

    /* -----------------------------------------------------
       SECURITY: TOGGLE REVEAL/HIDE CODES
       ----------------------------------------------------- */
    function toggleCodes() {
        var container = document.getElementById('codes-container');
        var btn = document.getElementById('btn-reveal');
        
        // Chino-check kung nakatago o hindi ang container
        if (container.style.display === "none" || container.style.display === "") {
            container.style.display = "block";
            btn.innerHTML = '<i class="fa-solid fa-eye-slash"></i> Hide Codes';
        } else {
            container.style.display = "none";
            btn.innerHTML = '<i class="fa-solid fa-eye"></i> Reveal Codes';
        }
    }

    /* -----------------------------------------------------
       INLINE APPOINTMENT EDITING FUNCTIONS
       ----------------------------------------------------- */
       
    // Store original values for cancel functionality
    var originalAppointmentData = {};
    
    // Enable inline edit mode for an appointment
    function enableInlineEdit(aptId, date, time, reason) {
        // Store original values
        originalAppointmentData[aptId] = {
            date: date,
            time: time,
            reason: reason
        };
        
        // Hide view cells and show edit cells
        var row = document.getElementById('apt-row-' + aptId);
        var viewCells = row.querySelectorAll('.view-cell');
        var editCells = row.querySelectorAll('.edit-cell');
        
        viewCells.forEach(function(cell) {
            cell.style.display = 'none';
        });
        
        editCells.forEach(function(cell) {
            cell.style.display = 'table-cell';
        });
        
        // Load time slots for the current date
        loadEditSlots(aptId, date);
    }
    
    // Cancel inline edit mode
    function cancelInlineEdit(aptId) {
        var row = document.getElementById('apt-row-' + aptId);
        var viewCells = row.querySelectorAll('.view-cell');
        var editCells = row.querySelectorAll('.edit-cell');
        
        // Restore original values
        if (originalAppointmentData[aptId]) {
            document.getElementById('edit-date-' + aptId).value = originalAppointmentData[aptId].date;
            document.getElementById('edit-time-' + aptId).value = originalAppointmentData[aptId].time;
            document.getElementById('edit-reason-' + aptId).value = originalAppointmentData[aptId].reason;
        }
        
        // Show view cells and hide edit cells
        viewCells.forEach(function(cell) {
            cell.style.display = '';
        });
        
        editCells.forEach(function(cell) {
            cell.style.display = 'none';
        });
        
        // Clear stored data
        delete originalAppointmentData[aptId];
    }
    
    // Load time slots for edit mode
    function loadEditSlots(aptId, date) {
        var grid = document.getElementById('edit-slot-grid-' + aptId);
        var timeInput = document.getElementById('edit-time-' + aptId);
        
        if (!date) {
            grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; font-size:11px; color:rgba(255,255,255,0.5);">Select date first</div>';
            timeInput.value = '';
            return;
        }
        
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; font-size:11px; color:rgba(255,255,255,0.5);">Loading...</div>';
        timeInput.value = '';
        
        fetch('p_dashboard.php?action=get_slots&date=' + date)
            .then(function(r) { return r.json(); })
            .then(function(slots) {
                grid.innerHTML = '';
                var currentTime = (originalAppointmentData[aptId] ? originalAppointmentData[aptId].time : timeInput.value) || '';
                
                slots.forEach(function(s) {
                    var div = document.createElement('div');
                    var isSelected = (s.time === currentTime);
                    var isClickable = !s.is_full || isSelected;
                    var statusLabel = s.is_past ? 'PASSED' : (s.is_full ? 'FULL' : (s.taken + '/' + s.limit));

                    div.className = 'pd-slot-item' + (s.is_full && !isSelected ? ' pd-slot-full' : '') + (isSelected ? ' pd-slot-selected' : '');
                    div.dataset.time = s.time;
                    div.style.cssText = 'padding:6px 4px; border-radius:6px; text-align:center; font-size:10px;';
                    div.innerHTML = '<b style="font-size:11px;">' + s.display + '</b><span style="font-size:9px; opacity:0.75; display:block;">' + statusLabel + '</span>';

                    if (isSelected) {
                        timeInput.value = s.time;
                    }

                    if (isClickable) {
                        div.onclick = function() {
                            grid.querySelectorAll('.pd-slot-item').forEach(function(el) {
                                el.classList.remove('pd-slot-selected');
                            });
                            div.classList.remove('pd-slot-full');
                            div.classList.add('pd-slot-selected');
                            timeInput.value = s.time;
                        };
                    }

                    grid.appendChild(div);
                });
            })
            .catch(function() {
                grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; font-size:11px; color:rgba(255,255,255,0.5);">Failed to load slots</div>';
            });
    }
    
    // Update appointment via AJAX/form submission
    function updateAppointment(aptId) {
        var date = document.getElementById('edit-date-' + aptId).value;
        var time = document.getElementById('edit-time-' + aptId).value;
        var reason = document.getElementById('edit-reason-' + aptId).value;
        
        // Validation
        if (!date) {
            alert('Please select a date.');
            return;
        }
        if (time && new Date(date + 'T' + time) <= new Date()) {
            alert('Please choose a future appointment date and time.');
            return;
        }
        if (!time) {
            alert('Please select a time slot.');
            return;
        }
        if (!reason.trim()) {
            alert('Please enter a reason for the appointment.');
            return;
        }
        
        // Create form and submit
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = 'p_dashboard.php';
        form.style.display = 'none';
        
        form.appendChild(createHiddenInput('update_date', '1'));
        form.appendChild(createHiddenInput('apt_id', aptId));
        form.appendChild(createHiddenInput('new_date', date));
        form.appendChild(createHiddenInput('new_time', time));
        form.appendChild(createHiddenInput('new_reason', reason));
        
        document.body.appendChild(form);
        form.submit();
    }
    
    // Helper function to create hidden input
    function createHiddenInput(name, value) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        return input;
    }

    /* -----------------------------------------------------
       PROFILE: TOGGLE BETWEEN VIEW AND EDIT MODE
       ----------------------------------------------------- */
    function toggleEdit() {
        var view = document.getElementById('profileView');
        var edit = document.getElementById('profileEdit');
        var btn  = document.getElementById('editBtn');

        if (edit.style.display === 'none' || edit.style.display === '') {
            view.style.display = 'none';
            edit.style.display = 'block';
            btn.innerHTML      = '<i class="fa-solid fa-xmark"></i> Cancel';
            btn.className      = 'btn btn-red btn-small';
        } else {
            view.style.display = 'grid';
            edit.style.display = 'none';
            btn.innerHTML      = '<i class="fa-solid fa-pen-to-square"></i> Edit Profile';
            btn.className      = 'btn btn-gray btn-small';
        }
    }

    const contactInput = document.getElementById('contact_number');

contactInput.addEventListener('input', function (e) {
    let value = e.target.value.replace(/\D/g, ''); // Remove all non-numeric characters

    // 1. Force the first digit to be '0'
    if (value.length > 0 && value[0] !== '0') {
        value = '';
    }
    
    // 2. Force the second digit to be '9'
    if (value.length > 1 && value[1] !== '9') {
        value = '0'; 
    }

    // 3. Limit to 11 digits
    if (value.length > 11) {
        value = value.slice(0, 11);
    }

    e.target.value = value;

});

    /* -----------------------------------------------------
       SLOT GRID — load available time slots sa booking form
       ----------------------------------------------------- */
    function pdLoadSlots(date) {
        var grid = document.getElementById('pdSlotGrid');
        var timeInput = document.getElementById('pdAptTime');
        if (!date) {
            grid.innerHTML = '<p class="pd-slot-placeholder">Please select a date first to see available slots...</p>';
            timeInput.value = '';
            return;
        }
        grid.innerHTML = '<p class="pd-slot-placeholder">Checking availability...</p>';
        timeInput.value = '';

        fetch('p_dashboard.php?action=get_slots&date=' + date)
            .then(function(r) { return r.json(); })
            .then(function(slots) {
                grid.innerHTML = '';
                slots.forEach(function(s) {
                    var div = document.createElement('div');
                    div.className = 'pd-slot-item' + (s.is_full ? ' pd-slot-full' : '');
                    var statusLabel = s.is_past ? 'PASSED' : (s.is_full ? 'FULL' : (s.taken + '/' + s.limit + ' SLOTS'));
                    div.innerHTML = '<b>' + s.display + '</b><span>' + statusLabel + '</span>';
                    if (!s.is_full) {
                        div.onclick = function() {
                            document.querySelectorAll('.pd-slot-item').forEach(function(el) {
                                el.classList.remove('pd-slot-selected');
                            });
                            div.classList.add('pd-slot-selected');
                            timeInput.value = s.time;
                        };
                    }
                    grid.appendChild(div);
                });
            })
            .catch(function() {
                grid.innerHTML = '<p class="pd-slot-placeholder">Failed to load slots. Please try again.</p>';
            });
    }

    // Validation: huwag ma-submit kung walang slot na pinili
    document.getElementById('pdBookingForm').addEventListener('submit', function(e) {
        var timeInput = document.getElementById('pdAptTime');
        var dateInput = document.getElementById('pdAptDate');
        if (dateInput && timeInput.value && new Date(dateInput.value + 'T' + timeInput.value) <= new Date()) {
            e.preventDefault();
            alert('Please choose a future appointment date and time.');
            return;
        }
        if (!timeInput.value) {
            e.preventDefault();
            alert('Please select a time slot before booking.');
        }
    });

    var flashAlert = document.getElementById('flashAlert');
    if (flashAlert) {
        setTimeout(function () {
            flashAlert.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            flashAlert.style.opacity    = '0';
            flashAlert.style.transform  = 'translateY(-10px)';
            setTimeout(function () { flashAlert.remove(); }, 500);
        }, 5000);
    }
</script>

</body>
</html>
