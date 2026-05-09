<?php
/* ============================================================
 * patients.php — Patient Records Module
 *
 * Purpose  : Display, add, edit, soft-delete, and export
 * patient records.
 * ============================================================ */

session_start();
require "db.php";
require_staff_login();
redirect_direct_fragment_access();

$current_role = $_SESSION['role'] ?? 'guest';
$is_admin = $current_role === 'admin';

/** @var mysqli $conn */

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
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include at least one uppercase letter (A–Z).';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must include at least one lowercase letter (a–z).';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must include at least one number (0–9).';
    }
    if (!preg_match('/[!@#$%^&*]/', $password)) {
        return 'Password must include at least one special character (!@#$%^&*).';
    }
    return null;
}

/* ── 1. AJAX: GENERATE DYNAMIC AUTO-INCREMENT ID (MM-YYYY-XX) ── */
if (isset($_GET['get_visitor_id'])) {
    ob_clean();
    header('Content-Type: text/plain');

    $current_month = date('m'); // "05"
    $current_year  = date('Y'); // "2026"
    $pattern       = "{$current_month}-{$current_year}-%"; // e.g., "05-2026-%"

    // Retrieve the highest existing sequence format for this specific month & year
    $idQuery = $conn->prepare("SELECT id_number FROM patients WHERE id_number LIKE ? ORDER BY patient_id DESC LIMIT 1");
    $idQuery->bind_param("s", $pattern);
    $idQuery->execute();
    $idResult = $idQuery->get_result();

    $nextNum = 1; // Default starting number "01"

    if ($idResult->num_rows > 0) {
        $last = $idResult->fetch_assoc();
        $parts = explode("-", $last['id_number']);
        
        if (count($parts) === 3) {
            $last_seq = (int)$parts[2]; // Extract "01" -> 1
            $nextNum = $last_seq + 1;   // Increment -> 2
        }
    }

    // Zero-pad sequence back to a 2-digit format (e.g., 2 -> "02")
    $seq = str_pad($nextNum, 2, "0", STR_PAD_LEFT);
    
    echo "{$current_month}-{$current_year}-{$seq}"; 
    exit();
}

/* ── 2. AJAX: SAVE PATIENT ── */
if (isset($_POST['ajax_save_patient'])) {
    ob_clean();
    header('Content-Type: application/json');

    $patient_id      = trim($_POST['patient_id']     ?? '');
    $id_number       = trim($_POST['id_number']      ?? '');
    $full_name       = trim($_POST['full_name']      ?? '');
    $username        = trim($_POST['username']       ?? '');
    $gender          = trim($_POST['gender']         ?? '');
    $birthdate       = trim($_POST['birthdate']      ?? '');
    $category        = trim($_POST['category']       ?? '');
    $contact_number  = trim($_POST['contact_number'] ?? '');
    $address         = trim($_POST['address']        ?? '');
    $password        = $_POST['password']            ?? '';
    $confirm_password = $_POST['confirm_password']  ?? '';

    if (!$is_admin && ($username !== '' || $password !== '' || $confirm_password !== '')) {
        echo json_encode(['status' => 'error', 'msg' => 'Only admin users can update patient login credentials.']);
        exit();
    }

    $is_visitor = ($category === 'Visitor');

    $birthdate_error = validateBirthdateValue($birthdate);
    if ($birthdate_error) {
        echo json_encode(['status' => 'error', 'msg' => $birthdate_error]);
        exit();
    }

    if (empty($patient_id)) {
        // ──────────────────────────────────────────────────────────
        // SERVER-SIDE AUTO-GENERATION SAFEGUARD (INSERT ONLY)
        // If the category is Visitor, we calculate the next ID on the server
        // to guarantee it is chronological and doesn't conflict.
        // ──────────────────────────────────────────────────────────
        if ($is_visitor) {
            $current_month = date('m');
            $current_year  = date('Y');
            $pattern       = "{$current_month}-{$current_year}-%";

            $idQuery = $conn->prepare("SELECT id_number FROM patients WHERE id_number LIKE ? ORDER BY patient_id DESC LIMIT 1");
            $idQuery->bind_param("s", $pattern);
            $idQuery->execute();
            $idResult = $idQuery->get_result();

            $nextNum = 1;

            if ($idResult->num_rows > 0) {
                $last = $idResult->fetch_assoc();
                $parts = explode("-", $last['id_number']);
                if (count($parts) === 3) {
                    $nextNum = (int)$parts[2] + 1;
                }
            }

            $seq = str_pad($nextNum, 2, "0", STR_PAD_LEFT);
            $id_number = "{$current_month}-{$current_year}-{$seq}";
        } else {
            // For standard patients (Students/Staff), make sure manual entry isn't blank
            if (empty($id_number) || $id_number == "Generating ID..." || $id_number == "Generating unique ID..." || $id_number == "Error") {
                echo json_encode(['status' => 'error', 'msg' => 'Please wait for the ID to generate or enter a valid ID.']);
                exit();
            }

            // Optional client validation matching your format standard (00-0000-00)
            if (!preg_match('/^[0-9]{2}-[0-9]{4}-[0-9]{2}$/', $id_number)) {
                echo json_encode(['status' => 'error', 'msg' => 'Invalid ID format. Please use the 00-0000-00 format.']);
                exit();
            }
        }

        // CHECK: Unique ID — ensure id_number is not already in use in database
        $checkStmt = $conn->prepare("SELECT patient_id FROM patients WHERE id_number = ? LIMIT 1");
        $checkStmt->bind_param("s", $id_number);
        $checkStmt->execute();
        $checkStmt->store_result();
        if ($checkStmt->num_rows > 0) {
            echo json_encode(['status' => 'error', 'msg' => 'ID Number "' . htmlspecialchars($id_number) . '" is already in use. Please generate or enter a unique ID.']);
            exit();
        }
        $checkStmt->close();

        if ($username !== '' || $password !== '' || $confirm_password !== '') {
            if ($username === '') {
                echo json_encode(['status' => 'error', 'msg' => 'Username is required when setting a patient login.']);
                exit();
            }
            if (strlen($username) < 4) {
                echo json_encode(['status' => 'error', 'msg' => 'Username must be at least 4 characters long.']);
                exit();
            }
            if ($password === '') {
                echo json_encode(['status' => 'error', 'msg' => 'Password is required when setting a patient login.']);
                exit();
            }
            $pass_error = validatePassword($password);
            if ($pass_error) {
                echo json_encode(['status' => 'error', 'msg' => $pass_error]);
                exit();
            }
            if ($password !== $confirm_password) {
                echo json_encode(['status' => 'error', 'msg' => 'Passwords do not match.']);
                exit();
            }

            $checkUser = $conn->prepare("SELECT patient_id FROM patients WHERE username = ? LIMIT 1");
            $checkUser->bind_param("s", $username);
            $checkUser->execute();
            $checkUser->store_result();
            if ($checkUser->num_rows > 0) {
                echo json_encode(['status' => 'error', 'msg' => 'Username is already taken.']);
                exit();
            }
            $checkUser->close();

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        } else {
            $username = null;
            $hashed_password = null;
        }

        $stmt = $conn->prepare(
            "INSERT INTO patients (id_number, full_name, gender, birthdate, category, contact_number, address, username, password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "sssssssss",
            $id_number,
            $full_name,
            $gender,
            $birthdate,
            $category,
            $contact_number,
            $address,
            $username,
            $hashed_password
        );
    } else {
        // UPDATE LOGIC (Maintains existing ID, only changes user profile details)
        $currentQuery = $conn->prepare("SELECT username, password FROM patients WHERE patient_id = ? LIMIT 1");
        $currentQuery->bind_param("i", $patient_id);
        $currentQuery->execute();
        $currentRow = $currentQuery->get_result()->fetch_assoc();
        $currentQuery->close();

        if ($username === '') {
            $username = $currentRow['username'] ?? '';
        } else {
            $checkUser = $conn->prepare("SELECT patient_id FROM patients WHERE username = ? AND patient_id != ? LIMIT 1");
            $checkUser->bind_param("si", $username, $patient_id);
            $checkUser->execute();
            $checkUser->store_result();
            if ($checkUser->num_rows > 0) {
                echo json_encode(['status' => 'error', 'msg' => 'Username is already taken by another patient.']);
                exit();
            }
            $checkUser->close();
        }

        if ($password !== '') {
            $pass_error = validatePassword($password);
            if ($pass_error) {
                echo json_encode(['status' => 'error', 'msg' => $pass_error]);
                exit();
            }
            if ($password !== $confirm_password) {
                echo json_encode(['status' => 'error', 'msg' => 'Passwords do not match.']);
                exit();
            }
            if ($currentRow && password_verify($password, $currentRow['password'])) {
                echo json_encode(['status' => 'error', 'msg' => 'New password must be different from the current password.']);
                exit();
            }

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                "UPDATE patients SET full_name=?, gender=?, birthdate=?, category=?, contact_number=?, address=?, username=?, password=? WHERE patient_id=?"
            );
            $stmt->bind_param(
                "ssssssssi",
                $full_name,
                $gender,
                $birthdate,
                $category,
                $contact_number,
                $address,
                $username,
                $hashed_password,
                $patient_id
            );
        } else {
            $stmt = $conn->prepare(
                "UPDATE patients SET full_name=?, gender=?, birthdate=?, category=?, contact_number=?, address=?, username=? WHERE patient_id=?"
            );
            $stmt->bind_param(
                "sssssssi",
                $full_name,
                $gender,
                $birthdate,
                $category,
                $contact_number,
                $address,
                $username,
                $patient_id
            );
        }
    }

    if ($stmt && $stmt->execute()) {
        echo json_encode(['status' => 'success']);
    } else {
        if ($conn->errno === 1062 && strpos($conn->error, "username") !== false) {
            echo json_encode(['status' => 'error', 'msg' => 'Username is already taken. Leave login credentials blank or use a different username.']);
            exit();
        }
        echo json_encode(['status' => 'error', 'msg' => 'DB Error: ' . $conn->error]);
    }
    exit();
}

