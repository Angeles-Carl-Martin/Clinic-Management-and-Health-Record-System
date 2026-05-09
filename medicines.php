<?php
/* ============================================================
 * medicines.php — Medicine Inventory Module
 *
 * Purpose  : Display, add, edit, and soft-delete medicine records.
 *            Shows alert badges for low stock, out of stock,
 *            expiring soon, and already expired medicines.
 *
 * Patterns :
 *   - Soft delete : UPDATE status = 0 (row kept, hidden from view)
 *   - AJAX only   : All mutations return JSON, no page reload
 *   - Modal style : Matches the appointments.php popup pattern
 *                   (fixed overlay + centered card + blur backdrop)
 * ============================================================ */

// Prevent PHP from sending stray whitespace before headers
ob_start();

session_start();
require "db.php";
require_staff_login();
redirect_direct_fragment_access();

/** @var mysqli $conn */

$today = date('Y-m-d');

function validateFutureDateValue(string $date, string $label): ?string {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    $errors = DateTime::getLastErrors();
    if (!$dt || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $dt->format('Y-m-d') !== $date) {
        return $label . ' must be a valid date.';
    }
    if ($dt < new DateTime('today')) {
        return $label . ' cannot be in the past.';
    }
    return null;
}


/* ============================================================
 * AJAX HANDLER: ADD or UPDATE MEDICINE
 *
 * Triggered by POST with 'action' = 'add' or 'update'.
 * Returns: { "status": "success" | "error", "message": "..." }
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');

    $action   = $_POST['action'];
    $name     = trim($_POST['medicine_name'] ?? '');
    $category = trim($_POST['category']      ?? '');
    $qty      = (int) ($_POST['quantity']     ?? 0);
    $unit     = trim($_POST['unit']           ?? '');
    $expiry   = trim($_POST['expiration_date'] ?? '');

    // Basic validation
    if (empty($name) || $qty <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Medicine name and a valid quantity are required.']);
        exit();
    }

    $expiry_error = validateFutureDateValue($expiry, 'Expiration date');
    if ($expiry_error) {
        echo json_encode(['status' => 'error', 'message' => $expiry_error]);
        exit();
    }

    try {
        if ($action === 'add') {
            $stmt = $conn->prepare("
                INSERT INTO medicines (medicine_name, category, quantity, unit, expiration_date, status)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->bind_param("ssiss", $name, $category, $qty, $unit, $expiry);

        } elseif ($action === 'update') {
            $id   = (int) ($_POST['medicine_id'] ?? 0);
            $stmt = $conn->prepare("
                UPDATE medicines
                SET medicine_name = ?, category = ?, quantity = ?, unit = ?, expiration_date = ?
                WHERE medicine_id = ?
            ");
            $stmt->bind_param("ssissi", $name, $category, $qty, $unit, $expiry, $id);

        } else {
            echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
            exit();
        }

        echo json_encode($stmt->execute()
            ? ['status' => 'success']
            : ['status' => 'error', 'message' => 'Database execution failed: ' . $conn->error]
        );

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }

    exit();
}


/* ============================================================
 * AJAX HANDLER: SOFT DELETE
 *
 * Triggered by GET: medicines.php?delete_id=N
 * Sets status = 0 instead of removing the row.
 * Returns: { "status": "success" | "error" }
 * ============================================================ */
if (isset($_GET['delete_id'])) {
    ob_clean();
    header('Content-Type: application/json');

    $delete_id = (int) $_GET['delete_id'];
    $stmt      = $conn->prepare("UPDATE medicines SET status = 0 WHERE medicine_id = ?");
    $stmt->bind_param("i", $delete_id);

    echo json_encode(['status' => $stmt->execute() ? 'success' : 'error']);
    exit();
}


/* ============================================================
 * DATA FETCHING
 *
 * Fetch all active medicines (status = 1) with days_left
 * calculated in SQL for easy expiry badge logic in PHP.
 * ============================================================ */
