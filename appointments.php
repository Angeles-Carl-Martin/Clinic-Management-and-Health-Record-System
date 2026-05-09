<?php
/* ============================================================
 * appointments.php — Appointment Management Module
 *
 * AJAX endpoints (identified by $_GET['action'] or $_POST['action']):
 *   GET  action=get_slots  → Returns available time slots for a date
 *   POST action=add        → Insert new appointment
 *   POST action=update_full → Update existing appointment
 *   POST action=update_status → Quick status change
 *   POST action=delete     → Soft delete (sets status1='0')
 *
 * Soft delete pattern: status1='0' means deleted, '1' means active.
 * ============================================================ */

session_start();
require "db.php";
require_staff_login();
redirect_direct_fragment_access();

/** @var mysqli $conn */


function validateAppointmentDateTime(string $date, string $time): ?string {
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    $dateErrors = DateTime::getLastErrors();
    if (!$dateObj || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $dateObj->format('Y-m-d') !== $date) {
        return 'Please choose a valid appointment date.';
    }

    $timeObj = DateTime::createFromFormat('H:i', substr($time, 0, 5));
    $timeErrors = DateTime::getLastErrors();
    if (!$timeObj || ($timeErrors && ($timeErrors['warning_count'] || $timeErrors['error_count']))) {
        return 'Please choose a valid appointment time.';
    }

    $hour = (int) $timeObj->format('H');
    $minute = (int) $timeObj->format('i');
    if ($minute !== 0 || $hour < 8 || $hour > 16) {
        return 'Appointment time must be an hourly slot from 8:00 AM to 4:00 PM.';
    }

    $selected = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $timeObj->format('H:i'));
    if ($selected <= new DateTime()) {
        return 'Appointment date and time must be in the future.';
    }

    return null;
}

/* ============================================================
 * AJAX: GET AVAILABLE TIME SLOTS
 *
 * Returns a JSON array of hourly slots (8 AM–4 PM) for a date,
 * showing how many are taken and whether each is full.
 * ============================================================ */
if (isset($_GET['action']) && $_GET['action'] === 'get_slots') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $date           = $_GET['date'] ?? date('Y-m-d');
    $limit_per_slot = 5; // Max patients per hourly slot

    /* Count booked appointments per time slot for the given date */
    $sql = "SELECT appointment_time, COUNT(*) AS total
            FROM appointments
            WHERE appointment_date = ?
              AND (status1 = '1' OR status1 = 'Active' OR status1 IS NULL)
              AND status != 'Cancelled'
            GROUP BY appointment_time";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $result = $stmt->get_result();

    /* Build a lookup: ['08:00' => 3, '09:00' => 5, ...] */
    $booked = [];
    while ($row = $result->fetch_assoc()) {
        $time_key          = date("H:00", strtotime($row['appointment_time']));
        $booked[$time_key] = $row['total'];
    }

    /* Generate slot objects for 8 AM through 4 PM */
    $slots = [];
    for ($h = 8; $h <= 16; $h++) {
        $t       = sprintf("%02d:00", $h);
        $count   = $booked[$t] ?? 0;
        $slotDateTime = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $t);
        $isPast = $slotDateTime && $slotDateTime <= new DateTime();
        $slots[] = [
            'time'    => $t,
            'display' => date("h:i A", strtotime($t)),
            'taken'   => $count,
            'limit'   => $limit_per_slot,
            'is_full' => ($count >= $limit_per_slot) || $isPast,
            'is_past' => $isPast,
        ];
    }

    echo json_encode($slots);
    exit();
}


/* ============================================================
 * AJAX: ADD NEW APPOINTMENT
 *
 * Checks slot capacity before inserting.
 * Returns: { "status": "success"|"error", "msg": "..." }
 * ============================================================ */
if (isset($_POST['action']) && $_POST['action'] === 'add') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $limit_per_slot = 5;
    $date = $_POST['appointment_date'] ?? '';
    $time = $_POST['appointment_time'] ?? '';

    $validation_error = validateAppointmentDateTime($date, $time);
    if ($validation_error) {
        echo json_encode(['status' => 'error', 'msg' => $validation_error]);
        exit();
    }

    /* Guard: re-check slot capacity server-side */
    $chk = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM appointments
        WHERE appointment_date = ?
          AND appointment_time = ?
          AND (status1 = '1' OR status1 = 'Active' OR status1 IS NULL)
          AND status != 'Cancelled'
    ");
    $chk->bind_param("ss", $date, $time);
    $chk->execute();
    $chk_count = $chk->get_result()->fetch_assoc()['total'];

    if ($chk_count >= $limit_per_slot) {
        echo json_encode(['status' => 'error', 'msg' => 'This time slot is already full (5/5).']);
        exit();
    }

    $status1 = '1'; // Active by default
    $stmt    = $conn->prepare("
        INSERT INTO appointments
            (patient_id, appointment_date, appointment_time, reason, status, status1)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("isssss",
        $_POST['patient_id'],
        $date,
        $time,
        $_POST['reason'],
        $_POST['status'],
        $status1
    );

    echo json_encode([
        'status' => $stmt->execute() ? 'success' : 'error',
        'msg'    => $conn->error,
    ]);
    exit();
}


/* ============================================================
 * AJAX: UPDATE FULL APPOINTMENT
 * ============================================================ */
if (isset($_POST['action']) && $_POST['action'] === 'update_full') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $date = $_POST['appointment_date'] ?? '';
    $time = $_POST['appointment_time'] ?? '';
    $validation_error = validateAppointmentDateTime($date, $time);
    if ($validation_error) {
        echo json_encode(['status' => 'error', 'msg' => $validation_error]);
        exit();
    }

    $stmt = $conn->prepare("
        UPDATE appointments
        SET patient_id       = ?,
            appointment_date = ?,
            appointment_time = ?,
            reason           = ?,
            status           = ?
        WHERE appointment_id = ?
    ");
    $stmt->bind_param("issssi",
        $_POST['patient_id'],
        $date,
        $time,
        $_POST['reason'],
        $_POST['status'],
        $_POST['appointment_id']
    );

    echo json_encode([
        'status' => $stmt->execute() ? 'success' : 'error',
        'msg'    => $conn->error,
    ]);
    exit();
}


/* ============================================================
 * AJAX: QUICK STATUS UPDATE (badge click)
 * ============================================================ */
if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ?");
    $stmt->bind_param("si", $_POST['status'], $_POST['appointment_id']);

    echo json_encode([
        'status' => $stmt->execute() ? 'success' : 'error',
        'msg'    => $conn->error,
    ]);
    exit();
}


/* ============================================================
 * AJAX: SOFT DELETE
 *
 * Sets status1='0' — keeps the row but hides it from the main table.
 * Recoverable from the Recycle Bin.
 * ============================================================ */
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $stmt = $conn->prepare("UPDATE appointments SET status1 = '0' WHERE appointment_id = ?");
    $stmt->bind_param("i", $_POST['delete_id']);

    echo json_encode([
        'status' => $stmt->execute() ? 'success' : 'error',
        'msg'    => $conn->error,
    ]);
    exit();
}

