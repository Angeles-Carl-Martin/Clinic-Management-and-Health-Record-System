<?php
/* =====================================================
   INITIALIZATION
   - Start session and connect to the database
   ===================================================== */
session_start();
require "db.php";
require_staff_login();
redirect_direct_fragment_access();

/** @var mysqli $conn */


function validateVisitDateValue(string $date): ?string {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    $errors = DateTime::getLastErrors();
    if (!$dt || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $dt->format('Y-m-d') !== $date) {
        return 'Visit date must be a valid date.';
    }
    if ($dt < new DateTime('today')) {
        return 'Visit date cannot be in the past.';
    }
    return null;
}

/* =====================================================
   AJAX HANDLER: SAVE RECORD (ADD / UPDATE)
   - Triggered when the consultation form is submitted
   - If consultation_id exists → UPDATE, otherwise → INSERT
   - Also handles medicine dispensing and stock deduction
   ===================================================== */
if (isset($_POST['ajax_save_record'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');

    // Collect form inputs
    $nurse_id        = $_SESSION['staff_id'] ?? null;
    $patient_id      = $_POST['patient_id'];
    $visit_date      = $_POST['visit_date'];
    $symptoms        = $_POST['symptoms']        ?? '';
    $diagnosis       = $_POST['diagnosis']       ?? '';
    $treatment       = $_POST['treatment']       ?? '';
    $notes           = $_POST['notes']           ?? '';
    $consultation_id = $_POST['consultation_id'] ?? '';

    $visit_date_error = validateVisitDateValue($visit_date);
    if ($visit_date_error) {
        echo json_encode(['status' => 'error', 'msg' => $visit_date_error]);
        exit();
    }

    // Medicine arrays from the dispense rows
    $med_ids  = $_POST['med_id']  ?? [];
    $med_qtys = $_POST['med_qty'] ?? [];

    // Use a transaction so all changes succeed or all fail together
    $conn->begin_transaction();

    try {
        /* -------------------------------------------------
           STEP A: Save or Update the Consultation record
           ------------------------------------------------- */
        if (!empty($consultation_id)) {
            // UPDATE existing consultation
            $stmt = $conn->prepare("
                UPDATE consultations
                SET patient_id=?, visit_date=?, symptoms=?, diagnosis=?, treatment=?, notes=?
                WHERE consultation_id=?
            ");
            $stmt->bind_param("isssssi",
                $patient_id, $visit_date, $symptoms,
                $diagnosis, $treatment, $notes,
                $consultation_id
            );
            $stmt->execute();
            $current_cons_id = $consultation_id;

        } else {
            // INSERT new consultation
            $stmt = $conn->prepare("
                INSERT INTO consultations (patient_id, nurse_id, visit_date, symptoms, diagnosis, treatment, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iisssss",
                $patient_id, $nurse_id, $visit_date,
                $symptoms, $diagnosis, $treatment, $notes
            );
            $stmt->execute();
            $current_cons_id = $conn->insert_id;
        }

        /* -------------------------------------------------
           STEP B: Dispense medicines and deduct stock
           ------------------------------------------------- */
        if (!empty($med_ids)) {
            foreach ($med_ids as $index => $mid) {
                if (empty($mid)) continue;

                $qty = (int) $med_qtys[$index];
                if ($qty > 0) {
                    // Deduct quantity from medicines inventory
                    $u_stmt = $conn->prepare("
                        UPDATE medicines SET quantity = quantity - ?
                        WHERE medicine_id = ?
                    ");
                    $u_stmt->bind_param("ii", $qty, $mid);
                    $u_stmt->execute();

                    // Log the dispense in medicine_dispense table
                    $disp_stmt = $conn->prepare("
                        INSERT INTO medicine_dispense (consultation_id, medicine_id, quantity_given)
                        VALUES (?, ?, ?)
                    ");
                    $disp_stmt->bind_param("iii", $current_cons_id, $mid, $qty);
                    $disp_stmt->execute();
                }
            }
        }

        $conn->commit();
        echo json_encode(['status' => 'success']);

    } catch (Exception $e) {
        // Roll back everything if any step fails
        $conn->rollback();
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit();
}


/* =====================================================
   AJAX HANDLER: DELETE RECORD
   - Triggered via GET request with delete_id parameter
   ===================================================== */
if (isset($_GET['delete_id'])) {
    ob_clean();
    header('Content-Type: application/json');

    // Soft delete: update lang ang status sa 0 imbes na burahin sa DB
    $stmt = $conn->prepare("UPDATE consultations SET status = 0 WHERE consultation_id = ?");
    $stmt->bind_param("i", $_GET['delete_id']);

    echo json_encode([
        'status' => $stmt->execute() ? 'success' : 'error'
    ]);
    exit();
}

if (isset($_POST['action']) && $_POST['action'] === 'delete_selected') {
    ob_clean();
    header('Content-Type: application/json');

    $ids = $_POST['delete_ids'] ?? [];
    if (!is_array($ids)) {
        $ids = explode(',', (string) $ids);
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    if (empty($ids)) {
        echo json_encode(['status' => 'error', 'msg' => 'No records selected.']);
        exit();
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types        = str_repeat('i', count($ids));
    $stmt         = $conn->prepare("UPDATE consultations SET status = 0 WHERE consultation_id IN ($placeholders)");
    $stmt->bind_param($types, ...$ids);

    echo json_encode([
        'status' => $stmt->execute() ? 'success' : 'error',
        'msg'    => $conn->error,
        'count'  => count($ids),
    ]);
    exit();
}

/* =====================================================
   DATA FETCHING
   - Load patients for the dropdown
   - Load available medicines for dispensing
   - Load all consultation records for the table
   ===================================================== */

// Patient dropdown list
$patients = $conn->query("
    SELECT patient_id, full_name FROM patients
    WHERE status = 1
    ORDER BY full_name ASC
");

// Available medicines (only those with stock)
$med_list        = [];
$medicines_query = $conn->query("
    SELECT medicine_id, medicine_name, quantity
    FROM medicines
    WHERE quantity > 0
");
while ($m = $medicines_query->fetch_assoc()) {
    $med_list[] = $m;
}

// All consultation records joined with patient names and staff (nurse/admin) name
$records = $conn->query("
    SELECT c.*, p.full_name,
           COALESCE(s.full_name, 'Unknown') AS nurse_name
    FROM consultations c
    JOIN patients p ON c.patient_id = p.patient_id
    LEFT JOIN staff s ON c.nurse_id = s.staff_id
    WHERE c.status = 1
    ORDER BY c.visit_date DESC
");
?>


<!-- =====================================================
     STYLES
     ===================================================== -->
<style>

    /* -----------------------------------------------------
       CARD — Main container wrapper
       ----------------------------------------------------- */
    .record-card {
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(10px);
        padding: 25px;
        border-radius: 20px;
        color: white;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
    }

    /* -----------------------------------------------------
       TOP BAR — Title and New Consultation button
       ----------------------------------------------------- */
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

    .top-bar h2 i {
        color: #00a651;
        font-size: 28px;
    }

    /* -----------------------------------------------------
       CONSULTATION FORM CONTAINER — Slide-down form
       ----------------------------------------------------- */
  /* Container ng Pop-up Modal */
/* Updated Consultation Modal Container */
#consultationFormContainer {
    display: none;
    position: fixed;
    top: 50%; 
    left: 50%; /* Changed to 50% for perfect centering */
    transform: translate(-50%, -50%); 
    
    width: 95%;
    max-width: 850px;
    max-height: 90vh; 
    overflow-y: auto;
    
    background: white;
    padding: 30px;
    border-radius: 24px; /* Matches the Patient form */
    z-index: 1100;       /* Higher than overlay */
    box-shadow: 0 20px 60px rgba(0,0,0,0.5); /* Deeper shadow for depth */
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from { opacity: 0; transform: translate(-50%, calc(-50% - 24px)); }
    to   { opacity: 1; transform: translate(-50%, -50%); }
}

.modal-form-header h3{
    margin:         0 0 24px;
    color:          #004d26;
    font-size:      22px;
    font-weight:    800;
    text-align:     center;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

/* Updated Overlay with Blur */
.form-overlay {
    display: none;
    position: fixed;
    inset: 0; /* Shorthand for top, left, bottom, right: 0 */
    background: rgba(0, 0, 0, 0.7); /* Consistent darkness */
    backdrop-filter: blur(5px);    /* The glass effect */
    z-index: 1050;                 /* Between the page and the modal */
}

/* Helper class to show them */
.modal-show { 
    display: block !important; 
}

/* Grid and Group Styling */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-group.full-width {
    grid-column: span 2;
}

/* Consistent Label Styling */
.form-group label {
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 8px;
    color: #084c24;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Overlay Background */


    /* -----------------------------------------------------
       FORM INPUTS — Shared styles
       ----------------------------------------------------- */
    input, select, textarea {
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

    textarea {
        min-height: 80px;
        resize: vertical;
    }

    /* Highlight effect on focus */
    input:focus, select:focus, textarea:focus {
        border-color: #00a651;
        box-shadow: 0 0 0 5px rgba(0, 166, 81, 0.1);
        transform: translateY(-1px);
    }

    /* -----------------------------------------------------
       DISPENSE MEDICINE SECTION
       ----------------------------------------------------- */
    .dispense-section {
        background: rgba(0, 166, 81, 0.06);
        padding: 16px;
        border-radius: 14px;
        border: 1px dashed rgba(0, 166, 81, 0.35);
    }

    .dispense-section label {
        color: #007a3d !important;
        font-size: 14px !important;
        font-weight: 800 !important;
        margin-bottom: 12px !important;
    }

    /* Each medicine row: dropdown + qty + remove button */
    .med-row {
        display: flex;
        gap: 10px;
        margin-bottom: 10px;
        align-items: center;
    }

    .med-row select {
        flex: 2;
    }

    .med-row input[type="number"] {
        flex: 1;
    }

    .dispense-actions {
        display: flex;
        gap: 10px;
        margin-top: 12px;
    }

    /* -----------------------------------------------------
       BUTTONS
       ----------------------------------------------------- */
    .btn {
        padding: 10px 18px;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        font-weight: 700;
        font-size: 14px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        color: white;
    }

    .btn:hover {
        transform: translateY(-2px);
    }

    .btn-green  { background: linear-gradient(135deg, #2f9e44, #20c997); box-shadow: 0 6px 16px rgba(32,201,151,0.22); }
    .btn-gray   { background: linear-gradient(135deg, #6c757d, #495057); box-shadow: 0 6px 16px rgba(73,80,87,0.22); }
    .btn-blue   { background: linear-gradient(135deg, #007bff, #0056b3); box-shadow: 0 6px 16px rgba(0,123,255,0.22); }
    .btn-red    { background: linear-gradient(135deg, #dc3545, #c82333); box-shadow: 0 6px 16px rgba(220,53,69,0.22); }
    .btn-teal   { background: linear-gradient(135deg, #17a2b8, #138496); box-shadow: 0 6px 16px rgba(23,162,184,0.22); }
    .btn-yellow { background: linear-gradient(135deg, #ffc107, #e67e22); color: #333; box-shadow: 0 6px 16px rgba(255,193,7,0.22); }

    /* -----------------------------------------------------
       FORM ACTION BUTTONS — Save and Cancel
       ----------------------------------------------------- */
   /* I-update ang section na ito sa iyong file */
.form-actions {
    margin-top: 40px; /* Dinagdagan ang space para hindi dikit sa inputs */
    display: flex;
    gap: 20px; /* Mas malapad na agwat sa pagitan ng buttons */
    justify-content: center;
    padding-top: 25px;
    border-top: 1px solid #eee; /* Nilagyan ng divider line sa itaas */
    flex-wrap: wrap;
}

.form-actions .btn {
    min-width: 180px; /* Mas mahaba para mas madaling pindutin */
    padding: 14px 30px;
    font-size: 15px;
    border-radius: 12px; /* Smooth corners gaya ng sa Patient Registration */
    display: flex;
    align-items: center;
    justify-content: center;
}

    /* -----------------------------------------------------
       SEARCH BAR
       ----------------------------------------------------- */
    .search-wrapper {
        margin-bottom: 16px;
    }

    .search-box {
        display: flex;
        align-items: center;
        background: white;
        border-radius: 12px;
        border: 1px solid #d6e8d7;
        padding: 10px 16px;
        gap: 10px;
        max-width: 360px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
        transition: border-color 0.2s ease;
    }

    .search-box:focus-within {
        border-color: #00a651;
    }

    .search-box i.search-icon {
        color: #00a651;
        font-size: 15px;
        flex-shrink: 0;
    }

    .search-box input {
        border: none;
        outline: none;
        background: transparent;
        font-size: 14px;
        color: #1f3822;
        width: 100%;
        max-width: 100%;
        padding: 0;
        transform: none;
        box-shadow: none;
    }

    .search-box input:focus {
        outline: none;
        box-shadow: none;
        border: none;
        transform: none;
    }

    .search-box input::placeholder {
        color: #aaa;
    }

    .search-box .clear-btn {
        color: #aaa;
        font-size: 14px;
        cursor: pointer;
        display: none;
        flex-shrink: 0;
        transition: color 0.2s ease;
    }

    .search-box .clear-btn:hover {
        color: #dc3545;
    }

    /* "No records found" notice */
    .no-results {
        display: none;
        margin-top: 12px;
        padding: 12px 16px;
        background: rgba(255, 255, 255, 0.07);
        border: 1px dashed rgba(255, 255, 255, 0.18);
        border-radius: 12px;
        max-width: 360px;
        align-items: center;
        gap: 10px;
    }

    .no-results i {
        color: rgba(255, 255, 255, 0.4);
        font-size: 15px;
        flex-shrink: 0;
    }

    .no-results span {
        font-size: 13px;
        color: rgba(255, 255, 255, 0.6);
        font-weight: 500;
    }

    .no-results span strong {
        color: rgba(255, 255, 255, 0.85);
        font-weight: 700;
    }

    /* -----------------------------------------------------
       TABLE CONTROLS — Row count + rows per page
       ----------------------------------------------------- */
    .table-controls {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
        flex-wrap: wrap;
        gap: 10px;
    }

    .row-count {
        font-size: 13px;
        color: rgba(255, 255, 255, 0.7);
        font-weight: 500;
    }

    .row-count strong {
        color: white;
        font-weight: 700;
    }

    .rows-per-page {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: rgba(255, 255, 255, 0.7);
    }

    .rows-per-page select {
        width: auto;
        max-width: 80px;
        padding: 6px 10px;
        border-radius: 8px;
        font-size: 13px;
        border: 1px solid rgba(255, 255, 255, 0.3);
        background: white;
        color: #1f3822;
        cursor: pointer;
        transform: none;
        box-shadow: none;
    }

    .rows-per-page select:focus {
        border-color: #00a651;
        box-shadow: none;
        transform: none;
    }

    /* -----------------------------------------------------
       TABLE — Consultation records list
       ----------------------------------------------------- */
    .table-responsive {
        width: 100%;
        overflow-x: auto;
        border-radius: 15px;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }

    table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        background: white;
        color: #333;
        border-radius: 15px;
        overflow: hidden;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }

    /* Sticky dark green header */
    th {
        background: linear-gradient(135deg, #004d26, #006633);
        color: white;
        padding: 18px 15px;
        font-size: 14px;
        font-weight: 600;
        text-align: left;
        position: sticky;
        top: 0;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
    }

    td {
        padding: 14px 15px;
        border-bottom: 1px solid #f0f0f0;
        font-size: 13px;
        vertical-align: top;
        word-wrap: break-word;
    }

    /* Alternating row color */
    tbody tr:nth-child(even) {
        background: #fafafa;
    }

    /* Row hover highlight */
    tbody tr:hover {
        background: #e8f5e8;
        transition: background 0.2s ease;
    }

    /* Sortable column headers */
    th.sortable {
        cursor: pointer;
        user-select: none;
        white-space: nowrap;
    }

    th.sortable:hover {
        background: linear-gradient(135deg, #006633, #008844);
    }

    th.sortable .sort-icon {
        margin-left: 6px;
        font-size: 11px;
        opacity: 0.5;
    }

    th.sortable.asc .sort-icon,
    th.sortable.desc .sort-icon {
        opacity: 1;
        color: #20c997;
    }

    /* Action buttons inside table rows */
    .action-btns {
        display: flex;
        gap: 8px;
        justify-content: center;
    }

    .edit-btn {
        background: linear-gradient(135deg, #007bff, #0056b3);
        color: white;
        border: none;
        width: 35px;
        height: 35px;
        border-radius: 8px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
        box-shadow: 0 2px 8px rgba(0, 123, 255, 0.3);
    }

    .edit-btn:hover {
        background: linear-gradient(135deg, #0056b3, #004085);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 123, 255, 0.4);
    }

    .delete-btn {
        background: linear-gradient(135deg, #dc3545, #c82333);
        color: white;
        border: none;
        width: 35px;
        height: 35px;
        border-radius: 8px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
        box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3);
    }

    .delete-btn:hover {
        background: linear-gradient(135deg, #c82333, #a02622);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(220, 53, 69, 0.4);
    }

    /* -----------------------------------------------------
       PAGINATION BUTTONS
       ----------------------------------------------------- */
    .pagination-wrapper {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 6px;
        margin-top: 16px;
        flex-wrap: wrap;
    }

    .page-btn {
        min-width: 36px;
        height: 36px;
        padding: 0 10px;
        border-radius: 8px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        background: rgba(255, 255, 255, 0.08);
        color: rgba(255, 255, 255, 0.8);
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .page-btn:hover {
        background: rgba(0, 166, 81, 0.25);
        border-color: #00a651;
        color: white;
    }

    /* Active/current page */
    .page-btn.active {
        background: linear-gradient(135deg, #2f9e44, #20c997);
        border-color: transparent;
        color: white;
        box-shadow: 0 4px 12px rgba(32, 201, 151, 0.3);
    }

    /* Disabled prev/next */
    .page-btn:disabled {
        opacity: 0.35;
        cursor: not-allowed;
        pointer-events: none;
    }

    /* -----------------------------------------------------
       DELETE MODAL — Confirmation dialog
       ----------------------------------------------------- */
    #deleteModal {
        position: fixed;
        top: 0; left: 0;
        width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.75);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        backdrop-filter: blur(4px);
    }

    .modal-box {
        background: white;
        padding: 36px 32px;
        border-radius: 24px;
        width: 360px;
        text-align: center;
        color: #1a1a2e;
        box-shadow: 0 25px 60px rgba(0, 0, 0, 0.35);
        position: relative;
        overflow: hidden;
        animation: modalPop 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    @keyframes modalPop {
        from { opacity: 0; transform: scale(0.85); }
        to   { opacity: 1; transform: scale(1); }
    }

    .modal-accent-bar {
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 5px;
        background: linear-gradient(90deg, #dc3545, #ff6b6b);
        border-radius: 24px 24px 0 0;
    }

    .modal-icon {
        width: 72px;
        height: 72px;
        background: linear-gradient(135deg, #ffe0e3, #ffc2c7);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        box-shadow: 0 6px 20px rgba(220, 53, 69, 0.2);
    }

    .modal-icon i {
        font-size: 28px;
        color: #dc3545;
    }

    .modal-title {
        margin: 0 0 8px;
        font-size: 22px;
        font-weight: 800;
        color: #c0392b;
    }

    .modal-subtitle {
        margin: 0 0 6px;
        font-size: 14px;
        color: #666;
        line-height: 1.5;
    }

    .modal-patient-name {
        margin: 0 0 20px;
        font-size: 16px;
        font-weight: 700;
        color: #1a1a2e;
    }

    .modal-warning {
        background: #fff8e1;
        border: 1px solid #ffe082;
        border-radius: 10px;
        padding: 10px 14px;
        margin-bottom: 28px;
        display: flex;
        align-items: center;
        gap: 8px;
        text-align: left;
    }

    .modal-warning i {
        color: #f59e0b;
        font-size: 15px;
        flex-shrink: 0;
    }

    .modal-warning span {
        font-size: 12px;
        color: #7a5c00;
        font-weight: 600;
    }

    .modal-buttons {
        display: flex;
        gap: 12px;
        justify-content: center;
    }

    .modal-btn-cancel {
        flex: 1;
        padding: 13px 20px;
        border-radius: 12px;
        border: 2px solid #e0e0e0;
        background: white;
        color: #555;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: background 0.2s ease;
    }

    .modal-btn-cancel:hover {
        background: #f5f5f5;
    }

    .modal-btn-delete {
        flex: 1;
        padding: 13px 20px;
        border-radius: 12px;
        border: none;
        background: linear-gradient(135deg, #dc3545, #c0392b);
        color: white;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        box-shadow: 0 6px 18px rgba(220, 53, 69, 0.35);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .modal-btn-delete:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 22px rgba(220, 53, 69, 0.4);
    }

    @media (max-width: 640px) {
        #consultationFormContainer {
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

        .modal-form-header h3 {
            font-size: 18px;
            line-height: 1.25;
            margin-bottom: 16px;
            letter-spacing: 0.05em;
        }

        #consultationFormContainer .form-grid {
            grid-template-columns: 1fr;
            gap: 12px;
        }

        #consultationFormContainer .form-group.full-width {
            grid-column: span 1;
        }

        #consultationFormContainer input,
        #consultationFormContainer select,
        #consultationFormContainer textarea {
            min-height: 44px;
            font-size: 16px;
        }

        #consultationFormContainer .form-actions,
        .modal-buttons {
            flex-direction: column;
        }

        #consultationFormContainer .form-actions .btn,
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
<div id="formOverlay" class="form-overlay" onclick="closeForm()"></div>

<div id="consultationFormContainer">
    <div class="modal-form-header">
        <h3 id="formTitle"> 
            <i class="fa-solid fa-file-medical"></i> NEW CONSULTATION RECORD
        </h3>
    </div>

    <form id="recordForm">
        <input type="hidden" name="consultation_id" id="consultation_id">
        
        <div class="form-grid">
            <div class="form-group" style="position:relative;">
                <label><i class="fa-solid fa-user"></i> Patient</label>
                <input type="hidden" name="patient_id" id="patient_id" required>
                <div style="position:relative;">
                    <input type="text"
                           id="patient_search_input"
                           placeholder="Type to search patient..."
                           autocomplete="off"
                           oninput="filterPatientDropdown(this.value)"
                           onfocus="showPatientDropdown()"
                           style="padding-right:36px;">
                    <i class="fa-solid fa-magnifying-glass"
                       style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#00a651;pointer-events:none;"></i>
                </div>
                <div id="patientDropdown"
                     style="display:none;position:absolute;top:100%;left:0;right:0;background:white;border:1px solid #d6e8d7;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,0.15);max-height:220px;overflow-y:auto;z-index:9999;margin-top:4px;">
                </div>
                <small id="patientSelectedName"
                       style="display:none;margin-top:5px;color:#2e7d32;font-size:12px;font-weight:600;">
                    <i class="fa-solid fa-circle-check"></i> <span id="patientSelectedLabel"></span>
                </small>
            </div>

            <div class="form-group">
                <label><i class="fa-solid fa-calendar"></i> Visit Date</label>
                <input type="date" name="visit_date" id="visit_date" 
                value="<?= date('Y-m-d') ?>" 
                min="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="form-group full-width dispense-section">
                <label><i class="fa-solid fa-pills"></i> Dispense Medicine</label>
                <div id="medContainer" style="margin-bottom: 10px;"></div>
                <div class="dispense-actions" style="display: flex; gap: 10px;">
                    <button type="button" class="btn btn-teal" onclick="addMedRow()">
                        <i class="fa-solid fa-plus"></i> Add Item
                    </button>
                    <button type="button" class="btn btn-yellow" onclick="applyToTreatment()">
                        <i class="fa-solid fa-check"></i> Apply to Treatment
                    </button>
                </div>
            </div>

            <div class="form-group full-width">
                <label><i class="fa-solid fa-stethoscope"></i> Symptoms</label>
                <textarea name="symptoms" id="symptoms" rows="3" placeholder="Describe symptoms..."></textarea>
            </div>

            <div class="form-group">
                <label><i class="fa-solid fa-magnifying-glass"></i> Diagnosis</label>
                <textarea name="diagnosis" id="diagnosis" rows="3" placeholder="Enter diagnosis..."></textarea>
            </div>

            <div class="form-group">
                <label><i class="fa-solid fa-prescription-bottle"></i> Treatment / Remarks</label>
                <textarea name="treatment" id="treatment" rows="3" placeholder="Enter treatment..."></textarea>
            </div>
        </div>

        <div class="form-actions" style="margin-top: 30px; display: flex; justify-content: center; gap: 15px;">
            <button type="submit" class="btn btn-green">
                <i class="fa-solid fa-save"></i> Save Record
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

    <!-- Header: Title and New Consultation button -->
    <div class="top-bar">
        <h2><i class="fa-solid fa-notes-medical"></i> Medical Records</h2>
        <button class="btn btn-green" onclick="prepareAdd()">
            <i class="fa-solid fa-plus"></i> New Consultation
        </button>
    </div>

    <!-- DITO MO I-PASTE ANG BAGONG CODE (Background Overlay at Modal Container) -->
   

    

    <!-- PAGKATAPOS NITO, HINDI NA BABAGUHIN YUNG SEARCH AT TABLE SA BABA -->

    <!-- Search Bar -->
    <div class="search-wrapper">
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <input
                type="text"
                id="recordSearch"
                placeholder="Search by patient name or diagnosis..."
                oninput="searchRecords()"
            >
            <i class="fa-solid fa-xmark clear-btn" id="clearSearch" onclick="clearSearch()"></i>
        </div>

        <!-- Shown when search returns no matches -->
        <div class="no-results" id="noResults">
            <i class="fa-solid fa-file-circle-xmark"></i>
            <span>No results for <strong id="noResultsQuery"></strong> — try a different name or diagnosis.</span>
        </div>
    </div>

    <!-- Table Controls: row count + rows per page -->
    <div class="table-controls">
        <div class="row-count" id="rowCount">
            Showing <strong>0</strong> of <strong>0</strong> records
        </div>
        <div class="rows-per-page">
            <button type="button" class="btn btn-green" style="padding:8px 12px;" onclick="toggleSelectAllRecords()">
                <i class="fa-solid fa-check-double"></i> Select All
            </button>
            <button type="button" class="btn btn-red" style="padding:8px 12px;" onclick="confirmDeleteSelectedRecords()">
                <i class="fa-solid fa-trash"></i> Delete Selected
            </button>
            Rows per page:
            <select id="rowsPerPage" onchange="changeRowsPerPage()">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
        </div>
    </div>

    <!-- Consultations Table -->
    <div class="table-responsive">
        <table id="recordTable">
            <thead>
                <tr>
                    <th class="sortable" onclick="sortTable(0)" data-col="0">
                        Date <span class="sort-icon fa-solid fa-sort"></span>
                    </th>
                    <th class="sortable" onclick="sortTable(1)" data-col="1">
                        Patient <span class="sort-icon fa-solid fa-sort"></span>
                    </th>
                    <th class="sortable" onclick="sortTable(2)" data-col="2">
                        Diagnosis <span class="sort-icon fa-solid fa-sort"></span>
                    </th>
                    <th>Treatment</th>
                    <th><i class="fa-solid fa-user-nurse" style="color:#20c997;"></i> Consulted By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $records->fetch_assoc()): ?>
                <tr data-id="<?= $row['consultation_id'] ?>">
                    <td><?= date("M d, Y", strtotime($row['visit_date'])) ?></td>
                    <td><strong><?= htmlspecialchars($row['full_name']) ?></strong></td>
                    <td><?= htmlspecialchars($row['diagnosis']) ?></td>
                    <td style="font-size:13px;"><?= nl2br(htmlspecialchars($row['treatment'])) ?></td>
                    <td style="font-size:13px;">
                        <i class="fa-solid fa-user-doctor" style="color:#00a651; margin-right:5px;"></i>
                        <?= htmlspecialchars($row['nurse_name']) ?>
                    </td>
                    <td>
                        <div class="action-btns">
                            <input type="checkbox"
                                   class="record-select"
                                   value="<?= $row['consultation_id'] ?>"
                                   aria-label="Select consultation record">
                            <!-- Edit button: passes full row data as JSON -->
                            <button class="edit-btn" onclick='prepareEdit(<?= json_encode($row) ?>)'>
                                <i class="fa-solid fa-edit"></i>
                            </button>
                            <!-- Delete button: passes consultation ID and patient name -->
                            <button class="delete-btn" onclick="confirmDelete(<?= $row['consultation_id'] ?>, '<?= addslashes($row['full_name']) ?>')">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination buttons -->
    <div class="pagination-wrapper" id="paginationWrapper"></div>

</div>


<!-- =====================================================
     DELETE CONFIRMATION MODAL
     ===================================================== -->
<div id="deleteModal">
    <div class="modal-box">

        <!-- Red accent bar at top -->
        <div class="modal-accent-bar"></div>

        <!-- Trash icon -->
        <div class="modal-icon">
            <i class="fa-solid fa-trash"></i>
        </div>

        <!-- Title and record name -->
        <h3 class="modal-title">Delete Record?</h3>
        <p class="modal-subtitle">You are about to permanently delete the record of</p>
        <p class="modal-patient-name">"<span id="delName"></span>"</p>

        <!-- Warning notice -->
        <div class="modal-warning">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>This action cannot be undone. The consultation record will be permanently removed.</span>
        </div>

        <!-- Buttons -->
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


<script>

    /* -----------------------------------------------------
       GLOBAL STATE
       ----------------------------------------------------- */

    /* -----------------------------------------------
       PATIENT SEARCH DROPDOWN
       ----------------------------------------------- */
    <?php
    $patients->data_seek(0);
    $patients_arr = [];
    while ($p = $patients->fetch_assoc()) {
        $patients_arr[] = ['id' => $p['patient_id'], 'name' => $p['full_name']];
    }
    ?>
    var patientsData = <?= json_encode($patients_arr) ?>;

    function filterPatientDropdown(query) {
        var q = query.toLowerCase().trim();
        var list = q === ''
            ? patientsData.slice(0, 30)
            : patientsData.filter(function(p){ return p.name.toLowerCase().includes(q); }).slice(0, 30);
        renderPatientDropdown(list);
        // clear selection if user is typing again
        document.getElementById('patient_id').value = '';
        document.getElementById('patientSelectedName').style.display = 'none';
    }

    function renderPatientDropdown(list) {
        var dd = document.getElementById('patientDropdown');
        if (list.length === 0) {
            dd.innerHTML = '<div style="padding:12px 16px;color:#888;font-size:13px;"><i class="fa-solid fa-user-slash" style="margin-right:6px;"></i>No patients found.</div>';
        } else {
            dd.innerHTML = list.map(function(p){
                return '<div onclick="selectPatient(' + p.id + ', \'' + p.name.replace(/'/g,"\\\'") + '\')"'
                     + ' style="padding:10px 16px;cursor:pointer;font-size:13px;color:#1f3822;border-bottom:1px solid #f0f0f0;"'
                     + ' onmouseover="this.style.background=\'#e8f5e8\'" onmouseout="this.style.background=\'white\'">'
                     + '<i class="fa-solid fa-user" style="margin-right:8px;color:#00a651;"></i>' + p.name
                     + '</div>';
            }).join('');
        }
        dd.style.display = 'block';
    }

    function showPatientDropdown() {
        var q = document.getElementById('patient_search_input').value.toLowerCase().trim();
        var list = q === ''
            ? patientsData.slice(0, 30)
            : patientsData.filter(function(p){ return p.name.toLowerCase().includes(q); }).slice(0, 30);
        renderPatientDropdown(list);
    }

    function selectPatient(id, name) {
        document.getElementById('patient_id').value = id;
        document.getElementById('patient_search_input').value = name;
        document.getElementById('patientDropdown').style.display = 'none';
        document.getElementById('patientSelectedLabel').innerText = name;
        document.getElementById('patientSelectedName').style.display = 'block';
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        var wrap = document.querySelector('#patient_search_input');
        var dd   = document.getElementById('patientDropdown');
        if (dd && wrap && !wrap.contains(e.target) && !dd.contains(e.target)) {
            dd.style.display = 'none';
        }
    });

    // Medicine list from PHP (used to populate med dropdowns)
    if (typeof medsData === 'undefined') {
        var medsData = <?= json_encode($med_list) ?>;
    } else {
        medsData = <?= json_encode($med_list) ?>;
    }

    // Stores the consultation ID pending deletion
    if (typeof currentDeleteId === 'undefined') {
        var currentDeleteId = null;
    }
    var currentDeleteIds = [];

    // Pagination state
    var currentPage   = 1;
    var rowsPerPage   = 10;
    var sortColIndex  = -1;    // Active sort column (-1 = none)
    var sortDirection = 'asc'; // 'asc' or 'desc'


    /* -----------------------------------------------------
       TOAST NOTIFICATION
       Shows a floating message at the bottom-right corner
       - type: "success" (green) or "error" (red)
       ----------------------------------------------------- */
    function showToast(message, type) {
        // Remove any existing toast first
        var existing = document.getElementById('toastNotif');
        if (existing) existing.remove();

        var toast     = document.createElement('div');
        toast.id      = 'toastNotif';
        var isSuccess = type !== 'error';

        toast.style.cssText = [
            'position:fixed',
            'bottom:30px',
            'right:30px',
            'background:' + (isSuccess
                ? 'linear-gradient(135deg,#2f9e44,#20c997)'
                : 'linear-gradient(135deg,#dc3545,#c82333)'),
            'color:white',
            'padding:16px 24px',
            'border-radius:14px',
            'font-size:15px',
            'font-weight:700',
            'display:flex',
            'align-items:center',
            'gap:10px',
            'box-shadow:0 8px 25px rgba(0,0,0,0.25)',
            'z-index:9999',
            'animation:toastIn 0.4s ease',
            'max-width:320px'
        ].join(';');

        var icon = isSuccess ? '✅' : '❌';
        toast.innerHTML = '<span style="font-size:20px">' + icon + '</span><span>' + message + '</span>';
        document.body.appendChild(toast);

        // Auto-dismiss after 3 seconds
        setTimeout(function () {
            toast.style.animation = 'toastOut 0.4s ease forwards';
            setTimeout(function () { toast.remove(); }, 400);
        }, 3000);
    }

    // Inject toast keyframes once
    if (!document.getElementById('toastStyles')) {
        var style       = document.createElement('style');
        style.id        = 'toastStyles';
        style.textContent = `
            @keyframes toastIn {
                from { opacity: 0; transform: translateY(20px); }
                to   { opacity: 1; transform: translateY(0); }
            }
            @keyframes toastOut {
                from { opacity: 1; transform: translateY(0); }
                to   { opacity: 0; transform: translateY(20px); }
            }
        `;
        document.head.appendChild(style);
    }

    function openConsultationForm() {
        // Reset form
        document.getElementById('recordForm').reset();
        
        // Show Modal and Overlay
        document.getElementById('consultationFormContainer').classList.add('modal-show');
        document.getElementById('formOverlay').classList.add('modal-show');
    }

    /* placeholder – real closeForm is defined below with prepareAdd */


    /* -----------------------------------------------------
       MEDICINE ROWS: ADD
       Appends a new medicine dropdown + qty row
       ----------------------------------------------------- */
    function addMedRow() {
        var container = document.getElementById('medContainer');
        var div       = document.createElement('div');
        div.className = 'med-row';

        // Build medicine options from PHP data
        var options = '<option value="">-- Select Medicine --</option>';
        medsData.forEach(function (m) {
            options += '<option value="' + m.medicine_id + '" data-name="' + m.medicine_name + '">'
                     + m.medicine_name + ' (' + m.quantity + ' left)</option>';
        });

        div.innerHTML =
            '<select name="med_id[]">' + options + '</select>' +
            '<input type="number" name="med_qty[]" placeholder="Qty" min="1">' +
            '<button type="button" class="btn btn-red" style="padding:8px 12px;" onclick="this.parentElement.remove()">×</button>';

        container.appendChild(div);
    }


    /* -----------------------------------------------------
       MEDICINE ROWS: APPLY TO TREATMENT
       Reads selected medicines and fills the treatment field
       ----------------------------------------------------- */
    function applyToTreatment() {
        var rows = document.querySelectorAll('.med-row');
        var treatmentField = document.getElementById('treatment');
        var items = [];

        rows.forEach(function (row) {
            var sel = row.querySelector('select');
            var qty = row.querySelector('input').value;
            if (sel.value && qty) {
                var name = sel.options[sel.selectedIndex].getAttribute('data-name');
                items.push(name + " (" + qty + " pcs)");
            }
        });

        if (items.length > 0) {
            var medicineText = "Medications: " + items.join(", ");
            // Append medicine to the end of the text already present
            treatmentField.value += (treatmentField.value ? "\n\n" : "") + medicineText;
            showToast("Medicines added to treatment!", "success");
        } else {
            showToast("Select a medicine and quantity first.", "error");
        }
    }


    /* -----------------------------------------------------
       FORM: OPEN FOR NEW CONSULTATION
       ----------------------------------------------------- */
    function prepareAdd() {
        // Reset text inputs
        document.getElementById('recordForm').reset();
        // Reset patient search
        document.getElementById('patient_search_input').value = '';
        document.getElementById('patient_id').value = '';
        document.getElementById('patientDropdown').style.display = 'none';
        document.getElementById('patientSelectedName').style.display = 'none';

        // Clear dispense medicine container
        document.getElementById('medContainer').innerHTML = ''; 

        // Add an empty row for visuals
        addMedRow(); 

        // Show modal and overlay
        document.getElementById('consultationFormContainer').style.display = 'block';
        document.getElementById('formOverlay').style.display = 'block';
        document.getElementById('formTitle').innerText = 'NEW CONSULTATION RECORD';
    }

    function closeForm() {
        // Hide containers
        document.getElementById('consultationFormContainer').style.display = 'none';
        document.getElementById('formOverlay').style.display = 'none';
        
        // Reset form for next use
        document.getElementById('recordForm').reset();
        // Reset patient search
        document.getElementById('patient_search_input').value = '';
        document.getElementById('patient_id').value = '';
        document.getElementById('patientDropdown').style.display = 'none';
        document.getElementById('patientSelectedName').style.display = 'none';
    }

    function prepareEdit(data) {
        // Ensure form is clean
        document.getElementById('recordForm').reset();
        
        // Populate inputs
        document.getElementById('consultation_id').value = data.consultation_id;
        document.getElementById('patient_id').value      = data.patient_id;
        document.getElementById('visit_date').value      = data.visit_date;
        document.getElementById('symptoms').value        = data.symptoms;
        document.getElementById('diagnosis').value       = data.diagnosis;
        document.getElementById('treatment').value       = data.treatment;

        // Populate patient search field
        document.getElementById('patient_search_input').value = data.full_name;
        document.getElementById('patientSelectedLabel').innerText = data.full_name;
        document.getElementById('patientSelectedName').style.display = 'block';
        document.getElementById('patientDropdown').style.display = 'none';
        
        // Change title header
        document.getElementById('formTitle').innerText   = 'EDIT CONSULTATION RECORD';

        // Show Modal and Overlay
        document.getElementById('consultationFormContainer').style.display = 'block';
        document.getElementById('formOverlay').style.display = 'block';
    }


    /* -----------------------------------------------------
       FORM: SUBMIT (ADD or UPDATE)
       Sends data via AJAX and shows toast on result
       ----------------------------------------------------- */
    document.getElementById('recordForm').onsubmit = function (e) {
        e.preventDefault();

        var isUpdate = document.getElementById('consultation_id').value !== '';
        var formData = new FormData(this);
        formData.append('ajax_save_record', '1');

        fetch('consultations.php', { method: 'POST', body: formData })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.status === 'success') {
                    closeForm();
                    showToast(
                        isUpdate ? 'Record updated successfully!' : 'Record saved successfully!',
                        'success'
                    );
                    setTimeout(function () { loadPage('consultations.php'); }, 1200);
                } else {
                    showToast('Error: ' + data.msg, 'error');
                }
            })
            .catch(function (err) {
                console.error('Fetch error:', err);
                showToast('An unexpected error occurred.', 'error');
            });
    };


    /* -----------------------------------------------------
       SEARCH: FILTER TABLE ROWS
       Matches against all visible consultation columns.
       ----------------------------------------------------- */
    function searchRecords() {
        var input          = document.getElementById('recordSearch');
        var filter         = input.value.toLowerCase().trim();
        var rows           = document.querySelectorAll('#recordTable tbody tr');
        var clearBtn       = document.getElementById('clearSearch');
        var noResults      = document.getElementById('noResults');
        var noResultsQuery = document.getElementById('noResultsQuery');

        clearBtn.style.display = filter.length > 0 ? 'inline' : 'none';

        rows.forEach(function (row) {
            var rowText = Array.from(row.cells).map(function (cell) {
                return cell.innerText.toLowerCase();
            }).join(' ');
            var matches = filter === '' || rowText.includes(filter);

            row.dataset.hidden    = matches ? 'false' : 'true';
            row.style.display     = matches ? '' : 'none';
        });

        var matchCount = getVisibleRows().length;

        if (matchCount === 0 && filter.length > 0) {
            noResultsQuery.innerText = '"' + input.value.trim() + '"';
            noResults.style.display  = 'flex';
        } else {
            noResults.style.display  = 'none';
        }

        currentPage = 1;
        applyPagination();
    }

    /* -----------------------------------------------------
       SEARCH: CLEAR INPUT AND RESET TABLE
       ----------------------------------------------------- */
    function clearSearch() {
        document.getElementById('recordSearch').value = '';
        searchRecords();
    }


    /* -----------------------------------------------------
       PAGINATION: GET VISIBLE ROWS
       Returns rows not hidden by the search filter
       ----------------------------------------------------- */
    function getVisibleRows() {
        return Array.from(document.querySelectorAll('#recordTable tbody tr')).filter(function (row) {
            return row.dataset.hidden !== 'true';
        });
    }


    /* -----------------------------------------------------
       PAGINATION: APPLY
       Shows only the rows for the current page
       ----------------------------------------------------- */
    function applyPagination() {
        var rows       = getVisibleRows();  // only non-hidden rows
        var total      = rows.length;
        var totalPages = Math.ceil(total / rowsPerPage) || 1;

        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1)          currentPage = 1;

        var start = (currentPage - 1) * rowsPerPage;
        var end   = start + rowsPerPage;

        // First hide ALL rows, then show only the current page slice
        document.querySelectorAll('#recordTable tbody tr').forEach(function (row) {
            if (row.dataset.hidden === 'true') {
                row.style.display = 'none'; // keep search-hidden rows hidden
            }
        });

        rows.forEach(function (row, index) {
            row.style.display = (index >= start && index < end) ? '' : 'none';
        });

        var countEl = document.getElementById('rowCount');
        if (countEl) {
            countEl.innerHTML =
                'Showing <strong>' + (total === 0 ? 0 : start + 1) + '–' + Math.min(end, total) + '</strong>' +
                ' of <strong>' + total + '</strong> record' + (total !== 1 ? 's' : '');
        }

        renderPagination(totalPages);
    }


    /* -----------------------------------------------------
       PAGINATION: RENDER BUTTONS
       Builds prev, numbered, and next page buttons
       ----------------------------------------------------- */
    function renderPagination(totalPages) {
        var wrapper = document.getElementById('paginationWrapper');
        if (!wrapper) return;
        wrapper.innerHTML = '';

        // Previous button
        var prev      = document.createElement('button');
        prev.className = 'page-btn';
        prev.innerHTML = '<i class="fa-solid fa-chevron-left"></i>';
        prev.disabled  = currentPage === 1;
        prev.onclick   = function () { currentPage--; applyPagination(); };
        wrapper.appendChild(prev);

        // Page number buttons (max 5 visible at a time)
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

        // Next button
        var next      = document.createElement('button');
        next.className = 'page-btn';
        next.innerHTML = '<i class="fa-solid fa-chevron-right"></i>';
        next.disabled  = currentPage === totalPages;
        next.onclick   = function () { currentPage++; applyPagination(); };
        wrapper.appendChild(next);
    }


    /* -----------------------------------------------------
       PAGINATION: CHANGE ROWS PER PAGE
       ----------------------------------------------------- */
    function changeRowsPerPage() {
        rowsPerPage = parseInt(document.getElementById('rowsPerPage').value);
        currentPage = 1;
        applyPagination();
    }


    /* -----------------------------------------------------
       SORT: SORT TABLE BY COLUMN
       Clicking a sortable header sorts that column
       ----------------------------------------------------- */
    function sortTable(colIndex) {
        var tbody = document.querySelector('#recordTable tbody');
        var rows  = Array.from(tbody.querySelectorAll('tr'));

        // Toggle direction if same column clicked, else reset to asc
        if (sortColIndex === colIndex) {
            sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            sortColIndex  = colIndex;
            sortDirection = 'asc';
        }

        rows.sort(function (a, b) {
            var aText = a.cells[colIndex] ? a.cells[colIndex].innerText.trim().toLowerCase() : '';
            var bText = b.cells[colIndex] ? b.cells[colIndex].innerText.trim().toLowerCase() : '';
            return sortDirection === 'asc'
                ? aText.localeCompare(bText)
                : bText.localeCompare(aText);
        });

        rows.forEach(function (row) { tbody.appendChild(row); });

        // Update sort arrow icons on headers
        document.querySelectorAll('th.sortable').forEach(function (th) {
            var icon = th.querySelector('.sort-icon');
            if (!icon) return;
            var col = parseInt(th.dataset.col);
            if (col === sortColIndex) {
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


    /* -----------------------------------------------------
       DELETE MODAL: OPEN
       Sets the target ID and shows the modal
       ----------------------------------------------------- */
    function confirmDelete(id, name) {
        currentDeleteId = id;
        currentDeleteIds = [];
        var modal       = document.getElementById('deleteModal');
        var nameEl      = document.getElementById('delName');
        if (modal && nameEl) {
            nameEl.innerText    = name;
            modal.style.display = 'flex';
        }
    }

    function getSelectedRecordIds() {
        return Array.from(document.querySelectorAll('.record-select:checked'))
            .map(function (box) { return box.value; });
    }

    function toggleSelectAllRecords() {
        var boxes = getVisibleRows()
            .map(function (row) { return row.querySelector('.record-select'); })
            .filter(Boolean);

        if (boxes.length === 0) {
            showToast('No records to select.', 'error');
            return;
        }

        var shouldCheck = boxes.some(function (box) { return !box.checked; });
        boxes.forEach(function (box) { box.checked = shouldCheck; });
    }

    function confirmDeleteSelectedRecords() {
        var ids = getSelectedRecordIds();
        if (ids.length === 0) {
            showToast('Please select records first.', 'error');
            return;
        }

        currentDeleteId = null;
        currentDeleteIds = ids;
        var modal  = document.getElementById('deleteModal');
        var nameEl = document.getElementById('delName');
        if (modal && nameEl) {
            nameEl.innerText = ids.length + ' selected record' + (ids.length > 1 ? 's' : '');
            modal.style.display = 'flex';
        }
    }


    /* -----------------------------------------------------
       DELETE MODAL: CLOSE
       ----------------------------------------------------- */
    function closeModal() {
        var modal = document.getElementById('deleteModal');
        if (modal) modal.style.display = 'none';
        currentDeleteId = null;
        currentDeleteIds = [];
    }


    /* -----------------------------------------------------
       DELETE: EXECUTE
       Sends delete request via AJAX and shows toast
       ----------------------------------------------------- */
    function executeDelete() {
        if (!currentDeleteId && currentDeleteIds.length === 0) return;

        var deletedId = currentDeleteId;
        var deletedIds = currentDeleteIds.slice();

        var request = null;
        if (deletedIds.length > 0) {
            var formData = new FormData();
            formData.append('action', 'delete_selected');
            deletedIds.forEach(function (id) {
                formData.append('delete_ids[]', id);
            });
            request = fetch('consultations.php', { method: 'POST', body: formData });
        } else {
            request = fetch('consultations.php?delete_id=' + encodeURIComponent(deletedId));
        }

        request
            .then(function (res) { return res.json(); })
            .then(function (data) {
                closeModal();
                if (data.status === 'success') {
                    showToast(deletedIds.length > 0 ? 'Selected records deleted successfully!' : 'Record deleted successfully!', 'success');

                    if (deletedIds.length > 0) {
                        deletedIds.forEach(function (id) {
                            var selectedRow = document.querySelector('#recordTable tbody tr[data-id="' + id + '"]');
                            if (selectedRow) selectedRow.remove();
                        });
                    } else {
                        var row = document.querySelector('#recordTable tbody tr[data-id="' + deletedId + '"]');
                        if (row) row.remove();
                    }

                    if (typeof searchRecords === 'function') {
                        searchRecords();
                    } else if (typeof applyPagination === 'function') {
                        applyPagination();
                    }
                } else {
                    showToast('Failed to delete record.', 'error');
                }
            })
            .catch(function () {
                closeModal();
                showToast('Network error while deleting record.', 'error');
            });
    }


    /* -----------------------------------------------------
       INIT: Order current consultations to the top & Paginate
       ----------------------------------------------------- */
    (function initializeAndSortRecords() {
        var tbody = document.querySelector('#recordTable tbody');
        if (!tbody) return;

        var rows = Array.from(tbody.querySelectorAll('tr'));
        if (rows.length === 0) return;

        // 1. Get today's local date in clean ISO format (YYYY-MM-DD)
        var today = new Date();
        var yyyy = today.getFullYear();
        var mm = String(today.getMonth() + 1).padStart(2, '0');
        var dd = String(today.getDate()).padStart(2, '0');
        var todayStr = yyyy + '-' + mm + '-' + dd;

        // 2. Separate rows: Today's visits vs other historical records
        var todayRows = [];
        var otherRows = [];

        rows.forEach(function (row) {
            // Assumes visit_date is stored in a data attribute (e.g. data-date) or parse from column 0 (Visit Date)
            var rowDate = row.getAttribute('data-date') || (row.cells[0] ? row.cells[0].innerText.trim() : '');
            
            // Clean/format the row date to match comparison format if necessary
            var cleanRowDate = new Date(rowDate);
            var rY = cleanRowDate.getFullYear();
            var rM = String(cleanRowDate.getMonth() + 1).padStart(2, '0');
            var rD = String(cleanRowDate.getDate()).padStart(2, '0');
            var rowDateStr = rY + '-' + rM + '-' + rD;

            if (rowDateStr === todayStr) {
                todayRows.push(row);
            } else {
                otherRows.push(row);
            }
        });

        // 3. Sort today's consultations chronologically (latest entries first)
        todayRows.sort(function (a, b) {
            return b.getAttribute('data-id') - a.getAttribute('data-id');
        });

        // 4. Sort other historical rows chronologically (descending to show newest-past first)
        otherRows.sort(function (a, b) {
            var dateA = new Date(a.getAttribute('data-date') || a.cells[0].innerText.trim());
            var dateB = new Date(b.getAttribute('data-date') || b.cells[0].innerText.trim());
            return dateB - dateA;
        });

        // 5. Append sorted rows to DOM (Today's first, followed by others)
        tbody.innerHTML = '';
        todayRows.forEach(function (row) { tbody.appendChild(row); });
        otherRows.forEach(function (row) { tbody.appendChild(row); });

        // 6. Run pagination layout
        applyPagination();
    })();

</script>
