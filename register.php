<?php
/* ============================================================
 * register.php — Patient Self-Registration Portal
 *
 * Three modes controlled by ?mode=:
 * selection — Choose existing or new patient
 * existing  — Link account to an existing clinic record
 * new       — Full new patient registration
 *
 * UX improvement:
 * - On username taken     → only username field is cleared
 * - On password mismatch  → only both password fields cleared
 * - All other fields keep their values (no frustrating re-typing)
 * - Client-side JS catches password mismatch BEFORE submission
 * - PHP remains the authoritative validator (server-side safety net)
 * ============================================================ */

session_start();
require 'db.php'; // Assumes this defines your database connection variable as $conn (or $db)

$success = '';
$error   = '';

/* Which error type so JS knows what to clear */
$error_type = ''; // 'username' | 'password_mismatch' | 'password_weak' | 'other'

$mode = $_GET['mode'] ?? 'selection';

/* ── Preserve form values across POST so user doesn't re-type ──
 * Collected here so PHP templates can echo them back into inputs.
 * Password fields are intentionally NOT preserved for security.
 * ────────────────────────────────────────────────────────────── */
$prev = [
    // Shared
    'full_name'   => '',
    'username'    => '',
    'search_val'  => '',
    'agree_terms' => false,
    // New patient
    'first_name'  => '',
    'last_name'   => '',
    'id_number'   => '',
    'gender'      => 'Male',
    'birthdate'   => '',
    'category'    => 'Student',
    'contact'     => '',
    'address'     => '',
    'sec_question1' => '',
    'sec_answer1'   => '',
    'sec_question2' => '',
    'sec_answer2'   => '',
];


/* ============================================================
 * PASSWORD VALIDATION HELPER
 * Returns an error string, or null if the password is valid.
 * ============================================================ */
function validateUsername(string $username): ?string {
    if (strlen($username) < 6) {
        return 'Username must be at least 6 characters long.';
    }
    return null;
}

function validateBirthdateValue(string $birthdate): ?string {
    $dt = DateTime::createFromFormat('Y-m-d', $birthdate);
    $errors = DateTime::getLastErrors();
    if (!$dt || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $dt->format('Y-m-d') !== $birthdate) {
        return 'Please enter a valid birthdate.';
    }
    $today = new DateTime('today');
    if ($dt > $today) {
        return 'Birthdate cannot be in the future.';
    }
    if ($dt < (clone $today)->modify('-120 years')) {
        return 'Birthdate cannot be more than 120 years ago.';
    }
    return null;
}

function validatePassword(string $password): ?string {
    if (strlen($password) < 8)
        return 'Password must be at least 8 characters long.';
    if (!preg_match('/[A-Z]/', $password))
        return 'Password must include at least one uppercase letter (A–Z).';
    if (!preg_match('/[a-z]/', $password))
        return 'Password must include at least one lowercase letter (a–z).';
    if (!preg_match('/[0-9]/', $password))
        return 'Password must include at least one number (0–9).';
    if (!preg_match('/[!@#$%^&*]/', $password))
        return 'Password must include at least one special character (!@#$%^&*).';
    return null;
}


/* ============================================================
 * AJAX ENDPOINT: AUTO-INCREMENT ID GENERATOR (MM-YYYY-XX)
 * Formats: Month (MM) - Year (YYYY) - Sequence (XX)
 * Sequence resets to 01 when a new month begins.
 * ============================================================ */
if (isset($_GET['action']) && $_GET['action'] === 'get_next_id') {
    header('Content-Type: application/json');

    $current_month = date('m'); // "05"
    $current_year  = date('Y'); // "2026"
    $pattern       = "{$current_month}-{$current_year}-%"; // e.g., "05-2026-%"

    // Retrieve the highest existing sequence format for this specific month & year
    $query = "SELECT id_number FROM patients WHERE id_number LIKE ? ORDER BY id_number DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $pattern);
    $stmt->execute();
    $result = $stmt->get_result();

    $next_seq = 1; // Default to starting number "01"

    if ($row = $result->fetch_assoc()) {
        $last_id = $row['id_number']; // e.g., "05-2026-01"
        $parts = explode('-', $last_id);
        
        if (count($parts) === 3) {
            $last_seq = intval($parts[2]); // Extract "01" -> 1
            $next_seq = $last_seq + 1;     // Increment -> 2
        }
    }

    // Zero-pad sequence back to a 2-digit format (e.g., 2 -> "02")
    $padded_seq = str_pad($next_seq, 2, '0', STR_PAD_LEFT);
    $generated_id = "{$current_month}-{$current_year}-{$padded_seq}";

    echo json_encode([
        'status' => 'success',
        'id_number' => $generated_id
    ]);
    exit;
}


/* ============================================================
 * HANDLER 1: EXISTING PATIENT — Link account to clinic record
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['link_account'])) {

    /* Collect & preserve inputs */
    $prev['full_name'] = trim($_POST['full_name'] ?? '');
    $prev['search_val']  = trim($_POST['search_val'] ?? '');
    $prev['username']    = trim($_POST['username']   ?? '');
    $prev['sec_question1'] = $_POST['sec_question1'] ?? '';
    $prev['sec_answer1']   = trim($_POST['sec_answer1'] ?? '');
    $prev['sec_question2'] = $_POST['sec_question2'] ?? '';
    $prev['sec_answer2']   = trim($_POST['sec_answer2'] ?? '');
    $prev['agree_terms'] = !empty($_POST['agree_terms']);
    $raw_pass            = $_POST['password']         ?? '';
    $confirm             = $_POST['confirm_pass']     ?? '';

    if (empty($_POST['agree_terms'])) {
        $error      = 'You must read and agree to the Data Privacy & Non-Disclosure Agreement to proceed.';
        $error_type = 'other';
    } else {

        $username_error = validateUsername($prev['username']);
        $pass_error = validatePassword($raw_pass);

        if ($username_error) {
            $error      = $username_error;
            $error_type = 'other';

        } elseif ($pass_error) {
            /* Weak password — clear password fields, keep everything else */
            $error      = $pass_error;
            $error_type = 'password_weak';

        } elseif ($raw_pass !== $confirm) {
            /* Mismatch — clear password fields only */
            $error      = 'Passwords do not match. Please re-enter your password.';
            $error_type = 'password_mismatch';

        } else {
            $password = password_hash($raw_pass, PASSWORD_DEFAULT);

            /* Find patient by ID number or full name */
            /* Find patient by ID number AND verify full name matches */
            $stmt = $conn->prepare('
                SELECT patient_id, full_name, username
                FROM patients
                WHERE id_number = ? AND status = 1
                LIMIT 1
            ');
            $stmt->bind_param('s', $prev['search_val']);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                $error      = 'No record found. Please verify your ID or register as a New Patient.';
                $error_type = 'other';

            } else {
                $patient = $result->fetch_assoc();

                // Verify the full name matches (case-insensitive)
                $submitted_name = trim($_POST['full_name'] ?? '');
                if (strtolower($submitted_name) !== strtolower($patient['full_name'])) {
                    $error      = 'The name you entered does not match our records for that ID. Please double-check.';
                    $error_type = 'other';

                } elseif (!empty($patient['username'])) {
                    $error      = 'An account already exists for this record. Please Sign In.';
                    $error_type = 'other';

                } else {
                    /* Check if chosen username is already taken */
                    $checkUser = $conn->prepare('SELECT patient_id FROM patients WHERE username = ?');
                    $checkUser->bind_param('s', $prev['username']);
                    $checkUser->execute();

                    if ($checkUser->get_result()->num_rows > 0) {
                        /* Username taken — clear only username, keep everything else */
                        $error            = 'That username is already taken. Please choose a different one.';
                        $error_type       = 'username';
                        $prev['username'] = ''; // Clear for re-echo

                    } else {
                        if ($prev['sec_answer1'] === '' || $prev['sec_answer2'] === '') {
                            $error      = 'Please answer both security questions.';
                            $error_type = 'other';
                        } else {
                            $ans1 = password_hash(strtolower($prev['sec_answer1']), PASSWORD_DEFAULT);
                            $ans2 = password_hash(strtolower($prev['sec_answer2']), PASSWORD_DEFAULT);
                            $update = $conn->prepare('UPDATE patients SET username = ?, password = ?, sec_question1 = ?, sec_answer1 = ?, sec_question2 = ?, sec_answer2 = ? WHERE patient_id = ?');
                            $update->bind_param('ssssssi', $prev['username'], $password, $prev['sec_question1'], $ans1, $prev['sec_question2'], $ans2, $patient['patient_id']);

                            if ($update->execute()) {
                                $success = 'Account verified for <strong>' . htmlspecialchars($patient['full_name']) . '</strong>! <a href="login.php">Sign in here.</a>';
                            } else {
                                $error      = 'Unable to create login. Please try again.';
                                $error_type = 'other';
                            }
                        }
                    }
                }
            }
        }
    } // end agree_terms check
}


