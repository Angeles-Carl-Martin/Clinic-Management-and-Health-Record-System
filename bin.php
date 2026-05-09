<?php
/* ============================================================
 * bin.php — Recycle Bin Module
 *
 * Purpose  : Admin-only temporary holding area for soft-deleted
 *            records. Items here are HIDDEN from all analytics,
 *            tables, and counts across the system. Admins can
 *            either RESTORE them (back to active) or permanently
 *            DELETE them (removes the row from the database).
 *
 * How soft-delete works per table:
 *   patients      → status   = 0 (deleted) / 1 (active)
 *   medicines     → status   = 0 (deleted) / 1 (active)
 *   staff         → status   = 0 (deleted) / 1 (active)
 *   consultations → status   = 0 (deleted) / 1 (active)
 *   appointments  → status1  = 0 (deleted) / 1 (active)
 *                   (appointments uses status1, not status,
 *                    because 'status' stores Pending/Approved etc.)
 *
 * Access : Admin role only.
 * ============================================================ */

session_start();
require "db.php";
require_staff_login('admin');
redirect_direct_fragment_access();

/** @var mysqli $conn */


/* ============================================================
 * ACCESS CONTROL
 * ============================================================ */
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    echo "
    <div style='display:flex;justify-content:center;align-items:center;
                height:60vh;color:white;font-family:sans-serif;text-align:center;'>
        <div>
            <h1 style='color:#ef4444;font-size:48px;'>403</h1>
            <p style='font-size:16px;opacity:0.8;'>Access Denied. Admin privileges required.</p>
        </div>
    </div>";
    exit();
}


/* ============================================================
 * AJAX: RESTORE or PERMANENT DELETE
 *
 * POST body: { action: 'restore_patient', id: 42 }
 *
 * Restore sets the soft-delete flag back to 1 (active).
 * Delete permanently removes the row from the database.
 *
 * NOTE on appointments:
 *   The appointments table has TWO status columns:
 *     status  → workflow state (Pending / Approved / Cancelled…)
 *     status1 → soft-delete flag (1 = active, 0 = in bin)
 *   All restore/delete queries for appointments use status1.
 * ============================================================ */