$meds = $conn->query("
    SELECT *, DATEDIFF(expiration_date, CURDATE()) AS days_left
    FROM medicines
    WHERE status = 1
    ORDER BY medicine_id DESC
");

// Safety check: This will tell you if a column name is wrong
if (!$meds) {
    die("Database Error: " . $conn->error);
}

/* ── Alert Counts (shown as badges in the top bar) ── */
$outOfStock    = $conn->query("SELECT COUNT(*) AS c FROM medicines WHERE quantity <= 0  AND status = 1")->fetch_assoc()['c'];
$lowStock      = $conn->query("SELECT COUNT(*) AS c FROM medicines WHERE quantity > 0 AND quantity <= 10 AND status = 1")->fetch_assoc()['c'];
$expiringCount = $conn->query("SELECT COUNT(*) AS c FROM medicines WHERE expiration_date > CURDATE() AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status = 1")->fetch_assoc()['c'];
$expiredCount  = $conn->query("SELECT COUNT(*) AS c FROM medicines WHERE expiration_date < CURDATE() AND status = 1")->fetch_assoc()['c'];
?>

<!--------------- EXTERNAL DEPENDENCIES --------------->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

<style>
/* ============================================================
 * STYLES — Medicine Inventory Module
 *
 * Modal design intentionally mirrors appointments.php:
 *   - Fixed full-screen overlay with blur backdrop
 *   - Centered card using transform: translate(-50%, -50%)
 *   - Toggled via .modal-show utility class
 * ============================================================ */

/* --- Main Page Card (glassmorphism) --- */
.record-card {
    background:      rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
    padding:         25px;
    border-radius:   20px;
    color:           white;
    box-shadow:      0 10px 25px rgba(0, 0, 0, 0.3);
}

/* --- Top Bar: Title + Badges + Add Button --- */
.top-bar {
    display:         flex;
    justify-content: space-between;
    align-items:     center;
    margin-bottom:   25px;
    padding:         20px;
    background:      rgba(0, 166, 81, 0.1);
    border-radius:   15px;
    border:          1px solid rgba(0, 166, 81, 0.2);
    backdrop-filter: blur(5px);
    flex-wrap:       wrap;
    gap:             12px;
}

.top-bar h2 {
    margin:      0;
    color:       white;
    font-size:   24px;
    font-weight: 700;
    display:     flex;
    align-items: center;
    gap:         10px;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
}

.top-bar h2 i { color: #00a651; font-size: 28px; }

/* --- Alert Badge Pills in Top Bar --- */
.alert-badges { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

.alert-badge {
    padding:      4px 10px;
    border-radius: 20px;
    font-size:    12px;
    font-weight:  700;
    display:      inline-flex;
    align-items:  center;
    gap:          6px;
    letter-spacing: 0.03em;
}

.badge-danger  { background: linear-gradient(135deg, #ff4d4d, #c0392b); color: white; box-shadow: 0 2px 8px rgba(220,53,69,0.3); }
.badge-warning { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; box-shadow: 0 2px 8px rgba(245,158,11,0.3); }
.badge-purple  { background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: white; box-shadow: 0 2px 8px rgba(139,92,246,0.3); }
.badge-expired { background: linear-gradient(135deg, #ef4444, #991b1b); color: white; box-shadow: 0 2px 8px rgba(239,68,68,0.3); }

/* ── STATUS FILTER PILL BAR ── */
.filter-bar-wrapper {
    display:         flex;
    align-items:     center;
    gap:             14px;
    flex-wrap:       wrap;
    margin-bottom:   16px;
    padding:         13px 18px;
    background:      white;
    border:          1px solid rgba(0, 166, 81, 0.2);
    border-radius:   14px;
    backdrop-filter: blur(4px);
}

.filter-bar-label {
    font-size:      12px;
    font-weight:    700;
    color:          #00a651;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    white-space:    nowrap;
    display:        flex;
    align-items:    center;
    gap:            6px;
    flex-shrink:    0;
}

.filter-pills { display: flex; gap: 8px; flex-wrap: wrap; }

.fpill {
    display:       inline-flex;
    align-items:   center;
    gap:           7px;
    padding:       6px 14px;
    border-radius: 999px;
    /* CHANGE: Green border instead of white */
    border:        1.5px solid rgba(0, 166, 81, 0.2); 
    /* CHANGE: Very light green tint (or transparent/white) for the background */
    background:    rgba(0, 166, 81, 0.05); 
    /* CHANGE: Theme green text color */
    color:         #00a651; 
    font-size:     13px;
    font-weight:   600;
    cursor:        pointer;
    transition:    background 0.15s, color 0.15s, border-color 0.15s, transform 0.12s, box-shadow 0.15s;
    white-space:   nowrap;
}

.fpill:hover {
    transform:  translateY(-1px);
    background: rgba(0, 166, 81, 0.12);
    color:      #008540; /* Slightly darker green for contrast */
}

.fpill.active { 
    color: white; 
    border-color: transparent; 
    box-shadow: 0 3px 10px rgba(0,0,0,0.2); 
}

.fdot {
    width:        7px;
    height:       7px;
    border-radius: 50%;
    flex-shrink:  0;
    opacity:      0.75;
    transition:   opacity 0.15s;
}

/* Make dots turn white when the pill is active so they are visible */
.fpill.active .fdot {
    background: #ffffff !important;
    opacity: 1;
}

.fdot-all      { background: #00a651; }
.fdot-out      { background: #ff4d4d; }
.fdot-low      { background: #f59e0b; }
.fdot-expiring { background: #a78bfa; }
.fdot-expired  { background: #fca5a5; }
.fdot-stable   { background: #4ade80; }

/* Active states */
.fpill.active                       { color: white; border-color: transparent; box-shadow: 0 3px 10px rgba(0,0,0,0.2); }
.fpill.active[data-val="all"]       { background: #00a651; }
.fpill.active[data-val="out"]       { background: linear-gradient(135deg, #ff4d4d, #c0392b); }
.fpill.active[data-val="low"]       { background: linear-gradient(135deg, #f59e0b, #d97706); }
.fpill.active[data-val="expiring"]  { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
.fpill.active[data-val="expired"]   { background: linear-gradient(135deg, #ef4444, #991b1b); }
.fpill.active[data-val="stable"]    { background: linear-gradient(135deg, #22c55e, #15803d); }



/* ============================================================
 * MODAL — Exact same pattern as appointments.php
 *
 * Structure:
 *   <div id="medOverlay">     ← full-screen blur backdrop
 *   <div id="medicineFormContainer"> ← centered white card
 *
 * Both start hidden; toggled via .modal-show class.
 * ============================================================ */

/* --- Full-Screen Dark Blur Overlay --- */
#medOverlay {
    display:         none;           /* Hidden by default */
    position:        fixed;
    inset:           0;              /* Covers entire viewport */
    background:      rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(5px);      /* Glass blur effect */
    z-index:         1050;           /* Below the modal card */
}

/* --- Centered Form Modal Card --- */
#medicineFormContainer {
    display:       none;             /* Hidden by default */
    position:      fixed;
    top:           50%;
    left:          50%;
    transform:     translate(-50%, -50%);   /* Perfect centering */

    width:         90%;
    max-width:     620px;
    max-height:    90vh;
    overflow-y:    auto;             /* Scroll if content is tall */

    background:    white;
    padding:       30px;
    border-radius: 24px;
    z-index:       1100;             /* Above the overlay */
    box-shadow:    0 20px 60px rgba(0, 0, 0, 0.5);
    animation:     slideDown 0.3s ease;
}

/* Entrance animation — slides in from slightly above */
@keyframes slideDown {
    from { opacity: 0; transform: translate(-50%, calc(-50% - 24px)); }
    to   { opacity: 1; transform: translate(-50%, -50%); }
}

/* Utility class to show the overlay and modal */
.modal-show { display: block !important; }

/* --- Modal Title --- */
#medicineFormContainer h3 {
    margin:         0 0 24px;
    color:          #004d26;
    font-size:      22px;
    font-weight:    800;
    text-align:     center;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

/* --- 2-Column Form Grid --- */
.form-grid {
    display:               grid;
    grid-template-columns: 1fr 1fr;
    gap:                   16px;
}

.form-group            { display: flex; flex-direction: column; }
.form-group.full-width { grid-column: span 2; }

/* --- Form Labels --- */
.form-group label {
    font-size:     13px;
    font-weight:   700;
    margin-bottom: 8px;
    color:         #084c24;
    display:       flex;
    align-items:   center;
    gap:           8px;
}

.form-group label i { color: #00a651; font-size: 14px; }

/* --- Inputs/Selects (scoped to modal only) --- */
#medicineFormContainer input,
#medicineFormContainer select,
#medicineFormContainer textarea {
    width:         100%;
    padding:       10px 12px;
    border:        1px solid #d6e8d7;
    border-radius: 10px;
    background:    #f7fcf7;
    color:         #1f3822;
    outline:       none;
    font-size:     13px;
    box-sizing:    border-box;
    transition:    border-color 0.25s, box-shadow 0.25s;
}

#medicineFormContainer input:focus,
#medicineFormContainer select:focus {
    border-color: #00a651;
    box-shadow:   0 0 0 4px rgba(0, 166, 81, 0.1);
    transform:    none; /* Override global transform on focus */
}

/* Red highlight when quantity is invalid */
#medicineFormContainer input.qty-error {
    border-color: #dc3545 !important;
    box-shadow:   0 0 0 4px rgba(220, 53, 69, 0.12) !important;
}

/* Inline hint below quantity field */
.qty-hint {
    font-size:   11px;
    color:       #dc3545;
    margin-top:  4px;
    display:     none;
    font-weight: 600;
}

/* Unit dropdown optgroup styling */
#medicineFormContainer select optgroup { font-weight: 700; color: #004d26; background: #f0faf4; }
#medicineFormContainer select option   { font-weight: 400; color: #1f3822; }

/* --- Reusable Button Styles --- */
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

.btn-green { background: linear-gradient(135deg, #2f9e44, #20c997); box-shadow: 0 6px 16px rgba(32, 201, 151, 0.22); }
.btn-gray  { background: linear-gradient(135deg, #6c757d, #495057); box-shadow: 0 6px 16px rgba(73, 80, 87, 0.22); }

/* --- Form Action Buttons Row --- */
.form-actions {
    margin-top:      24px;
    display:         flex;
    gap:             12px;
    justify-content: center;
    padding-top:     18px;
    border-top:      1px solid #eee;
    flex-wrap:       wrap;
}

.form-actions .btn {
    min-width:       155px;
    justify-content: center;
    padding:         13px 26px;
    font-size:       15px;
}


/* --- Search Bar --- */
.search-wrapper { margin-bottom: 16px; }

.search-box {
    display:       flex;
    align-items:   center;
    background:    white;
    border-radius: 12px;
    border:        1px solid #d6e8d7;
    padding:       10px 16px;
    gap:           10px;
    max-width:     360px;
    box-shadow:    0 2px 10px rgba(0, 0, 0, 0.06);
    transition:    border-color 0.2s;
}

.search-box:focus-within          { border-color: #00a651; }
.search-box i.search-icon         { color: #00a651; font-size: 15px; flex-shrink: 0; }

/* Override global input styles inside the search box */
.search-box input {
    border:     none;
    outline:    none;
    background: transparent;
    font-size:  14px;
    color:      #1f3822;
    width:      100%;
    padding:    0;
    transform:  none;
    box-shadow: none;
}

.search-box input:focus           { box-shadow: none; transform: none; border: none; }
.search-box input::placeholder    { color: #aaa; }

/* Clear (×) button — shown via JS when input has text */
.search-box .clear-btn {
    color:       #aaa;
    font-size:   14px;
    cursor:      pointer;
    display:     none;
    flex-shrink: 0;
    transition:  color 0.2s;
}

.search-box .clear-btn:hover { color: #dc3545; }

/* "No results" message below search box */
.no-results {
    display:       none;
    margin-top:    12px;
    padding:       12px 16px;
    background:    rgba(255, 255, 255, 0.07);
    border:        1px dashed rgba(255, 255, 255, 0.18);
    border-radius: 12px;
    max-width:     360px;
    align-items:   center;
    gap:           10px;
}

.no-results i      { color: rgba(255, 255, 255, 0.4); font-size: 15px; flex-shrink: 0; }
.no-results span   { font-size: 13px; color: rgba(255, 255, 255, 0.6); font-weight: 500; }
.no-results strong { color: rgba(255, 255, 255, 0.85); font-weight: 700; }


/* --- Table Controls (row count + rows-per-page) --- */
.table-controls {
    display:         flex;
    justify-content: space-between;
    align-items:     center;
    margin-bottom:   12px;
    flex-wrap:       wrap;
    gap:             10px;
}

.row-count        { font-size: 13px; color: rgba(255, 255, 255, 0.7); font-weight: 500; }
.row-count strong { color: white; font-weight: 700; }

.rows-per-page { display: flex; align-items: center; gap: 8px; font-size: 13px; color: rgba(255, 255, 255, 0.7); }

/* Override global select inside rows-per-page */
.rows-per-page select {
    width:         auto;
    padding:       6px 10px;
    border-radius: 8px;
    font-size:     13px;
    border:        1px solid rgba(255, 255, 255, 0.3);
    background:    white;
    color:         #1f3822;
    cursor:        pointer;
    transform:     none;
    box-shadow:    none;
}


/* --- Scrollable Table Wrapper --- */
.table-responsive {
    width:         100%;
    overflow-x:    auto;
    border-radius: 15px;
    box-shadow:    0 8px 25px rgba(0, 0, 0, 0.1);
}

/* --- Data Table --- */
table {
    width:            100%;
    border-collapse:  separate;
    border-spacing:   0;
    background:       white;
    color:            #333;
    border-radius:    15px;
    overflow:         hidden;
    box-shadow:       0 4px 15px rgba(0, 0, 0, 0.05);
}

th {
    background:  linear-gradient(135deg, #004d26, #006633);
    color:       white;
    padding:     18px 15px;
    font-size:   14px;
    font-weight: 600;
    text-align:  left;
    position:    sticky;
    top:         0;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
}

td {
    padding:        14px 15px;
    border-bottom:  1px solid #f0f0f0;
    font-size:      13px;
    vertical-align: middle;
    word-wrap:      break-word;
}

tbody tr:nth-child(even)  { background: #fafafa; }
tbody tr:hover            { background: #e8f5e8; transition: background 0.2s; }

th.sortable       { cursor: pointer; user-select: none; white-space: nowrap; }
th.sortable:hover { background: linear-gradient(135deg, #006633, #008844); }

th.sortable .sort-icon                { margin-left: 6px; font-size: 11px; opacity: 0.5; }
th.sortable.asc .sort-icon,
th.sortable.desc .sort-icon           { opacity: 1; color: #20c997; }


/* --- Stock/Expiry Status Badges inside table --- */
.status-badge {
    padding:        4px 10px;
    border-radius:  20px;
    font-size:      12px;
    font-weight:    700;
    display:        inline-block;
    margin-right:   4px;
    margin-bottom:  2px;
    letter-spacing: 0.03em;
}

.status-out      { background: linear-gradient(135deg, #ff4d4d, #c0392b); color: white; box-shadow: 0 2px 8px rgba(220,53,69,0.3); }
.status-low      { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; box-shadow: 0 2px 8px rgba(245,158,11,0.3); }
.status-expiring { background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: white; box-shadow: 0 2px 8px rgba(139,92,246,0.3); }
.status-expired  { background: linear-gradient(135deg, #ef4444, #991b1b); color: white; box-shadow: 0 2px 8px rgba(239,68,68,0.3); }
.status-stable   { background: linear-gradient(135deg, #22c55e, #15803d); color: white; box-shadow: 0 2px 8px rgba(34,197,94,0.3); }


/* --- Row Action Buttons (Edit / Delete) --- */
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

.edit-btn   { background: linear-gradient(135deg, #007bff, #0056b3); box-shadow: 0 2px 8px rgba(0, 123, 255, 0.3); }
.delete-btn { background: linear-gradient(135deg, #dc3545, #c82333); box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3); }

.edit-btn:hover   { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0, 123, 255, 0.4); }
.delete-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(220, 53, 69, 0.4); }


/* --- Pagination Controls --- */
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

.page-btn:hover    { background: rgba(0, 166, 81, 0.25); border-color: #00a651; color: white; }
.page-btn.active   { background: linear-gradient(135deg, #2f9e44, #20c997); border-color: transparent; color: white; box-shadow: 0 4px 12px rgba(32, 201, 151, 0.3); }
.page-btn:disabled { opacity: 0.35; cursor: not-allowed; pointer-events: none; }


/* --- Delete Confirmation Modal --- */
#deleteModal {
    position:        fixed;
    inset:           0;
    background:      rgba(0, 0, 0, 0.75);
    display:         none;
    align-items:     center;
    justify-content: center;
    z-index:         1200;           /* Above both overlay and form modal */
    backdrop-filter: blur(4px);
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
    animation:     modalPop 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes modalPop {
    from { opacity: 0; transform: scale(0.85); }
    to   { opacity: 1; transform: scale(1); }
}

/* Red accent line on top of delete modal */
.modal-accent-bar {
    position:      absolute;
    top: 0; left: 0; right: 0;
    height:        5px;
    background:    linear-gradient(90deg, #dc3545, #ff6b6b);
    border-radius: 24px 24px 0 0;
}

.modal-icon {
    width:           72px;
    height:          72px;
    background:      linear-gradient(135deg, #ffe0e3, #ffc2c7);
    border-radius:   50%;
    display:         flex;
    align-items:     center;
    justify-content: center;
    margin:          0 auto 20px;
    box-shadow:      0 6px 20px rgba(220, 53, 69, 0.2);
}

.modal-icon i       { font-size: 28px; color: #dc3545; }
.modal-title        { margin: 0 0 8px; font-size: 22px; font-weight: 800; color: #c0392b; }
.modal-subtitle     { margin: 0 0 6px; font-size: 14px; color: #666; line-height: 1.5; }
.modal-item-name    { margin: 0 0 20px; font-size: 16px; font-weight: 700; color: #1a1a2e; }

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
    background:      linear-gradient(135deg, #dc3545, #c0392b);
    color:           white;
    font-size:       14px;
    font-weight:     700;
    cursor:          pointer;
    display:         flex;
    align-items:     center;
    justify-content: center;
    gap:             8px;
    box-shadow:      0 6px 18px rgba(220, 53, 69, 0.35);
    transition:      transform 0.2s, box-shadow 0.2s;
}

.modal-btn-delete:hover { transform: translateY(-2px); box-shadow: 0 10px 22px rgba(220, 53, 69, 0.4); }

/* Toast animations */
@keyframes toastIn  { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes toastOut { from { opacity: 1; transform: translateY(0); }   to { opacity: 0; transform: translateY(20px); } }

@media (max-width: 640px) {
    #medicineFormContainer {
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

    #medicineFormContainer h3 {
        font-size: 18px;
        line-height: 1.25;
        margin-bottom: 16px;
        letter-spacing: 0.05em;
    }

    #medicineFormContainer .form-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    #medicineFormContainer .form-group.full-width {
        grid-column: span 1;
    }

    #medicineFormContainer input,
    #medicineFormContainer select,
    #medicineFormContainer textarea {
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
}
</style>


<!-- ============================================================
     1. DARK BLUR OVERLAY (outside .record-card, same as appointments)
     Clicking it closes the form modal.
     ============================================================ -->
<div id="medOverlay" onclick="closeForm()"></div>


<!-- ============================================================
     2. ADD / EDIT MEDICINE MODAL (outside .record-card)
     Centered card, fixed position, z-index above overlay.
     ============================================================ -->
<div id="medicineFormContainer">
    <h3 id="formTitle">Add Medicine</h3>

    <form id="medicineForm">
        <!-- Hidden inputs for action routing -->
        <input type="hidden" name="action"      id="formAction"     value="add">
        <input type="hidden" name="medicine_id" id="formMedicineId" value="">

        <div class="form-grid">

            <!-- Medicine Name -->
            <div class="form-group">
                <label><i class="fa-solid fa-pills"></i> Medicine Name</label>
                <input type="text" name="medicine_name" id="formName"
                       placeholder="e.g. Paracetamol" required>
            </div>

            <!-- Category -->
            <div class="form-group">
                <label><i class="fa-solid fa-tag"></i> Category</label>
                <input type="text" name="category" id="formCategory"
                       placeholder="e.g. Antibiotic">
            </div>

            <!-- Quantity -->
            <div class="form-group">
                <label><i class="fa-solid fa-cubes"></i> Quantity</label>
                <input type="number" name="quantity" id="formQty"
                       placeholder="Enter quantity" min="1"
                       required oninput="validateQty(this)">
                <span class="qty-hint" id="qtyHint">Quantity must be at least 1.</span>
            </div>

            <!-- Unit -->
            <div class="form-group">
                <label><i class="fa-solid fa-scale-balanced"></i> Unit</label>
                <select name="unit" id="formUnit" required>
                    <option value="" disabled selected>— Select a unit —</option>
                    <optgroup label="Solid Forms">
                        <option value="tablet">Tablet (tab)</option>
                        <option value="capsule">Capsule (cap)</option>
                        <option value="caplet">Caplet</option>
                        <option value="piece">Piece (pcs)</option>
                    </optgroup>
                    <optgroup label="Packaging">
                        <option value="box">Box</option>
                        <option value="strip">Strip</option>
                        <option value="pack">Pack</option>
                    </optgroup>
                    <optgroup label="Liquids">
                        <option value="bottle">Bottle</option>
                        <option value="vial">Vial</option>
                        <option value="ml">Milliliter (mL)</option>
                    </optgroup>
                </select>
            </div>

            <!-- Expiration Date (full-width) -->
            <div class="form-group full-width">
                <label><i class="fa-solid fa-calendar-xmark"></i> Expiration Date</label>
                <input type="date" name="expiration_date" id="formExpiry"
                       min="<?= $today ?>" required>
            </div>

        </div><!-- /.form-grid -->

        <!-- Action Buttons -->
        <div class="form-actions">
            <button type="submit" class="btn btn-green" id="submitBtn">
                <i class="fa-solid fa-save"></i> Save Medicine
            </button>
            <button type="button" class="btn btn-gray" onclick="closeForm()">
                <i class="fa-solid fa-times"></i> Cancel
            </button>
        </div>

    </form>
</div><!-- /#medicineFormContainer -->


<!-- ============================================================
     3. DELETE CONFIRMATION MODAL (outside .record-card)
     ============================================================ -->
<div id="deleteModal">
    <div class="modal-box">
        <div class="modal-accent-bar"></div>
        <div class="modal-icon"><i class="fa-solid fa-trash"></i></div>
        <h3 class="modal-title">Remove Medicine?</h3>
        <p class="modal-subtitle">You are about to archive</p>
        <p class="modal-item-name">"<span id="delName"></span>"</p>
        <div class="modal-warning">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>This item will be hidden from inventory but kept in records. You can restore it from the Recycle Bin.</span>
        </div>
        <div class="modal-buttons">
            <button class="modal-btn-cancel" onclick="closeDeleteModal()">
                <i class="fa-solid fa-xmark"></i> Cancel
            </button>
            <button class="modal-btn-delete" onclick="executeDelete()">
                <i class="fa-solid fa-trash"></i> Yes, Archive
            </button>
        </div>
    </div>
</div>


<!-- ============================================================
     4. MAIN PAGE CARD (table only — modals are placed above)
     ============================================================ -->
<div class="record-card">

    <!-- ── Top Bar ───────────────────────────────────────── -->
    <div class="top-bar">
        <h2><i class="fa-solid fa-pills"></i> Medicine Inventory</h2>

        <!-- Alert Badges: only shown when count > 0 -->
        <div class="alert-badges">
            <?php if ($outOfStock > 0): ?>
                <span class="alert-badge badge-danger">
                    <i class="fa-solid fa-ban"></i> <?= $outOfStock ?> Out of Stock
                </span>
            <?php endif; ?>
            <?php if ($lowStock > 0): ?>
                <span class="alert-badge badge-warning">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?= $lowStock ?> Low Stock
                </span>
            <?php endif; ?>
            <?php if ($expiringCount > 0): ?>
                <span class="alert-badge badge-purple">
                    <i class="fa-solid fa-hourglass-half"></i> <?= $expiringCount ?> Expiring Soon
                </span>
            <?php endif; ?>
            <?php if ($expiredCount > 0): ?>
                <span class="alert-badge badge-expired">
                    <i class="fa-solid fa-calendar-xmark"></i> <?= $expiredCount ?> Expired
                </span>
            <?php endif; ?>
        </div>

        <button class="btn btn-green" onclick="openAddForm()">
            <i class="fa-solid fa-plus"></i> Add Medicine
        </button>
    </div>

    <!-- ── Search Bar ──────────────────────────────────── -->
    <div class="search-wrapper">
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <input type="text" id="medicineSearch"
                   placeholder="Search by name or category..."
                   oninput="searchMedicines()">
            <i class="fa-solid fa-xmark clear-btn" id="clearSearch" onclick="clearSearch()"></i>
        </div>
        <div class="no-results" id="noResults">
            <i class="fa-solid fa-pills"></i>
            <span>No results for <strong id="noResultsQuery"></strong> — try a different name or category.</span>
        </div>
    </div>

    <!-- Status Filter Pill Bar -->
    <div class="filter-bar-wrapper">
        <span class="filter-bar-label">
            <i class="fa-solid fa-filter"></i> Filter by status
        </span>
        <div class="filter-pills" id="filterPills">
            <button class="fpill active" data-val="all"      onclick="setPillFilter(this)"><span class="fdot fdot-all"></span>      All</button>
            <button class="fpill"        data-val="out"      onclick="setPillFilter(this)"><span class="fdot fdot-out"></span>      Out of stock</button>
            <button class="fpill"        data-val="low"      onclick="setPillFilter(this)"><span class="fdot fdot-low"></span>      Low stock</button>
            <button class="fpill"        data-val="expiring" onclick="setPillFilter(this)"><span class="fdot fdot-expiring"></span> Expiring soon</button>
            <button class="fpill"        data-val="expired"  onclick="setPillFilter(this)"><span class="fdot fdot-expired"></span>  Expired</button>
            <button class="fpill"        data-val="stable"   onclick="setPillFilter(this)"><span class="fdot fdot-stable"></span>   Stable</button>
        </div>
    </div>

    <!-- ── Table Controls ──────────────────────────────── -->
    <div class="table-controls">
    <div class="row-count" id="rowCount">
        <?php 
            // Get the total count from your query result
            $total = $meds->num_rows; 
            
            if ($total > 0) {
                // If there are records, show "Showing 1–4 of 4"
                // Note: We use 1 as start because it's the first page
                echo "Showing <strong>1–" . $total . "</strong> of <strong>" . $total . "</strong> records";
            } else {
                echo "Showing <strong>0</strong> of <strong>0</strong> records";
            }
        ?>
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

    <!-- ── Medicine Table ──────────────────────────────── -->
<div class="table-responsive">
    <table id="medicineTable">
        <thead>
            <tr>
                <th class="sortable" onclick="sortTable(0)">Medicine Name <span class="sort-icon fa-solid fa-sort"></span></th>
                <th class="sortable" onclick="sortTable(1)">Category <span class="sort-icon fa-solid fa-sort"></span></th>
                <th class="sortable" onclick="sortTable(2)">Stock <span class="sort-icon fa-solid fa-sort"></span></th>
                <th class="sortable" onclick="sortTable(3)">Expiration <span class="sort-icon fa-solid fa-sort"></span></th>
                <th>Status</th>
                <th style="text-align:center;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($meds->num_rows > 0): ?>
                <?php while ($row = $meds->fetch_assoc()): 
                    // Calculate Badges
                    $badges = '';
                    
                    // Stock Logic
                    if ($row['quantity'] <= 0) {
                        $badges .= "<span class='status-badge status-out'>Out of Stock</span>";
                    } elseif ($row['quantity'] <= 10) {
                        $badges .= "<span class='status-badge status-low'>Low Stock</span>";
                    }

                    // Expiry Logic (Uses the 'days_left' from SQL)
                    if ($row['days_left'] < 0) {
                        $badges .= "<span class='status-badge status-expired'>Expired</span>";
                    } elseif ($row['days_left'] <= 30) {
                        $badges .= "<span class='status-badge status-expiring'>Expiring Soon</span>";
                    }

                    if ($badges === '') {
                        $badges = "<span class='status-badge status-stable'>Stable</span>";
                    }
                ?>
                <tr data-id="<?= $row['medicine_id'] ?>">
                    <td><strong><?= htmlspecialchars($row['medicine_name']) ?></strong></td>
                    <td><?= htmlspecialchars($row['category']) ?></td>
                    <td>
                        <strong style="font-size:15px;"><?= $row['quantity'] ?></strong>
                        <small style="color:#777; margin-left:4px;"><?= htmlspecialchars($row['unit']) ?></small>
                    </td>
                    <td><?= date("M d, Y", strtotime($row['expiration_date'])) ?></td>
                    <td><?= $badges ?></td>
                    <td>
                        <div class="action-btns">
                            <button type="button" class="edit-btn" 
                                onclick='openEditForm(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, "UTF-8") ?>)'>
                                <i class="fa-solid fa-edit"></i>
                            </button>

                            <button type="button" class="delete-btn" 
                                onclick="confirmDelete(<?= $row['medicine_id'] ?>, '<?= addslashes($row['medicine_name']) ?>')">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" style="text-align:center; padding: 20px;">No medicines found in the system.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

    <!-- Pagination rendered by JS -->
    <div class="pagination-wrapper" id="paginationWrapper"></div>

</div><!-- /.record-card -->


<script>
/* ============================================================
 * JavaScript — Medicine Inventory Module
 *
 * Sections:
 *   1.  Global State
 *   2.  Toast Notification
 *   3.  Quantity Validation
 *   4.  Modal Open/Close (Add & Edit)
 *   5.  Form Submit (AJAX)
 *   6.  Delete Modal
 *   7.  Search
 *   8.  Pagination
 *   9.  Column Sorting
 *  10.  Init
 * ============================================================ */


/* ── 1. GLOBAL STATE ──────────────────────────────────────── */
var currentDeleteId = null;  // patient_id pending deletion
var isDeleting      = false; // Prevents double-submit on delete
var currentPage     = 1;
var rowsPerPage     = 10;
var sortColIndex    = -1;
var sortDirection   = 'asc';
var activeStatusFilter = 'all';   // ← add this


/* ── 2. TOAST NOTIFICATION ────────────────────────────────── */

/**
 * showToast(message, type)
 * Temporary slide-up notification at the bottom-right corner.
 * @param {string} message
 * @param {string} type  'success' | 'error'
 */
function showToast(message, type) {
    var existing = document.getElementById('toastNotif');
    if (existing) existing.remove();

    var toast     = document.createElement('div');
    toast.id      = 'toastNotif';
    var isSuccess = type !== 'error';

    toast.style.cssText = [
        'position:fixed', 'bottom:30px', 'right:30px',
        'background:' + (isSuccess
            ? 'linear-gradient(135deg,#2f9e44,#20c997)'
            : 'linear-gradient(135deg,#dc3545,#c82333)'),
        'color:white', 'padding:16px 24px', 'border-radius:14px',
        'font-size:15px', 'font-weight:700', 'display:flex',
        'align-items:center', 'gap:10px',
        'box-shadow:0 8px 25px rgba(0,0,0,0.25)',
        'z-index:9999', 'animation:toastIn 0.4s ease', 'max-width:320px'
    ].join(';');

    toast.innerHTML =
        '<span style="font-size:20px">' + (isSuccess ? '✅' : '❌') + '</span>' +
        '<span>' + message + '</span>';

    document.body.appendChild(toast);

    setTimeout(function () {
        toast.style.animation = 'toastOut 0.4s ease forwards';
        setTimeout(function () { toast.remove(); }, 400);
    }, 3000);
}


/* ── 3. QUANTITY VALIDATION ───────────────────────────────── */

/**
 * validateQty(input)
 * Shows/hides the inline error hint when quantity is invalid.
 * Also toggles the red border class.
 */
function validateQty(input) {
    var hint = document.getElementById('qtyHint');
    if (!input.value || parseInt(input.value) <= 0) {
        input.classList.add('qty-error');
        hint.style.display = 'block';
    } else {
        input.classList.remove('qty-error');
        hint.style.display = 'none';
    }
}


/* ── 4. MODAL OPEN / CLOSE ────────────────────────────────── */

/**
 * showModal() — Shared helper: shows the overlay + form card.
 * Both elements use .modal-show which sets display:block.
 */
function showModal() {
    document.getElementById('medicineFormContainer').classList.add('modal-show');
    document.getElementById('medOverlay').classList.add('modal-show');
}

/** closeForm() — Hides the overlay and form card. */
function closeForm() {
    document.getElementById('medicineFormContainer').classList.remove('modal-show');
    document.getElementById('medOverlay').classList.remove('modal-show');
}

/**
 * openAddForm()
 * Resets the form for a new entry, then shows the modal.
 */
function openAddForm() {
    document.getElementById('medicineForm').reset();
    document.getElementById('formAction').value    = 'add';
    document.getElementById('formMedicineId').value = '';
    document.getElementById('formTitle').innerText  = 'Add Medicine';
    document.getElementById('submitBtn').innerHTML  =
        '<i class="fa-solid fa-save"></i> Save Medicine';
    showModal();
}

/**
 * openEditForm(data)
 * Populates the form with existing medicine data, then shows the modal.
 * @param {Object} data  Medicine row data from PHP json_encode()
 */
function openEditForm(data) {
    document.getElementById('formAction').value     = 'update';
    document.getElementById('formMedicineId').value = data.medicine_id;
    document.getElementById('formName').value       = data.medicine_name;
    document.getElementById('formCategory').value   = data.category;
    document.getElementById('formQty').value        = data.quantity;
    document.getElementById('formUnit').value       = data.unit;
    document.getElementById('formExpiry').value     = data.expiration_date;
    document.getElementById('formTitle').innerText  = 'Edit Medicine';
    document.getElementById('submitBtn').innerHTML  =
        '<i class="fa-solid fa-save"></i> Update Medicine';
    showModal();
}


/* ── 5. FORM SUBMIT (AJAX) ────────────────────────────────── */
document.getElementById('medicineForm').addEventListener('submit', function (e) {
    e.preventDefault();

    /* Validate quantity before sending */
    var qtyInput = document.getElementById('formQty');
    if (!qtyInput.value || parseInt(qtyInput.value) <= 0) {
        validateQty(qtyInput);
        showToast('Quantity must be greater than 0.', 'error');
        return;
    }

    var isUpdate = document.getElementById('formAction').value === 'update';
    var formData = new FormData(this);

    fetch('medicines.php', { method: 'POST', body: formData })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.status === 'success') {
                closeForm();
                showToast(isUpdate ? 'Medicine updated successfully!' : 'Medicine added successfully!', 'success');
                setTimeout(function () { loadPage('medicines.php'); }, 1200);
            } else {
                showToast(data.message || 'Error saving medicine.', 'error');
            }
        })
        .catch(function (err) {
            console.error('Fetch error:', err);
            showToast('An unexpected error occurred.', 'error');
        });
});


/* ── 6. DELETE MODAL ──────────────────────────────────────── */

/**
 * confirmDelete(id, name)
 * Opens the delete confirmation popup with the medicine's name.
 */
function confirmDelete(id, name) {
    currentDeleteId = id;
    document.getElementById('delName').innerText         = name;
    document.getElementById('deleteModal').style.display = 'flex';
}

/** closeDeleteModal() — Hides the delete confirmation modal. */
function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
    currentDeleteId = null;
}

/**
 * executeDelete()
 * Sends a soft-delete GET request to medicines.php.
 * On success, hides the row and refreshes pagination.
 */
function executeDelete() {
    if (!currentDeleteId || isDeleting) return;

    isDeleting = true;

    fetch('medicines.php?delete_id=' + currentDeleteId)
        .then(function (res) { return res.json(); })
        .then(function (data) {
            closeDeleteModal();

            if (data.status === 'success') {
                showToast('Medicine archived successfully!', 'success');

                /* Reload the page so changes reflect immediately */
                setTimeout(function () {
                    loadPage('medicines.php');
                }, 500);
            } else {
                showToast(data.message || 'Failed to archive medicine.', 'error');
            }

            isDeleting = false;
        })
        .catch(function (err) {
            console.error('Delete error:', err);
            showToast('A network error occurred.', 'error');
            isDeleting = false;
        });
}


/* ── 7. SEARCH ────────────────────────────────────────────── */

/**
 * searchMedicines()
 * Filters rows by matching against medicine name (col 0) and
 * category (col 1). Non-matching rows are marked data-hidden="true"
 * so pagination excludes them.
 */
function searchMedicines() {
    var input    = document.getElementById('medicineSearch');
    var filter   = input.value.toLowerCase().trim();
    var clearBtn = document.getElementById('clearSearch');
    var noResults = document.getElementById('noResults');

    clearBtn.style.display = filter.length > 0 ? 'inline' : 'none';

    document.querySelectorAll('#medicineTable tbody tr').forEach(function (row) {
        var name     = row.cells[0] ? row.cells[0].innerText.toLowerCase() : '';
        var category = row.cells[1] ? row.cells[1].innerText.toLowerCase() : '';
        var matches  = filter === '' || name.includes(filter) || category.includes(filter);

        row.dataset.hidden = matches ? 'false' : 'true';
        if (!matches) row.style.display = 'none';
    });

    /* Show/hide "no results" message */
    var matchCount = getVisibleRows().length;
    if (matchCount === 0 && filter.length > 0) {
        document.getElementById('noResultsQuery').innerText = '"' + input.value.trim() + '"';
        noResults.style.display = 'flex';
    } else {
        noResults.style.display = 'none';
    }

    currentPage = 1;
    applyPagination();
}

/** clearSearch() — Resets the search input. */
function clearSearch() {
    document.getElementById('medicineSearch').value = '';
    searchMedicines();
}


/* ── 8. PAGINATION ────────────────────────────────────────── */

/* ── getVisibleRows() — respects BOTH search AND status filter ── */
function getVisibleRows() {
    return Array.from(document.querySelectorAll('#medicineTable tbody tr'))
        .filter(function (row) {
            return row.dataset.hidden !== 'true' && row.dataset.statusHidden !== 'true';
        });
}
/**
 * applyPagination()
 * Hides all rows, then shows only the current page slice
 * of search-visible rows. Updates the row-count label.
 */
function applyPagination() {
    var rows       = getVisibleRows(); // All rows that match search
    var total       = rows.length;
    var totalPages = Math.ceil(total / rowsPerPage) || 1;

    if (currentPage > totalPages) currentPage = totalPages;
    if (currentPage < 1)          currentPage = 1;

    var start = (currentPage - 1) * rowsPerPage;
    var end   = start + rowsPerPage;

    /* 1. Hide every row in the table first */
    document.querySelectorAll('#medicineTable tbody tr').forEach(function (row) {
        row.style.display = 'none';
    });

    /* 2. Show only the current page slice */
    rows.forEach(function (row, index) {
        if (index >= start && index < end) {
            row.style.display = '';
        }
    });

    /* 3. Update the "Showing X of X" counter */
    var countEl = document.getElementById('rowCount');
    if (countEl) {
        if (total > 0) {
            // Calculate real range: e.g., 11-20
            var displayStart = start + 1;
            var displayEnd = Math.min(end, total);
            countEl.innerHTML = `Showing <strong>${displayStart}–${displayEnd}</strong> of <strong>${total}</strong> records`;
        } else {
            countEl.innerHTML = `Showing <strong>0</strong> of <strong>0</strong> records`;
        }
    }

    /* 4. Handle "No Results" message visibility */
    var noResultsBox = document.getElementById('noResultsMessage'); // Assuming you have this ID for the "No results for..." box
    if (noResultsBox) {
        noResultsBox.style.display = (total === 0) ? 'block' : 'none';
    }

    renderPagination(totalPages);
}

/** renderPagination(totalPages) — Builds prev/page-numbers/next buttons. */
function renderPagination(totalPages) {
    var wrapper = document.getElementById('paginationWrapper');
    if (!wrapper) return;
    wrapper.innerHTML = '';

    function makeBtn(html, page, disabled, isActive) {
        var btn       = document.createElement('button');
        btn.className = 'page-btn' + (isActive ? ' active' : '');
        btn.innerHTML = html;
        btn.disabled  = disabled;
        if (!disabled) btn.onclick = function () { currentPage = page; applyPagination(); };
        wrapper.appendChild(btn);
    }

    makeBtn('<i class="fa-solid fa-chevron-left"></i>', currentPage - 1, currentPage === 1, false);

    var startPage = Math.max(1, currentPage - 2);
    var endPage   = Math.min(totalPages, startPage + 4);
    if (endPage - startPage < 4) startPage = Math.max(1, endPage - 4);

    for (var i = startPage; i <= endPage; i++) {
        makeBtn(i, i, false, i === currentPage);
    }

    makeBtn('<i class="fa-solid fa-chevron-right"></i>', currentPage + 1, currentPage === totalPages, false);
}

/** changeRowsPerPage() — Called when the rows-per-page select changes. */
function changeRowsPerPage() {
    rowsPerPage = parseInt(document.getElementById('rowsPerPage').value);
    currentPage = 1;
    applyPagination();
}

/* ── 8. STATUS FILTER (Pill Buttons) ── */
function setPillFilter(el) {
    document.querySelectorAll('.fpill').forEach(function (b) {
        b.classList.remove('active');
    });
    el.classList.add('active');
    activeStatusFilter = el.dataset.val;
    filterByStatus();
}

/* ── filterByStatus() — marks rows hidden based on active pill ── */
function filterByStatus() {
    var filter = activeStatusFilter || 'all';

    document.querySelectorAll('#medicineTable tbody tr').forEach(function (row) {
        if (!row.cells[4]) return;
        var statusCell = row.cells[4].innerText.toLowerCase().trim();

        var matches = filter === 'all'
            || (filter === 'out'      && statusCell.includes('out of stock'))
            || (filter === 'low'      && statusCell.includes('low stock'))
            || (filter === 'expiring' && statusCell.includes('expiring soon'))
            || (filter === 'expired'  && statusCell.includes('expired') && !statusCell.includes('expiring'))
            || (filter === 'stable'   && statusCell.includes('stable'));

        row.dataset.statusHidden = matches ? 'false' : 'true';
    });

    currentPage = 1;
    applyPagination();
}


/* ── 9. COLUMN SORTING ────────────────────────────────────── */

/**
 * sortTable(colIndex)
 * Sorts all tbody rows by the given column.
 * Column 2 (Stock qty) sorted numerically; others alphabetically.
 */
function sortTable(colIndex) {
    var tbody = document.querySelector('#medicineTable tbody');
    var rows  = Array.from(tbody.querySelectorAll('tr'));

    /* Toggle direction if same column clicked again */
    if (sortColIndex === colIndex) {
        sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
    } else {
        sortColIndex  = colIndex;
        sortDirection = 'asc';
    }

    rows.sort(function (a, b) {
        var aText = a.cells[colIndex] ? a.cells[colIndex].innerText.trim().toLowerCase() : '';
        var bText = b.cells[colIndex] ? b.cells[colIndex].innerText.trim().toLowerCase() : '';

        /* Numeric sort for Stock column */
        if (colIndex === 2) {
            aText = parseInt(aText) || 0;
            bText = parseInt(bText) || 0;
            return sortDirection === 'asc' ? aText - bText : bText - aText;
        }

        return sortDirection === 'asc'
            ? aText.localeCompare(bText)
            : bText.localeCompare(aText);
    });

    rows.forEach(function (row) { tbody.appendChild(row); });

    /* Update sort icons on all sortable headers */
    document.querySelectorAll('th.sortable').forEach(function (th) {
        var icon = th.querySelector('.sort-icon');
        if (!icon) return;
        var col  = parseInt(th.dataset.col);

        if (col === sortColIndex) {
            icon.className = 'sort-icon fa-solid ' +
                (sortDirection === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
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


/* ── 10. INIT ─────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', applyPagination);
</script>