/* ============================================================
 * HANDLER 2: NEW PATIENT — Full registration
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_new'])) {

    /* Collect & preserve all inputs except passwords */
    $prev['first_name']   = trim($_POST['first_name']   ?? '');
    $prev['last_name']    = trim($_POST['last_name']     ?? '');
    $prev['category']     = $_POST['category']           ?? 'Student';
    $prev['username']     = trim($_POST['username']      ?? '');
    $prev['gender']       = $_POST['gender']             ?? 'Male';
    $prev['birthdate']    = $_POST['birthdate']          ?? '';

    $is_guest = ($prev['category'] === 'Visitor');
    $prev['id_number'] = trim($_POST['id_number'] ?? '');
    $prev['contact']      = trim($_POST['contact']       ?? '');
    $prev['address']      = trim($_POST['address']       ?? '');
    $prev['sec_question1'] = $_POST['sec_question1']     ?? '';
    $prev['sec_answer1']   = trim($_POST['sec_answer1']  ?? '');
    $prev['sec_question2'] = $_POST['sec_question2']     ?? '';
    $prev['sec_answer2']   = trim($_POST['sec_answer2']  ?? '');
    $prev['agree_terms']   = !empty($_POST['agree_terms']);

    $raw_pass = $_POST['password']     ?? '';
    $confirm  = $_POST['confirm_pass'] ?? '';

    /* Build full name */
    $full_name = trim($prev['first_name'] . ' ' . $prev['last_name']);

    /* Validate in order of importance */
    if (empty($_POST['agree_terms'])) {
        $error      = 'You must read and agree to the Data Privacy & Non-Disclosure Agreement to proceed.';
        $error_type = 'other';
    } else {

        $username_error = validateUsername($prev['username']);
        $birthdate_error = validateBirthdateValue($prev['birthdate']);
        $pass_error = validatePassword($raw_pass);

        if ($username_error) {
            $error      = $username_error;
            $error_type = 'other';

        } elseif ($birthdate_error) {
            $error      = $birthdate_error;
            $error_type = 'other';

        } elseif ($pass_error) {
            $error      = $pass_error;
            $error_type = 'password_weak';

        } elseif ($raw_pass !== $confirm) {
            $error      = 'Passwords do not match. Please re-enter your password.';
            $error_type = 'password_mismatch';

        } else {
            
            /* ──────────────────────────────────────────────────────────
             * SERVER-SIDE AUTO-INCREMENT GENERATION SAFEGUARD
             * If the user is a Visitor, we automatically generate a safe,
             * non-repeating chronological ID in the server backend before
             * saving, bypassing manual browser inputs.
             * ────────────────────────────────────────────────────────── */
            if ($is_guest) {
                $current_month = date('m');
                $current_year  = date('Y');
                $pattern       = "{$current_month}-{$current_year}-%";

                $query = "SELECT id_number FROM patients WHERE id_number LIKE ? ORDER BY id_number DESC LIMIT 1";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("s", $pattern);
                $stmt->execute();
                $result = $stmt->get_result();

                $next_seq = 1;

                if ($row = $result->fetch_assoc()) {
                    $last_id = $row['id_number'];
                    $parts = explode('-', $last_id);
                    if (count($parts) === 3) {
                        $next_seq = intval($parts[2]) + 1;
                    }
                }

                $padded_seq = str_pad($next_seq, 2, '0', STR_PAD_LEFT);
                $prev['id_number'] = "{$current_month}-{$current_year}-{$padded_seq}";
            }

            // Check formatting validity for non-visitors
            if (!$is_guest && !preg_match('/^[0-9]{2}-[0-9]{4}-[0-9]{2}$/', $prev['id_number'])) {
                $error      = 'Invalid ID format. Please use the 00-0000-00 format.';
                $error_type = 'other';

            } else {
                $password = password_hash($raw_pass, PASSWORD_DEFAULT);

                /* Check username availability */
                $checkUser = $conn->prepare('SELECT patient_id FROM patients WHERE username = ?');
                $checkUser->bind_param('s', $prev['username']);
                $checkUser->execute();

                if ($checkUser->get_result()->num_rows > 0) {
                    /* Username taken — clear only username field */
                    $error            = 'That username is already taken. Please choose a different one.';
                    $error_type       = 'username';
                    $prev['username'] = '';

                } else {
                    /* Check if ID number is already registered */
                    $checkID = $conn->prepare('SELECT patient_id FROM patients WHERE id_number = ?');
                    $checkID->bind_param('s', $prev['id_number']);
                    $checkID->execute();

                    if ($checkID->get_result()->num_rows > 0) {
                        $error      = 'That ID number is already registered. If you already have an account, please Sign In. If you have an existing clinic record, go back and choose YES, I HAVE A RECORD.';
                        $error_type = 'other';

                    } else {
                        /* Hash security answers (case-insensitive comparison later) */
                        $ans1 = password_hash(strtolower($prev['sec_answer1']), PASSWORD_DEFAULT);
                        $ans2 = password_hash(strtolower($prev['sec_answer2']), PASSWORD_DEFAULT);

                        $ins = $conn->prepare('
                            INSERT INTO patients
                                (id_number, full_name, gender, birthdate, category,
                                 contact_number, address, username, password,
                                 sec_question1, sec_answer1, sec_question2, sec_answer2)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ');

                        $ins->bind_param('sssssssssssss',
                            $prev['id_number'], $full_name,       $prev['gender'],
                            $prev['birthdate'], $prev['category'], $prev['contact'],
                            $prev['address'],   $prev['username'], $password,
                            $prev['sec_question1'], $ans1,
                            $prev['sec_question2'], $ans2
                        );

                        if ($ins->execute()) {
                            $success = "Registration Successful! <a href='login.php'>Click here to Sign In.</a>";
                        } else {
                            $error      = 'Registration failed. Please try again.';
                            $error_type = 'other';
                        }
                    } // end id_number duplicate check
                }
            }
        }
    } // end agree_terms check
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SPIST Clinic | Registration</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="SPISTLOGOPNG.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        /* ── CSS Variables ──────────────────────────────────── */
        :root {
            --primary:   #006633;
            --primary-d: #004d26;
            --secondary: #00a651;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        /* ── Page Background ─────────────────────────────────── */
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, rgba(0,102,51,0.55), rgba(0,166,81,0.55), rgba(0,102,51,0.55)),
                        url("clinic background.jpg") center center / cover no-repeat fixed;
            animation: gradientShift 15s ease infinite;
            background-size: 400% 400%, cover;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        @keyframes gradientShift {
            0%, 100% { background-position: 0% 50%, center; }
            50%       { background-position: 100% 50%, center; }
        }

        /* ── Navbar ─────────────────────────────────────────── */
        .navbar {
            padding: 14px 36px;
            background: rgba(0,0,0,0.48);
            backdrop-filter: blur(12px);
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.25);
        }

        .navbar img { width: 48px; border-radius: 50%; box-shadow: 0 2px 6px rgba(0,0,0,0.3); }

        .navbar h1 {
            font-size: 22px;
            font-weight: 700;
            color: #fff;
            letter-spacing: 1px;
            text-shadow: 0 1px 3px rgba(0,0,0,0.4);
        }

        /* ── Layout ─────────────────────────────────────────── */
        .main-container {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 40px 20px;
        }

        /* ── Register Card ───────────────────────────────────── */
        .register-card {
            background: rgba(255,255,255,0.96);
            width: 100%;
            max-width: 560px;
            border-radius: 20px;
            box-shadow: 0 18px 40px rgba(0,0,0,0.35);
            overflow: hidden;
            animation: slideUp .55s ease-out;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(28px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Card Header ─────────────────────────────────────── */
        .card-header {
            background: linear-gradient(135deg, #005022, #007a3e);
            color: #fff;
            padding: 28px 24px;
            text-align: center;
        }

        .card-header h2 {
            font-size: 24px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        /* ── Card Body ───────────────────────────────────────── */
        .card-body { padding: 32px 36px; }

        /* ── Alert Banners ───────────────────────────────────── */
        .alert {
            padding: 11px 14px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 13.5px;
            text-align: center;
        }

        .alert-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert a       { color: var(--primary-d); font-weight: 700; }

        /* ── Section Titles ──────────────────────────────────── */
        .section-title {
            font-size: 11px;
            font-weight: 700;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: .6px;
            border-bottom: 2px solid #e8e8e8;
            padding-bottom: 5px;
            margin: 18px 0 14px;
        }

        /* ── Field Wrapper ───────────────────────────────────── */
        .field-wrap            { display: flex; flex-direction: column; gap: 4px; }
        .field-wrap label      { font-size: 11px; font-weight: 700; color: #555; text-transform: uppercase; letter-spacing: .4px; }

        /* ── Inputs / Selects / Textarea ─────────────────────── */
        input, select, textarea {
            width: 100%;
            padding: 11px 13px;
            border: 1.5px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            color: #222;
            background: #fff;
            transition: border-color .2s, box-shadow .2s;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(0,166,81,.15);
        }

        /* Highlight fields with errors */
        input.field-error {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 3px rgba(220,53,69,.15) !important;
            animation: shake 0.35s ease;
        }

        /* Gentle shake animation when a field is cleared due to error */
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%       { transform: translateX(-6px); }
            40%       { transform: translateX(6px); }
            60%       { transform: translateX(-4px); }
            80%       { transform: translateX(4px); }
        }

        /* ── Two-Column Grid ─────────────────────────────────── */
        .input-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }

        /* ── Password Field with Eye Toggle ──────────────────── */
        .pass-wrap          { position: relative; width: 100%; margin-bottom: 6px; }
        .pass-wrap input    { padding-right: 42px; margin-bottom: 0 !important; }

        .pass-wrap .eye-icon {
            position:  absolute;
            right:     15px;
            top:       50%;
            transform: translateY(-50%);
            cursor:    pointer;
            color:     #666;
            transition: color .2s;
        }

        .pass-wrap .eye-icon:hover { color: var(--primary); }

        /* ── Tooltip on date input ───────────────────────────── */
        .tooltip-wrap { position: relative; width: 100%; }

        .tooltip-wrap .tooltip-text {
            visibility:  hidden;
            opacity:     0;
            background:  var(--primary);
            color:       #fff;
            font-size:   12px;
            font-weight: 600;
            padding:     6px 11px;
            border-radius: 7px;
            position:    absolute;
            bottom:      calc(100% + 8px);
            left:        50%;
            transform:   translateX(-50%);
            white-space: nowrap;
            transition:  opacity .2s ease;
            z-index:     20;
            pointer-events: none;
        }

        .tooltip-wrap .tooltip-text::after {
            content:      '';
            position:     absolute;
            top:          100%;
            left:         50%;
            transform:    translateX(-50%);
            border:       5px solid transparent;
            border-top-color: var(--primary);
        }

        .tooltip-wrap:hover .tooltip-text { visibility: visible; opacity: 1; }

        /* ── Primary Button ──────────────────────────────────── */
        .btn-primary {
            width:         100%;
            background:    var(--primary);
            color:         #fff;
            border:        none;
            padding:       14px;
            border-radius: 9px;
            font-weight:   700;
            font-size:     15px;
            cursor:        pointer;
            margin-top:    10px;
            transition:    background .25s, transform .2s;
        }

        .btn-primary:hover { background: var(--primary-d); transform: translateY(-2px); }

        /* ── Outline Button ──────────────────────────────────── */
        .btn-outline {
            width:         100%;
            background:    transparent;
            color:         var(--primary);
            border:        2px solid var(--primary);
            padding:       13px;
            border-radius: 9px;
            font-weight:   700;
            font-size:     15px;
            cursor:        pointer;
            margin-top:    10px;
            transition:    background .25s, color .25s;
        }

        .btn-outline:hover { background: var(--primary); color: #fff; }

        /* ── Footer Links ────────────────────────────────────── */
        .footer-link          { text-align: center; margin-top: 14px; font-size: 13.5px; }
        .footer-link a        { color: var(--primary); font-weight: 700; text-decoration: none; }
        .footer-link a:hover  { text-decoration: underline; }

        /* ── Selection Page Description ──────────────────────── */
        .selection-desc {
            text-align: center;
            color: #555;
            font-size: 14.5px;
            margin-bottom: 22px;
            line-height: 1.5;
        }

        /* ── Password Strength Meter ──────────────────────────── */
        .strength-meter { margin-top: 8px; margin-bottom: 10px; }

        .strength-bars { display: flex; gap: 5px; margin-bottom: 5px; }

        .strength-bar {
            flex:          1;
            height:        5px;
            border-radius: 3px;
            background:    #e0e0e0;
            transition:    background .3s ease;
        }

        .strength-bar.active-weak    { background: #e53935; }
        .strength-bar.active-fair    { background: #fb8c00; }
        .strength-bar.active-good    { background: #fdd835; }
        .strength-bar.active-strong  { background: #43a047; }
        .strength-bar.active-vstrong { background: #1b5e20; }

        .strength-label { font-size: 12px; font-weight: 700; color: #888; transition: color .3s; }

        /* ── Password Requirements Checklist ─────────────────── */
        .pass-requirements {
            background:    #f8f9fa;
            border:        1.5px solid #e8e8e8;
            border-radius: 9px;
            padding:       12px 14px;
            margin-bottom: 12px;
        }

        .pass-requirements p {
            font-size:      11px;
            font-weight:    700;
            color:          #555;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom:  8px;
        }

        .req-item {
            display:       flex;
            align-items:   center;
            gap:           8px;
            font-size:     12.5px;
            color:         #888;
            margin-bottom: 4px;
            transition:    color .25s;
        }

        .req-item:last-child { margin-bottom: 0; }

        .req-item i {
            font-size:  13px;
            width:      14px;
            text-align: center;
            color:      #ccc;
            transition: color .25s;
        }

        .req-item.met       { color: #2e7d32; }
        .req-item.met i     { color: #43a047; }
        .req-item.unmet i   { color: #e53935; }

        /* ── Inline field error hint ─────────────────────────── */
        .field-hint {
            font-size:   12px;
            color:       #dc3545;
            font-weight: 600;
            margin-top:  4px;
            display:     none;   /* shown by JS when needed */
        }

        /* ── Terms Agreement Box ─────────────────────────────── */
        .terms-box {
            background: #f8fffe;
            border: 1.5px solid #c3e6cb;
            border-radius: 10px;
            padding: 14px 16px;
            margin-top: 16px;
            margin-bottom: 4px;
        }

        .terms-scroll {
            max-height: 120px;
            overflow-y: auto;
            font-size: 12px;
            color: #444;
            line-height: 1.6;
            margin-bottom: 10px;
            padding-right: 4px;
        }

        .terms-scroll::-webkit-scrollbar { width: 5px; }
        .terms-scroll::-webkit-scrollbar-thumb { background: #a5d6a7; border-radius: 4px; }

        .terms-check-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 13px;
            color: #333;
            font-weight: 600;
            cursor: pointer;
        }

        .terms-check-row input[type="checkbox"] {
            width: 17px;
            height: 17px;
            min-width: 17px;
            margin-top: 1px;
            accent-color: var(--primary);
            cursor: pointer;
        }

    </style>
</head>
<body>

<!-- ── Navbar ───────────────────────────────────────────────── -->
<div class="navbar">
    <img src="SPISTLOGOPNG.png" alt="SPIST Logo">
    <h1>SPIST CLINIC PORTAL</h1>
</div>

<!-- ── Main Content ──────────────────────────────────────────── -->
<div class="main-container">
    <div class="register-card">

        <!-- Card Header -->
        <div class="card-header">
            <h2><i class="fas fa-user-plus"></i> Patient Registration</h2>
        </div>

        <div class="card-body">

            <!-- Success message (only show, no further form) -->
            <?php if ($success): ?>
                <div class="alert alert-success"><?= $success ?></div>
            <?php endif; ?>

            <?php if (!$success): ?>

            <!-- ── MODE: SELECTION ───────────────────────────── -->
            <?php if ($mode === 'selection'): ?>
                <p class="selection-desc">Do you have an existing medical record at our clinic?</p>
                <a href="?mode=existing" style="text-decoration:none;">
                    <button class="btn-primary">YES, I HAVE A RECORD</button>
                </a>
                <a href="?mode=new" style="text-decoration:none;">
                    <button class="btn-outline">NO, I AM A NEW PATIENT</button>
                </a>
                <div class="footer-link" style="margin-top:18px;">
                    <a href="login.php"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
                </div>


            <!-- ── MODE: EXISTING PATIENT ────────────────────── -->
            <?php elseif ($mode === 'existing'): ?>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" id="existingForm" novalidate>

                    <div class="section-title">Verify Clinic Record</div>

                    <!-- Patient ID / Name — always preserved -->
                    <input type="text"
                           name="search_val"
                           id="search_val"
                           placeholder="Enter Patient ID (e.g. 26-0001-01)"
                           value="<?= htmlspecialchars($prev['search_val']) ?>"
                           oninput="formatID(this)"
                           required
                           style="margin-bottom:12px;">

                           <!-- ADD THIS BELOW -->
                    <input type="text"
                        name="full_name"
                        id="full_name"
                        placeholder="Enter your Full Name (as registered)"
                        value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                        required
                        style="margin-bottom:12px;">

                    <div class="section-title">Setup Your Login</div>

                    <!-- Username — cleared only when taken -->
                    <input type="text"
                           name="username"
                           id="exist_username"
                           placeholder="Create Username"
                           minlength="6"
                           value="<?= htmlspecialchars($prev['username']) ?>"
                           required
                           style="margin-bottom:4px;"
                           autocomplete="off">
                    <!-- Inline hint shown by JS when username is taken -->
                    <span class="field-hint" id="exist_username_hint">
                        <i class="fa-solid fa-circle-xmark"></i> That username is already taken — please choose another.
                    </span>

                    <!-- Password -->
                    <div class="pass-wrap" style="margin-top:10px;">
                        <input type="password"
                               name="password"
                               id="exist_pass"
                               placeholder="Create Password"
                               required
                               oninput="checkStrength(this, 'exist_strength')">
                        <i class="fa-regular fa-eye eye-icon" onclick="togglePass('exist_pass', this)"></i>
                    </div>

                    <!-- Strength Meter -->
                    <div class="strength-meter" id="exist_strength" style="display:none;">
                        <div class="strength-bars">
                            <div class="strength-bar" id="exist_bar1"></div>
                            <div class="strength-bar" id="exist_bar2"></div>
                            <div class="strength-bar" id="exist_bar3"></div>
                            <div class="strength-bar" id="exist_bar4"></div>
                            <div class="strength-bar" id="exist_bar5"></div>
                        </div>
                        <span class="strength-label" id="exist_strength_label"></span>
                    </div>

                    <!-- Requirements Checklist -->
                    <div class="pass-requirements" id="exist_requirements" style="display:none;">
                        <p><i class="fa-solid fa-shield-halved"></i> Password Requirements</p>
                        <div class="req-item" id="exist_req_length"><i class="fa-solid fa-circle-xmark"></i> Minimum 8 characters (12+ recommended)</div>
                        <div class="req-item" id="exist_req_upper"><i class="fa-solid fa-circle-xmark"></i> At least 1 uppercase letter (A–Z)</div>
                        <div class="req-item" id="exist_req_lower"><i class="fa-solid fa-circle-xmark"></i> At least 1 lowercase letter (a–z)</div>
                        <div class="req-item" id="exist_req_number"><i class="fa-solid fa-circle-xmark"></i> At least 1 number (0–9)</div>
                        <div class="req-item" id="exist_req_special"><i class="fa-solid fa-circle-xmark"></i> At least 1 special character (!@#$%^&*)</div>
                    </div>

                    <!-- Confirm Password -->
                    <div class="pass-wrap">
                        <input type="password"
                               name="confirm_pass"
                               id="exist_confirm"
                               placeholder="Confirm Password"
                               required>
                        <i class="fa-regular fa-eye eye-icon" onclick="togglePass('exist_confirm', this)"></i>
                    </div>
                    <!-- Inline mismatch hint (shown by JS before submit) -->
                    <span class="field-hint" id="exist_confirm_hint">
                        <i class="fa-solid fa-circle-xmark"></i> Passwords do not match.
                    </span>


                    <!-- Security Questions -->
                    <div class="section-title">Security Recovery</div>
                    <p style="font-size:12px; color:#666; margin-bottom:10px;">
                        These will be used if you forget your password.
                    </p>

                    <div class="field-wrap" style="margin-bottom:12px;">
                        <label>Security Question 1</label>
                        <select name="sec_question1" required>
                            <?php
                            $q1_options = [
                                "What is your mother's maiden name?",
                                "What was the name of your first pet?",
                                "What was the model of your first car?",
                            ];
                            foreach ($q1_options as $opt):
                                $sel = $prev['sec_question1'] === $opt ? 'selected' : '';
                            ?>
                                <option value="<?= htmlspecialchars($opt) ?>" <?= $sel ?>>
                                    <?= htmlspecialchars($opt) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text"
                               name="sec_answer1"
                               placeholder="Answer to Question 1"
                               value="<?= htmlspecialchars($prev['sec_answer1']) ?>"
                               required
                               style="margin-top:5px;">
                    </div>

                    <div class="field-wrap" style="margin-bottom:12px;">
                        <label>Security Question 2</label>
                        <select name="sec_question2" required>
                            <?php
                            $q2_options = [
                                "What city were you born in?",
                                "What was your high school's name?",
                                "What is your favorite book?",
                            ];
                            foreach ($q2_options as $opt):
                                $sel = $prev['sec_question2'] === $opt ? 'selected' : '';
                            ?>
                                <option value="<?= htmlspecialchars($opt) ?>" <?= $sel ?>>
                                    <?= htmlspecialchars($opt) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text"
                               name="sec_answer2"
                               placeholder="Answer to Question 2"
                               value="<?= htmlspecialchars($prev['sec_answer2']) ?>"
                               required
                               style="margin-top:5px;">
                    </div>
                    <!-- Terms & Non-Disclosure Agreement -->
                    <div class="terms-box">
                        <div class="terms-scroll">
                            <strong>SPIST Clinic — Data Privacy & Non-Disclosure Agreement</strong><br><br>
                            By creating an account, you acknowledge and agree that:<br><br>
                            1. <strong>Data Collection:</strong> The SPIST Clinic will collect and store your personal and medical information solely for the purpose of providing healthcare services.<br><br>
                            2. <strong>Confidentiality:</strong> Your health records, consultation history, and personal data are strictly confidential and will not be shared with unauthorized third parties without your consent, except as required by law.<br><br>
                            3. <strong>Access:</strong> Only authorized clinic staff and you (the patient) may access your medical information through this portal.<br><br>
                            4. <strong>Data Security:</strong> The clinic employs appropriate security measures to protect your data. You are responsible for keeping your login credentials confidential.<br><br>
                            5. <strong>Non-Disclosure:</strong> Information disclosed during consultations is protected and will be used only for your medical care and treatment.
                        </div>
                        <label class="terms-check-row">
                            <input type="checkbox" name="agree_terms" id="exist_terms" <?= $prev['agree_terms'] ? 'checked' : '' ?>>
                            <span>I have read and agree to the <strong>Data Privacy &amp; Non-Disclosure Agreement</strong>.</span>
                        </label>
                    </div>

                    <input type="hidden" name="link_account" value="1">
                    <button type="submit" id="existSubmitBtn" class="btn-primary"
                        style="opacity:<?= $prev['agree_terms'] ? '1' : '0.6' ?>; cursor:<?= $prev['agree_terms'] ? 'pointer' : 'not-allowed' ?>; background:<?= $prev['agree_terms'] ? '#006633' : '#888' ?>;">
                        VERIFY &amp; CREATE LOGIN
                    </button>
                    <div class="footer-link"><a href="?mode=selection"><i class="fa-solid fa-arrow-left"></i> Go Back</a></div>
                    <div class="footer-link"><a href="login.php"><i class="fa-solid fa-arrow-left"></i> Back to Login</a></div>
                </form>


            <!-- ── MODE: NEW PATIENT ─────────────────────────── -->
            <?php elseif ($mode === 'new'): ?>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" id="newForm" novalidate>

                    <div class="section-title">Identity Information</div>

                    <!-- Category — first so guest ID logic triggers before ID field -->
                    <div class="field-wrap" style="margin-bottom:12px;">
                        <label>Category</label>
                        <select name="category" id="reg_category" required onchange="handleRegCategory(this)">
                            <option value="Student"  <?= $prev['category'] === 'Student'  ? 'selected' : '' ?>>Student</option>
                            <option value="Faculty"  <?= $prev['category'] === 'Faculty'  ? 'selected' : '' ?>>Faculty</option>
                            <option value="Staff"    <?= $prev['category'] === 'Staff'    ? 'selected' : '' ?>>Staff</option>
                            <option value="Visitor"  <?= $prev['category'] === 'Visitor'  ? 'selected' : '' ?>>Guest / Visitor</option>
                        </select>
                    </div>

                    <!-- Patient ID Number -->
                    <div class="field-wrap" style="margin-bottom:12px;">
                        <label>
                            Patient ID Number
                            <span id="id_auto_badge" style="display:none; margin-left:6px; font-size:10px;
                                background:#e8f5e9; color:#2e7d32; border:1px solid #a5d6a7;
                                border-radius:4px; padding:1px 6px; font-weight:700;">AUTO-GENERATED</span>
                        </label>
                        <input type="hidden" name="id_number" id="id_number_hidden"
                               value="<?= htmlspecialchars($prev['id_number']) ?>">
                        <input type="text"
                               id="id_number"
                               placeholder="00-0000-00"
                               maxlength="10"
                               value="<?= htmlspecialchars($prev['id_number']) ?>"
                               oninput="formatID(this); document.getElementById('id_number_hidden').value=this.value;"
                               style="font-family:monospace; letter-spacing:2px; text-align:center; font-weight:bold;">
                        <span id="id_guest_note" style="display:none; font-size:12px; color:#2e7d32; margin-top:3px;">
                            <i class="fa-solid fa-circle-info"></i> ID is provided by the system — no need to type.
                        </span>
                        <span id="id_loading" style="display:none; font-size:12px; color:#888; margin-top:3px;">
                            <i class="fa-solid fa-spinner fa-spin"></i> Generating your ID...
                        </span>
                    </div>

                    <!-- Name — preserved -->
                    <div class="input-group">
                        <input type="text"
                               name="first_name"
                               placeholder="First Name"
                               value="<?= htmlspecialchars($prev['first_name']) ?>"
                               required>
                        <input type="text"
                               name="last_name"
                               placeholder="Last Name"
                               value="<?= htmlspecialchars($prev['last_name']) ?>"
                               required>
                    </div>

                    <!-- Gender + Birthdate — preserved -->
                    <div class="input-group">
                        <div class="field-wrap">
                            <select name="gender" required>
                                <option value="Male"   <?= $prev['gender'] === 'Male'   ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= $prev['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                        <div class="field-wrap">
                            <div class="tooltip-wrap">
                                <input type="date"
                                       name="birthdate"
                                       value="<?= htmlspecialchars($prev['birthdate']) ?>"
                                       min="<?= date('Y-m-d', strtotime('-120 years')) ?>"
                                       max="<?= date('Y-m-d') ?>"
                                       required>
                                <span class="tooltip-text">🎂 Enter your Birthday</span>
                            </div>
                        </div>
                    </div>

                    <!-- Contact + Address — preserved -->
                    <input type="text"
                           id="contact"
                           name="contact"
                           placeholder="Contact Number (e.g. 09XXXXXXXXX)"
                           inputmode="numeric"
                           maxlength="11"
                           pattern="^09[0-9]{9}$"
                           value="<?= htmlspecialchars($prev['contact']) ?>"
                           style="margin-bottom:12px;">

                    <textarea name="address"
                              rows="2"
                              placeholder="Complete Home Address"
                              style="margin-bottom:12px;"><?= htmlspecialchars($prev['address']) ?></textarea>

                    <!-- Security Questions — preserved -->
                    <div class="section-title">Security Recovery</div>
                    <p style="font-size:12px; color:#666; margin-bottom:10px;">
                        These will be used if you forget your password.
                    </p>

                    <div class="field-wrap" style="margin-bottom:12px;">
                        <label>Security Question 1</label>
                        <select name="sec_question1" required>
                            <?php
                            $q1_options = [
                                "What is your mother's maiden name?",
                                "What was the name of your first pet?",
                                "What was the model of your first car?",
                            ];
                            foreach ($q1_options as $opt):
                                $sel = $prev['sec_question1'] === $opt ? 'selected' : '';
                            ?>
                                <option value="<?= htmlspecialchars($opt) ?>" <?= $sel ?>>
                                    <?= htmlspecialchars($opt) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text"
                               name="sec_answer1"
                               placeholder="Answer to Question 1"
                               value="<?= htmlspecialchars($prev['sec_answer1']) ?>"
                               required
                               style="margin-top:5px;">
                    </div>

                    <div class="field-wrap" style="margin-bottom:12px;">
                        <label>Security Question 2</label>
                        <select name="sec_question2" required>
                            <?php
                            $q2_options = [
                                "What city were you born in?",
                                "What was your high school's name?",
                                "What is your favorite book?",
                            ];
                            foreach ($q2_options as $opt):
                                $sel = $prev['sec_question2'] === $opt ? 'selected' : '';
                            ?>
                                <option value="<?= htmlspecialchars($opt) ?>" <?= $sel ?>>
                                    <?= htmlspecialchars($opt) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text"
                               name="sec_answer2"
                               placeholder="Answer to Question 2"
                               value="<?= htmlspecialchars($prev['sec_answer2']) ?>"
                               required
                               style="margin-top:5px;">
                    </div>

                    <!-- Login Credentials -->
                    <div class="section-title">Login Credentials</div>

                    <!-- Username — cleared only when taken -->
                    <input type="text"
                           name="username"
                           id="new_username"
                           placeholder="Choose Username"
                           minlength="6"
                           value="<?= htmlspecialchars($prev['username']) ?>"
                           required
                           style="margin-bottom:4px;"
                           autocomplete="off">
                    <!-- Inline hint shown by JS -->
                    <span class="field-hint" id="new_username_hint">
                        <i class="fa-solid fa-circle-xmark"></i> That username is already taken — please choose another.
                    </span>

                    <!-- Password -->
                    <div class="pass-wrap" style="margin-top:10px;">
                        <input type="password"
                               name="password"
                               id="new_pass"
                               placeholder="Create Password"
                               required
                               oninput="checkStrength(this, 'new_strength')">
                        <i class="fa-regular fa-eye eye-icon" onclick="togglePass('new_pass', this)"></i>
                    </div>

                    <!-- Strength Meter -->
                    <div class="strength-meter" id="new_strength" style="display:none;">
                        <div class="strength-bars">
                            <div class="strength-bar" id="new_bar1"></div>
                            <div class="strength-bar" id="new_bar2"></div>
                            <div class="strength-bar" id="new_bar3"></div>
                            <div class="strength-bar" id="new_bar4"></div>
                            <div class="strength-bar" id="new_bar5"></div>
                        </div>
                        <span class="strength-label" id="new_strength_label"></span>
                    </div>

                    <!-- Requirements Checklist -->
                    <div class="pass-requirements" id="new_requirements" style="display:none;">
                        <p><i class="fa-solid fa-shield-halved"></i> Password Requirements</p>
                        <div class="req-item" id="new_req_length"><i class="fa-solid fa-circle-xmark"></i> Minimum 8 characters (12+ recommended)</div>
                        <div class="req-item" id="new_req_upper"><i class="fa-solid fa-circle-xmark"></i> At least 1 uppercase letter (A–Z)</div>
                        <div class="req-item" id="new_req_lower"><i class="fa-solid fa-circle-xmark"></i> At least 1 lowercase letter (a–z)</div>
                        <div class="req-item" id="new_req_number"><i class="fa-solid fa-circle-xmark"></i> At least 1 number (0–9)</div>
                        <div class="req-item" id="new_req_special"><i class="fa-solid fa-circle-xmark"></i> At least 1 special character (!@#$%^&*)</div>
                    </div>

                    <!-- Confirm Password -->
                    <div class="pass-wrap">
                        <input type="password"
                               name="confirm_pass"
                               id="new_confirm"
                               placeholder="Confirm Password"
                               required>
                        <i class="fa-regular fa-eye eye-icon" onclick="togglePass('new_confirm', this)"></i>
                    </div>
                    <!-- Inline mismatch hint -->
                    <span class="field-hint" id="new_confirm_hint">
                        <i class="fa-solid fa-circle-xmark"></i> Passwords do not match.
                    </span>

                    <!-- Terms & Non-Disclosure Agreement -->
                    <div class="terms-box">
                        <div class="terms-scroll">
                            <strong>SPIST Clinic — Data Privacy & Non-Disclosure Agreement</strong><br><br>
                            By registering, you acknowledge and agree that:<br><br>
                            1. <strong>Data Collection:</strong> The SPIST Clinic will collect and store your personal and medical information solely for the purpose of providing healthcare services.<br><br>
                            2. <strong>Confidentiality:</strong> Your health records, consultation history, and personal data are strictly confidential and will not be shared with unauthorized third parties without your consent, except as required by law.<br><br>
                            3. <strong>Access:</strong> Only authorized clinic staff and you (the patient) may access your medical information through this portal.<br><br>
                            4. <strong>Data Security:</strong> The clinic employs appropriate security measures to protect your data. You are responsible for keeping your login credentials confidential.<br><br>
                            5. <strong>Non-Disclosure:</strong> Information disclosed during consultations is protected and will be used only for your medical care and treatment.
                        </div>
                        <label class="terms-check-row">
                            <input type="checkbox" name="agree_terms" id="new_terms" <?= $prev['agree_terms'] ? 'checked' : '' ?>>
                            <span>I have read and agree to the <strong>Data Privacy &amp; Non-Disclosure Agreement</strong>.</span>
                        </label>
                    </div>

                    <input type="hidden" name="register_new" value="1">
                    <button type="submit" id="newSubmitBtn" class="btn-primary"
                        style="opacity:<?= $prev['agree_terms'] ? '1' : '0.6' ?>; cursor:<?= $prev['agree_terms'] ? 'pointer' : 'not-allowed' ?>; background:<?= $prev['agree_terms'] ? '#006633' : '#888' ?>;">
                        REGISTER &amp; CREATE ACCOUNT
                    </button>
                    <div class="footer-link"><a href="?mode=selection"><i class="fa-solid fa-arrow-left"></i> Go Back</a></div>
                    <div class="footer-link"><a href="login.php"><i class="fa-solid fa-arrow-left"></i> Back to Login</a></div>
                </form>

            <?php endif; // mode ?>

            <?php endif; // !$success ?>

        </div><!-- /.card-body -->
    </div><!-- /.register-card -->
</div><!-- /.main-container -->


<script>
/* ============================================================
 * register.js — Client-side UX for Registration Forms
 *
 * Key behaviors:
 * 1. Password mismatch → caught BEFORE submit, both password
 * fields cleared + shaken, inline hint shown.
 * 2. Username taken (PHP echo) → username field cleared +
 * shaken, inline hint shown on page load.
 * 3. All other fields always preserve their values.
 * 4. Password strength meter + requirements checklist.
 * 5. ID number auto-formatter.
 * 6. Eye icon password toggle.
 * ============================================================ */


/* ── PHP error type passed to JS so we know what to clear ──── */
const ERROR_TYPE = <?= json_encode($error_type) ?>;
const FORM_MODE  = <?= json_encode($mode) ?>;


/* ── On page load: apply error-specific clearing ────────────── */
document.addEventListener('DOMContentLoaded', function () {

    if (!ERROR_TYPE) return; // No error — nothing to clear

    /* Determine prefix based on which form is showing */
    const prefix = FORM_MODE === 'existing' ? 'exist' : 'new';

    if (ERROR_TYPE === 'username') {
        /* ── Username taken ──
         * PHP already cleared $prev['username'] so the field
         * renders empty. We just add the shake + show the hint. */
        const usernameField = document.getElementById(prefix + '_username');
        if (usernameField) {
            shakeField(usernameField);
            showHint(prefix + '_username_hint');
            usernameField.focus();
        }
    }

    if (ERROR_TYPE === 'password_mismatch' || ERROR_TYPE === 'password_weak') {
        /* ── Password error ──
         * Clear BOTH password fields (they're never re-echoed for security).
         * Show inline hint for mismatch. Shake both fields. */
        const passField    = document.getElementById(prefix + '_pass');
        const confirmField = document.getElementById(prefix + '_confirm');

        if (passField)    { passField.value = '';    shakeField(passField); }
        if (confirmField) { confirmField.value = ''; shakeField(confirmField); }

        if (ERROR_TYPE === 'password_mismatch') {
            showHint(prefix + '_confirm_hint');
        }

        /* Reset the strength meter since password was cleared */
        const strengthDiv = document.getElementById(prefix + '_strength');
        const requireDiv  = document.getElementById(prefix + '_requirements');
        if (strengthDiv) strengthDiv.style.display = 'none';
        if (requireDiv)  requireDiv.style.display  = 'none';

        if (passField) passField.focus();
    }
});


/* ── showHint(id) ───────────────────────────────────────────── */
function showHint(hintId) {
    const el = document.getElementById(hintId);
    if (el) el.style.display = 'block';
}

/* ── shakeField(el) ─────────────────────────────────────────── */
function shakeField(el) {
    el.classList.remove('field-error'); // Reset first (force re-trigger)
    void el.offsetWidth;               // Trigger reflow so animation restarts
    el.classList.add('field-error');
    setTimeout(function () { el.classList.remove('field-error'); }, 400);
}


/* ── CLIENT-SIDE: Password mismatch check BEFORE form submits ─
 * This intercepts before the page reloads so the user gets
 * instant feedback without losing any other filled-in data.  */
function attachMismatchCheck(formId, passId, confirmId, hintId) {
    const form = document.getElementById(formId);
    if (!form) return;

    form.addEventListener('submit', function (e) {
        const passVal    = document.getElementById(passId).value;
        const confirmVal = document.getElementById(confirmId).value;
        const hint       = document.getElementById(hintId);

        if (passVal !== confirmVal) {
            e.preventDefault(); // Stop form submission

            /* Clear both password fields */
            document.getElementById(passId).value    = '';
            document.getElementById(confirmId).value = '';

            /* Shake both fields */
            shakeField(document.getElementById(passId));
            shakeField(document.getElementById(confirmId));

            /* Show inline hint */
            if (hint) hint.style.display = 'block';

            /* Reset strength meter */
            const prefix      = passId.replace('_pass', '');
            const strengthDiv = document.getElementById(prefix + '_strength');
            const requireDiv  = document.getElementById(prefix + '_requirements');
            if (strengthDiv) strengthDiv.style.display = 'none';
            if (requireDiv)  requireDiv.style.display  = 'none';

            document.getElementById(passId).focus();
        } else {
            /* Match — hide the hint if it was showing */
            if (hint) hint.style.display = 'none';
        }
    });
}

/* Attach to both forms */
attachMismatchCheck('existingForm', 'exist_pass', 'exist_confirm', 'exist_confirm_hint');
attachMismatchCheck('newForm',      'new_pass',   'new_confirm',   'new_confirm_hint');


/* ── Hide inline hint when user starts typing in the field ──── */
['exist_username', 'new_username'].forEach(function (id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function () {
        const hint = document.getElementById(id + '_hint');
        if (hint) hint.style.display = 'none';
    });
});

['exist_confirm', 'new_confirm'].forEach(function (id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function () {
        const hint = document.getElementById(id + '_hint');
        if (hint) hint.style.display = 'none';
    });
});

const phoneInput = document.getElementById('contact');
if (phoneInput) {
    phoneInput.addEventListener('input', function (e) {
        let value = e.target.value.replace(/\D/g, ''); // Remove non-digits

        // Force the first digit to be '0'
        if (value.length > 0 && value[0] !== '0') {
            value = '';
        }
        
        // Force the second digit to be '9'
        if (value.length > 1 && value[1] !== '9') {
            value = '0'; // Revert to just '0' if the second digit isn't '9'
        }

        // Limit to 11 digits
        if (value.length > 11) {
            value = value.slice(0, 11);
        }

        e.target.value = value;
    });
}


/* ── ID Number Auto-Formatter (00-0000-00) ───────────────────── */
function formatID(input) {
    let val = input.value.replace(/\D/g, '').substring(0, 8);
    let out = '';
    if (val.length > 0) out += val.substring(0, 2);
    if (val.length > 2) out += '-' + val.substring(2, 6);
    if (val.length > 6) out += '-' + val.substring(6, 8);
    input.value = out;
}


/* ── Password Show / Hide Toggle ────────────────────────────── */
function togglePass(inputId, icon) {
    const field = document.getElementById(inputId);
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}


/* ── Password Strength Meter + Requirements Checklist ──────── */
function checkStrength(input, meterId) {
    const password = input.value;
    const prefix   = meterId.replace('_strength', ''); // 'exist' or 'new'

    const strengthDiv = document.getElementById(meterId);
    const requireDiv  = document.getElementById(prefix + '_requirements');

    if (password.length === 0) {
        strengthDiv.style.display = 'none';
        requireDiv.style.display  = 'none';
        return;
    }

    strengthDiv.style.display = 'block';
    requireDiv.style.display  = 'block';

    /* Evaluate each requirement */
    const checks = {
        length:  password.length >= 8,
        upper:   /[A-Z]/.test(password),
        lower:   /[a-z]/.test(password),
        number:  /[0-9]/.test(password),
        special: /[!@#$%^&*]/.test(password)
    };

    /* Update checklist items */
    updateReq(prefix + '_req_length',  checks.length);
    updateReq(prefix + '_req_upper',   checks.upper);
    updateReq(prefix + '_req_lower',   checks.lower);
    updateReq(prefix + '_req_number',  checks.number);
    updateReq(prefix + '_req_special', checks.special);

    /* Score (0–5), bonus for length >= 12 */
    let score = Object.values(checks).filter(Boolean).length;
    if (password.length >= 12) score = Math.min(score + 1, 5);

    /* Level lookup */
    const levels = [
        { label: '',           barClass: '' },
        { label: 'Very Weak',  barClass: 'active-weak' },
        { label: 'Weak',       barClass: 'active-weak' },
        { label: 'Fair',       barClass: 'active-fair' },
        { label: 'Good',       barClass: 'active-good' },
        { label: 'Strong',     barClass: 'active-strong' },
    ];

    const allMet   = Object.values(checks).every(Boolean);
    let barClass   = levels[Math.min(score, 5)].barClass;
    let labelText  = levels[Math.min(score, 5)].label;

    if (allMet && password.length >= 12) {
        labelText = 'Very Strong';
        barClass  = 'active-vstrong';
    }

    /* Paint bars */
    for (let i = 1; i <= 5; i++) {
        const bar = document.getElementById(prefix + '_bar' + i);
        bar.className = 'strength-bar' + (i <= score ? ' ' + barClass : '');
    }

    /* Label color map */
    const colorMap = {
        'active-weak':    '#e53935',
        'active-fair':    '#fb8c00',
        'active-good':    '#fdd835',
        'active-strong':  '#43a047',
        'active-vstrong': '#1b5e20'
    };

    const labelEl      = document.getElementById(prefix + '_strength_label');
    labelEl.textContent = labelText;
    labelEl.style.color = colorMap[barClass] || '#888';
}

/* Update a single requirement checklist item */
function updateReq(id, met) {
    const el   = document.getElementById(id);
    const icon = el.querySelector('i');
    if (met) {
        el.classList.add('met');
        el.classList.remove('unmet');
        icon.className = 'fa-solid fa-circle-check';
    } else {
        el.classList.remove('met');
        el.classList.add('unmet');
        icon.className = 'fa-solid fa-circle-xmark';
    }
}


/* ── Terms Checkbox — visual state + block submit if unchecked ── */
document.addEventListener('DOMContentLoaded', function() {

    function bindTerms(checkId, btnId, formId) {
        var cb   = document.getElementById(checkId);
        var btn  = document.getElementById(btnId);
        var form = document.getElementById(formId);
        if (!cb || !btn || !form) return;

        /* Apply button state immediately on load (handles page reload after PHP error) */
        function updateBtn() {
            var on = cb.checked;
            btn.style.setProperty('opacity',    on ? '1'       : '0.6',        'important');
            btn.style.setProperty('cursor',     on ? 'pointer' : 'not-allowed','important');
            btn.style.setProperty('background', on ? '#006633' : '#888',       'important');
        }
        /* Defer until after browser has rendered the checked attribute */
        requestAnimationFrame(function() { requestAnimationFrame(updateBtn); });

        /* Update button appearance on checkbox change */
        /* Use label click + setTimeout so cb.checked is already updated */
        var label = cb.closest('label') || cb.parentElement;
        function delayedUpdate() { setTimeout(updateBtn, 0); }
        cb.addEventListener('change', updateBtn);
        cb.addEventListener('click',  delayedUpdate);
        if (label) label.addEventListener('click', delayedUpdate);

        /* Block form submit if terms not checked */
        form.addEventListener('submit', function(e) {
            if (!cb.checked) {
                e.preventDefault();
                e.stopImmediatePropagation();
                cb.focus();
                cb.closest('.terms-box').style.outline = '2px solid #dc3545';
                setTimeout(function() {
                    cb.closest('.terms-box').style.outline = '';
                }, 1500);
            }
        }, true); /* useCapture=true so this runs BEFORE attachMismatchCheck */
    }

    bindTerms('exist_terms', 'existSubmitBtn', 'existingForm');
    bindTerms('new_terms',   'newSubmitBtn',   'newForm');
});


/* ── Dynamic Category & Unique ID Auto-Increment Handler ────── */
function handleRegCategory(select) {
    var cat     = select.value;
    var vis     = document.getElementById('id_number');
    var hid     = document.getElementById('id_number_hidden');
    var badge   = document.getElementById('id_auto_badge');
    var note    = document.getElementById('id_guest_note');
    var loading = document.getElementById('id_loading');
    var isGuest = (cat === 'Visitor');

    if (isGuest) {
        vis.readOnly          = true;
        vis.value             = '';
        vis.placeholder       = 'Generating ID...';
        vis.style.background  = '#f1f8f4';
        vis.style.color       = '#888';
        vis.style.cursor      = 'not-allowed';
        vis.removeAttribute('required');
        hid.value             = '';
        
        if (badge) badge.style.display   = 'inline-block';
        if (loading) loading.style.display = 'block';
        if (note) note.style.display    = 'none';

        // Fetch our auto-increment ID dynamically from the register endpoint
        fetch('register.php?action=get_next_id')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 'success') {
                    vis.value       = data.id_number;
                    hid.value       = data.id_number;
                    vis.placeholder = '';
                    if (loading) loading.style.display = 'none';
                    if (note) note.style.display    = 'block';
                } else {
                    vis.placeholder = 'Generation failed';
                    if (loading) loading.style.display = 'none';
                }
            })
            .catch(function(err) {
                console.error("Failed to fetch ID:", err);
                vis.placeholder = 'Generation failed';
                if (loading) loading.style.display = 'none';
            });
    } else {
        vis.readOnly          = false;
        vis.value             = '';
        hid.value             = '';
        vis.placeholder       = '00-0000-00';
        vis.style.background  = '#fff';
        vis.style.color       = '#222';
        vis.style.cursor      = '';
        vis.setAttribute('required', 'required');
        
        if (badge) badge.style.display   = 'none';
        if (note) note.style.display    = 'none';
        if (loading) loading.style.display = 'none';
    }
}


/* ── Page Load Handler: Restore State & Auto-Fetch on Reload ── */
document.addEventListener('DOMContentLoaded', function () {
    var catSel = document.getElementById('reg_category');
    if (catSel && catSel.value === 'Visitor') {
        var vis     = document.getElementById('id_number');
        var hid     = document.getElementById('id_number_hidden');
        var badge   = document.getElementById('id_auto_badge');
        var note    = document.getElementById('id_guest_note');
        var loading = document.getElementById('id_loading');
        
        vis.readOnly         = true;
        vis.style.background = '#f1f8f4';
        vis.style.color      = '#888';
        vis.style.cursor     = 'not-allowed';
        vis.removeAttribute('required');
        
        if (badge) badge.style.display = 'inline-block';

        // If page reloads with empty values (like after a validation reset), fetch a new ID
        if (!vis.value || vis.value === '') {
            vis.placeholder = 'Generating ID...';
            if (loading) loading.style.display = 'block';
            if (note) note.style.display = 'none';

            fetch('register.php?action=get_next_id')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.status === 'success') {
                        vis.value = data.id_number;
                        hid.value = data.id_number;
                        vis.placeholder = '';
                        if (loading) loading.style.display = 'none';
                        if (note) note.style.display = 'block';
                    }
                })
                .catch(function(err) {
                    console.error("Failed to restore unique ID:", err);
                    vis.placeholder = 'Generation failed';
                    if (loading) loading.style.display = 'none';
                });
        } else {
            // Already has a preserved valid input from server, display note immediately
            if (note) note.style.display = 'block';
        }
    }
});

</script>

</body>
</html>