/* ============================================================
 * AJAX: SOFT DELETE
 * GET  : patients.php?delete_id=N
 * Returns { "status": "success"|"error" }
 * ============================================================ */
if (isset($_GET['delete_id'])) {
    ob_clean();
    header('Content-Type: application/json');

    $delete_id = (int) $_GET['delete_id'];
    $stmt      = $conn->prepare("UPDATE patients SET status = 0 WHERE patient_id = ?");
    $stmt->bind_param("i", $delete_id);

    echo json_encode(['status' => $stmt->execute() ? 'success' : 'error']);
    exit();
}


/* ============================================================
 * FETCH ALL ACTIVE PATIENTS  (status = 1)
 * ============================================================ */
$patients = $conn->query("
    SELECT * FROM patients
    WHERE  status = 1
    ORDER  BY patient_id DESC
");
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

<style>
/* ============================================================
 * STYLES — Patient Records
 * ============================================================ */
:root {
    --green-dark:   #004d26;
    --green-mid:    #006633;
    --green-accent: #00a651;
    --green-light:  #20c997;
    --danger:       #dc3545;
    --danger-dark:  #c0392b;
    --blue:         #007bff;
    --blue-dark:    #0056b3;
    --text-dark:    #1a1a2e;
    --text-muted:   #64748b;
    --border-soft:  #d6e8d7;
    --bg-input:     #f7fcf7;
}

.record-card {
    background:      rgba(255,255,255,0.15);
    backdrop-filter: blur(10px);
    padding:         25px;
    border-radius:   20px;
    color:           white;
    box-shadow:      0 10px 25px rgba(0,0,0,0.3);
}

/* ── Top Bar ─────────────────────────────────────────────── */
.top-bar {
    display:         flex;
    justify-content: space-between;
    align-items:     center;
    margin-bottom:   25px;
    padding:         20px;
    background:      rgba(0,166,81,0.1);
    border-radius:   15px;
    border:          1px solid rgba(0,166,81,0.2);
    backdrop-filter: blur(5px);
}

.top-bar h2 {
    margin:      0;
    color:       white;
    font-size:   24px;
    font-weight: 700;
    display:     flex;
    align-items: center;
    gap:         10px;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
}

.top-bar h2 i { color: var(--green-accent); font-size: 28px; }

/* ── Add/Edit Modal ──────────────────────────────────────── */
#patientFormContainer {
    display:       none;
    position:      fixed;
    top:           50%;
    left:          50%;
    transform:     translate(-50%, -50%);
    z-index:       1100;
    background:    white;
    padding:       20px;
    border-radius: 20px;
    width:         min(88vw, 820px);
    max-width:     820px;
    max-height:    88vh;
    overflow-y:    auto;
    box-shadow:    0 18px 48px rgba(0,0,0,0.30);
    animation:     slideDown 0.3s ease;
}

@media (max-width: 768px) {
    #patientFormContainer {
        padding: 16px;
        width:    100vw;
        height:   100vh;
        border-radius: 0;
    }
}

.form-grid {
    display:               grid;
    grid-template-columns: 1fr 1fr;
    gap:                   12px;
}

.form-group {
    margin-bottom: 10px;
}

@media (min-width: 1200px) {
    #patientFormContainer {
        width: min(80vw, 1100px);
    }
}

.modal-overlay {
    display:         none;
    position:        fixed;
    inset:           0;
    background:      rgba(0,0,0,0.7);
    backdrop-filter: blur(5px);
    z-index:         1050;
}

.modal-show { display: block !important; }

@keyframes slideDown {
    from { opacity: 0; transform: translate(-50%, calc(-50% - 24px)); }
    to   { opacity: 1; transform: translate(-50%, -50%); }
}

#patientFormContainer h3 {
    margin:         0 0 24px;
    color:          var(--green-dark);
    font-size:      22px;
    font-weight:    800;
    text-align:     center;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

/* ── Form Grid ───────────────────────────────────────────── */
.form-grid {
    display:               grid;
    grid-template-columns: 1fr 1fr;
    gap:                   16px;
}

.form-group            { display: flex; flex-direction: column; }
.form-group.full-width { grid-column: span 2; }

.form-group label {
    font-size:     13px;
    font-weight:   700;
    margin-bottom: 8px;
    color:         #084c24;
    display:       flex;
    align-items:   center;
    gap:           8px;
}

.form-group label i { color: var(--green-accent); font-size: 14px; }

.password-wrap { position: relative; }
.password-wrap input { padding-right: 42px; }

.eye-btn {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    color: #666;
    font-size: 14px;
    padding: 0;
}
.eye-btn:hover { color: var(--green-accent); }

.strength-meter,
.pass-requirements {
    margin-top: 10px;
}

.strength-bars {
    display: flex;
    gap: 5px;
    margin-bottom: 6px;
}

.strength-bar {
    flex: 1;
    height: 6px;
    border-radius: 4px;
    background: #e0e0e0;
    transition: background 0.25s ease;
}

