<?php
/* =====================================================
   INITIALIZATION
   ===================================================== */
session_start();
require "db.php";
require_staff_login('admin');
redirect_direct_fragment_access();

/** @var mysqli $conn */

/* PROTECT PAGE — must be logged in */
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

/* ADMIN ONLY */
if ($_SESSION['role'] !== 'admin') {
    echo "<h2 style='color:white; text-align:center; margin-top:60px;'>
              <i class='fa-solid fa-lock'></i> Access Denied
          </h2>";
    exit();
}


/* =====================================================
   PASSWORD VALIDATION FUNCTION
   ===================================================== */
function validatePassword($password) {
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
    return null; // null = valid
}


/* =====================================================
   AJAX HANDLER: CREATE NEW STAFF ACCOUNT
   ===================================================== */
if (isset($_POST['action']) && $_POST['action'] === 'create') {
    ob_clean();
    header('Content-Type: application/json');

    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username']  ?? '');
    $password  = $_POST['password']           ?? '';
    $confirm   = $_POST['confirm_password']   ?? '';
    $role      = $_POST['role']               ?? '';

    if (strlen($username) < 6) {
        echo json_encode(['status' => 'error', 'message' => 'Username must be at least 6 characters long.']);
        exit();
    }

    // Validate password strength
    $pass_error = validatePassword($password);
    if ($pass_error) {
        echo json_encode(['status' => 'error', 'message' => $pass_error]);
        exit();
    }

    // Passwords must match
    if ($password !== $confirm) {
        echo json_encode(['status' => 'error', 'message' => 'Passwords do not match!']);
        exit();
    }

    // Username must be unique
    $check = $conn->prepare("SELECT staff_id FROM staff WHERE username = ?");
    $check->bind_param("s", $username);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Username already exists!']);
        exit();
    }

    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt   = $conn->prepare("INSERT INTO staff (full_name, username, password, role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $full_name, $username, $hashed, $role);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Staff account created successfully!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
    }
    exit();
}


/* =====================================================
   AJAX HANDLER: UPDATE EXISTING STAFF ACCOUNT
   ===================================================== */
if (isset($_POST['action']) && $_POST['action'] === 'update') {
    ob_clean();
    header('Content-Type: application/json');

    $staff_id  = $_POST['staff_id']  ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username']  ?? '');
    $role      = $_POST['role']           ?? '';
    $password  = $_POST['password']       ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';

    if (strlen($username) < 6) {
        echo json_encode(['status' => 'error', 'message' => 'Username must be at least 6 characters long.']);
        exit();
    }

    // Username must not be taken by another staff member
    $check = $conn->prepare("SELECT staff_id FROM staff WHERE username = ? AND staff_id != ?");
    $check->bind_param("si", $username, $staff_id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Username already taken by another account!']);
        exit();
    }

    if (!empty($password)) {
        // Validate password strength before updating
        $pass_error = validatePassword($password);
        if ($pass_error) {
            echo json_encode(['status' => 'error', 'message' => $pass_error]);
            exit();
        }

        if ($password !== $confirm) {
            echo json_encode(['status' => 'error', 'message' => 'Passwords do not match!']);
            exit();
        }

        $current = $conn->prepare("SELECT password FROM staff WHERE staff_id = ? LIMIT 1");
        $current->bind_param("i", $staff_id);
        $current->execute();
        $currentRow = $current->get_result()->fetch_assoc();
        if ($currentRow && password_verify($password, $currentRow['password'])) {
            echo json_encode(['status' => 'error', 'message' => 'New password must be different from the current password.']);
            exit();
        }

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt   = $conn->prepare("UPDATE staff SET full_name=?, username=?, role=?, password=? WHERE staff_id=?");
        $stmt->bind_param("ssssi", $full_name, $username, $role, $hashed, $staff_id);

    } else {
        $stmt = $conn->prepare("UPDATE staff SET full_name=?, username=?, role=? WHERE staff_id=?");
        $stmt->bind_param("sssi", $full_name, $username, $role, $staff_id);
    }

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Staff account updated successfully!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
    }
    exit();
}

/* =====================================================
   AJAX HANDLER: SOFT DELETE STAFF ACCOUNT
   ===================================================== */