if (isset($_POST['action']) && $_POST['action'] === 'delete_selected') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $ids = $_POST['delete_ids'] ?? [];
    if (!is_array($ids)) {
        $ids = explode(',', (string) $ids);
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    if (empty($ids)) {
        echo json_encode(['status' => 'error', 'msg' => 'No appointments selected.']);
        exit();
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types        = str_repeat('i', count($ids));
    $stmt         = $conn->prepare("UPDATE appointments SET status1 = '0' WHERE appointment_id IN ($placeholders)");
    $stmt->bind_param($types, ...$ids);

    echo json_encode([
        'status' => $stmt->execute() ? 'success' : 'error',
        'msg'    => $conn->error,
        'count'  => count($ids),
    ]);
    exit();
}


/* ============================================================
 * DATA FETCH: Patient list + Active appointments
 * ============================================================ */

/* All active patients (for the patient dropdown in the form) */
$patients = [];
$pr = $conn->query("SELECT patient_id, full_name FROM patients WHERE status = 1 ORDER BY full_name ASC");
if ($pr) while ($p = $pr->fetch_assoc()) $patients[] = $p;

/* All active appointments (status1='1'), newest first */
$appointments = [];
$ar = $conn->query("
    SELECT a.*, p.full_name AS patient_name
    FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id
    WHERE (a.status1 = '1' OR a.status1 = 'Active' OR a.status1 IS NULL)
      AND p.status = 1
    ORDER BY a.appointment_date DESC
");
if ($ar) while ($r = $ar->fetch_assoc()) $appointments[] = $r;

$today = date('Y-m-d');
?>

<!--------------- EXTERNAL DEPENDENCIES --------------->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

<style>
/* ============================================================
 * STYLES — Appointments Module
 * ============================================================ */

/* --- Main glassmorphism card --- */
.record-card {
    background:      rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
    padding:         25px;
    border-radius:   20px;
    color:           white;
    box-shadow:      0 10px 25px rgba(0, 0, 0, 0.3);
}

/* --- Top bar: title + add button --- */
.top-bar {
    display:         flex;
    justify-content: space-between;
    align-items:     center;
    margin-bottom:   25px;
    padding:         20px;
    background:      rgba(0, 166, 81, 0.1);
    border-radius:   15px;
    border:          1px solid rgba(0, 166, 81, 0.2);
}

.top-bar h2 { margin: 0; color: white; font-size: 24px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
.top-bar h2 i { color: #00a651; font-size: 28px; }

/* --- Dark blur overlay (outside .record-card to avoid stacking context issues) --- */
.appt-overlay {
    display:         none;
    position:        fixed;
    inset:           0;
    background:      rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(5px);
    z-index:         1050;
}

/* --- Appointment Form Modal --- */
#appointmentFormContainer {
    display:       none;
    position:      fixed;
    top:           50%;
    left:          50%;
    transform:     translate(-50%, -50%);

    width:         90%;
    max-width:     600px;
    max-height:    90vh;
    overflow-y:    auto;

    background:    white;
    padding:       30px;
    border-radius: 24px;
    z-index:       1100;
    box-shadow:    0 20px 60px rgba(0, 0, 0, 0.5);
    animation:     slideDown 0.3s ease;
}

@keyframes slideDown {
    from { opacity: 0; transform: translate(-50%, calc(-50% - 24px)); }
    to   { opacity: 1; transform: translate(-50%, -50%); }
}

.modal-show { display: block !important; }

/* --- Form modal title --- */
#appointmentFormContainer h3 {
    margin:         0 0 24px;
    color:          #004d26;
    font-size:      22px;
    font-weight:    800;
    text-align:     center;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

/* --- Time Slot Grid --- */
#slotGrid {
    display:               grid;
    grid-template-columns: repeat(3, 1fr);
    gap:                   15px;
    margin-top:            15px;
    padding:               10px 0;
}

.slot-item {
    display:         flex;
    flex-direction:  column;
    align-items:     center;
    justify-content: center;
    padding:         15px 10px;
    border-radius:   12px;
    border:          1px solid #d4edda;
    background:      #f8fff9;
    cursor:          pointer;
    transition:      all 0.2s ease-in-out;
    box-shadow:      0 2px 5px rgba(0, 0, 0, 0.02);
}

.slot-item b    { font-size: 1.1rem; color: #2f9e44; margin-bottom: 4px; display: block; }
.slot-item span { font-size: 0.8rem; color: #666; font-weight: 500; }

/* Hover: only when not full */
.slot-item:hover:not(.full) {
    background:  #e1f7e5;
    border-color: #40c057;
    transform:   translateY(-2px);
}

/* Selected slot */
.slot-item.selected             { background: #00a651 !important; border-color: #00813f !important; box-shadow: 0 4px 12px rgba(0,166,81,0.3); }
.slot-item.selected b,
.slot-item.selected span        { color: #ffffff !important; }

/* Full slot */
.slot-item.full                 { background: #f1f3f5; border-color: #dee2e6; cursor: not-allowed; }
.slot-item.full b,
.slot-item.full span            { color: #adb5bd; }

/* --- 2-column form grid --- */
.appt-form-grid                 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.appt-form-group                { display: flex; flex-direction: column; }
.appt-form-group.full-width     { grid-column: span 2; }

.appt-form-group label {
    font-size:   13px;
    font-weight: 700;
    margin-bottom: 8px;
    color:       #084c24;
    display:     flex;
    align-items: center;
    gap:         8px;
}

.appt-form-group label i { color: #00a651; font-size: 14px; }

/* --- Inputs scoped to modal only --- */
#appointmentFormContainer input,
#appointmentFormContainer select,
#appointmentFormContainer textarea {
    width:         100%;
    padding:       10px 12px;
    border:        1px solid #d6e8d7;
    border-radius: 10px;
    background:    #f7fcf7;
    color:         #1f3822;
    outline:       none;
    font-size:     13px;
    box-sizing:    border-box;
    transition:    border-color 0.2s, box-shadow 0.2s;
}

#appointmentFormContainer textarea { min-height: 80px; resize: vertical; }

#appointmentFormContainer input:focus,
#appointmentFormContainer select:focus,
#appointmentFormContainer textarea:focus {
    border-color: #00a651;
    box-shadow:   0 0 0 4px rgba(0, 166, 81, 0.1);
    transform:    none;
}

/* --- Reusable buttons --- */
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

.btn:hover  { transform: translateY(-2px); }
.btn-green  { background: linear-gradient(135deg, #2f9e44, #20c997); box-shadow: 0 6px 16px rgba(32,201,151,0.22); }
.btn-gray   { background: linear-gradient(135deg, #6c757d, #495057); box-shadow: 0 6px 16px rgba(73,80,87,0.22); }

/* --- Form action buttons row --- */
.appt-form-actions {
    margin-top:      22px;
    display:         flex;
    gap:             12px;
    justify-content: center;
    padding-top:     18px;
    border-top:      1px solid #eee;
    flex-wrap:       wrap;
}

.appt-form-actions .btn { min-width: 155px; justify-content: center; padding: 13px 26px; }

/* --- Search bar --- */
.search-wrapper { margin-bottom: 16px; }

.search-box {
    display:       flex;
    align-items:   center;
    background:    white;
    border-radius: 12px;
    border:        1px solid #d6e8d7;
    padding:       10px 16px;
    gap:           10px;
    max-width:     380px;
    box-shadow:    0 2px 10px rgba(0, 0, 0, 0.06);
    transition:    border-color 0.2s;
}

.search-box:focus-within      { border-color: #00a651; }
.search-box i.search-icon     { color: #00a651; font-size: 15px; flex-shrink: 0; }

.search-box input {
    border: none; outline: none; background: transparent;
    font-size: 14px; color: #1f3822; width: 100%;
    padding: 0; box-shadow: none; transform: none;
}

.search-box input:focus       { box-shadow: none; transform: none; border: none; }
.search-box input::placeholder { color: #aaa; }

.search-clear-btn {
    color:       #aaa;
    font-size:   14px;
    cursor:      pointer;
    display:     none;
    flex-shrink: 0;
    background:  none;
    border:      none;
    transition:  color 0.2s;
    padding:     0;
}

.search-clear-btn:hover { color: #dc3545; }

.no-results {
    display:       none;
    margin-top:    12px;
    padding:       12px 16px;
    background:    rgba(255, 255, 255, 0.07);
    border:        1px dashed rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    max-width:     380px;
    align-items:   center;
    gap:           10px;
}

.no-results i      { color: rgba(255,255,255,0.45); font-size: 15px; }
.no-results span   { font-size: 13px; color: rgba(255,255,255,0.65); }
.no-results strong { color: white; }

/* --- Table controls: row count + rows per page --- */
.table-controls {
    display:         flex;
    justify-content: space-between;
    align-items:     center;
    margin-bottom:   12px;
    flex-wrap:       wrap;
    gap:             10px;
}

.row-count        { font-size: 13px; color: rgba(255,255,255,0.7); font-weight: 500; }
.row-count strong { color: white; }

.rows-per-page { display: flex; align-items: center; gap: 8px; font-size: 13px; color: rgba(255,255,255,0.7); }

.table-control-actions { display: flex; align-items: center; gap: 10px; margin-left: auto; flex-wrap: wrap; }

.rows-per-page select {
    width:         auto;
    padding:       6px 10px;
    border-radius: 8px;
    font-size:     13px;
    border:        1px solid rgba(255,255,255,0.3);
    background:    white;
    color:         #1f3822;
    cursor:        pointer;
}


/* CSS Styles for the Archive/History Toggle Button */
.btn-toggle-archive {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    font-size: 14px;
    font-weight: 600;
    color: #495057;
    background-color: #f1f3f5;
    border: 1px solid #dee2e6;
    border-radius: 12px; /* Smooth rounded corners */
    padding: 10px 18px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
    user-select: none;
    outline: none;
}

/* Hover Effect */
.btn-toggle-archive:hover {
    background-color: #e9ecef;
    border-color: #ced4da;
    color: #212529;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
}

/* Active Press Effect */
.btn-toggle-archive:active {
    transform: translateY(0) scale(0.97);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
}

/* FontAwesome Icon Spin Transition on Switch */
.btn-toggle-archive i {
    transition: transform 0.3s ease;
}
.btn-toggle-archive:hover i {
    transform: rotate(-15deg);
}


/* --- Data table --- */
.table-responsive { width: 100%; overflow-x: auto; border-radius: 15px; box-shadow: 0 8px 25px rgba(0,0,0,0.1); }

table {
    width:            100%;
    border-collapse:  separate;
    border-spacing:   0;
    background:       white;
    color:            #333;
    border-radius:    15px;
    overflow:         hidden;
    box-shadow:       0 4px 15px rgba(0,0,0,0.05);
}

th {
    background:  linear-gradient(135deg, #004d26, #006633);
    color:       white;
    padding:     16px 15px;
    font-size:   13px;
    font-weight: 600;
    text-align:  left;
    position:    sticky;
    top:         0;
    white-space: nowrap;
}

td {
    padding:        13px 15px;
    border-bottom:  1px solid #f0f0f0;
    font-size:      13px;
    vertical-align: middle;
}

tbody tr:nth-child(even)  { background: #fafafa; }
tbody tr:hover            { background: #e8f5e8; transition: background 0.2s; }

th.sortable       { cursor: pointer; user-select: none; }
th.sortable:hover { background: linear-gradient(135deg, #006633, #008844); }

th.sortable .sort-icon                { margin-left: 6px; font-size: 11px; opacity: 0.5; }
th.sortable.asc .sort-icon,
th.sortable.desc .sort-icon           { opacity: 1; color: #20c997; }

/* --- Status badges (clickable) --- */
.badge {
    padding:     5px 12px;
    border-radius: 50px;
    font-size:   11px;
    font-weight: 700;
    min-width:   88px;
    text-align:  center;
    display:     inline-block;
    border:      1px solid transparent;
    cursor:      pointer;
    transition:  filter 0.2s, transform 0.2s;
}

.badge:hover          { filter: brightness(0.9); transform: scale(1.05); }
.badge-Pending        { background: #fff3cd; color: #856404; border-color: #ffeeba; }
.badge-Approved       { background: #d1ecf1; color: #0c5460; border-color: #bee5eb; }
.badge-Completed      { background: #d4edda; color: #155724; border-color: #c3e6cb; }
.badge-Cancelled      { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
.badge-Postponed      { background: #e2e3e5; color: #383d41; border-color: #d6d8db; }

/* --- Quick status popup --- */
.quick-status-popup {
    position:      fixed;
    background:    white;
    border-radius: 14px;
    box-shadow:    0 10px 35px rgba(0, 0, 0, 0.18);
    padding:       10px;
    z-index:       2000;
    min-width:     155px;
    border:        1px solid #e8e8e8;
    animation:     popIn 0.18s ease;
}

.quick-status-popup p {
    margin:         0 0 6px;
    font-size:      11px;
    font-weight:    700;
    color:          #999;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding:        0 6px;
}

.status-option {
    display:       block;
    width:         100%;
    text-align:    left;
    padding:       8px 12px;
    border:        none;
    background:    none;
    cursor:        pointer;
    font-size:     13px;
    font-weight:   600;
    border-radius: 8px;
    color:         #333;
    transition:    background 0.15s;
}

.status-option:hover { background: #f4f4f4; }

@keyframes popIn {
    from { opacity: 0; transform: scale(0.9) translateY(-4px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}

/* --- Row action buttons --- */
.action-btns { display: flex; gap: 8px; justify-content: center; }

.edit-btn, .delete-btn {
    width:           35px;
    height:          35px;
    border:          none;
    border-radius:   8px;
    cursor:          pointer;
    display:         flex;
    align-items:     center;
    justify-content: center;
    color:           white;
    transition:      transform 0.25s, box-shadow 0.25s;
}

.edit-btn   { background: linear-gradient(135deg, #007bff, #0056b3); box-shadow: 0 2px 8px rgba(0,123,255,0.3); }
.delete-btn { background: linear-gradient(135deg, #dc3545, #c82333); box-shadow: 0 2px 8px rgba(220,53,69,0.3); }

.edit-btn:hover   { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,123,255,0.4); }
.delete-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(220,53,69,0.4); }

/* --- Pagination --- */
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
    border:          1px solid rgba(255, 255, 255, 0.2);
    background:      rgba(255, 255, 255, 0.08);
    color:           rgba(255, 255, 255, 0.8);
    font-size:       13px;
    font-weight:     600;
    cursor:          pointer;
    transition:      all 0.2s;
    display:         flex;
    align-items:     center;
    justify-content: center;
}

.page-btn:hover    { background: rgba(0,166,81,0.25); border-color: #00a651; color: white; }
.page-btn.active   { background: linear-gradient(135deg, #2f9e44, #20c997); border-color: transparent; color: white; box-shadow: 0 4px 12px rgba(32,201,151,0.3); }
.page-btn:disabled { opacity: 0.35; cursor: not-allowed; pointer-events: none; }

/* --- Delete confirmation modal --- */
#deleteModal {
    position:        fixed;
    inset:           0;
    background:      rgba(0, 0, 0, 0.65);
    display:         none;
    align-items:     center;
    justify-content: center;
    z-index:         1200;
}

.modal-box {
    background:    white;
    padding:       36px 32px;
    border-radius: 24px;
    width:         360px;
    text-align:    center;
    color:         #1a1a2e;
    box-shadow:    0 25px 60px rgba(0, 0, 0, 0.35);
    position:      relative;
    overflow:      hidden;
    animation:     modalPop 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes modalPop {
    from { opacity: 0; transform: scale(0.88); }
    to   { opacity: 1; transform: scale(1); }
}

.modal-accent-bar { position: absolute; top: 0; left: 0; right: 0; height: 5px; background: linear-gradient(90deg, #dc3545, #ff6b6b); border-radius: 24px 24px 0 0; }
.modal-icon       { width: 72px; height: 72px; background: linear-gradient(135deg, #ffe0e3, #ffc2c7); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
.modal-icon i     { font-size: 28px; color: #dc3545; }
.modal-title      { margin: 0 0 8px; font-size: 22px; font-weight: 800; color: #c0392b; }
.modal-subtitle   { margin: 0 0 6px; font-size: 14px; color: #666; }
.modal-patient-name { margin: 0 0 24px; font-size: 16px; font-weight: 700; color: #1a1a2e; }
.modal-buttons    { display: flex; gap: 12px; justify-content: center; }

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
    background:      linear-gradient(135deg, #dc3545, #c0392b);
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

/* Toast keyframes */
@keyframes toastIn  { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes toastOut { from { opacity: 1; transform: translateY(0); }   to { opacity: 0; transform: translateY(20px); } }

@media (max-width: 640px) {
    #appointmentFormContainer {
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

    #appointmentFormContainer h3 {
        font-size: 18px;
        line-height: 1.25;
        margin-bottom: 16px;
        letter-spacing: 0.05em;
    }

    .appt-form-grid,
    #slotGrid {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .appt-form-group.full-width {
        grid-column: span 1;
    }

    #appointmentFormContainer input,
    #appointmentFormContainer select,
    #appointmentFormContainer textarea {
        min-height: 44px;
        font-size: 16px;
    }

    .appt-form-actions,
    .modal-buttons {
        flex-direction: column;
    }

    .appt-form-actions .btn,
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

    .quick-status-popup {
        left: 14px !important;
        right: 14px !important;
        width: auto;
        min-width: 0;
    }
}
</style>


<!-- ============================================================
     IMPORTANT: All three floating layers (overlay, form modal,
     delete modal) are placed OUTSIDE .record-card.
     Placing them inside would cause .record-card's backdrop-filter
     to create a new stacking context, breaking position:fixed
     and causing the "all black" overlay bug.
     ============================================================ -->

<!-- 1. Dim overlay — clicking it closes the form -->
<div id="apptOverlay" class="appt-overlay" onclick="closeForm()"></div>

<!-- 2. Appointment add/edit form modal -->
<div id="appointmentFormContainer">
    <h3 id="formTitle">New Appointment</h3>
    <form id="appointmentForm">
        <input type="hidden" name="action"         id="formAction"         value="add">
        <input type="hidden" name="appointment_id" id="formAppointmentId"  value="">

        <div class="appt-form-grid">

            <!-- Patient selector -->
            <div class="appt-form-group">
                <label><i class="fa-solid fa-user"></i> Patient</label>
                <select name="patient_id" id="formPatientId" required>
                    <option value="">-- Select Patient --</option>
                    <?php foreach ($patients as $p): ?>
                        <option value="<?= $p['patient_id'] ?>">
                            <?= htmlspecialchars($p['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Appointment date -->
            <div class="appt-form-group">
                <label><i class="fa-solid fa-calendar"></i> Appointment Date</label>
                <input type="date" name="appointment_date" id="formDate"
                       min="<?= $today ?>" required>
            </div>

            <!-- Status -->
            <div class="appt-form-group">
                <label><i class="fa-solid fa-stethoscope"></i> Status</label>
                <select name="status" id="formStatus">
                    <option value="Pending">Pending</option>
                    <option value="Approved">Approved</option>
                    <option value="Completed">Completed</option>
                    <option value="Cancelled">Cancelled</option>
                    <option value="Postponed">Postponed</option>
                </select>
            </div>

            <!-- Reason (full width) -->
            <div class="appt-form-group full-width">
                <label><i class="fa-solid fa-notes-medical"></i> Reason</label>
                <input type="text" name="reason" id="formReason"
                       placeholder="e.g. Cough, Check-up" required>
            </div>

            <!-- Time slot picker (full width) -->
            <div class="appt-form-group full-width">
                <label><i class="fa-solid fa-clock"></i> Select Available Time Slot (8 AM – 5 PM)</label>
                <div id="slotGrid">
                    <p style="color:#666; font-size:12px; font-style:italic; grid-column:1/-1;">
                        Please select an appointment date first to see available slots...
                    </p>
                </div>
                <!-- Hidden input updated when a slot is clicked -->
                <input type="hidden" name="appointment_time" id="formTime" required>
            </div>

        </div><!-- /.appt-form-grid -->

        <div class="appt-form-actions">
            <button type="submit" class="btn btn-green">
                <i class="fa-solid fa-save"></i> Save Appointment
            </button>
            <button type="button" class="btn btn-gray" onclick="closeForm()">
                <i class="fa-solid fa-times"></i> Cancel
            </button>
        </div>
    </form>
</div><!-- /#appointmentFormContainer -->

<!-- 3. Delete confirmation modal -->
<div id="deleteModal">
    <div class="modal-box">
        <div class="modal-accent-bar"></div>
        <div class="modal-icon"><i class="fa-solid fa-trash"></i></div>
        <h3 class="modal-title">Delete Appointment?</h3>
        <p class="modal-subtitle">You are about to remove the appointment of</p>
        <p class="modal-patient-name">"<span id="delName"></span>"</p>
        <div class="modal-buttons">
            <button class="modal-btn-cancel" onclick="closeDeleteModal()">
                <i class="fa-solid fa-xmark"></i> Cancel
            </button>
            <button class="modal-btn-delete" onclick="executeDelete()">
                <i class="fa-solid fa-trash"></i> Yes, Delete
            </button>
        </div>
    </div>
</div>

<!-- 4. Main content card (table only — no modals inside) -->
<div class="record-card">
    <div class="top-bar">
        <h2><i class="fa-solid fa-calendar-check"></i> Appointments</h2>
        <button class="btn btn-green" onclick="prepareAdd()">
            <i class="fa-solid fa-plus"></i> New Appointment
        </button>
    </div>

    <!-- Search bar -->
    <div class="search-wrapper">
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <input type="text" id="appointmentSearch"
                   placeholder="Search by patient or reason..."
                   oninput="searchAppointments()">
            <button class="search-clear-btn" id="clearSearchBtn" onclick="clearSearch()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="no-results" id="noResults">
            <i class="fa-solid fa-calendar-xmark"></i>
            <span>No results for <strong id="noResultsQuery"></strong></span>
        </div>
    </div>

    <!-- Table controls: row count + rows per page -->
    <div class="table-controls">
        <div class="row-count" id="rowCount">
            Showing <strong>0</strong> of <strong>0</strong> records
        </div>
        <div class="table-control-actions">
            <button id="toggleAppointmentsBtn" class="btn-toggle-archive" onclick="toggleAppointmentView()">
                <i class="fa-solid fa-calendar-day"></i>
                <span>Active Upcoming</span>
            </button>
            <button type="button" class="btn-toggle-archive" onclick="toggleSelectAllAppointments()">
                <i class="fa-solid fa-check-double"></i>
                <span>Select All</span>
            </button>
            <button type="button" class="btn-toggle-archive" onclick="confirmDeleteSelectedAppointments()"
                    style="background:#fff5f5;color:#c92a2a;border-color:#ffc9c9;">
                <i class="fa-solid fa-trash"></i>
                <span>Delete Selected</span>
            </button>
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
    </div>

    <!-- Appointments table -->
    <div class="table-responsive">
        <table id="appointmentTable">
            <thead>
                <tr>
                    <th class="sortable" onclick="sortTable(0)" data-col="0">Patient <span class="sort-icon fa-solid fa-sort"></span></th>
                    <th class="sortable" onclick="sortTable(1)" data-col="1">Date <span class="sort-icon fa-solid fa-sort"></span></th>
                    <th>Time</th>
                    <th>Reason</th>
                    <th class="sortable" onclick="sortTable(4)" data-col="4">Status <span class="sort-icon fa-solid fa-sort"></span></th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($appointments)): ?>
                    <tr class="empty-row">
                        <td colspan="6" style="text-align:center; color:#aaa; padding:30px;">
                            <i class="fa-solid fa-calendar-xmark" style="font-size:28px; display:block; margin-bottom:8px;"></i>
                            No appointments found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($appointments as $row): ?>
                        <tr data-id="<?= $row['appointment_id'] ?>">
                            <td><strong><?= htmlspecialchars($row['patient_name']) ?></strong></td>
                            <td><?= date("M d, Y", strtotime($row['appointment_date'])) ?></td>
                            <td><?= date("h:i A", strtotime($row['appointment_time'])) ?></td>
                            <td><?= htmlspecialchars($row['reason']) ?></td>
                            <td>
                                <span class="badge badge-<?= htmlspecialchars($row['status']) ?>"
                                      onclick="openQuickStatus(<?= $row['appointment_id'] ?>, '<?= htmlspecialchars($row['status']) ?>', this)"
                                      title="Click to change status">
                                    <?= htmlspecialchars($row['status']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-btns">
                                    <input type="checkbox"
                                           class="appointment-select"
                                           value="<?= $row['appointment_id'] ?>"
                                           aria-label="Select appointment">
                                    <button class="edit-btn" title="Edit Appointment"
                                        onclick='prepareEdit(<?= json_encode([
                                            "appointment_id"   => $row["appointment_id"],
                                            "patient_id"       => $row["patient_id"],
                                            "appointment_date" => $row["appointment_date"],
                                            "appointment_time" => $row["appointment_time"],
                                            "reason"           => $row["reason"],
                                            "status"           => $row["status"],
                                        ]) ?>)'>
                                        <i class="fa-solid fa-edit"></i>
                                    </button>
                                    <button class="delete-btn" title="Delete Appointment"
                                        onclick="confirmDelete(<?= $row['appointment_id'] ?>, '<?= addslashes(htmlspecialchars($row['patient_name'])) ?>')">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination-wrapper" id="paginationWrapper"></div>
</div><!-- /.record-card -->


<script>
/* ============================================================
 * JavaScript — Appointments Module
 *
 * Sections:
 * 1.  Global State
 * 2.  Toast Notification
 * 3.  Time Slot Loader
 * 4.  Form Open / Close
 * 5.  Form Submit (AJAX)
 * 6.  Quick Status Popup
 * 7.  Search
 * 8.  Pagination
 * 9.  Column Sorting
 * 10.  Delete Modal
 * 11.  Init & Default Today Filter  ← FIX APPLIED HERE
 * ============================================================ */


/* ── 1. GLOBAL STATE ──────────────────────────────────────── */
var currentDeleteId = null;
var currentDeleteIds = [];
var currentPage     = 1;
var rowsPerPage     = 10;
var sortColIndex    = -1;
var sortDir         = 'asc';
var TODAY           = '<?= $today ?>';
var appointmentViewMode = 'active'; // active | history | all


/* ── 2. TOAST NOTIFICATION ────────────────────────────────── */
function showToast(msg, type) {
    var old = document.getElementById('apptToast');
    if (old) old.remove();

    var t   = document.createElement('div');
    t.id   = 'apptToast';
    var ok = type !== 'error';

    t.style.cssText =
        'position:fixed;bottom:28px;right:28px;z-index:9999;' +
        'background:' + (ok
            ? 'linear-gradient(135deg,#2f9e44,#20c997)'
            : 'linear-gradient(135deg,#dc3545,#c82333)') + ';' +
        'color:#fff;padding:15px 22px;border-radius:14px;font-size:14px;font-weight:700;' +
        'display:flex;align-items:center;gap:10px;box-shadow:0 8px 25px rgba(0,0,0,.25);' +
        'max-width:320px;animation:toastIn .35s ease;';

    t.innerHTML = '<span style="font-size:13px;font-weight:900">' + (ok ? 'OK' : '!') + '</span><span>' + msg + '</span>';
    document.body.appendChild(t);

    setTimeout(function () {
        t.style.animation = 'toastOut .35s ease forwards';
        setTimeout(function () { t.remove(); }, 380);
    }, 3000);
}


/* ── 3. TIME SLOT LOADER ──────────────────────────────────── */
function loadAvailableSlots(date, selectedTime) {
    var grid      = document.getElementById('slotGrid');
    var timeInput = document.getElementById('formTime');

    grid.innerHTML = '<p style="grid-column:1/-1;text-align:center;color:#666;">Checking availability...</p>';
    if (!selectedTime) timeInput.value = '';

    fetch('appointments.php?action=get_slots&date=' + encodeURIComponent(date))
        .then(function (r) { return r.json(); })
        .then(function (slots) {
            grid.innerHTML = '';

            slots.forEach(function (s) {
                var div        = document.createElement('div');
                var isSelected = selectedTime && selectedTime.substring(0, 5) === s.time;

                div.className = 'slot-item' +
                    (s.is_full   ? ' full'     : '') +
                    (isSelected  ? ' selected' : '');

                div.innerHTML = '<b>' + s.display + '</b>' +
                    '<span>' + (s.is_past ? 'PASSED' : (s.is_full ? 'FULL' : s.taken + '/' + s.limit + ' SLOTS')) + '</span>';

                if (!s.is_full || isSelected) {
                    div.onclick = function () {
                        document.querySelectorAll('.slot-item').forEach(function (el) {
                            el.classList.remove('selected');
                        });
                        div.classList.add('selected');
                        timeInput.value = s.time;
                    };
                }

                grid.appendChild(div);
            });
        })
        .catch(function () {
            grid.innerHTML = '<p style="grid-column:1/-1;color:#dc3545;text-align:center;">Failed to load slots.</p>';
        });
}

document.getElementById('formDate').addEventListener('change', function () {
    loadAvailableSlots(this.value);
});


/* ── 4. FORM OPEN / CLOSE ─────────────────────────────────── */
function prepareAdd() {
    document.getElementById('appointmentForm').reset();
    document.getElementById('formAction').value        = 'add';
    document.getElementById('formAppointmentId').value = '';
    document.getElementById('formTitle').innerText     = 'New Appointment';
    document.getElementById('formDate').setAttribute('min', TODAY);

    var todayStr = new Date().toISOString().split('T')[0];
    document.getElementById('formDate').value = todayStr;
    loadAvailableSlots(todayStr, null);

    openForm();
}

function prepareEdit(data) {
    document.getElementById('formAction').value         = 'update_full';
    document.getElementById('formAppointmentId').value  = data.appointment_id;
    document.getElementById('formPatientId').value      = data.patient_id;
    document.getElementById('formDate').value           = data.appointment_date;
    document.getElementById('formStatus').value         = data.status;
    document.getElementById('formReason').value         = data.reason;
    document.getElementById('formTitle').innerText      = 'Edit Appointment';

    document.getElementById('formDate').removeAttribute('min');

    loadAvailableSlots(data.appointment_date, data.appointment_time);

    openForm();
}

function openForm() {
    document.getElementById('appointmentFormContainer').classList.add('modal-show');
    document.getElementById('apptOverlay').classList.add('modal-show');
}

function closeForm() {
    document.getElementById('appointmentFormContainer').classList.remove('modal-show');
    document.getElementById('apptOverlay').classList.remove('modal-show');
}


/* ── 5. FORM SUBMIT (AJAX) ────────────────────────────────── */
document.getElementById('appointmentForm').addEventListener('submit', function (e) {
    e.preventDefault();

    var action   = document.getElementById('formAction').value;
    var isUpdate = (action === 'update_full');

    if (!isUpdate) {
        var selectedDate = document.getElementById('formDate').value;
        var selectedTime = document.getElementById('formTime').value;
        var now          = new Date();
        var todayStr     = now.toISOString().split('T')[0];

        if (selectedDate < todayStr) {
            showToast('Cannot book an appointment in the past.', 'error');
            return;
        }

        if (selectedDate === todayStr && selectedTime) {
            var nowMins  = now.getHours() * 60 + now.getMinutes();
            var parts    = selectedTime.split(':');
            var selMins  = parseInt(parts[0]) * 60 + parseInt(parts[1]);
            if (selMins <= nowMins) {
                showToast('Cannot book a time that has already passed today.', 'error');
                return;
            }
        }
    }

    if (!document.getElementById('formTime').value) {
        showToast('Please select a time slot first.', 'error');
        return;
    }

    fetch('appointments.php', { method: 'POST', body: new FormData(this) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.status === 'success') {
                closeForm();
                showToast(isUpdate ? 'Appointment updated!' : 'Appointment saved!', 'success');
                setTimeout(function () {
                    if (typeof loadPage === 'function') {
                        loadPage('appointments.php');
                    } else {
                        location.reload();
                    }
                }, 1200);
            } else {
                showToast('Error: ' + (d.msg || 'Could not save.'), 'error');
            }
        })
        .catch(function () { showToast('Network error. Try again.', 'error'); });
});


/* ── 6. QUICK STATUS POPUP ────────────────────────────────── */
function openQuickStatus(id, current, badgeEl) {
    var old = document.getElementById('qsPopup');
    if (old) { old.remove(); return; }

    var statuses = ['Pending', 'Approved', 'Completed', 'Cancelled', 'Postponed'];
    var popup    = document.createElement('div');
    popup.id     = 'qsPopup';
    popup.className = 'quick-status-popup';

    var lbl       = document.createElement('p');
    lbl.innerText = 'Change Status';
    popup.appendChild(lbl);

    statuses.forEach(function (s) {
        var btn       = document.createElement('button');
        btn.className = 'status-option';
        btn.style.color = (s === current) ? '#00a651' : '#333';
        btn.innerText = (s === current ? '✓ ' : '') + s;
        btn.onclick   = function () { doUpdateStatus(id, s, badgeEl); popup.remove(); };
        popup.appendChild(btn);
    });

    var rect         = badgeEl.getBoundingClientRect();
    popup.style.top  = (rect.bottom + 6) + 'px';
    popup.style.left = rect.left + 'px';
    document.body.appendChild(popup);

    setTimeout(function () {
        document.addEventListener('click', function handler(e) {
            if (!popup.contains(e.target) && e.target !== badgeEl) {
                popup.remove();
                document.removeEventListener('click', handler);
            }
        });
    }, 10);
}

function doUpdateStatus(id, status, badgeEl) {
    var fd = new FormData();
    fd.append('action',         'update_status');
    fd.append('appointment_id', id);
    fd.append('status',         status);

    fetch('appointments.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.status === 'success') {
                badgeEl.className = 'badge badge-' + status;
                badgeEl.innerText = status;
                badgeEl.setAttribute('onclick',
                    "openQuickStatus(" + id + ", '" + status + "', this)");
                showToast('Status → ' + status, 'success');
                
                // Re-evaluate table filter settings based on status change
                if (typeof filterAndSortAppointments === 'function') {
                    filterAndSortAppointments();
                }
            } else {
                showToast('Failed to update status.', 'error');
            }
        });
}


/* ── 7. SEARCH ────────────────────────────────────────────── */
function searchAppointments() {
    var val    = document.getElementById('appointmentSearch').value;
    var filter = val.toLowerCase().trim();

    document.getElementById('clearSearchBtn').style.display = filter ? 'inline-flex' : 'none';

    var found = 0;
    document.querySelectorAll('#appointmentTable tbody tr').forEach(function (row) {
        if (row.classList.contains('empty-row')) return;
        if (!row.cells || row.cells.length < 4) return;

        var match = !filter ||
            row.cells[0].innerText.toLowerCase().includes(filter) ||
            row.cells[3].innerText.toLowerCase().includes(filter);

        row.dataset.hidden = match ? 'false' : 'true';
        if (!match) row.style.display = 'none';
        else found++;
    });

    var nr = document.getElementById('noResults');
    if (!found && filter) {
        document.getElementById('noResultsQuery').innerText = '"' + val.trim() + '"';
        nr.style.display = 'flex';
    } else {
        nr.style.display = 'none';
    }

    currentPage = 1;
    applyPagination();
}

function clearSearch() {
    document.getElementById('appointmentSearch').value = '';
    document.getElementById('clearSearchBtn').style.display = 'none';
    searchAppointments();
}


/* ── 8. PAGINATION ────────────────────────────────────────── */
function getVisibleRows() {
    return Array.from(document.querySelectorAll('#appointmentTable tbody tr'))
        .filter(function (row) {
            if (row.classList.contains('empty-row')) return false;
            return row.dataset.hidden !== 'true';
        });
}

function applyPagination() {
    var rows       = getVisibleRows();
    var total      = rows.length;
    var totalPages = Math.ceil(total / rowsPerPage) || 1;

    if (currentPage > totalPages) currentPage = totalPages;
    if (currentPage < 1)          currentPage = 1;

    var start = (currentPage - 1) * rowsPerPage;
    var end   = start + rowsPerPage;

    document.querySelectorAll('#appointmentTable tbody tr').forEach(function (row) {
        if (row.dataset.hidden === 'true') {
            row.style.display = 'none';
        }
    });

    rows.forEach(function (row, index) {
        row.style.display = (index >= start && index < end) ? '' : 'none';
    });

    var countEl = document.getElementById('rowCount');
    if (countEl) {
        var from = total === 0 ? 0 : start + 1;
        var to   = Math.min(end, total);
        countEl.innerHTML =
            'Showing <strong>' + from + (total > 1 ? '–' + to : '') + '</strong>' +
            ' of <strong>' + total + '</strong>' +
            ' record' + (total !== 1 ? 's' : '');
    }

    renderPagination(totalPages);
}

function renderPagination(totalPages) {
    var wrapper = document.getElementById('paginationWrapper');
    if (!wrapper) return;
    wrapper.innerHTML = '';

    function mkBtn(html, page, disabled, isActive) {
        var b       = document.createElement('button');
        b.className = 'page-btn' + (isActive ? ' active' : '');
        b.innerHTML = html;
        b.disabled  = disabled;
        if (!disabled) b.onclick = function () { currentPage = page; applyPagination(); };
        wrapper.appendChild(b);
    }

    mkBtn('<i class="fa-solid fa-chevron-left"></i>', currentPage - 1, currentPage === 1, false);

    var startPage = Math.max(1, currentPage - 2);
    var endPage   = Math.min(totalPages, startPage + 4);
    if (endPage - startPage < 4) startPage = Math.max(1, endPage - 4);

    for (var i = startPage; i <= endPage; i++) {
        mkBtn(i, i, false, i === currentPage);
    }

    mkBtn('<i class="fa-solid fa-chevron-right"></i>', currentPage + 1, currentPage === totalPages, false);
}

function changeRowsPerPage() {
    rowsPerPage = parseInt(document.getElementById('rowsPerPage').value);
    currentPage = 1;
    applyPagination();
}


/* ── 9. COLUMN SORTING ────────────────────────────────────── */
function sortTable(col) {
    var tbody = document.querySelector('#appointmentTable tbody');
    var rows  = Array.from(tbody.querySelectorAll('tr:not(.empty-row)'));

    sortDir      = (sortColIndex === col && sortDir === 'asc') ? 'desc' : 'asc';
    sortColIndex = col;

    rows.sort(function (a, b) {
        var at = a.cells[col] ? a.cells[col].innerText.trim().toLowerCase() : '';
        var bt = b.cells[col] ? b.cells[col].innerText.trim().toLowerCase() : '';
        return sortDir === 'asc' ? at.localeCompare(bt) : bt.localeCompare(at);
    });

    rows.forEach(function (r) { tbody.appendChild(r); });

    document.querySelectorAll('th.sortable').forEach(function (th) {
        var icon = th.querySelector('.sort-icon');
        if (!icon) return;
        var c = parseInt(th.dataset.col);

        if (c === sortColIndex) {
            icon.className = 'sort-icon fa-solid ' + (sortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
            th.classList.toggle('asc',  sortDir === 'asc');
            th.classList.toggle('desc', sortDir === 'desc');
        } else {
            icon.className = 'sort-icon fa-solid fa-sort';
            th.classList.remove('asc', 'desc');
        }
    });

    currentPage = 1;
    applyPagination();
}


/* ── 10. DELETE MODAL ─────────────────────────────────────── */
function confirmDelete(id, name) {
    currentDeleteId = id;
    currentDeleteIds = [];
    document.getElementById('delName').innerText         = name;
    document.getElementById('deleteModal').style.display = 'flex';
}

function getSelectedAppointmentIds() {
    return Array.from(document.querySelectorAll('.appointment-select:checked'))
        .map(function (box) { return box.value; });
}

function toggleSelectAllAppointments() {
    var rows = getVisibleRows();
    var boxes = rows
        .map(function (row) { return row.querySelector('.appointment-select'); })
        .filter(Boolean);

    if (boxes.length === 0) {
        showToast('No appointments to select.', 'error');
        return;
    }

    var shouldCheck = boxes.some(function (box) { return !box.checked; });
    boxes.forEach(function (box) { box.checked = shouldCheck; });
}

function confirmDeleteSelectedAppointments() {
    var ids = getSelectedAppointmentIds();
    if (ids.length === 0) {
        showToast('Please select appointments first.', 'error');
        return;
    }

    currentDeleteId = null;
    currentDeleteIds = ids;
    document.getElementById('delName').innerText = ids.length + ' selected appointment' + (ids.length > 1 ? 's' : '');
    document.getElementById('deleteModal').style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
    currentDeleteId = null;
    currentDeleteIds = [];
}

function executeDelete() {
    if (!currentDeleteId && currentDeleteIds.length === 0) return;

    var deletedId = currentDeleteId;
    var deletedIds = currentDeleteIds.slice();
    var fd = new FormData();
    if (deletedIds.length > 0) {
        fd.append('action', 'delete_selected');
        deletedIds.forEach(function (id) {
            fd.append('delete_ids[]', id);
        });
    } else {
        fd.append('action',    'delete');
        fd.append('delete_id', deletedId);
    }

    fetch('appointments.php', { method: 'POST', body: fd })
        .then(function (r) {
            if (!r.ok) throw new Error('Network error');
            return r.json();
        })
        .then(function (d) {
            if (d.status === 'success') {
                closeDeleteModal();
                showToast(deletedIds.length > 0 ? 'Selected appointments deleted!' : 'Appointment deleted!', 'success');

                if (deletedIds.length > 0) {
                    deletedIds.forEach(function (id) {
                        var selectedRow = document.querySelector('tr[data-id="' + id + '"]');
                        if (selectedRow) selectedRow.remove();
                    });
                } else {
                    var row = document.querySelector('tr[data-id="' + deletedId + '"]');
                    if (row) row.remove();
                }

                if (typeof loadPage === 'function') {
                    loadPage('appointments.php');
                } else if (typeof filterAndSortAppointments === 'function') {
                    filterAndSortAppointments();
                } else {
                    location.reload();
                }
            } else {
                showToast('Error: ' + (d.msg || 'Failed to delete'), 'error');
            }
        })
        .catch(function (err) {
            console.error(err);
            showToast('Network error.', 'error');
            closeDeleteModal();
        });
}


/* ── 11. INIT, DATE FILTER & TOGGLE SYSTEM ────────────────── */

/**
 * initializeTable()
 * Runs immediately on load. Filters table to show Today's and Future appointments.
 */
(function initializeTable() {
    updateAppointmentViewButton();
    filterAndSortAppointments();
})();

/**
 * filterAndSortAppointments()
 * Handles parsing row dates, separating "today & future" from "past & completed",
 * and updating the DOM layout based on the active view.
 */
function filterAndSortAppointments() {
    var tbody = document.querySelector('#appointmentTable tbody');
    if (!tbody) return;

    var rows = Array.from(tbody.querySelectorAll('tr:not(.empty-row)'));
    if (rows.length === 0) {
        applyPagination();
        return;
    }

    // 1. Get today's local date (ignoring current hours/minutes for clean date comparison)
    var todayObj = new Date();
    todayObj.setHours(0, 0, 0, 0); 

    var visibleRowsList = [];

    // 2. Loop through rows and filter
    rows.forEach(function (row) {
        var dateText = row.cells[1] ? row.cells[1].innerText.trim() : '';
        var timeText = row.cells[2] ? row.cells[2].innerText.trim() : '';
        var statusText = row.cells[4] ? row.cells[4].innerText.trim().toUpperCase() : '';

        // Create comparable Date object for this row
        var apptDateOnly = new Date(dateText);
        apptDateOnly.setHours(0, 0, 0, 0);

        var isPastDate = (apptDateOnly < todayObj);
        var isCompletedOrCancelled = (statusText === 'COMPLETED' || statusText === 'CANCELLED');

        // Determine if this row belongs to "Past & Completed" (History)
        var isHistoryItem = isPastDate || isCompletedOrCancelled;

        var shouldShow = appointmentViewMode === 'all'
            || (appointmentViewMode === 'history' && isHistoryItem)
            || (appointmentViewMode === 'active' && !isHistoryItem);

        row.dataset.hidden = shouldShow ? 'false' : 'true';
        row.style.display = shouldShow ? '' : 'none';
        if (shouldShow) visibleRowsList.push(row);
    });

    // 3. Chronological Sort: Earliest date & time on top
    visibleRowsList.sort(function (a, b) {
        var dateAStr = a.cells[1] ? a.cells[1].innerText.trim() : '';
        var timeAStr = a.cells[2] ? a.cells[2].innerText.trim() : '';
        var dateBStr = b.cells[1] ? b.cells[1].innerText.trim() : '';
        var timeBStr = b.cells[2] ? b.cells[2].innerText.trim() : '';

        var dateTimeA = new Date(dateAStr + " " + timeAStr);
        var dateTimeB = new Date(dateBStr + " " + timeBStr);

        return dateTimeA - dateTimeB; 
    });

    // 4. Update the DOM table
    var existingEmpty = tbody.querySelector('.empty-row');
    if (existingEmpty) existingEmpty.remove();

    if (visibleRowsList.length === 0) {
        var emptyRow = document.createElement('tr');
        emptyRow.className = 'empty-row';
        
        // Dynamic empty message depending on the active filter mode
        var noRecordsMsg = appointmentViewMode === 'all'
            ? "No appointments found."
            : (appointmentViewMode === 'history'
                ? "No past or completed appointments found in history."
                : "No upcoming active appointments found.");

        emptyRow.innerHTML = `
            <td colspan="6" style="text-align:center; color:#aaa; padding:30px;">
                <i class="fa-solid fa-calendar-xmark" style="font-size:28px; display:block; margin-bottom:8px;"></i>
                ${noRecordsMsg}
            </td>`;
        tbody.appendChild(emptyRow);
    } else {
        visibleRowsList.forEach(function (r) { 
            tbody.appendChild(r); 
        });
    }

    currentPage = 1;
    applyPagination();
}

function toggleAppointmentView() {
    var modes = ['active', 'history', 'all'];
    var currentIndex = modes.indexOf(appointmentViewMode);
    appointmentViewMode = modes[(currentIndex + 1) % modes.length];
    updateAppointmentViewButton();
    filterAndSortAppointments();
}

function updateAppointmentViewButton() {
    var btn = document.getElementById('toggleAppointmentsBtn');
    if (!btn) return;

    var span = btn.querySelector('span');
    var icon = btn.querySelector('i');

    if (appointmentViewMode === 'history') {
        span.innerText = 'Past / Completed';
        icon.className = 'fa-solid fa-history';
        btn.style.background = '#fff0f6';
        btn.style.color = '#d6336c';
        btn.style.borderColor = '#fcc2d7';
    } else if (appointmentViewMode === 'all') {
        span.innerText = 'Show All';
        icon.className = 'fa-solid fa-list';
        btn.style.background = '#e7f5ff';
        btn.style.color = '#1971c2';
        btn.style.borderColor = '#a5d8ff';
    } else {
        span.innerText = 'Active Upcoming';
        icon.className = 'fa-solid fa-calendar-day';
        btn.style.background = '#e8f5e9';
        btn.style.color = '#2e7d32';
        btn.style.borderColor = '#a5d6a7';
    }
}
                    
</script>