.strength-bar.active-weak    { background: #e53935; }
.strength-bar.active-fair    { background: #fb8c00; }
.strength-bar.active-good    { background: #fdd835; }
.strength-bar.active-strong  { background: #43a047; }
.strength-bar.active-vstrong { background: #1b5e20; }

.strength-label {
    font-size: 12px;
    color: #666;
    font-weight: 700;
}

.pass-requirements {
    background: #f8f9fa;
    border: 1px solid #e8e8e8;
    border-radius: 10px;
    padding: 12px 14px;
}

.pass-requirements p {
    margin: 0 0 8px;
    font-size: 11px;
    font-weight: 700;
    color: #555;
    text-transform: uppercase;
    letter-spacing: .3px;
}

.req-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #666;
    margin-bottom: 6px;
}

.req-item:last-child { margin-bottom: 0; }

.req-item i {
    width: 16px;
    text-align: center;
    color: #ccc;
}

.req-item.met {
    color: #2e7d32;
}

.req-item.met i {
    color: #43a047;
}

.req-item.unmet i {
    color: #e53935;
}

input, select, textarea {
    width:         100%;
    padding:       10px 12px;
    border:        1px solid var(--border-soft);
    border-radius: 12px;
    background:    var(--bg-input);
    color:         #1f3822;
    outline:       none;
    font-size:     13px;
    box-sizing:    border-box;
    transition:    border-color 0.25s, box-shadow 0.25s, transform 0.25s;
}

textarea { min-height: 80px; resize: vertical; }

input:focus, select:focus, textarea:focus {
    border-color: var(--green-accent);
    box-shadow:   0 0 0 5px rgba(0,166,81,0.1);
    transform:    translateY(-1px);
}

.form-actions {
    margin-top:      24px;
    display:         flex;
    gap:             12px;
    justify-content: center;
    flex-wrap:       wrap;
}

.form-actions .btn {
    min-width:       160px;
    justify-content: center;
    padding:         14px 28px;
    font-size:       15px;
}

/* ── Buttons ─────────────────────────────────────────────── */
.btn {
    padding:       10px 18px;
    border:        none;
    border-radius: 10px;
    cursor:        pointer;
    font-weight:   700;
    font-size:     14px;
    display:       inline-flex;
    align-items:   center;
    gap:           8px;
    transition:    transform 0.2s, box-shadow 0.2s;
    color:         white;
}

.btn:hover { transform: translateY(-2px); }

.btn-green {
    background: linear-gradient(135deg, #2f9e44, var(--green-light));
    box-shadow: 0 6px 16px rgba(32,201,151,0.22);
}

.btn-gray {
    background: linear-gradient(135deg, #6c757d, #495057);
    box-shadow: 0 6px 16px rgba(73,80,87,0.22);
}

/* ── Search ──────────────────────────────────────────────── */
.search-wrapper { margin-bottom: 16px; }

.search-box {
    display:       flex;
    align-items:   center;
    background:    white;
    border-radius: 12px;
    border:        1px solid var(--border-soft);
    padding:       10px 16px;
    gap:           10px;
    max-width:     360px;
    box-shadow:    0 2px 10px rgba(0,0,0,0.06);
    transition:    border-color 0.2s;
}

.search-box:focus-within  { border-color: var(--green-accent); }
.search-box .search-icon  { color: var(--green-accent); font-size: 15px; flex-shrink: 0; }

.search-box input {
    border: none; outline: none; background: transparent;
    font-size: 14px; color: #1f3822; width: 100%;
    padding: 0; transform: none; box-shadow: none;
}

.search-box input:focus { border: none; box-shadow: none; transform: none; }
.search-box input::placeholder { color: #aaa; }

.search-box .clear-btn {
    color: #aaa; font-size: 14px; cursor: pointer;
    display: none; flex-shrink: 0; transition: color 0.2s;
}

.search-box .clear-btn:hover { color: var(--danger); }

.no-results {
    display:       none;
    margin-top:    12px;
    padding:       12px 16px;
    background:    rgba(255,255,255,0.07);
    border:        1px dashed rgba(255,255,255,0.18);
    border-radius: 12px;
    max-width:     360px;
    align-items:   center;
    gap:           10px;
}

.no-results i    { color: rgba(255,255,255,0.4); font-size: 15px; flex-shrink: 0; }
.no-results span { font-size: 13px; color: rgba(255,255,255,0.6); font-weight: 500; }
.no-results span strong { color: rgba(255,255,255,0.85); font-weight: 700; }

/* ── Table Controls ──────────────────────────────────────── */
.table-controls {
    display:         flex;
    justify-content: space-between;
    align-items:     center;
    margin-bottom:   12px;
    flex-wrap:       wrap;
    gap:             10px;
}

.row-count        { font-size: 13px; color: rgba(255,255,255,0.7); font-weight: 500; }
.row-count strong { color: white; font-weight: 700; }

.rows-per-page {
    display:     flex;
    align-items: center;
    gap:         8px;
    font-size:   13px;
    color:       rgba(255,255,255,0.7);
}

.rows-per-page select {
    width: auto; max-width: 80px; padding: 6px 10px;
    border-radius: 8px; font-size: 13px;
    border: 1px solid rgba(255,255,255,0.3);
    background: white; color: #1f3822;
    cursor: pointer; transform: none; box-shadow: none;
}

.rows-per-page select:focus { border-color: var(--green-accent); box-shadow: none; transform: none; }

/* ── Table ───────────────────────────────────────────────── */
.table-responsive {
    width:         100%;
    overflow-x:    auto;
    border-radius: 15px;
    box-shadow:    0 8px 25px rgba(0,0,0,0.1);
}

table {
    width:           100%;
    border-collapse: separate;
    border-spacing:  0;
    background:      white;
    color:           #333;
    border-radius:   15px;
    overflow:        hidden;
    box-shadow:      0 4px 15px rgba(0,0,0,0.05);
}

th {
    background:  linear-gradient(135deg, var(--green-dark), var(--green-mid));
    color:       white;
    padding:     18px 15px;
    font-size:   14px;
    font-weight: 600;
    text-align:  left;
    position:    sticky;
    top:         0;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
}

td {
    padding:        14px 15px;
    border-bottom:  1px solid #f0f0f0;
    font-size:      13px;
    vertical-align: top;
    word-wrap:      break-word;
}

tbody tr:nth-child(even) { background: #fafafa; }
tbody tr:hover           { background: #e8f5e8; transition: background 0.2s; }

th.sortable       { cursor: pointer; user-select: none; white-space: nowrap; }
th.sortable:hover { background: linear-gradient(135deg, var(--green-mid), #008844); }

th.sortable .sort-icon { margin-left: 6px; font-size: 11px; opacity: 0.5; }
th.sortable.asc  .sort-icon,
th.sortable.desc .sort-icon { opacity: 1; color: var(--green-light); }

.address-col {
    font-size:   11px;
    color:       #555;
    white-space: normal;
    word-break:  break-word;
    line-height: 1.4;
    max-width:   200px;
}

/* ── Row Action Buttons ──────────────────────────────────── */
.action-btns { display: flex; gap: 3px; justify-content: center; flex-wrap: wrap; }

.action-icon-btn {
    width:           32px;
    height:          32px;
    border-radius:   8px;
    border:          none;
    cursor:          pointer;
    display:         flex;
    align-items:     center;
    justify-content: center;
    font-size:       13px;
    color:           white;
    transition:      transform 0.2s, box-shadow 0.2s;
}

.action-icon-btn:hover { transform: translateY(-2px); }

.edit-btn   { background: linear-gradient(135deg, var(--blue), var(--blue-dark)); box-shadow: 0 2px 8px rgba(0,123,255,0.3); }
.delete-btn { background: linear-gradient(135deg, var(--danger), #c82333);        box-shadow: 0 2px 8px rgba(220,53,69,0.3); }
.export-btn { background: linear-gradient(135deg, #fd7e14, #e06910);               box-shadow: 0 2px 8px rgba(253,126,20,0.3); }

/* ── Pagination ──────────────────────────────────────────── */
.pagination-wrapper {
    display:         flex;
    justify-content: center;
    align-items:     center;
    gap:             6px;
    margin-top:      16px;
    flex-wrap:       wrap;
}

.page-btn {
    min-width:       36px;
    height:          36px;
    padding:         0 10px;
    border-radius:   8px;
    border:          1px solid rgba(255,255,255,0.2);
    background:      rgba(255,255,255,0.08);
    color:           rgba(255,255,255,0.8);
    font-size:       13px;
    font-weight:     600;
    cursor:          pointer;
    transition:      all 0.2s;
    display:         flex;
    align-items:     center;
    justify-content: center;
}

.page-btn:hover   { background: rgba(0,166,81,0.25); border-color: var(--green-accent); color: white; }
.page-btn.active  { background: linear-gradient(135deg, #2f9e44, var(--green-light)); border-color: transparent; box-shadow: 0 4px 12px rgba(32,201,151,0.3); }
.page-btn:disabled { opacity: 0.35; cursor: not-allowed; pointer-events: none; }

/* ── Delete Confirmation Modal ───────────────────────────── */
#deleteModal {
    position:        fixed;
    inset:           0;
    background:      rgba(0,0,0,0.75);
    display:         none;
    align-items:     center;
    justify-content: center;
    z-index:         1000;
    backdrop-filter: blur(4px);
}

.modal-box {
    background:    white;
    padding:       36px 32px;
    border-radius: 24px;
    width:         360px;
    text-align:    center;
    color:         var(--text-dark);
    box-shadow:    0 25px 60px rgba(0,0,0,0.35);
    position:      relative;
    overflow:      hidden;
    animation:     modalPop 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes modalPop {
    from { opacity: 0; transform: scale(0.85); }
    to   { opacity: 1; transform: scale(1); }
}

.modal-accent-bar {
    position:      absolute;
    top: 0; left: 0; right: 0;
    height:        5px;
    background:    linear-gradient(90deg, var(--danger), #ff6b6b);
    border-radius: 24px 24px 0 0;
}

.modal-icon {
    width:           72px; height: 72px;
    background:      linear-gradient(135deg, #ffe0e3, #ffc2c7);
    border-radius:   50%;
    display:         flex;
    align-items:     center;
    justify-content: center;
    margin:          0 auto 20px;
    box-shadow:      0 6px 20px rgba(220,53,69,0.2);
}

.modal-icon i     { font-size: 28px; color: var(--danger); }
.modal-title      { margin: 0 0 8px; font-size: 22px; font-weight: 800; color: var(--danger-dark); }
.modal-subtitle   { margin: 0 0 6px; font-size: 14px; color: #666; line-height: 1.5; }
.modal-patient-name { margin: 0 0 20px; font-size: 16px; font-weight: 700; color: var(--text-dark); }

.modal-warning {
    background:    #fff8e1;
    border:        1px solid #ffe082;
    border-radius: 10px;
    padding:       10px 14px;
    margin-bottom: 28px;
    display:       flex;
    align-items:   center;
    gap:           8px;
    text-align:    left;
}

.modal-warning i    { color: #f59e0b; font-size: 15px; flex-shrink: 0; }
.modal-warning span { font-size: 12px; color: #7a5c00; font-weight: 600; }
.modal-buttons      { display: flex; gap: 12px; justify-content: center; }

.modal-btn-cancel {
    flex:            1;
    padding:         13px 20px;
    border-radius:   12px;
    border:          2px solid #e0e0e0;
    background:      white;
    color:           #555;
    font-size:       14px;
    font-weight:     700;
    cursor:          pointer;
    display:         flex;
    align-items:     center;
    justify-content: center;
    gap:             8px;
    transition:      background 0.2s;
}

.modal-btn-cancel:hover { background: #f5f5f5; }

.modal-btn-delete {
    flex:            1;
    padding:         13px 20px;
    border-radius:   12px;
    border:          none;
    background:      linear-gradient(135deg, var(--danger), var(--danger-dark));
    color:           white;
    font-size:       14px;
    font-weight:     700;
    cursor:          pointer;
    display:         flex;
    align-items:     center;
    justify-content: center;
    gap:             8px;
    box-shadow:      0 6px 18px rgba(220,53,69,0.35);
    transition:      transform 0.2s, box-shadow 0.2s;
}

.modal-btn-delete:hover { transform: translateY(-2px); box-shadow: 0 10px 22px rgba(220,53,69,0.4); }

@media (max-width: 640px) {
    #patientFormContainer {
        top: auto;
        left: 0;
        right: 0;
        bottom: 0;
        transform: none;
        width: 100vw;
        max-width: none;
        height: min(92dvh, 92vh);
        max-height: min(92dvh, 92vh);
        padding: 18px 16px 22px;
        border-radius: 18px 18px 0 0;
    }

    #patientFormContainer h3 {
        font-size: 18px;
        line-height: 1.25;
        margin-bottom: 16px;
        letter-spacing: 0.05em;
    }

    #patientFormContainer .form-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    #patientFormContainer .form-group.full-width {
        grid-column: span 1;
    }

    #patientFormContainer input,
    #patientFormContainer select,
    #patientFormContainer textarea {
        min-height: 44px;
        font-size: 16px;
    }

    .form-actions,
    .modal-buttons {
        flex-direction: column;
    }

    .form-actions .btn,
    .modal-btn-cancel,
    .modal-btn-delete {
        width: 100%;
        min-width: 0;
    }

    #deleteModal {
        padding: 14px;
    }

    .modal-box {
        width: 100%;
        max-width: 380px;
        padding: 28px 20px 22px;
        border-radius: 18px;
    }

    .modal-icon {
        width: 58px;
        height: 58px;
        margin-bottom: 14px;
    }

    .modal-title {
        font-size: 20px;
    }
}
</style>


<div class="modal-overlay" id="modalOverlay" onclick="closeForm()"></div>
<div id="patientFormContainer">
    <h3 id="formTitle">New Patient Registration</h3>

    <form id="patientForm">
        <input type="hidden" name="patient_id" id="patient_id">

        <div class="form-grid">
            <div class="form-group full-width">
    <label>
        <i class="fa-solid fa-id-card"></i> Patient ID / ID Number
        <span id="id_auto_badge" style="display:none; margin-left:8px; font-size:10px;
            background:#e8f5e9; color:#2e7d32; border:1px solid #a5d6a7;
            border-radius:4px; padding:2px 6px; font-weight:700; letter-spacing: normal; vertical-align: middle;">
            AUTO-GENERATED
        </span>
    </label>

    <input type="hidden" name="id_number" id="id_number_hidden" value="">

    <input type="text" 
           id="id_number" 
           placeholder="00-0000-00" 
           maxlength="10" 
           required
           oninput="if(typeof formatID === 'function') { formatID(this); } document.getElementById('id_number_hidden').value=this.value;"
           style="font-family: monospace; letter-spacing: 2px; text-align: center; font-weight: bold;">

    <span id="id_guest_note" style="display:none; font-size:12px; color:#2e7d32; margin-top:5px; font-weight: 500;">
        <i class="fa-solid fa-circle-info"></i> ID is provided by the system — no need to type.
    </span>
    
    <span id="id_loading" style="display:none; font-size:12px; color:#888; margin-top:5px;">
        <i class="fa-solid fa-spinner fa-spin"></i> Generating unique ID...
    </span>
</div>

            <div class="form-group">
                <label><i class="fa-solid fa-user"></i> Full Name</label>
                <input type="text" name="full_name" id="full_name" placeholder="Enter full name" required>
            </div>

            <?php if ($is_admin): ?>
            <div class="form-group" id="usernameSection" style="display:none;">
                <label><i class="fa-solid fa-user-tag"></i> Username</label>
                <input type="text" name="username" id="username" placeholder="Enter patient username">
                <small id="usernameHint" style="display:block; margin-top:6px; color:#888; font-size:11px;">
                    Optional. Use this if the patient will log in with credentials.
                </small>
            </div>

            <div class="form-group full-width" id="passwordSection" style="display:none;">
                <label><i class="fa-solid fa-lock"></i> <span id="passwordLabel">Password</span></label>
                <div class="password-wrap">
                    <input type="password" name="password" id="password" placeholder="Enter password" oninput="checkPasswordStrength(this)">
                    <button type="button" class="eye-btn" onclick="togglePassword('password', this)">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>

                <div class="strength-meter" id="password_strength_meter" style="display:none;">
                    <div class="strength-bars">
                        <div class="strength-bar" id="password_bar1"></div>
                        <div class="strength-bar" id="password_bar2"></div>
                        <div class="strength-bar" id="password_bar3"></div>
                        <div class="strength-bar" id="password_bar4"></div>
                        <div class="strength-bar" id="password_bar5"></div>
                    </div>
                    <span class="strength-label" id="password_strength_label"></span>
                </div>

                <div class="pass-requirements" id="password_requirements" style="display:none;">
                    <p><i class="fa-solid fa-shield-halved"></i> Password Requirements</p>
                    <div class="req-item" id="req_length"><i class="fa-solid fa-circle-xmark"></i> Minimum 8 characters</div>
                    <div class="req-item" id="req_upper"><i class="fa-solid fa-circle-xmark"></i> At least 1 uppercase letter</div>
                    <div class="req-item" id="req_lower"><i class="fa-solid fa-circle-xmark"></i> At least 1 lowercase letter</div>
                    <div class="req-item" id="req_number"><i class="fa-solid fa-circle-xmark"></i> At least 1 number</div>
                    <div class="req-item" id="req_special"><i class="fa-solid fa-circle-xmark"></i> At least 1 special character (!@#$%^&*)</div>
                </div>

                <small id="passwordHint" style="display:none; margin-top:6px; color:#888; font-size:11px;">
                    Leave blank to keep the current password unchanged.
                </small>
            </div>

            <div class="form-group full-width" id="confirmPasswordSection">
                <label><i class="fa-solid fa-lock"></i> <span id="confirmPasswordLabel">Confirm Password</span></label>
                <div class="password-wrap">
                    <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm password">
                    <button type="button" class="eye-btn" onclick="togglePassword('confirm_password', this)">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label><i class="fa-solid fa-venus-mars"></i> Gender</label>
                <select name="gender" id="gender">
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>
            </div>

            <div class="form-group">
                <label><i class="fa-solid fa-calendar"></i> Birthdate</label>
                <input type="date" name="birthdate" id="birthdate" min="<?= date('Y-m-d', strtotime('-120 years')) ?>" max="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="form-group">
                <label><i class="fa-solid fa-tag"></i> Category</label>
                <select name="category" id="category">
                    <option>Student</option>
                    <option>Faculty</option>
                    <option>Staff</option>
                    <option>Visitor</option>
                </select>
            </div>

            <div class="form-group">
                <label><i class="fa-solid fa-phone"></i> Contact Number</label>
                <input type="text" name="contact_number" id="contact_number" placeholder="e.g. 09XX-XXX-XXXX"
                inputmode="numeric" maxlength="11" pattern="^09[0-9]{9}$">
            </div>

            <div class="form-group full-width">
                <label><i class="fa-solid fa-map-marker-alt"></i> Address</label>
                <textarea name="address" id="address" rows="2" placeholder="Full home address"></textarea>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-green" id="submitBtn">
                <i class="fa-solid fa-save"></i> Save Patient
            </button>
            <button type="button" class="btn btn-gray" onclick="closeForm()">
                <i class="fa-solid fa-times"></i> Cancel
            </button>
        </div>
    </form>
</div>
    <div class="record-card">
        <div class="top-bar">
            <h2><i class="fa-solid fa-users"></i> Patient Records</h2>
            <button class="btn btn-green" onclick="openAddForm()">
                <i class="fa-solid fa-plus"></i> Add Patient
            </button>
    </div>
    

    <!-- ── Search ────────────────────────────────────────── -->
    <div class="search-wrapper">
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <input type="text" id="patientSearch"
                   placeholder="Search by name or ID..."
                   oninput="searchPatients()">
            <i class="fa-solid fa-xmark clear-btn" id="clearSearch"
               onclick="clearSearch()"></i>
        </div>
        <div class="no-results" id="noResults">
            <i class="fa-solid fa-user-slash"></i>
            <span>No results for <strong id="noResultsQuery"></strong>
                  — try a different name or ID.</span>
        </div>
    </div>

    <!-- ── Table Controls ─────────────────────────────────── -->
    <div class="table-controls">
        <div class="row-count" id="rowCount">
            Showing <strong>0</strong> of <strong>0</strong> patients
        </div>
        <div class="rows-per-page">
            Rows per page:
            <select id="rowsPerPage" onchange="changeRowsPerPage()">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
        </div>
    </div>

    <!-- ── Patients Table ─────────────────────────────────── -->
    <div class="table-responsive">
        <table id="patientTable">
            <thead>
                <tr>
                    <th class="sortable" onclick="sortTable(0)" data-col="0">
                        ID <span class="sort-icon fa-solid fa-sort"></span>
                    </th>
                    <th class="sortable" onclick="sortTable(1)" data-col="1">
                        Name <span class="sort-icon fa-solid fa-sort"></span>
                    </th>
                    <th>Gender</th>
                    <th class="sortable" onclick="sortTable(3)" data-col="3">
                        Age <span class="sort-icon fa-solid fa-sort"></span>
                    </th>
                    <th class="sortable" onclick="sortTable(4)" data-col="4">
                        Category <span class="sort-icon fa-solid fa-sort"></span>
                    </th>
                    <th>Contact</th>
                    <th>Address</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $patients->fetch_assoc()):
                    $age = "N/A";
                    if (!empty($row['birthdate'])) {
                        $dob = new DateTime($row['birthdate']);
                        $age = $dob->diff(new DateTime())->y . " yrs";
                    }
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['id_number']) ?></strong></td>
                    <td><?= htmlspecialchars($row['full_name']) ?></td>
                    <td><?= htmlspecialchars($row['gender']) ?></td>
                    <td><?= $age ?></td>
                    <td><?= htmlspecialchars($row['category']) ?></td>
                    <td><?= htmlspecialchars($row['contact_number']) ?></td>
                    <td class="address-col"><?= htmlspecialchars($row['address']) ?></td>
                    <td>
                        <div class="action-btns">
                            <button class="action-icon-btn edit-btn"
                                    title="Edit Patient"
                                    onclick='openEditForm(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, "UTF-8") ?>)'>
                                <i class="fa-solid fa-edit"></i>
                            </button>
                            <button class="action-icon-btn delete-btn"
                                    title="Delete Patient"
                                    onclick="confirmDelete(<?= $row['patient_id'] ?>, '<?= addslashes($row['full_name']) ?>')">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                            <!-- Orange button → downloads patient PDF with consultation history -->
                            <button class="action-icon-btn export-btn"
                                    title="Export Patient PDF"
                                    onclick="exportPatientPDF(<?= $row['patient_id'] ?>, '<?= addslashes($row['full_name']) ?>')">
                                <i class="fa-solid fa-file-pdf"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination-wrapper" id="paginationWrapper"></div>

</div>


<!-- ── Delete Confirmation Modal ─────────────────────────── -->
<div id="deleteModal">
    <div class="modal-box">
        <div class="modal-accent-bar"></div>
        <div class="modal-icon"><i class="fa-solid fa-trash"></i></div>
        <h3 class="modal-title">Remove Patient?</h3>
        <p class="modal-subtitle">You are about to remove:</p>
        <p class="modal-patient-name">"<span id="delName"></span>"</p>
        <div class="modal-warning">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>This will move the patient to the Recycle Bin. You can restore them from there.</span>
        </div>
        <div class="modal-buttons">
            <button class="modal-btn-cancel" onclick="closeDeleteModal()">
                <i class="fa-solid fa-xmark"></i> Cancel
            </button>
            <button class="modal-btn-delete" onclick="executeDelete()">
                <i class="fa-solid fa-trash"></i> Yes, Remove
            </button>
        </div>
    </div>
</div>


<script>
/* ============================================================
 * JavaScript — Patient Records Module
 * ============================================================ */

/* ── State ────────────────────────────────────────────────── */
var deleteTargetId = null;
var currentPage    = 1;
var rowsPerPage    = 10;
var sortColIndex   = -1;
var sortDirection  = 'asc';

/* ============================================================
 * PATIENT FORM SCRIPTS (Auto-ID & AJAX Save)
 * ============================================================ */

// 1. AUTO-GENERATE VISITOR ID KAPAG BINAGO ANG CATEGORY
document.getElementById('category').addEventListener('change', function() {
    const category = this.value;
    const idInput = document.getElementById('id_number');
    const patientId = document.getElementById('patient_id').value;

    // Huwag baguhin ang ID kung "Edit Mode" (may patientId na sa hidden field)
    if (patientId !== "") return;

    if (category === 'Visitor') {
        idInput.readOnly = true;
        idInput.style.backgroundColor = "#e9ecef";
        idInput.value = "Generating ID..."; // Ipakita sa user na kumukuha pa ng ID

        // Tawagin ang PHP para makuha ang bagong ID (MM-YYYY-XX)
        fetch('patients.php?get_visitor_id=1')
            .then(res => res.text())
            .then(newId => {
                idInput.value = newId.trim();
            })
            .catch(err => {
                console.error("Error fetching ID:", err);
                idInput.value = "Error";
                idInput.placeholder = "Error generating ID";
            });
    } else {
        // Ibalik sa manual input at default placeholder kung Student, Faculty, o Staff
        idInput.readOnly = false;
        idInput.style.backgroundColor = "white";
        idInput.value = "";
        idInput.placeholder = "00-0000-00";
    }
});

// 2. AJAX SAVE (ADD OR UPDATE)
document.getElementById('patientForm').onsubmit = function (e) {
    e.preventDefault();

    // --- FIX: VALIDATION BAGO MAG-SAVE ---
    const idValue = document.getElementById('id_number').value.trim();
    const category = document.getElementById('category').value;
    
    if (idValue === "" || idValue === "Generating ID..." || idValue === "Error") {
        showToast('Please wait for the Patient ID to generate or enter a valid ID.', 'error');
        return; // Ititigil ang submit dito
    }

    // Siguraduhin na ang manual entry ay sumusunod sa tamang standard pattern kung hindi naman Visitor
    if (category !== 'Visitor') {
        const idPattern = /^[0-9]{2}-[0-9]{4}-[0-9]{2}$/;
        if (!idPattern.test(idValue)) {
            showToast('Invalid ID format. Please use the 00-0000-00 format.', 'error');
            return;
        }
    }
    // -------------------------------------

    var formData = new FormData(this);
    formData.append('ajax_save_patient', '1');

    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

    fetch('patients.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            var isUpdate = document.getElementById('patient_id').value !== '';
            showToast(isUpdate ? 'Patient updated successfully!' : 'Patient added successfully!', 'success');
            closeForm();
            // I-reload ang patients content sa loob ng SPA (hindi buong dashboard)
            setTimeout(() => loadPage('patients.php'), 1200);
        } else {
            showToast(data.msg || 'Error saving record', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Patient';
        }
    })
    .catch(err => {
        showToast('Server error. Please try again.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Patient';
    });
};

// Siguraduhin na malinis ang fields pag nag-Add Patient
function checkPasswordStrength(input) {
    var password = input.value;
    var meter    = document.getElementById('password_strength_meter');
    var reqBox   = document.getElementById('password_requirements');

    if (!meter || !reqBox) return;
    if (password.length === 0) {
        meter.style.display = 'none';
        reqBox.style.display = 'none';
        return;
    }

    meter.style.display = 'block';
    reqBox.style.display = 'block';

    var checks = {
        length:  password.length >= 8,
        upper:   /[A-Z]/.test(password),
        lower:   /[a-z]/.test(password),
        number:  /[0-9]/.test(password),
        special: /[!@#$%^&*]/.test(password)
    };

    updateReq('req_length',  checks.length);
    updateReq('req_upper',   checks.upper);
    updateReq('req_lower',   checks.lower);
    updateReq('req_number',  checks.number);
    updateReq('req_special', checks.special);

    var score = Object.values(checks).filter(Boolean).length;
    if (password.length >= 12) score = Math.min(score + 1, 5);

    var levels = [
        { label: '',          cls: '' },
        { label: 'Very Weak', cls: 'active-weak' },
        { label: 'Weak',      cls: 'active-weak' },
        { label: 'Fair',      cls: 'active-fair' },
        { label: 'Good',      cls: 'active-good' },
        { label: 'Strong',    cls: 'active-strong' }
    ];

    var allMet    = Object.values(checks).every(Boolean);
    var level     = levels[Math.min(score, 5)];
    var labelText = level.label;
    var barClass  = level.cls;

    if (allMet && password.length >= 12) {
        labelText = 'Very Strong';
        barClass  = 'active-vstrong';
    }

    for (var i = 1; i <= 5; i++) {
        var bar = document.getElementById('password_bar' + i);
        if (!bar) continue;
        bar.className = 'strength-bar';
        if (i <= score) bar.classList.add(barClass);
    }

    var labelEl = document.getElementById('password_strength_label');
    if (labelEl) {
        labelEl.textContent = labelText;
        labelEl.style.color = {
            'active-weak': '#e53935',
            'active-fair': '#fb8c00',
            'active-good': '#fdd835',
            'active-strong': '#43a047',
            'active-vstrong': '#1b5e20'
        }[barClass] || '#666';
    }
}

function updateReq(id, met) {
    var el = document.getElementById(id);
    if (!el) return;
    var icon = el.querySelector('i');
    if (met) {
        el.classList.add('met');
        el.classList.remove('unmet');
        if (icon) icon.className = 'fa-solid fa-circle-check';
    } else {
        el.classList.remove('met');
        el.classList.add('unmet');
        if (icon) icon.className = 'fa-solid fa-circle-xmark';
    }
}

function resetPasswordStrength() {
    var meter = document.getElementById('password_strength_meter');
    var reqBox = document.getElementById('password_requirements');
    if (meter) meter.style.display = 'none';
    if (reqBox) reqBox.style.display = 'none';

    for (var i = 1; i <= 5; i++) {
        var bar = document.getElementById('password_bar' + i);
        if (bar) bar.className = 'strength-bar';
    }

    var labelEl = document.getElementById('password_strength_label');
    if (labelEl) {
        labelEl.textContent = '';
        labelEl.style.color = '#666';
    }

    ['req_length','req_upper','req_lower','req_number','req_special'].forEach(function(id) {
        var item = document.getElementById(id);
        if (!item) return;
        item.classList.remove('met','unmet');
        var icon = item.querySelector('i');
        if (icon) icon.className = 'fa-solid fa-circle-xmark';
    });
}

function togglePassword(inputId, btn) {
    var input = document.getElementById(inputId);
    if (!input) return;
    var icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        if (icon) icon.className = 'fa-solid fa-eye-slash';
    } else {
        input.type = 'password';
        if (icon) icon.className = 'fa-solid fa-eye';
    }
}

function configurePatientPasswordFields(isCreateMode) {
    var label = document.getElementById('passwordLabel');
    var confirmLabel = document.getElementById('confirmPasswordLabel');
    var hint = document.getElementById('passwordHint');
    var pwd = document.getElementById('password');
    var confirm = document.getElementById('confirm_password');

    if (!label || !confirmLabel || !hint || !pwd || !confirm) return;

    if (isCreateMode) {
        label.innerText = 'Password';
        confirmLabel.innerText = 'Confirm Password';
        pwd.placeholder = 'Enter password';
        confirm.placeholder = 'Confirm password';
        hint.style.display = 'none';
    } else {
        label.innerText = 'New Password';
        confirmLabel.innerText = 'Confirm New Password';
        pwd.placeholder = 'Leave blank to keep current password';
        confirm.placeholder = 'Confirm new password';
        hint.style.display = 'block';
    }

    pwd.value = '';
    confirm.value = '';
    pwd.type = 'password';
    confirm.type = 'password';
    document.querySelectorAll('.eye-btn i').forEach(function (el) { el.className = 'fa-solid fa-eye'; });
    resetPasswordStrength();
}

function openAddForm() {
    document.getElementById('patientForm').reset();
    document.getElementById('patient_id').value = '';
    configurePatientPasswordFields(true);
    
    const idInput = document.getElementById('id_number');
    idInput.readOnly = false;
    idInput.style.backgroundColor = "white";
    idInput.placeholder = "00-0000-00";

    // Hide username & password — not needed when adding
    var usernameSection = document.getElementById('usernameSection');
    var passwordSection = document.getElementById('passwordSection');
    var confirmSection  = document.getElementById('confirmPasswordSection');
    if (usernameSection) usernameSection.style.display = 'none';
    if (passwordSection) passwordSection.style.display = 'none';
    if (confirmSection)  confirmSection.style.display  = 'none';
    
    document.getElementById('formTitle').innerText = 'New Patient Registration';
    document.getElementById('submitBtn').innerHTML = '<i class="fa-solid fa-save"></i> Save Patient';
    document.getElementById('patientFormContainer').classList.add('modal-show');
    document.getElementById('modalOverlay').classList.add('modal-show');
}

/* ── Toast ────────────────────────────────────────────────── */
function showToast(message, type) {
    var existing = document.getElementById('toastNotif');
    if (existing) existing.remove();

    var toast     = document.createElement('div');
    toast.id      = 'toastNotif';
    var isSuccess = type !== 'error';

    Object.assign(toast.style, {
        position:     'fixed',
        bottom:       '30px',
        right:        '30px',
        background:   isSuccess
                        ? 'linear-gradient(135deg,#2f9e44,#20c997)'
                        : 'linear-gradient(135deg,#dc3545,#c82333)',
        color:        'white',
        padding:      '16px 24px',
        borderRadius: '14px',
        fontSize:     '15px',
        fontWeight:   '700',
        display:      'flex',
        alignItems:   'center',
        gap:          '10px',
        boxShadow:    '0 8px 25px rgba(0,0,0,0.25)',
        zIndex:       '9999',
        animation:    'toastIn 0.4s ease',
        maxWidth:     '320px',
    });

    toast.innerHTML = '<span style="font-size:20px">' + (isSuccess ? '✅' : '❌') + '</span>'
                    + '<span>' + message + '</span>';
    document.body.appendChild(toast);

    setTimeout(function () {
        toast.style.animation = 'toastOut 0.4s ease forwards';
        setTimeout(function () { toast.remove(); }, 400);
    }, 3000);
}

if (!document.getElementById('toastStyles')) {
    var ts = document.createElement('style');
    ts.id  = 'toastStyles';
    ts.textContent =
        '@keyframes toastIn  { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }' +
        '@keyframes toastOut { from{opacity:1;transform:translateY(0)} to{opacity:0;transform:translateY(20px)} }';
    document.head.appendChild(ts);
}


/* ── Search ───────────────────────────────────────────────── */
function searchPatients() {
    var input      = document.getElementById('patientSearch');
    var filter     = input.value.toLowerCase().trim();
    var rows       = document.querySelectorAll('#patientTable tbody tr');
    var clearBtn   = document.getElementById('clearSearch');
    var matchCount = 0;

    clearBtn.style.display = filter.length > 0 ? 'inline' : 'none';

    rows.forEach(function (row) {
        var name  = (row.cells[1] || {}).innerText.toLowerCase();
        var idNum = (row.cells[0] || {}).innerText.toLowerCase();
        var match = !filter || name.includes(filter) || idNum.includes(filter);

        row.dataset.searchHidden = match ? 'false' : 'true';
        if (!match) row.style.display = 'none';
        if (match)  matchCount++;
    });

    var noResults = document.getElementById('noResults');
    if (matchCount === 0 && filter.length > 0) {
        document.getElementById('noResultsQuery').innerText = '"' + input.value.trim() + '"';
        noResults.style.display = 'flex';
    } else {
        noResults.style.display = 'none';
    }

    currentPage = 1;
    applyPagination();
}

function clearSearch() {
    document.getElementById('patientSearch').value = '';
    searchPatients();
}


/* ── Pagination ───────────────────────────────────────────── */
function getVisibleRows() {
    return Array.from(document.querySelectorAll('#patientTable tbody tr'))
        .filter(function (r) { return r.dataset.searchHidden !== 'true'; });
}

function applyPagination() {
    var visible    = getVisibleRows();
    var total      = visible.length;
    var totalPages = Math.ceil(total / rowsPerPage) || 1;

    currentPage = Math.max(1, Math.min(currentPage, totalPages));

    var start = (currentPage - 1) * rowsPerPage;
    var end   = start + rowsPerPage;

    document.querySelectorAll('#patientTable tbody tr').forEach(function (r) {
        r.style.display = 'none';
    });
    visible.forEach(function (r, i) {
        if (i >= start && i < end) r.style.display = '';
    });

    var from = total === 0 ? 0 : start + 1;
    var to   = Math.min(end, total);
    document.getElementById('rowCount').innerHTML =
        'Showing <strong>' + from + '–' + to + '</strong> of <strong>' + total + '</strong> patient' + (total !== 1 ? 's' : '');

    renderPagination(totalPages);
}

function renderPagination(totalPages) {
    var wrapper = document.getElementById('paginationWrapper');
    if (!wrapper) return;
    wrapper.innerHTML = '';

    function makeBtn(html, disabled, onClick, extra) {
        var b       = document.createElement('button');
        b.className = 'page-btn' + (extra ? ' ' + extra : '');
        b.innerHTML = html;
        b.disabled  = disabled;
        if (!disabled) b.onclick = onClick;
        wrapper.appendChild(b);
    }

    makeBtn('<i class="fa-solid fa-chevron-left"></i>', currentPage === 1,
        function () { currentPage--; applyPagination(); });

    var sp = Math.max(1, currentPage - 2);
    var ep = Math.min(totalPages, sp + 4);
    if (ep - sp < 4) sp = Math.max(1, ep - 4);

    for (var i = sp; i <= ep; i++) {
        (function (p) {
            makeBtn(p, false, function () { currentPage = p; applyPagination(); },
                p === currentPage ? 'active' : '');
        })(i);
    }

    makeBtn('<i class="fa-solid fa-chevron-right"></i>', currentPage === totalPages,
        function () { currentPage++; applyPagination(); });
}

function changeRowsPerPage() {
    rowsPerPage = parseInt(document.getElementById('rowsPerPage').value);
    currentPage = 1;
    applyPagination();
}


/* ── Sorting ──────────────────────────────────────────────── */
function sortTable(colIndex) {
    var tbody = document.querySelector('#patientTable tbody');
    var rows  = Array.from(tbody.querySelectorAll('tr'));

    sortDirection = (sortColIndex === colIndex && sortDirection === 'asc') ? 'desc' : 'asc';
    sortColIndex  = colIndex;

    rows.sort(function (a, b) {
        var aT = (a.cells[colIndex] || {}).innerText.trim().toLowerCase();
        var bT = (b.cells[colIndex] || {}).innerText.trim().toLowerCase();

        if (colIndex === 3) { // Age column → numeric
            aT = parseInt(aT) || 0;
            bT = parseInt(bT) || 0;
            return sortDirection === 'asc' ? aT - bT : bT - aT;
        }
        return sortDirection === 'asc' ? aT.localeCompare(bT) : bT.localeCompare(aT);
    });

    rows.forEach(function (r) { tbody.appendChild(r); });

    document.querySelectorAll('th.sortable').forEach(function (th) {
        var icon = th.querySelector('.sort-icon');
        if (!icon) return;
        if (parseInt(th.dataset.col) === sortColIndex) {
            icon.className = 'sort-icon fa-solid ' + (sortDirection === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
            th.classList.toggle('asc',  sortDirection === 'asc');
            th.classList.toggle('desc', sortDirection === 'desc');
        } else {
            icon.className = 'sort-icon fa-solid fa-sort';
            th.classList.remove('asc', 'desc');
        }
    });

    currentPage = 1;
    applyPagination();
}

const contactInput = document.getElementById('contact_number');
if (contactInput) {
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
}


/* ── ID Number Auto-Formatter (00-0000-00) for standard patients ── */
const idInput = document.getElementById('id_number');
if (idInput) {
    idInput.addEventListener('input', function (e) {
        const category = document.getElementById('category').value;
        if (category === 'Visitor') return; // Do not auto-format generated visitor IDs
        
        let val = e.target.value.replace(/\D/g, '').substring(0, 8);
        let out = '';
        if (val.length > 0) out += val.substring(0, 2);
        if (val.length > 2) out += '-' + val.substring(2, 6);
        if (val.length > 6) out += '-' + val.substring(6, 8);
        e.target.value = out;
    });
}


/* ── Add / Edit Form ──────────────────────────────────────── */
/* ── Modal & Edit Controls ─────────────────────────────────── */
function openEditForm(patientData) {
    document.getElementById('formTitle').innerText = 'Edit Patient Record';
    document.getElementById('submitBtn').innerHTML = '<i class="fa-solid fa-save"></i> Update Patient';
    
    // Populate form fields
    document.getElementById('patient_id').value     = patientData.patient_id;
    document.getElementById('id_number').value      = patientData.id_number;
    document.getElementById('full_name').value      = patientData.full_name;
    document.getElementById('gender').value         = patientData.gender;
    document.getElementById('birthdate').value      = patientData.birthdate;
    document.getElementById('category').value       = patientData.category;
    document.getElementById('contact_number').value = patientData.contact_number;
    document.getElementById('address').value        = patientData.address;

    // Show username/password sections for admin (edit mode only)
    var usernameSection = document.getElementById('usernameSection');
    var passwordSection = document.getElementById('passwordSection');
    var confirmSection  = document.getElementById('confirmPasswordSection');
    if (usernameSection) usernameSection.style.display = 'block';
    if (passwordSection) passwordSection.style.display = 'block';
    if (confirmSection)  confirmSection.style.display  = 'block';

    var usernameField = document.getElementById('username');
    if (usernameField) {
        usernameField.value = patientData.username || '';
    }
    configurePatientPasswordFields(false);

    // Lock ID number during edit to prevent conflicts
    document.getElementById('id_number').readOnly = true;
    document.getElementById('id_number').style.backgroundColor = "#f8f9fa";

    document.getElementById('patientFormContainer').classList.add('modal-show');
    document.getElementById('modalOverlay').classList.add('modal-show');
}

function closeForm() {
    document.getElementById('patientFormContainer').classList.remove('modal-show');
    document.getElementById('modalOverlay').classList.remove('modal-show');
    document.getElementById('patientForm').reset();
}


/* ── Delete Modal ─────────────────────────────────────────── */
/* ── Deletion Logic ────────────────────────────────────────── */
function confirmDelete(id, name) {
    deleteTargetId = id;
    document.getElementById('delName').innerText = name;
    document.getElementById('deleteModal').style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
    deleteTargetId = null;
}

function executeDelete() {
    if (!deleteTargetId) return;

    fetch('patients.php?delete_id=' + deleteTargetId)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                showToast('Patient moved to Recycle Bin', 'success');
                loadPage('patients.php');
            } else {
                showToast('Failed to delete patient', 'error');
            }
        });
}

/* ── PDF Export ───────────────────────────────────────────── */
/**
 * exportPatientPDF(patientId, patientName)
 *
 * Calls export.php?type=patient_pdf&patient_id=N
 * The server generates a full patient profile PDF that
 * includes all consultation records and streams it back.
 */
function exportPatientPDF(patientId, patientName) {
    showToast('Generating PDF, please wait…', 'success');

    fetch('export.php?type=patient_pdf&patient_id=' + patientId)
        .then(function (res) {
            // Check for JSON error response (library not installed, etc.)
            var contentType = res.headers.get('Content-Type') || '';
            if (contentType.includes('application/json')) {
                return res.json().then(function (j) { throw new Error(j.error || 'PDF generation failed.'); });
            }
            if (!res.ok) throw new Error('Server returned ' + res.status);
            return res.blob();
        })
        .then(function (blob) {
            var url      = URL.createObjectURL(blob);
            var link     = document.createElement('a');
            var safeName = patientName.replace(/[^a-z0-9]/gi, '_');
            link.href     = url;
            link.download = 'Patient_' + safeName + '_' + patientId + '.pdf';
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        })
        .catch(function (err) {
            showToast('PDF export failed: ' + err.message, 'error');
        });
}


/* ── Init ─────────────────────────────────────────────────── */
applyPagination();
</script>