if (isset($_POST['action']) && $_POST['action'] === 'delete_staff') {
    // Linisin ang anumang buffer para JSON lang ang lumabas
    if(ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');

    $delete_id = $_POST['delete_id'] ?? 0;
    
    $stmt = $conn->prepare("UPDATE staff SET status = 0 WHERE staff_id = ?");
    $stmt->bind_param("i", $delete_id);
    $result = $stmt->execute();

    echo json_encode(['status' => $result ? 'success' : 'error']);
    exit(); // Napaka-importante nito!
}

/* =====================================================
   DATA FETCHING
   ===================================================== */
// Gumamit tayo ng alias na 's' para hindi mag-conflict sa 'status' table mo
$staff = $conn->query("SELECT s.* FROM staff s WHERE s.status = 1 ORDER BY s.staff_id DESC");
?>

<!-- =====================================================
     STYLES
     ===================================================== -->
<style>

    .record-card {
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(10px);
        padding: 25px;
        border-radius: 20px;
        color: white;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
    }

    .top-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        padding: 20px;
        background: rgba(0, 166, 81, 0.1);
        border-radius: 15px;
        border: 1px solid rgba(0, 166, 81, 0.2);
        backdrop-filter: blur(5px);
    }

    .top-bar h2 {
        margin: 0;
        color: white;
        font-size: 24px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    }

    .top-bar h2 i { color: #00a651; font-size: 28px; }

    #staffFormContainer {
    display: none;
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 9999;
    align-items: center;
    padding-left: 260px;
    justify-content: center;
    pointer-events: auto;
    border-radius: 16px;
    }

    #deleteModal {
    display: none;
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.75);
    backdrop-filter: blur(4px);
    z-index: 10000;
    align-items: center;
    justify-content: center;
    pointer-events: auto;
    }   

    #staffFormContainer form,
    #deleteModal .modal-box {
        background: white !important;
        pointer-events: auto;
        padding: 30px;
        border-radius: 12px;
        width: 100%;
        max-width: 550px;
        box-shadow: 0 15px 50px rgba(0, 0, 0, 0.2);
        border: 1px solid #e0e0e0;
        animation: popIn 0.3s ease-out;
        /* Allow scrolling for taller form */
        max-height: 90vh;
        overflow-y: auto;
    }

    #staffFormContainer label {
        color: #084c24 !important;
        font-weight: 800;
        font-size: 14px;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    

    @keyframes popIn {
        from { opacity: 0; transform: scale(0.95) translateY(-10px); }
        to   { opacity: 1; transform: scale(1) translateY(0); }
    }

    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }

    .form-group { display: flex; flex-direction: column; }
    .form-group.full-width { grid-column: span 2; }

    .form-group label {
        font-size: 13px;
        font-weight: 700;
        margin-bottom: 8px;
        color: #084c24;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .form-group label i { color: #00a651; font-size: 14px; }

    input, select {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #d6e8d7;
        border-radius: 12px;
        background: #f7fcf7;
        color: #1f3822;
        outline: none;
        font-size: 13px;
        box-sizing: border-box;
        transition: border-color 0.25s ease, box-shadow 0.25s ease, transform 0.25s ease;
    }

    input:focus, select:focus {
        border-color: #00a651;
        box-shadow: 0 0 0 5px rgba(0, 166, 81, 0.1);
        transform: translateY(-1px);
    }

    .password-wrap { position: relative; }
    .password-wrap input { padding-right: 42px; }

    .eye-btn {
        position: absolute;
        right: 12px; top: 50%;
        transform: translateY(-50%);
        background: none; border: none;
        cursor: pointer; color: #888;
        font-size: 14px; padding: 0;
        transition: color 0.2s ease;
    }
    .eye-btn:hover { color: #00a651; }

    #responseMsg {
        display: none;
        padding: 12px 16px;
        border-radius: 10px;
        margin-bottom: 16px;
        text-align: center;
        font-weight: 700;
        font-size: 14px;
    }

    .btn {
        padding: 10px 18px;
        border: none; border-radius: 10px;
        cursor: pointer; font-weight: 700; font-size: 14px;
        display: inline-flex; align-items: center; gap: 8px;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        color: white;
    }
    .btn:hover { transform: translateY(-2px); }
    .btn-green { background: linear-gradient(135deg, #2f9e44, #20c997); box-shadow: 0 6px 16px rgba(32,201,151,0.22); }
    .btn-gray  { background: linear-gradient(135deg, #6c757d, #495057); box-shadow: 0 6px 16px rgba(73,80,87,0.22); }
    .btn-blue  { background: linear-gradient(135deg, #007bff, #0056b3); box-shadow: 0 6px 16px rgba(0,123,255,0.22); }
    .btn-red   { background: linear-gradient(135deg, #dc3545, #c82333); box-shadow: 0 6px 16px rgba(220,53,69,0.22); }

    .form-actions {
        margin-top: 24px;
        display: flex; gap: 12px;
        justify-content: center; flex-wrap: wrap;
    }

    .form-actions .btn {
        min-width: 160px; justify-content: center;
        padding: 14px 28px; font-size: 15px;
    }

    .search-wrapper { margin-bottom: 16px; }

    .search-box {
        display: flex; align-items: center;
        background: white; border-radius: 12px;
        border: 1px solid #d6e8d7; padding: 10px 16px;
        gap: 10px; max-width: 360px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.06);
        transition: border-color 0.2s ease;
    }

    .search-box:focus-within { border-color: #00a651; }
    .search-box i.search-icon { color: #00a651; font-size: 15px; flex-shrink: 0; }

    .search-box input {
        border: none; outline: none;
        background: transparent; font-size: 14px;
        color: #1f3822; width: 100%; padding: 0;
        transform: none; box-shadow: none;
    }
    .search-box input:focus { outline: none; box-shadow: none; border: none; transform: none; }
    .search-box input::placeholder { color: #aaa; }

    .search-box .clear-btn {
        color: #aaa; font-size: 14px; cursor: pointer;
        display: none; flex-shrink: 0;
        transition: color 0.2s ease;
    }
    .search-box .clear-btn:hover { color: #dc3545; }

    .no-results {
        display: none; margin-top: 12px;
        padding: 12px 16px;
        background: rgba(255,255,255,0.07);
        border: 1px dashed rgba(255,255,255,0.18);
        border-radius: 12px; max-width: 360px;
        align-items: center; gap: 10px;
    }
    .no-results i { color: rgba(255,255,255,0.4); font-size: 15px; flex-shrink: 0; }
    .no-results span { font-size: 13px; color: rgba(255,255,255,0.6); font-weight: 500; }
    .no-results span strong { color: rgba(255,255,255,0.85); font-weight: 700; }

    .table-controls {
        display: flex; justify-content: space-between;
        align-items: center; margin-bottom: 12px;
        flex-wrap: wrap; gap: 10px;
    }

    .row-count { font-size: 13px; color: rgba(255,255,255,0.7); font-weight: 500; }
    .row-count strong { color: white; font-weight: 700; }

    .rows-per-page {
        display: flex; align-items: center;
        gap: 8px; font-size: 13px; color: rgba(255,255,255,0.7);
    }

    .rows-per-page select {
        width: auto; max-width: 80px; padding: 6px 10px;
        border-radius: 8px; font-size: 13px;
        border: 1px solid rgba(255,255,255,0.3);
        background: white; color: #1f3822;
        cursor: pointer; transform: none; box-shadow: none;
    }
    .rows-per-page select:focus { border-color: #00a651; box-shadow: none; transform: none; }

    .table-responsive {
        width: 100%; overflow-x: auto;
        border-radius: 15px; box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }

    table {
        width: 100%; border-collapse: separate;
        border-spacing: 0; background: white;
        color: #333; border-radius: 15px;
        overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    }

    th {
        background: linear-gradient(135deg, #004d26, #006633);
        color: white; padding: 18px 15px;
        font-size: 14px; font-weight: 600;
        text-align: left; position: sticky; top: 0;
        text-shadow: 0 1px 2px rgba(0,0,0,0.3);
    }

    td {
        padding: 14px 15px;
        border-bottom: 1px solid #f0f0f0;
        font-size: 13px; vertical-align: middle;
    }

    tbody tr:nth-child(even) { background: #fafafa; }
    tbody tr:hover { background: #e8f5e8; transition: background 0.2s ease; }

    th.sortable { cursor: pointer; user-select: none; white-space: nowrap; }
    th.sortable:hover { background: linear-gradient(135deg, #006633, #008844); }
    th.sortable .sort-icon { margin-left: 6px; font-size: 11px; opacity: 0.5; }
    th.sortable.asc .sort-icon,
    th.sortable.desc .sort-icon { opacity: 1; color: #20c997; }

    .role-badge {
        padding: 4px 12px; border-radius: 50px;
        font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.05em;
        display: inline-block;
    }
    .role-admin { background: linear-gradient(135deg, #6366f1, #4338ca); color: white; box-shadow: 0 2px 8px rgba(99,102,241,0.3); }
    .role-nurse { background: linear-gradient(135deg, #22c55e, #15803d); color: white; box-shadow: 0 2px 8px rgba(34,197,94,0.3); }

    .you-badge {
        padding: 2px 8px; border-radius: 50px;
        font-size: 10px; font-weight: 700;
        background: rgba(0,166,81,0.15);
        border: 1px solid rgba(0,166,81,0.3);
        color: #00a651; margin-left: 6px; vertical-align: middle;
    }

    .action-btns { display: flex; gap: 8px; justify-content: center; }

    .edit-btn {
        background: linear-gradient(135deg, #007bff, #0056b3);
        color: white; border: none;
        width: 35px; height: 35px; border-radius: 8px;
        cursor: pointer; display: flex;
        align-items: center; justify-content: center;
        transition: all 0.3s ease;
        box-shadow: 0 2px 8px rgba(0,123,255,0.3);
    }
    .edit-btn:hover {
        background: linear-gradient(135deg, #0056b3, #004085);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,123,255,0.4);
    }

    .delete-btn {
        background: linear-gradient(135deg, #dc3545, #c82333);
        color: white; border: none;
        width: 35px; height: 35px; border-radius: 8px;
        cursor: pointer; display: flex;
        align-items: center; justify-content: center;
        transition: all 0.3s ease;
        box-shadow: 0 2px 8px rgba(220,53,69,0.3);
    }
    .delete-btn:hover {
        background: linear-gradient(135deg, #c82333, #a02622);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(220,53,69,0.4);
    }

    .pagination-wrapper {
        display: flex; justify-content: center;
        align-items: center; gap: 6px;
        margin-top: 16px; flex-wrap: wrap;
    }

    .page-btn {
        min-width: 36px; height: 36px; padding: 0 10px;
        border-radius: 8px;
        border: 1px solid rgba(255,255,255,0.2);
        background: rgba(255,255,255,0.08);
        color: rgba(255,255,255,0.8);
        font-size: 13px; font-weight: 600; cursor: pointer;
        transition: all 0.2s ease;
        display: flex; align-items: center; justify-content: center;
    }
    .page-btn:hover { background: rgba(0,166,81,0.25); border-color: #00a651; color: white; }
    .page-btn.active { background: linear-gradient(135deg,#2f9e44,#20c997); border-color: transparent; color: white; box-shadow: 0 4px 12px rgba(32,201,151,0.3); }
    .page-btn:disabled { opacity: 0.35; cursor: not-allowed; pointer-events: none; }

    #deleteModal {
        position: fixed; top: 0; left: 0;
        width: 100%; height: 100%;
        background: rgba(0,0,0,0.75);
        display: none; align-items: center;
        justify-content: center; z-index: 1000;
        backdrop-filter: blur(4px);
    }

    .modal-box {
        background: white; padding: 36px 32px;
        border-radius: 24px; width: 360px;
        text-align: center; color: #1a1a2e;
        box-shadow: 0 25px 60px rgba(0,0,0,0.35);
        position: relative; overflow: hidden;
        animation: modalPop 0.35s cubic-bezier(0.34,1.56,0.64,1);
    }

    @keyframes modalPop {
        from { opacity: 0; transform: scale(0.85); }
        to   { opacity: 1; transform: scale(1); }
    }

    .modal-accent-bar {
        position: absolute; top: 0; left: 0; right: 0; height: 5px;
        background: linear-gradient(90deg,#dc3545,#ff6b6b);
        border-radius: 24px 24px 0 0;
    }

    .modal-icon {
        width: 72px; height: 72px;
        background: linear-gradient(135deg,#ffe0e3,#ffc2c7);
        border-radius: 50%; display: flex;
        align-items: center; justify-content: center;
        margin: 0 auto 20px;
        box-shadow: 0 6px 20px rgba(220,53,69,0.2);
    }
    .modal-icon i { font-size: 28px; color: #dc3545; }
    .modal-title  { margin: 0 0 8px; font-size: 22px; font-weight: 800; color: #c0392b; }
    .modal-subtitle { margin: 0 0 6px; font-size: 14px; color: #666; line-height: 1.5; }
    .modal-name   { margin: 0 0 20px; font-size: 16px; font-weight: 700; color: #1a1a2e; }

    .modal-warning {
        background: #fff8e1; border: 1px solid #ffe082;
        border-radius: 10px; padding: 10px 14px;
        margin-bottom: 28px; display: flex;
        align-items: center; gap: 8px; text-align: left;
    }
    .modal-warning i   { color: #f59e0b; font-size: 15px; flex-shrink: 0; }
    .modal-warning span { font-size: 12px; color: #7a5c00; font-weight: 600; }

    .modal-buttons { display: flex; gap: 12px; justify-content: center; }

    .modal-btn-cancel {
        flex: 1; padding: 13px 20px; border-radius: 12px;
        border: 2px solid #e0e0e0; background: white; color: #555;
        font-size: 14px; font-weight: 700; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        gap: 8px; transition: background 0.2s ease;
    }
    .modal-btn-cancel:hover { background: #f5f5f5; }

    .modal-btn-delete {
        flex: 1; padding: 13px 20px; border-radius: 12px;
        border: none;
        background: linear-gradient(135deg,#dc3545,#c0392b);
        color: white; font-size: 14px; font-weight: 700;
        cursor: pointer; display: flex;
        align-items: center; justify-content: center; gap: 8px;
        box-shadow: 0 6px 18px rgba(220,53,69,0.35);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .modal-btn-delete:hover { transform: translateY(-2px); box-shadow: 0 10px 22px rgba(220,53,69,0.4); }


    /* ===== PASSWORD STRENGTH METER ===== */
    .strength-meter {
        margin-top: 6px;
        margin-bottom: 4px;
    }

    .strength-bars {
        display: flex;
        gap: 5px;
        margin-bottom: 4px;
    }

    .strength-bar {
        flex: 1;
        height: 5px;
        border-radius: 3px;
        background: #e0e0e0;
        transition: background 0.3s ease;
    }

    .strength-bar.active-weak    { background: #e53935; }
    .strength-bar.active-fair    { background: #fb8c00; }
    .strength-bar.active-good    { background: #fdd835; }
    .strength-bar.active-strong  { background: #43a047; }
    .strength-bar.active-vstrong { background: #1b5e20; }

    .strength-label {
        font-size: 11px;
        font-weight: 700;
        color: #888;
        transition: color 0.3s;
    }

    /* ===== PASSWORD REQUIREMENTS CHECKLIST ===== */
    .pass-requirements {
        background: #f8f9fa;
        border: 1.5px solid #e8e8e8;
        border-radius: 9px;
        padding: 10px 12px;
        margin-top: 6px;
        margin-bottom: 4px;
    }

    .pass-requirements p {
        font-size: 11px;
        font-weight: 700;
        color: #555;
        text-transform: uppercase;
        letter-spacing: .4px;
        margin-bottom: 6px;
    }

    .req-item {
        display: flex;
        align-items: center;
        gap: 7px;
        font-size: 12px;
        color: #888;
        margin-bottom: 3px;
        transition: color 0.25s;
    }
    .req-item:last-child { margin-bottom: 0; }

    .req-item i {
        font-size: 12px;
        width: 13px;
        text-align: center;
        color: #ccc;
        transition: color 0.25s;
    }

    .req-item.met       { color: #2e7d32; }
    .req-item.met i     { color: #43a047; }
    .req-item.unmet i   { color: #e53935; }

    @media (max-width: 640px) {
        #staffFormContainer {
            padding: 0;
            padding-left: 0;
            align-items: flex-end;
        }

        #staffFormContainer form {
            width: 100%;
            max-width: none;
            height: min(92dvh, 92vh);
            max-height: min(92dvh, 92vh);
            padding: 18px 16px 22px;
            border-radius: 18px 18px 0 0;
        }

        #staffFormContainer h3 {
            font-size: 18px;
            line-height: 1.25;
            margin-bottom: 16px !important;
        }

        #staffFormContainer .form-grid {
            grid-template-columns: 1fr;
            gap: 12px;
        }

        #staffFormContainer .form-group.full-width {
            grid-column: span 1;
        }

        #staffFormContainer input,
        #staffFormContainer select {
            min-height: 44px;
            font-size: 16px;
        }

        #staffFormContainer .form-actions,
        .modal-buttons {
            flex-direction: column;
        }

        #staffFormContainer .form-actions .btn,
        .modal-btn-cancel,
        .modal-btn-delete {
            width: 100%;
            min-width: 0;
        }

        #deleteModal {
            padding: 14px;
        }

        #deleteModal .modal-box {
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
    }

</style>

<div id="staffFormContainer">
    <form id="staffForm">
    <h3 id="formTitle" style="color:#084c24; margin-bottom:16px;">Create Staff Account</h3>

    <div id="responseMsg"></div>
            
            <input type="hidden" name="action"   id="formAction"  value="create">
            <input type="hidden" name="staff_id" id="editStaffId" value="">

            <div class="form-grid">

                <div class="form-group full-width">
                    <label><i class="fa-solid fa-id-card"></i> Full Name</label>
                    <input type="text" name="full_name" id="field_full_name"
                           placeholder="Enter full name" required>
                </div>

                <div class="form-group">
                    <label><i class="fa-solid fa-user"></i> Username</label>
                    <input type="text" name="username" id="field_username"
                           placeholder="Enter username" minlength="6" required>
                </div>

                <div class="form-group">
                    <label><i class="fa-solid fa-tag"></i> Role</label>
                    <select name="role" id="field_role" required>
                        <option value="nurse">Nurse</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>

                <!-- Password field with strength meter -->
                <div class="form-group full-width" id="passwordField">
                    <label><i class="fa-solid fa-lock"></i>
                        <span id="passwordLabel">Password</span>
                    </label>
                    <div class="password-wrap">
                        <input type="password" id="p1" name="password"
                               placeholder="Enter password"
                               oninput="checkStrength(this)">
                        <button type="button" class="eye-btn" onclick="togglePassword('p1', this)">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>

                    <!-- Strength Meter -->
                    <div class="strength-meter" id="staff_strength_meter" style="display:none;">
                        <div class="strength-bars">
                            <div class="strength-bar" id="staff_bar1"></div>
                            <div class="strength-bar" id="staff_bar2"></div>
                            <div class="strength-bar" id="staff_bar3"></div>
                            <div class="strength-bar" id="staff_bar4"></div>
                            <div class="strength-bar" id="staff_bar5"></div>
                        </div>
                        <span class="strength-label" id="staff_strength_label"></span>
                    </div>

                    <!-- Requirements Checklist -->
                    <div class="pass-requirements" id="staff_requirements" style="display:none;">
                        <p><i class="fa-solid fa-shield-halved"></i> Password Requirements</p>
                        <div class="req-item" id="staff_req_length"><i class="fa-solid fa-circle-xmark"></i> Minimum 8 characters (12+ recommended)</div>
                        <div class="req-item" id="staff_req_upper"><i class="fa-solid fa-circle-xmark"></i> At least 1 uppercase letter (A–Z)</div>
                        <div class="req-item" id="staff_req_lower"><i class="fa-solid fa-circle-xmark"></i> At least 1 lowercase letter (a–z)</div>
                        <div class="req-item" id="staff_req_number"><i class="fa-solid fa-circle-xmark"></i> At least 1 number (0–9)</div>
                        <div class="req-item" id="staff_req_special"><i class="fa-solid fa-circle-xmark"></i> At least 1 special character (!@#$%^&*)</div>
                    </div>

                    <small id="passwordHint" style="display:none; margin-top:6px; color:#888; font-size:11px;">
                        <i class="fa-solid fa-circle-info"></i> Leave blank to keep the current password unchanged.
                    </small>
                </div>

                <!-- Confirm Password field -->
                <div class="form-group full-width" id="confirmPasswordField">
                    <label><i class="fa-solid fa-lock"></i>
                        <span id="confirmPasswordLabel">Confirm Password</span>
                    </label>
                    <div class="password-wrap">
                        <input type="password" id="p2" name="confirm_password"
                               placeholder="Confirm password">
                        <button type="button" class="eye-btn" onclick="togglePassword('p2', this)">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </div>

            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-green" id="submitBtn">
                    <i class="fa-solid fa-save"></i> Save Account
                </button>
                <button type="button" class="btn btn-gray" onclick="closeForm()">
                    <i class="fa-solid fa-times"></i> Cancel
                </button>
            </div>

        </form>
    </div>
<!-- =====================================================
     MAIN CONTENT
     ===================================================== -->
<div class="record-card">

    <div class="top-bar">
        <h2><i class="fa-solid fa-users-gear"></i> Manage Staff</h2>
        <button class="btn btn-green" onclick="prepareAdd()">
            <i class="fa-solid fa-user-plus"></i> Create Staff
        </button>
    </div>

    <!-- Create / Edit Staff Form (hidden by default) -->
    

    <!-- Search Bar -->
    <div class="search-wrapper">
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <input
                type="text"
                id="staffSearch"
                placeholder="Search by name, username, or role..."
                oninput="searchStaff()"
            >
            <i class="fa-solid fa-xmark clear-btn" id="clearSearch" onclick="clearSearch()"></i>
        </div>
        <div class="no-results" id="noResults">
            <i class="fa-solid fa-user-slash"></i>
            <span>No results for <strong id="noResultsQuery"></strong> — try a different name or role.</span>
        </div>
    </div>

    <!-- Table Controls -->
    <div class="table-controls">
        <div class="row-count" id="rowCount">
            Showing <strong>0</strong> of <strong>0</strong> accounts
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

    <!-- Staff Table -->
    <div class="table-responsive">
        <table id="staffTable">
            <thead>
                <tr>
                    <th class="sortable" onclick="sortTable(0)" data-col="0">
                        Full Name <span class="sort-icon fa-solid fa-sort"></span>
                    </th>
                    <th class="sortable" onclick="sortTable(1)" data-col="1">
                        Username <span class="sort-icon fa-solid fa-sort"></span>
                    </th>
                    <th class="sortable" onclick="sortTable(2)" data-col="2">
                        Role <span class="sort-icon fa-solid fa-sort"></span>
                    </th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $staff->fetch_assoc()):
                    $isCurrentUser = ($row['username'] === $_SESSION['username']);
                    $roleClass     = $row['role'] === 'admin' ? 'role-admin' : 'role-nurse';
                ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($row['full_name']) ?></strong>
                        <?php if ($isCurrentUser): ?>
                            <span class="you-badge">You</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($row['username']) ?></td>
                    <td>
                        <span class="role-badge <?= $roleClass ?>">
                            <?= ucfirst($row['role']) ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-btns">
                            <button class="edit-btn"
                                    onclick='prepareEdit(<?= json_encode($row) ?>)'
                                    title="Edit">
                                <i class="fa-solid fa-edit"></i>
                            </button>
                            <?php if (!$isCurrentUser): ?>
                            <button class="delete-btn"
                                    onclick="confirmDelete(<?= $row['staff_id'] ?>, '<?= addslashes($row['full_name']) ?>')"
                                    title="Delete">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                            <?php else: ?>
                            <button class="delete-btn"
                                    style="opacity:0.3; cursor:not-allowed;" disabled
                                    title="Cannot delete yourself">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination-wrapper" id="paginationWrapper"></div>

</div>


<!-- =====================================================
     DELETE CONFIRMATION MODAL
     ===================================================== -->
<div id="deleteModal">
    <div class="modal-box">
        <div class="modal-accent-bar"></div>
        <div class="modal-icon"><i class="fa-solid fa-user-slash"></i></div>
        <h3 class="modal-title">Remove Staff?</h3>
        <p class="modal-subtitle">You are about to permanently delete</p>
        <p class="modal-name">"<span id="delName"></span>"</p>
        <div class="modal-warning">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>This action cannot be undone. The staff account will be permanently removed.</span>
        </div>
        <div class="modal-buttons">
            <button class="modal-btn-cancel" onclick="closeModal()">
                <i class="fa-solid fa-xmark"></i> Cancel
            </button>
            <button class="modal-btn-delete" onclick="executeDelete()">
                <i class="fa-solid fa-trash"></i> Yes, Delete
            </button>
        </div>
    </div>
</div>


<!-- =====================================================
     JAVASCRIPT
     ===================================================== -->
<script>

    /* ----- GLOBAL STATE ----- */
    if (typeof currentDeleteId === 'undefined') var currentDeleteId = null;
    var currentPage   = 1;
    var rowsPerPage   = 10;
    var sortColIndex  = -1;
    var sortDirection = 'asc';


    /* ===================================================
       PASSWORD STRENGTH & REQUIREMENTS (Staff Form)
       =================================================== */
    function checkStrength(input) {
        var password     = input.value;
        var meterDiv     = document.getElementById('staff_strength_meter');
        var requireDiv   = document.getElementById('staff_requirements');

        if (password.length === 0) {
            meterDiv.style.display   = 'none';
            requireDiv.style.display = 'none';
            return;
        }

        meterDiv.style.display   = 'block';
        requireDiv.style.display = 'block';

        // Evaluate requirements
        var checks = {
            length:  password.length >= 8,
            upper:   /[A-Z]/.test(password),
            lower:   /[a-z]/.test(password),
            number:  /[0-9]/.test(password),
            special: /[!@#$%^&*]/.test(password)
        };

        updateReq('staff_req_length',  checks.length);
        updateReq('staff_req_upper',   checks.upper);
        updateReq('staff_req_lower',   checks.lower);
        updateReq('staff_req_number',  checks.number);
        updateReq('staff_req_special', checks.special);

        // Score (0–5)
        var score   = Object.values(checks).filter(Boolean).length;
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
        var labelText = levels[Math.min(score, 5)].label;
        var barClass  = levels[Math.min(score, 5)].cls;

        if (allMet && password.length >= 12) {
            labelText = 'Very Strong';
            barClass  = 'active-vstrong';
        }

        // Paint bars
        for (var i = 1; i <= 5; i++) {
            var bar = document.getElementById('staff_bar' + i);
            bar.className = 'strength-bar';
            if (i <= score) bar.classList.add(barClass);
        }

        var colorMap = {
            'active-weak':    '#e53935',
            'active-fair':    '#fb8c00',
            'active-good':    '#fdd835',
            'active-strong':  '#43a047',
            'active-vstrong': '#1b5e20'
        };

        var labelEl      = document.getElementById('staff_strength_label');
        labelEl.textContent = labelText;
        labelEl.style.color = colorMap[barClass] || '#888';
    }

    function updateReq(id, met) {
        var el   = document.getElementById(id);
        if (!el) return;
        var icon = el.querySelector('i');
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

    /* Reset strength meter and checklist */
    function resetStrengthMeter() {
        var meterDiv   = document.getElementById('staff_strength_meter');
        var requireDiv = document.getElementById('staff_requirements');
        if (meterDiv)   meterDiv.style.display   = 'none';
        if (requireDiv) requireDiv.style.display = 'none';

        for (var i = 1; i <= 5; i++) {
            var bar = document.getElementById('staff_bar' + i);
            if (bar) bar.className = 'strength-bar';
        }

        var labelEl = document.getElementById('staff_strength_label');
        if (labelEl) { labelEl.textContent = ''; labelEl.style.color = '#888'; }

        ['staff_req_length','staff_req_upper','staff_req_lower','staff_req_number','staff_req_special']
            .forEach(function (id) {
                var el = document.getElementById(id);
                if (!el) return;
                el.classList.remove('met','unmet');
                el.querySelector('i').className = 'fa-solid fa-circle-xmark';
            });
    }


    /* ===================================================
       TOAST NOTIFICATION
       =================================================== */
    function showToast(message, type) {
        var existing = document.getElementById('toastNotif');
        if (existing) existing.remove();

        var toast     = document.createElement('div');
        toast.id      = 'toastNotif';
        var isSuccess = type !== 'error';

        toast.style.cssText = [
            'position:fixed','bottom:30px','right:30px',
            'background:' + (isSuccess
                ? 'linear-gradient(135deg,#2f9e44,#20c997)'
                : 'linear-gradient(135deg,#dc3545,#c82333)'),
            'color:white','padding:16px 24px','border-radius:14px',
            'font-size:15px','font-weight:700','display:flex',
            'align-items:center','gap:10px',
            'box-shadow:0 8px 25px rgba(0,0,0,0.25)',
            'z-index:9999','animation:toastIn 0.4s ease','max-width:320px'
        ].join(';');

        toast.innerHTML = '<span style="font-size:20px">' + (isSuccess ? '✅' : '❌') + '</span>'
                        + '<span>' + message + '</span>';
        document.body.appendChild(toast);

        setTimeout(function () {
            toast.style.animation = 'toastOut 0.4s ease forwards';
            setTimeout(function () { toast.remove(); }, 400);
        }, 3000);
    }

    if (!document.getElementById('toastStyles')) {
        var style = document.createElement('style');
        style.id  = 'toastStyles';
        style.textContent = `
            @keyframes toastIn  { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
            @keyframes toastOut { from { opacity:1; transform:translateY(0); } to { opacity:0; transform:translateY(20px); } }
        `;
        document.head.appendChild(style);
    }


    /* ===================================================
       PASSWORD VISIBILITY TOGGLE
       =================================================== */
    function togglePassword(inputId, btn) {
        var input = document.getElementById(inputId);
        var icon  = btn.querySelector('i');
        if (input.type === 'password') {
            input.type     = 'text';
            icon.className = 'fa-solid fa-eye-slash';
        } else {
            input.type     = 'password';
            icon.className = 'fa-solid fa-eye';
        }
    }


    /* ===================================================
       FORM HELPERS
       =================================================== */
    function showPasswordFields(isCreateMode) {
        var passwordLabel        = document.getElementById('passwordLabel');
        var confirmPasswordLabel = document.getElementById('confirmPasswordLabel');
        var passwordHint         = document.getElementById('passwordHint');
        var p1                   = document.getElementById('p1');
        var p2                   = document.getElementById('p2');

        if (isCreateMode) {
            passwordLabel.innerText        = 'Password';
            confirmPasswordLabel.innerText = 'Confirm Password';
            p1.placeholder                 = 'Enter password';
            p2.placeholder                 = 'Confirm password';
            p1.required                    = true;
            p2.required                    = true;
            passwordHint.style.display     = 'none';
        } else {
            passwordLabel.innerText        = 'New Password';
            confirmPasswordLabel.innerText = 'Confirm New Password';
            p1.placeholder                 = 'Leave blank to keep current password';
            p2.placeholder                 = 'Confirm new password';
            p1.required                    = false;
            p2.required                    = false;
            passwordHint.style.display     = 'block';
        }

        // Reset values & eye icons
        p1.value = ''; p2.value = '';
        p1.type  = 'password'; p2.type = 'password';
        document.querySelectorAll('.eye-btn i').forEach(function (i) {
            i.className = 'fa-solid fa-eye';
        });

        // Reset strength meter
        resetStrengthMeter();
    }


    /* ===================================================
       FORM: OPEN FOR CREATING
       =================================================== */
    function prepareAdd() {
        document.getElementById('staffForm').reset();
        document.getElementById('responseMsg').style.display = 'none';
        document.getElementById('formTitle').innerText       = 'Create Staff Account';
        document.getElementById('submitBtn').innerHTML       = '<i class="fa-solid fa-save"></i> Save Account';
        document.getElementById('formAction').value         = 'create';
        document.getElementById('editStaffId').value        = '';

        showPasswordFields(true);

        var container = document.getElementById('staffFormContainer');
        container.style.display = 'flex';
    }


    /* ===================================================
       FORM: OPEN FOR EDITING
       =================================================== */
    function prepareEdit(data) {
        document.getElementById('responseMsg').style.display  = 'none';
        document.getElementById('formTitle').innerText        = 'Edit Staff Account';
        document.getElementById('submitBtn').innerHTML        = '<i class="fa-solid fa-pen"></i> Update Account';
        document.getElementById('formAction').value          = 'update';
        document.getElementById('editStaffId').value         = data.staff_id;

        document.getElementById('field_full_name').value = data.full_name;
        document.getElementById('field_username').value  = data.username;
        document.getElementById('field_role').value      = data.role;

        showPasswordFields(false);

        var container = document.getElementById('staffFormContainer');
        container.style.display = 'flex';
    }


    /* ===================================================
       FORM: CLOSE
       =================================================== */
    function closeForm() {
        var container = document.getElementById('staffFormContainer');
        if (container) container.style.display = 'none';
    }


    /* ===================================================
       FORM: SUBMIT
       =================================================== */
    document.getElementById('staffForm').onsubmit = function (e) {
        e.preventDefault();

        var action   = document.getElementById('formAction').value;
        var isUpdate = action === 'update';
        var msgBox   = document.getElementById('responseMsg');
        var formData = new FormData(this);

        fetch('manage_staff.php', { method: 'POST', body: formData })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                msgBox.innerHTML          = data.message;
                msgBox.style.display      = 'block';
                msgBox.style.borderRadius = '10px';
                msgBox.style.padding      = '12px 16px';

                if (data.status === 'success') {
                    msgBox.style.background = '#dcfce7';
                    msgBox.style.color      = '#15803d';
                    msgBox.style.border     = '1px solid #86efac';
                    showToast(isUpdate ? 'Staff updated successfully!' : 'Staff account created successfully!', 'success');
                    setTimeout(function () { loadPage('manage_staff.php'); }, 1200);
                } else {
                    msgBox.style.background = '#fee2e2';
                    msgBox.style.color      = '#b91c1c';
                    msgBox.style.border     = '1px solid #fca5a5';
                    showToast(data.message, 'error');
                }
            })
            .catch(function () {
                showToast('Connection error. Please try again.', 'error');
            });
    };


    /* ===================================================
       SEARCH
       =================================================== */
    function searchStaff() {
        var input          = document.getElementById('staffSearch');
        var filter         = input.value.toLowerCase().trim();
        var rows           = document.querySelectorAll('#staffTable tbody tr');
        var clearBtn       = document.getElementById('clearSearch');
        var noResults      = document.getElementById('noResults');
        var noResultsQuery = document.getElementById('noResultsQuery');
        var matchCount     = 0;

        clearBtn.style.display = filter.length > 0 ? 'inline' : 'none';

        rows.forEach(function (row) {
            var name     = row.cells[0] ? row.cells[0].innerText.toLowerCase() : '';
            var username = row.cells[1] ? row.cells[1].innerText.toLowerCase() : '';
            var role     = row.cells[2] ? row.cells[2].innerText.toLowerCase() : '';
            var matches  = (filter === '') || name.includes(filter) || username.includes(filter) || role.includes(filter);

            if (matches) {
                row.dataset.searchHidden = 'false';
                matchCount++;
            } else {
                row.dataset.searchHidden = 'true';
                row.style.display = 'none';
            }
        });

        if (matchCount === 0 && filter.length > 0) {
            noResultsQuery.innerText = '"' + input.value.trim() + '"';
            noResults.style.display  = 'flex';
        } else {
            noResults.style.display = 'none';
        }

        currentPage = 1;
        applyPagination();
    }

    function clearSearch() {
        document.getElementById('staffSearch').value = '';
        searchStaff();
    }


    /* ===================================================
       PAGINATION
       =================================================== */
    function getVisibleRows() {
        return Array.from(document.querySelectorAll('#staffTable tbody tr')).filter(function (row) {
            return row.dataset.searchHidden !== 'true';
        });
    }

    function applyPagination() {
        var visibleRows = getVisibleRows();
        var total       = visibleRows.length;
        var totalPages  = Math.ceil(total / rowsPerPage) || 1;

        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1)          currentPage = 1;

        var start = (currentPage - 1) * rowsPerPage;
        var end   = start + rowsPerPage;

        document.querySelectorAll('#staffTable tbody tr').forEach(function (row) {
            row.style.display = 'none';
        });

        visibleRows.forEach(function (row, index) {
            if (index >= start && index < end) row.style.display = '';
        });

        var countEl = document.getElementById('rowCount');
        if (countEl) {
            var from = total === 0 ? 0 : start + 1;
            var to   = Math.min(end, total);
            countEl.innerHTML =
                'Showing <strong>' + from + '–' + to + '</strong>' +
                ' of <strong>' + total + '</strong> staff' + (total !== 1 ? ' members' : ' member');
        }

        renderPagination(totalPages);
    }

    function renderPagination(totalPages) {
        var wrapper = document.getElementById('paginationWrapper');
        if (!wrapper) return;
        wrapper.innerHTML = '';

        var prev       = document.createElement('button');
        prev.className = 'page-btn';
        prev.innerHTML = '<i class="fa-solid fa-chevron-left"></i>';
        prev.disabled  = currentPage === 1;
        prev.onclick   = function () { currentPage--; applyPagination(); };
        wrapper.appendChild(prev);

        var startPage = Math.max(1, currentPage - 2);
        var endPage   = Math.min(totalPages, startPage + 4);
        if (endPage - startPage < 4) startPage = Math.max(1, endPage - 4);

        for (var i = startPage; i <= endPage; i++) {
            (function (page) {
                var btn       = document.createElement('button');
                btn.className = 'page-btn' + (page === currentPage ? ' active' : '');
                btn.innerText = page;
                btn.onclick   = function () { currentPage = page; applyPagination(); };
                wrapper.appendChild(btn);
            })(i);
        }

        var next       = document.createElement('button');
        next.className = 'page-btn';
        next.innerHTML = '<i class="fa-solid fa-chevron-right"></i>';
        next.disabled  = currentPage === totalPages;
        next.onclick   = function () { currentPage++; applyPagination(); };
        wrapper.appendChild(next);
    }

    function changeRowsPerPage() {
        rowsPerPage = parseInt(document.getElementById('rowsPerPage').value);
        currentPage = 1;
        applyPagination();
    }


    /* ===================================================
       SORT
       =================================================== */
    function sortTable(colIndex) {
        var tbody = document.querySelector('#staffTable tbody');
        var rows  = Array.from(tbody.querySelectorAll('tr'));

        if (sortColIndex === colIndex) {
            sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            sortColIndex  = colIndex;
            sortDirection = 'asc';
        }

        rows.sort(function (a, b) {
            var aText = a.cells[colIndex] ? a.cells[colIndex].innerText.trim().toLowerCase() : '';
            var bText = b.cells[colIndex] ? b.cells[colIndex].innerText.trim().toLowerCase() : '';
            return sortDirection === 'asc' ? aText.localeCompare(bText) : bText.localeCompare(aText);
        });

        rows.forEach(function (row) { tbody.appendChild(row); });

        document.querySelectorAll('th.sortable').forEach(function (th) {
            var icon = th.querySelector('.sort-icon');
            if (!icon) return;
            var col  = parseInt(th.dataset.col);
            if (col === sortColIndex) {
                icon.className = 'sort-icon fa-solid ' + (sortDirection === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
                th.classList.toggle('asc',  sortDirection === 'asc');
                th.classList.toggle('desc', sortDirection === 'desc');
            } else {
                icon.className = 'sort-icon fa-solid fa-sort';
                th.classList.remove('asc','desc');
            }
        });

        currentPage = 1;
        applyPagination();
    }


    /* ===================================================
       DELETE MODAL
       =================================================== */
    function confirmDelete(id, name) {
        currentDeleteId = id;
        var modal  = document.getElementById('deleteModal');
        var nameEl = document.getElementById('delName');
        if (modal && nameEl) {
            nameEl.innerText    = name;
            modal.style.display = 'flex';
        }
    }

    function closeModal() {
        var modal = document.getElementById('deleteModal');
        if (modal) modal.style.display = 'none';
    }

function executeDelete() {
    if (!currentDeleteId) return;

    let fd = new FormData();
    fd.append('action', 'delete_staff');
    fd.append('delete_id', currentDeleteId);

    // Ituro sa mismong file, huwag sa window.location.href
    fetch('manage_staff.php', { 
        method: 'POST', 
        body: fd 
    })
    .then(res => {
        // I-verify kung JSON ang nakuha natin
        if (!res.ok) throw new Error('Server Error');
        return res.json();
    })
    .then(data => {
        closeModal();
        if (data.status === 'success') {
            showToast('Staff account removed successfully!', 'success');
            // Sa AJAX Dashboard, mas mainam na loadPage ulit para fresh ang data
            setTimeout(() => { loadPage('manage_staff.php'); }, 1200);
        } else {
            showToast('Failed to delete account.', 'error');
        }
    })
    .catch(err => {
        console.error("Delete error:", err);
        showToast('Network error or server misconfiguration.', 'error');
    });
}
    /* ===================================================
       INIT
       =================================================== */
    applyPagination();

</script>