if (isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');

    $action = $_POST['action'];
    $id     = (int) ($_POST['id'] ?? 0);
    $ids    = $_POST['ids'] ?? [];
    if (!is_array($ids)) {
        $ids = explode(',', (string) $ids);
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    /* ── SQL map: action → prepared statement ───────────────
     * Restore: flip the soft-delete flag back to 1 (active).
     * Delete : physically remove the row (irreversible).
     * ─────────────────────────────────────────────────────── */
    $action_map = [
        /* Restore — bring back to active */
        'restore_patient'      => "UPDATE patients      SET status  = 1 WHERE patient_id      = ?",
        'restore_medicine'     => "UPDATE medicines     SET status  = 1 WHERE medicine_id     = ?",
        'restore_staff'        => "UPDATE staff         SET status  = 1 WHERE staff_id        = ?",
        'restore_consultation' => "UPDATE consultations SET status  = 1 WHERE consultation_id = ?",
        'restore_appointment'  => "UPDATE appointments  SET status1 = 1 WHERE appointment_id  = ?",
        //                                              ↑ uses status1, not status

        /* Permanent delete — removes row entirely */
        'delete_patient'       => "DELETE FROM patients      WHERE patient_id      = ?",
        'delete_medicine'      => "DELETE FROM medicines     WHERE medicine_id     = ?",
        'delete_staff'         => "DELETE FROM staff         WHERE staff_id        = ?",
        'delete_consultation'  => "DELETE FROM consultations WHERE consultation_id = ?",
        'delete_appointment'   => "DELETE FROM appointments  WHERE appointment_id  = ?",
    ];

    try {
        if (!array_key_exists($action, $action_map)) {
            echo json_encode(['status' => 'error', 'msg' => 'Unknown action: ' . htmlspecialchars($action)]);
            exit();
        }

        if (!empty($ids)) {
            $baseSql      = $action_map[$action];
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql          = preg_replace('/= \?$/', "IN ($placeholders)", $baseSql);
            $types        = str_repeat('i', count($ids));
            $stmt         = $conn->prepare($sql);
            $stmt->bind_param($types, ...$ids);
        } else {
            $stmt = $conn->prepare($action_map[$action]);
            $stmt->bind_param("i", $id);
        }

        echo json_encode(['status' => $stmt->execute() ? 'success' : 'error']);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }

    exit();
}


/* ============================================================
 * DATA FETCHING — Bin contents (soft-deleted records only)
 *
 * Each query fetches ONLY rows where the soft-delete flag = 0.
 * These are completely hidden from the rest of the system.
 * ============================================================ */

/* Appointments: status1 = 0 means in the bin */
$del_appointments = $conn->query("
    SELECT a.*, p.full_name
    FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id
    WHERE a.status1 = '0'
    ORDER BY a.appointment_date DESC
")->fetch_all(MYSQLI_ASSOC);

/* Consultations: status = 0 means in the bin */
$del_consultations = $conn->query("
    SELECT c.*, p.full_name
    FROM consultations c
    JOIN patients p ON c.patient_id = p.patient_id
    WHERE c.status = 0
    ORDER BY c.visit_date DESC
")->fetch_all(MYSQLI_ASSOC);

/* Patients: status = 0 means in the bin */
$del_patients = $conn->query("
    SELECT * FROM patients
    WHERE status = '0'
    ORDER BY patient_id DESC
")->fetch_all(MYSQLI_ASSOC);

/* Medicines: status = 0 means in the bin */
$del_medicines = $conn->query("
    SELECT * FROM medicines
    WHERE status = '0'
    ORDER BY medicine_id DESC
")->fetch_all(MYSQLI_ASSOC);

/* Staff: status = 0 means in the bin */
$del_staff = $conn->query("
    SELECT * FROM staff
    WHERE status = '0'
    ORDER BY staff_id DESC
")->fetch_all(MYSQLI_ASSOC);


/* ============================================================
 * DATA NORMALIZATION
 *
 * Some tables have inconsistent column names across older and
 * newer schemas. We normalize them here into safe display keys
 * so renderBinTable() doesn't need to know the schema details.
 * ============================================================ */

/* Patients: find whichever contact column exists */
$patient_rows = array_map(function ($p) {
    $p['display_contact'] = $p['contact_number'] // preferred column name
                         ?? $p['contact_no']
                         ?? $p['contact']
                         ?? $p['phone']
                         ?? $p['mobile']
                         ?? 'N/A';
    return $p;
}, $del_patients);

/* Medicines: normalize name + stock to display-safe keys */
$medicine_rows = array_map(function ($m) {
    $m['display_name']  = $m['medicine_name']  ?? $m['name']     ?? 'Unknown';
    $m['display_stock'] = $m['stock_quantity'] ?? $m['stock']    ?? $m['quantity'] ?? '0';
    return $m;
}, $del_medicines);

/* Staff: combine first + last name */
$staff_rows = array_map(function ($s) {
    $s['s_name'] = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
    return $s;
}, $del_staff);
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

<style>
/* ============================================================
 * STYLES — Recycle Bin
 * ============================================================ */

.bin-wrapper   { padding: 20px; font-family: 'Inter', sans-serif; }

/* Main glass card */
.bin-container {
    background:      rgba(255, 255, 255, 0.22);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border:          1px solid rgba(255, 255, 255, 0.35);
    border-radius:   20px;
    padding:         30px;
    box-shadow:      0 8px 32px rgba(0, 0, 0, 0.12);
}

/* Header row */
.bin-header        { display: flex; align-items: center; gap: 14px; margin-bottom: 8px; }
.bin-header h2     { color: #fff; font-weight: 700; font-size: 24px; margin: 0; }
.bin-header i      { color: #10b981; font-size: 28px; }

/* Subtitle explaining bin purpose */
.bin-subtitle {
    color:         rgba(255,255,255,0.65);
    font-size:     13px;
    margin-bottom: 24px;
    padding-left:  4px;
    display:       flex;
    align-items:   center;
    gap:           8px;
}

/* Tab pills */
.tabs-wrapper {
    display:       flex;
    gap:           10px;
    margin-bottom: 22px;
    overflow-x:    auto;
    padding-bottom: 4px;
}

.tab-item {
    background:    rgba(255,255,255,0.18);
    border:        1px solid rgba(255,255,255,0.28);
    color:         #fff;
    padding:       8px 16px;
    border-radius: 20px;
    cursor:        pointer;
    font-size:     13px;
    font-weight:   500;
    transition:    all 0.25s ease;
    white-space:   nowrap;
    user-select:   none;
}

.tab-item:hover        { background: rgba(255,255,255,0.32); }
.tab-item.active       { background: #10b981; border-color: #10b981; box-shadow: 0 4px 10px rgba(16,185,129,0.3); }

/* Empty count badge — muted so it doesn't look like an error */
.tab-item .tab-count-zero { opacity: 0.5; }

/* Table card */
.glass-table-card {
    background:    #fff;
    border-radius: 12px;
    overflow:      hidden;
    box-shadow:    0 4px 15px rgba(0,0,0,0.06);
}

/* Table */
table              { width: 100%; border-collapse: collapse; background: #fff; }
thead th           { background: #096a3e; padding: 14px 18px; text-align: left; font-size: 12px; color: #fff; font-weight: 600; letter-spacing: 0.04em; }
tbody td           { padding: 14px 18px; font-size: 13px; color: #1e293b; border-bottom: 1px solid #f1f5f9; font-weight: 500; }
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover     { background: #f8fafc; }

/* Action buttons */
.action-btn {
    width:           34px;
    height:          34px;
    border-radius:   8px;
    border:          none;
    cursor:          pointer;
    margin:          0 3px;
    display:         inline-flex;
    align-items:     center;
    justify-content: center;
    color:           white;
    font-size:       14px;
    transition:      background 0.2s, transform 0.15s;
}

.action-btn:hover  { transform: translateY(-1px); }
.res-btn           { background: #007bff; box-shadow: 0 2px 6px rgba(0,123,255,0.25); }
.res-btn:hover     { background: #0056b3; }
.del-btn           { background: #dc3545; box-shadow: 0 2px 6px rgba(220,53,69,0.25); }
.del-btn:hover     { background: #b02a37; }

.bin-bulk-actions {
    display:         flex;
    justify-content: flex-end;
    align-items:     center;
    gap:             8px;
    margin-bottom:   10px;
}

.bin-bulk-btn {
    border:        none;
    border-radius: 8px;
    padding:       8px 12px;
    font-size:     12px;
    font-weight:   700;
    cursor:        pointer;
    color:         #fff;
    display:       inline-flex;
    align-items:   center;
    gap:           6px;
}

.bin-bulk-select { background: #0ea5e9; }
.bin-bulk-delete { background: #dc3545; }
.bin-select-cell { width: 42px; text-align: center; }

/* Empty state */
.bin-empty {
    text-align:  center;
    padding:     50px 20px;
    color:       #64748b;
}

.bin-empty i { font-size: 36px; margin-bottom: 12px; display: block; color: #94a3b8; }
.bin-empty p { font-size: 14px; margin: 0; }

/* Confirmation modal overlay */
.bin-modal-overlay {
    display:         none;
    position:        fixed;
    inset:           0;
    background:      rgba(0,0,0,0.55);
    backdrop-filter: blur(5px);
    z-index:         9999;
    align-items:     center;
    justify-content: center;
}

.bin-modal-box {
    background:    #fff;
    padding:       32px 28px;
    border-radius: 18px;
    width:         400px;
    max-width:     90vw;
    text-align:    center;
    color:         #1e293b;
    box-shadow:    0 20px 50px rgba(0,0,0,0.2);
    animation:     binModalPop 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes binModalPop {
    from { opacity: 0; transform: scale(0.88); }
    to   { opacity: 1; transform: scale(1); }
}

.bin-modal-icon    { font-size: 44px; margin-bottom: 16px; }
.bin-modal-title   { font-size: 20px; font-weight: 800; margin: 0 0 8px; }
.bin-modal-body    { font-size: 14px; color: #64748b; margin-bottom: 24px; line-height: 1.5; }
.bin-modal-btns    { display: flex; gap: 10px; }

.bin-cancel-btn {
    flex:          1;
    padding:       12px;
    border-radius: 10px;
    border:        1.5px solid #cbd5e1;
    background:    #fff;
    color:         #475569;
    font-size:     14px;
    font-weight:   600;
    cursor:        pointer;
    transition:    background 0.2s;
}

.bin-cancel-btn:hover { background: #f1f5f9; }

.bin-confirm-btn {
    flex:          1;
    padding:       12px;
    border-radius: 10px;
    border:        none;
    color:         white;
    font-size:     14px;
    font-weight:   700;
    cursor:        pointer;
    transition:    opacity 0.2s, transform 0.2s;
}

.bin-confirm-btn:hover { opacity: 0.9; transform: translateY(-1px); }

/* Warning banner inside delete modal */
.bin-warning {
    background:    #fff8e1;
    border:        1px solid #ffe082;
    border-radius: 10px;
    padding:       10px 14px;
    margin-bottom: 20px;
    display:       flex;
    align-items:   center;
    gap:           8px;
    text-align:    left;
}

.bin-warning i    { color: #f59e0b; font-size: 15px; flex-shrink: 0; }
.bin-warning span { font-size: 12px; color: #7a5c00; font-weight: 600; }

@media (max-width: 900px) {
    .bin-wrapper {
        padding: 0;
    }

    .bin-container {
        padding: 18px 14px;
        border-radius: 14px;
    }

    .bin-header {
        align-items: flex-start;
    }

    .bin-header h2 {
        font-size: 20px;
        line-height: 1.25;
    }

    .bin-subtitle {
        align-items: flex-start;
        line-height: 1.45;
    }

    .tabs-wrapper {
        margin-left: -2px;
        margin-right: -2px;
    }

    .tab-item {
        padding: 8px 12px;
        font-size: 12px;
    }

    .bin-bulk-actions {
        justify-content: stretch;
        flex-wrap: wrap;
    }

    .bin-bulk-btn {
        flex: 1 1 140px;
        justify-content: center;
    }

    .glass-table-card {
        overflow-x: auto;
    }

    table {
        min-width: 620px;
    }

    thead th,
    tbody td {
        padding: 11px 12px;
    }

    .bin-modal-box {
        padding: 24px 18px;
    }

    .bin-modal-btns {
        flex-direction: column;
    }
}

@media (max-width: 640px) {
    .bin-modal-overlay {
        padding: 14px;
    }

    .bin-modal-box {
        width: 100%;
        max-width: 380px;
        padding: 24px 18px 20px;
        border-radius: 18px;
    }

    .bin-modal-title {
        font-size: 19px;
    }

    .bin-cancel-btn,
    .bin-confirm-btn {
        width: 100%;
        min-height: 44px;
    }
}
</style>


<!-- ============================================================
     HTML — Recycle Bin Page
     ============================================================ -->
<div class="bin-wrapper">
    <div class="bin-container">

        <!-- Header -->
        <div class="bin-header">
            <i class="fa-solid fa-trash-can"></i>
            <h2>Recycle Bin</h2>
        </div>

        <!-- Purpose subtitle — clarifies this is a temp holding area -->
        <p class="bin-subtitle">
            <i class="fa-solid fa-circle-info"></i>
            Items here are hidden from the system and analytics. Restore them to make them active again, or delete them permanently.
        </p>

        <!-- Tab Navigation -->
        <div class="tabs-wrapper" id="binTabs">
            <div class="tab-item active" onclick="switchBinTab('appointments', this)">
                <i class="fa-solid fa-calendar-check"></i>
                Appointments
                <span class="<?= count($del_appointments) === 0 ? 'tab-count-zero' : '' ?>">
                    (<?= count($del_appointments) ?>)
                </span>
            </div>
            <div class="tab-item" onclick="switchBinTab('consultations', this)">
                <i class="fa-solid fa-stethoscope"></i>
                Consultations
                <span class="<?= count($del_consultations) === 0 ? 'tab-count-zero' : '' ?>">
                    (<?= count($del_consultations) ?>)
                </span>
            </div>
            <div class="tab-item" onclick="switchBinTab('patients', this)">
                <i class="fa-solid fa-users"></i>
                Patients
                <span class="<?= count($patient_rows) === 0 ? 'tab-count-zero' : '' ?>">
                    (<?= count($patient_rows) ?>)
                </span>
            </div>
            <div class="tab-item" onclick="switchBinTab('medicines', this)">
                <i class="fa-solid fa-pills"></i>
                Medicines
                <span class="<?= count($medicine_rows) === 0 ? 'tab-count-zero' : '' ?>">
                    (<?= count($medicine_rows) ?>)
                </span>
            </div>
            <div class="tab-item" onclick="switchBinTab('staff', this)">
                <i class="fa-solid fa-user-gear"></i>
                Staff
                <span class="<?= count($staff_rows) === 0 ? 'tab-count-zero' : '' ?>">
                    (<?= count($staff_rows) ?>)
                </span>
            </div>
        </div>

        <!-- Tab Content Panels -->

        <!-- Appointments -->
        <div id="bin-content-appointments" class="bin-tab-panel">
            <?php renderBinTable(
                $del_appointments,
                ['full_name' => 'Patient', 'appointment_date' => 'Date', 'reason' => 'Reason'],
                'appointment',
                'appointment_id',
                'full_name'
            ); ?>
        </div>

        <!-- Consultations -->
        <div id="bin-content-consultations" class="bin-tab-panel" style="display:none;">
            <?php renderBinTable(
                $del_consultations,
                ['full_name' => 'Patient', 'visit_date' => 'Visit Date', 'diagnosis' => 'Diagnosis'],
                'consultation',
                'consultation_id',
                'full_name'
            ); ?>
        </div>

        <!-- Patients -->
        <div id="bin-content-patients" class="bin-tab-panel" style="display:none;">
            <?php renderBinTable(
                $patient_rows,
                ['full_name' => 'Name', 'display_contact' => 'Contact', 'category' => 'Category'],
                'patient',
                'patient_id',
                'full_name'
            ); ?>
        </div>

        <!-- Medicines -->
        <div id="bin-content-medicines" class="bin-tab-panel" style="display:none;">
            <?php renderBinTable(
                $medicine_rows,
                ['display_name' => 'Medicine Name', 'category' => 'Category', 'display_stock' => 'Stock'],
                'medicine',
                'medicine_id',
                'display_name'
            ); ?>
        </div>

        <!-- Staff -->
        <div id="bin-content-staff" class="bin-tab-panel" style="display:none;">
            <?php renderBinTable(
                $staff_rows,
                ['s_name' => 'Staff Name', 'role' => 'Role'],
                'staff',
                'staff_id',
                's_name'
            ); ?>
        </div>

    </div><!-- /.bin-container -->
</div><!-- /.bin-wrapper -->


<!-- Confirmation Modal -->
<div id="binModalOverlay" class="bin-modal-overlay">
    <div class="bin-modal-box">
        <div class="bin-modal-icon"  id="binModalIcon"></div>
        <h3  class="bin-modal-title" id="binModalTitle"></h3>
        <p   class="bin-modal-body"  id="binModalBody"></p>

        <!-- Warning shown only for permanent delete -->
        <div class="bin-warning" id="binModalWarning" style="display:none;">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>This will permanently remove the record from the database. This cannot be undone.</span>
        </div>

        <div class="bin-modal-btns">
            <button class="bin-cancel-btn"  onclick="closeBinModal()">
                <i class="fa-solid fa-xmark"></i> Cancel
            </button>
            <button class="bin-confirm-btn" id="binConfirmBtn" onclick="executeBinAction()">
                Confirm
            </button>
        </div>
    </div>
</div>


<?php
/* ============================================================
 * HELPER: renderBinTable()
 *
 * Renders one tab's table of soft-deleted records.
 *
 * @param array  $data     Rows from fetch_all(MYSQLI_ASSOC)
 * @param array  $cols     ['db_key' => 'Column Header Label']
 * @param string $type     Entity type: 'patient', 'appointment', etc.
 * @param string $id_col   Primary key column name in $data
 * @param string $name_col Column used as display name in the modal
 * ============================================================ */
function renderBinTable(array $data, array $cols, string $type, string $id_col, string $name_col): void
{
    if (empty($data)) {
        echo '<div class="bin-empty">
                <i class="fa-regular fa-folder-open"></i>
                <p>No deleted records found in this category.</p>
              </div>';
        return;
    }

    echo "
    <div class='bin-bulk-actions'>
        <button type='button' class='bin-bulk-btn bin-bulk-select' onclick=\"toggleBinSelectAll('{$type}')\">
            <i class='fa-solid fa-check-double'></i> Select All
        </button>
        <button type='button' class='bin-bulk-btn bin-bulk-delete' onclick=\"openBinBulkDelete('{$type}')\">
            <i class='fa-solid fa-trash'></i> Delete Selected
        </button>
    </div>";

    echo "<div class='glass-table-card'><table data-bin-type='{$type}'><thead><tr>";
    echo '<th class="bin-select-cell"></th>';

    foreach ($cols as $label) {
        echo "<th>{$label}</th>";
    }

    echo '<th style="text-align:center; width:140px;">Actions</th>';
    echo '</tr></thead><tbody>';

    foreach ($data as $row) {
        $record_id   = (int) $row[$id_col];
        echo '<tr>';
        echo "<td class='bin-select-cell'>
                <input type='checkbox' class='bin-row-select' data-type='{$type}' value='{$record_id}' aria-label='Select deleted record'>
              </td>";

        foreach ($cols as $col_key => $label) {
            $raw   = $row[$col_key] ?? '';
            $value = htmlspecialchars((string) $raw);

            /* Format date columns */
            if (in_array($col_key, ['visit_date', 'appointment_date']) && !empty($raw)) {
                $value = date('M d, Y', strtotime($raw));
            }

            echo "<td>{$value}</td>";
        }

        $record_name = addslashes($row[$name_col] ?? 'Record');

        echo "
        <td style='text-align:center;'>
            <button class='action-btn res-btn'
                    title='Restore to active records'
                    onclick=\"openBinModal('restore', '{$type}', {$record_id}, '{$record_name}')\">
                <i class='fa-solid fa-rotate-left'></i>
            </button>
            <button class='action-btn del-btn'
                    title='Delete permanently'
                    onclick=\"openBinModal('delete', '{$type}', {$record_id}, '{$record_name}')\">
                <i class='fa-solid fa-trash'></i>
            </button>
        </td>";

        echo '</tr>';
    }

    echo '</tbody></table></div>';
}
?>


<script>
/* ============================================================
 * JavaScript — Recycle Bin
 * ============================================================ */

/* Pending action stored when modal opens */
var binPendingAction = {};


/* ── Tab Switching ────────────────────────────────────────── */

/**
 * switchBinTab(tabId, btn)
 * Shows the target panel, hides all others, marks btn active.
 * Also saves the active tab to localStorage for persistence.
 */
function switchBinTab(tabId, btn) {
    /* Deactivate all tabs */
    document.querySelectorAll('.tab-item').forEach(function (el) {
        el.classList.remove('active');
    });

    /* Hide all panels */
    document.querySelectorAll('.bin-tab-panel').forEach(function (el) {
        el.style.display = 'none';
    });

    /* Activate selected tab and panel */
    btn.classList.add('active');
    var panel = document.getElementById('bin-content-' + tabId);
    if (panel) panel.style.display = 'block';

    /* Persist selection */
    localStorage.setItem('bin_active_tab', tabId);
}

/* ── Tab persistence on load ──────────────────────────────── 
 * Called directly (not inside DOMContentLoaded) because this
 * file is loaded as a fragment via loadPage() — DOMContentLoaded
 * has already fired on the parent page by that point.
 */
(function restoreActiveTab() {
    var savedTab = localStorage.getItem('bin_active_tab');
    if (!savedTab) return;

    var tabBtn = Array.from(document.querySelectorAll('.tab-item'))
        .find(function (btn) {
            return btn.innerText.toLowerCase().includes(savedTab);
        });

    if (tabBtn) switchBinTab(savedTab, tabBtn);
})();


/* ── Confirmation Modal ───────────────────────────────────── */

/**
 * openBinModal(mode, type, id, name)
 * Configures and opens the confirm modal for restore or delete.
 *
 * @param {string} mode  'restore' | 'delete'
 * @param {string} type  Entity type (e.g. 'patient')
 * @param {number} id    Primary key value
 * @param {string} name  Human-readable record name
 */
function openBinModal(mode, type, id, name) {
    binPendingAction = { mode: mode, type: type, id: id };

    var isRestore = mode === 'restore';

    /* Icon */
    document.getElementById('binModalIcon').innerHTML = isRestore
        ? "<i class='fa-solid fa-rotate-left' style='color:#10b981;'></i>"
        : "<i class='fa-solid fa-triangle-exclamation' style='color:#ef4444;'></i>";

    /* Title */
    document.getElementById('binModalTitle').innerText = isRestore
        ? 'Restore Record?'
        : 'Delete Permanently?';

    /* Body */
    document.getElementById('binModalBody').innerHTML = isRestore
        ? 'Restore <b>' + name + '</b> to the active system? It will reappear in all tables and analytics.'
        : 'You are about to permanently delete <b>' + name + '</b>.';

    /* Warning banner — only for delete */
    document.getElementById('binModalWarning').style.display = isRestore ? 'none' : 'flex';

    /* Confirm button */
    var btn = document.getElementById('binConfirmBtn');
    btn.disabled = false;
    btn.style.background = isRestore ? '#10b981' : '#dc3545';
    btn.innerHTML = isRestore
        ? "<i class='fa-solid fa-rotate-left'></i> Restore"
        : "<i class='fa-solid fa-trash'></i> Delete Forever";

    /* Show modal */
    document.getElementById('binModalOverlay').style.display = 'flex';
}

function getSelectedBinIds(type) {
    return Array.from(document.querySelectorAll('.bin-row-select[data-type="' + type + '"]:checked'))
        .map(function (box) { return box.value; });
}

function toggleBinSelectAll(type) {
    var boxes = Array.from(document.querySelectorAll('.bin-row-select[data-type="' + type + '"]'));
    if (boxes.length === 0) return;

    var shouldCheck = boxes.some(function (box) { return !box.checked; });
    boxes.forEach(function (box) { box.checked = shouldCheck; });
}

function openBinBulkDelete(type) {
    var ids = getSelectedBinIds(type);
    if (ids.length === 0) {
        alert('Please select records first.');
        return;
    }

    binPendingAction = { mode: 'delete', type: type, ids: ids };

    document.getElementById('binModalIcon').innerHTML =
        "<i class='fa-solid fa-triangle-exclamation' style='color:#ef4444;'></i>";
    document.getElementById('binModalTitle').innerText = 'Delete Selected Permanently?';
    document.getElementById('binModalBody').innerHTML =
        'You are about to permanently delete <b>' + ids.length + ' selected ' + type + (ids.length > 1 ? ' records' : ' record') + '</b>.';
    document.getElementById('binModalWarning').style.display = 'flex';

    var btn = document.getElementById('binConfirmBtn');
    btn.disabled = false;
    btn.style.background = '#dc3545';
    btn.innerHTML = "<i class='fa-solid fa-trash'></i> Delete Forever";

    document.getElementById('binModalOverlay').style.display = 'flex';
}

/** closeBinModal() — Hides the confirmation modal. */
function closeBinModal() {
    document.getElementById('binModalOverlay').style.display = 'none';
    binPendingAction = {};
}

/**
 * executeBinAction()
 * Sends the restore or delete request to bin.php via AJAX POST.
 * On success, reloads bin.php content through the parent's loadPage().
 */
function executeBinAction() {
    var action = binPendingAction.mode + '_' + binPendingAction.type;

    var formData = new FormData();
    formData.append('action', action);
    if (binPendingAction.ids && binPendingAction.ids.length) {
        binPendingAction.ids.forEach(function (id) {
            formData.append('ids[]', id);
        });
    } else {
        formData.append('id', binPendingAction.id);
    }

    /* Disable button to prevent double-click */
    var btn = document.getElementById('binConfirmBtn');
    btn.disabled  = true;
    btn.innerHTML = "<i class='fa-solid fa-circle-notch fa-spin'></i> Processing...";

    fetch('bin.php', { method: 'POST', body: formData })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            closeBinModal();

            if (data.status === 'success') {
                /* Reload the bin page content via the dashboard's loadPage() */
                if (typeof loadPage === 'function') {
                    loadPage('bin.php');
                } else {
                    location.reload();
                }
            } else {
                alert('Error: ' + (data.msg || 'The action could not be completed. Please try again.'));
            }
        })
        .catch(function (err) {
            console.error('Bin action error:', err);
            alert('A network error occurred. Please check your connection and try again.');
            closeBinModal();
        });
}

/* Close modal when clicking outside the box */
document.getElementById('binModalOverlay').addEventListener('click', function (e) {
    if (e.target === this) closeBinModal();
});
</script>
