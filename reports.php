<?php
/* ============================================================
 * reports.php — Clinic Analytics & Reports
 * ============================================================ */

ob_start();
session_start();
require "db.php";
require_staff_login();
redirect_direct_fragment_access();

/* Protect page — must be logged in as staff */
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

/** @var mysqli $conn */

/* ── Data aggregation ────────────────────────────────────── */
$daily_visits       = $conn->query("SELECT COUNT(*) AS t FROM consultations WHERE visit_date = CURDATE() AND status = 1")->fetch_assoc()['t'];
$monthly_visits     = $conn->query("SELECT COUNT(*) AS t FROM consultations WHERE MONTH(visit_date)=MONTH(CURDATE()) AND YEAR(visit_date)=YEAR(CURDATE()) AND status = 1")->fetch_assoc()['t'];
$total_patients     = $conn->query("SELECT COUNT(*) AS t FROM patients WHERE status = 1")->fetch_assoc()['t'];
$total_consults     = $conn->query("SELECT COUNT(*) AS t FROM consultations WHERE status = 1")->fetch_assoc()['t'];
$total_appointments = $conn->query("SELECT COUNT(*) AS t FROM appointments WHERE status1 = 1")->fetch_assoc()['t'];
$low_stock_count    = $conn->query("SELECT COUNT(*) AS t FROM medicines WHERE quantity <= 10 AND quantity > 0 AND status = 1")->fetch_assoc()['t'];
$out_of_stock_count = $conn->query("SELECT COUNT(*) AS t FROM medicines WHERE quantity <= 0 AND status = 1")->fetch_assoc()['t'];
$expiring_count     = $conn->query("SELECT COUNT(*) AS t FROM medicines WHERE DATEDIFF(expiration_date, CURDATE()) BETWEEN 0 AND 30 AND status = 1")->fetch_assoc()['t'];

$max_row       = $conn->query("
    SELECT SUM(d.quantity_given) AS t
    FROM medicine_dispense d
    JOIN medicines m ON d.medicine_id = m.medicine_id
    WHERE m.status = 1
    GROUP BY d.medicine_id
    ORDER BY t DESC LIMIT 1
")->fetch_assoc();
$max_dispensed = $max_row ? (int) $max_row['t'] : 1;

$low_stock_list     = $conn->query("SELECT medicine_name, quantity, unit FROM medicines WHERE quantity <= 10 AND status = 1 ORDER BY quantity ASC LIMIT 8");
$app_stats          = $conn->query("SELECT status, COUNT(*) AS count FROM appointments WHERE status1 = 1 GROUP BY status ORDER BY count DESC");
$patient_categories = $conn->query("SELECT category, COUNT(*) AS count FROM patients WHERE status = 1 GROUP BY category ORDER BY count DESC");

// BEFORE
$recent_consults = $conn->query("
    SELECT c.visit_date, p.full_name, c.diagnosis
    FROM   consultations c JOIN patients p ON c.patient_id = p.patient_id
    WHERE  c.status = 1 AND p.status = 1
    ORDER  BY c.visit_date DESC, c.consultation_id DESC LIMIT 7
");

$monthly_trend = $conn->query("
    SELECT DATE_FORMAT(visit_date,'%b') AS month_label,
           DATE_FORMAT(visit_date,'%Y-%m') AS month_sort,
           COUNT(*) AS total
    FROM   consultations
    WHERE  visit_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
      AND  status = 1
    GROUP  BY month_sort ORDER BY month_sort ASC
");

$trend_labels = []; $trend_data = [];
while ($t = $monthly_trend->fetch_assoc()) {
    $trend_labels[] = $t['month_label'];
    $trend_data[]   = (int) $t['total'];
}

$top_medicines = $conn->query("
    SELECT m.medicine_name, SUM(d.quantity_given) AS total_dispensed
    FROM   medicine_dispense d JOIN medicines m ON d.medicine_id = m.medicine_id
    WHERE  m.status = 1
    GROUP  BY d.medicine_id ORDER BY total_dispensed DESC LIMIT 8
");

// AFTER
$recent_consults = $conn->query("
    SELECT c.visit_date, p.full_name, c.diagnosis
    FROM   consultations c JOIN patients p ON c.patient_id = p.patient_id
    WHERE  c.status = 1 AND p.status = 1
    ORDER  BY c.visit_date DESC, c.consultation_id DESC LIMIT 7
");

$monthly_trend = $conn->query("
    SELECT DATE_FORMAT(visit_date,'%b') AS month_label,
           DATE_FORMAT(visit_date,'%Y-%m') AS month_sort,
           COUNT(*) AS total
    FROM   consultations
    WHERE  visit_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
      AND  status = 1
    GROUP  BY month_sort ORDER BY month_sort ASC
");

$top_medicines = $conn->query("
    SELECT m.medicine_name, SUM(d.quantity_given) AS total_dispensed
    FROM   medicine_dispense d JOIN medicines m ON d.medicine_id = m.medicine_id
    WHERE  m.status = 1
    GROUP  BY d.medicine_id ORDER BY total_dispensed DESC LIMIT 8
");
?>

<style>
/* ============================================================
 * REPORTS PAGE — Styles
 * ============================================================ */
:root {
    --green-dark:   #004d26;
    --green-mid:    #006633;
    --green-accent: #00a651;
    --green-light:  #20c997;
    --red:    #ef4444;
    --yellow: #f59e0b;
    --radius: 18px;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.07);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.10);
    --shadow-lg: 0 8px 28px rgba(0,0,0,0.14);
}

.reports-wrapper { animation: fadeUp 0.4s ease both; }

@keyframes fadeUp {
    from { opacity:0; transform:translateY(12px); }
    to   { opacity:1; transform:translateY(0); }
}

/* ── Top bar ─────────────────────────────────────────────── */
.top-bar {
    display:         flex;
    justify-content: space-between;
    align-items:     center;
    margin-bottom:   22px;
    padding:         18px 22px;
    background:      rgba(0,166,81,0.1);
    border-radius:   15px;
    border:          1px solid rgba(0,166,81,0.22);
    backdrop-filter: blur(6px);
    flex-wrap:       wrap;
    gap:             12px;
}

.top-bar h2 {
    margin:0; color:white; font-size:22px; font-weight:700;
    display:flex; align-items:center; gap:10px;
    text-shadow:0 1px 3px rgba(0,0,0,0.25);
}
.top-bar h2 i { color: var(--green-accent); }

.btn-print {
    padding:10px 20px; background:white; color:var(--green-dark);
    border:none; border-radius:10px; font-weight:700; font-size:14px;
    cursor:pointer; display:inline-flex; align-items:center; gap:8px;
    box-shadow:var(--shadow-md); transition:transform 0.2s, box-shadow 0.2s;
}
.btn-print:hover { transform:translateY(-2px); box-shadow:var(--shadow-lg); background:#f0faf4; }

/* ── Export section ──────────────────────────────────────── */
.export-section { margin-bottom:26px; }

.export-section-title {
    color:rgba(255,255,255,0.75); font-size:12px; font-weight:700;
    text-transform:uppercase; letter-spacing:0.08em;
    margin-bottom:12px; display:flex; align-items:center; gap:8px;
}
.export-section-title::after {
    content:''; flex:1; height:1px;
    background:rgba(255,255,255,0.12); margin-left:6px;
}

.export-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    /* All cards stretch to the same height */
    align-items: stretch;
}

/* ── Export card ─────────────────────────────────────────── */
.export-card {
    background:     white;
    border-radius:  16px;
    padding:        18px 16px 16px;
    cursor:         pointer;
    border:         none;
    text-align:     left;

    /* Flex column with space-between pushes action row to bottom */
    display:        flex;
    flex-direction: column;
    justify-content: space-between;
    gap:            10px;

    box-shadow:  var(--shadow-sm);
    transition:  transform 0.2s, box-shadow 0.2s;
    position:    relative;
    overflow:    hidden;
}

/* Colored top accent line */
.export-card::before {
    content:''; position:absolute; top:0; left:0; right:0;
    height:4px; border-radius:16px 16px 0 0;
}
.export-card.ec-patients::before    { background: linear-gradient(90deg,#00a651,#20c997); }
.export-card.ec-appointments::before { background: linear-gradient(90deg,#3b82f6,#60a5fa); }
.export-card.ec-records::before     { background: linear-gradient(90deg,#8b5cf6,#a78bfa); }
.export-card.ec-medicines::before   { background: linear-gradient(90deg,#f59e0b,#fbbf24); }

.export-card:hover  { transform:translateY(-3px); box-shadow:var(--shadow-lg); }
.export-card:active { transform:translateY(-1px); }

/* Icon bubble */
.export-card-icon {
    width:42px; height:42px; border-radius:12px;
    display:flex; align-items:center; justify-content:center;
    font-size:18px; flex-shrink:0;
}
.ec-patients  .export-card-icon { background:rgba(0,166,81,0.12);   color:#00a651; }
.ec-appointments .export-card-icon { background:rgba(59,130,246,0.12); color:#3b82f6; }
.ec-records   .export-card-icon { background:rgba(139,92,246,0.12); color:#8b5cf6; }
.ec-medicines .export-card-icon { background:rgba(245,158,11,0.12); color:#f59e0b; }

/* Text block */
.export-card-body { flex:1; }

.export-card-label {
    font-size:14px; font-weight:800; color:#1a1a2e; line-height:1.2;
    margin-bottom:4px;
}
.export-card-desc {
    font-size:11px; color:#9ca3af; font-weight:500; line-height:1.5;
}

/* ── Action row — always at the bottom ───────────────────── */
.export-card-action {
    display:         flex;
    align-items:     center;
    gap:             6px;
    font-size:       12px;
    font-weight:     700;
    padding-top:     10px;
    border-top:      1px solid #f3f4f6;
    margin-top:      auto;
    white-space:     nowrap;
}

.ec-patients  .export-card-action { color:#00a651; }
.ec-appointments .export-card-action { color:#3b82f6; }
.ec-records   .export-card-action { color:#8b5cf6; }
.ec-medicines .export-card-action { color:#f59e0b; }

/* Spinner for loading state */
.export-card .spinner {
    display:none; width:13px; height:13px;
    border:2px solid currentColor; border-top-color:transparent;
    border-radius:50%; animation:spin 0.7s linear infinite; flex-shrink:0;
}
@keyframes spin { to { transform:rotate(360deg); } }

.export-card.loading .spinner   { display:inline-block; }
.export-card.loading .ec-dl-icon { display:none; }
.export-card.loading .export-card-action span { opacity:0.6; }

/* ── Stat cards ──────────────────────────────────────────── */
.stat-grid {
    display:grid; grid-template-columns:repeat(4,1fr);
    gap:14px; margin-bottom:24px;
}

.stat-card {
    background:white; border-radius:18px; padding:22px 18px;
    display:flex; flex-direction:column; align-items:center; text-align:center;
    box-shadow:var(--shadow-md);
    border:1.5px solid rgba(0,166,81,0.15);
    border-top:4px solid var(--green-accent);
    position:relative; overflow:hidden;
    transition:transform 0.2s, box-shadow 0.2s;
}
.stat-card::after {
    content:''; position:absolute; bottom:-20px; right:-20px;
    width:80px; height:80px; border-radius:50%;
    background:rgba(0,166,81,0.05); pointer-events:none;
}
.stat-card:hover { transform:translateY(-4px); box-shadow:var(--shadow-lg); }

.stat-icon {
    width:48px; height:48px; border-radius:13px;
    display:flex; align-items:center; justify-content:center;
    margin-bottom:12px; background:rgba(0,166,81,0.1);
    font-size:20px; color:var(--green-accent);
}
.stat-label {
    font-size:11px; font-weight:700; text-transform:uppercase;
    letter-spacing:0.07em; color:#9ca3af; margin-bottom:6px;
}
.stat-value { font-size:34px; font-weight:800; color:#111827; line-height:1; }

/* ── Section headings ────────────────────────────────────── */
.section-heading {
    color:rgba(255,255,255,0.85); font-size:13px; font-weight:700;
    margin:8px 0 14px; display:flex; align-items:center; gap:8px;
    letter-spacing:0.05em; text-transform:uppercase;
}
.section-heading i { color:var(--green-accent); }
.section-heading::after {
    content:''; flex:1; height:1px;
    background:rgba(255,255,255,0.12); margin-left:6px;
}

/* ── Content grid ────────────────────────────────────────── */
.content-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px; }

/* ── Panel ───────────────────────────────────────────────── */
.panel { background:white; border-radius:var(--radius); padding:20px; color:#333; box-shadow:var(--shadow-md); }
.panel h4 {
    margin:0 0 14px; font-size:12px; font-weight:700; color:var(--green-dark);
    display:flex; align-items:center; gap:8px;
    padding-bottom:11px; border-bottom:2px solid #f0fdf4;
    text-transform:uppercase; letter-spacing:0.05em;
}
.panel h4 i { color:var(--green-accent); }

/* ── Report table ────────────────────────────────────────── */
.report-table { width:100%; border-collapse:collapse; }
.report-table th {
    background:linear-gradient(135deg,var(--green-dark),var(--green-mid));
    color:white; padding:10px 13px; font-size:11px; font-weight:600; text-align:left;
}
.report-table th:first-child { border-radius:8px 0 0 0; }
.report-table th:last-child  { border-radius:0 8px 0 0; }
.report-table td { padding:9px 13px; border-bottom:1px solid #f3f4f6; font-size:13px; vertical-align:middle; }
.report-table tbody tr:hover { background:#f0fdf4; transition:background 0.15s; }

/* ── Usage bars ──────────────────────────────────────────── */
.usage-bar-wrap { margin-bottom:10px; }
.usage-bar-label { display:flex; justify-content:space-between; font-size:12px; font-weight:600; color:#374151; margin-bottom:4px; }
.usage-bar-label span:last-child { color:var(--green-accent); }
.usage-bar-track { background:#ecfdf5; border-radius:50px; height:8px; overflow:hidden; }
.usage-bar-fill  { height:100%; border-radius:50px; background:linear-gradient(90deg,var(--green-accent),var(--green-light)); transition:width 0.7s cubic-bezier(0.4,0,0.2,1); }

/* ── Trend chart ─────────────────────────────────────────── */
.trend-chart { display:flex; align-items:flex-end; gap:8px; height:105px; padding:0 2px; }
.trend-bar-group { display:flex; flex-direction:column; align-items:center; flex:1; gap:5px; height:100%; justify-content:flex-end; }
.trend-bar-value { font-size:11px; font-weight:700; color:var(--green-dark); }
.trend-bar { width:100%; border-radius:5px 5px 0 0; background:linear-gradient(180deg,var(--green-light),var(--green-accent)); min-height:4px; box-shadow:0 2px 6px rgba(0,166,81,0.18); }
.trend-bar-month { font-size:10px; color:#9ca3af; font-weight:600; }

/* ── Pills ───────────────────────────────────────────────── */
.status-pill { padding:3px 10px; border-radius:50px; font-size:11px; font-weight:700; display:inline-block; text-transform:uppercase; letter-spacing:0.04em; }
.pill-Pending   { background:#fef9c3; color:#854d0e; border:1px solid #fde68a; }
.pill-Approved  { background:#cffafe; color:#164e63; border:1px solid #a5f3fc; }
.pill-Completed { background:#dcfce7; color:#14532d; border:1px solid #86efac; }
.pill-Cancelled { background:#fee2e2; color:#7f1d1d; border:1px solid #fca5a5; }
.pill-Postponed { background:#f3f4f6; color:#374151; border:1px solid #d1d5db; }

.category-pill { padding:3px 10px; border-radius:50px; font-size:11px; font-weight:700; display:inline-block; background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; text-transform:uppercase; letter-spacing:0.04em; }

.qty-zero { color:var(--red);    font-weight:700; }
.qty-low  { color:var(--yellow); font-weight:700; }

/* ── Empty state ─────────────────────────────────────────── */
.empty-state { text-align:center; padding:26px 16px; color:#9ca3af; font-size:13px; }
.empty-state i { font-size:24px; margin-bottom:7px; display:block; opacity:0.4; }

/* ── Toast ───────────────────────────────────────────────── */
#exportToast {
    position:fixed; bottom:30px; right:30px;
    background:linear-gradient(135deg,#2f9e44,#20c997);
    color:white; padding:14px 22px; border-radius:14px;
    font-size:14px; font-weight:700;
    display:none; align-items:center; gap:10px;
    box-shadow:0 8px 25px rgba(0,0,0,0.25); z-index:9999;
}
@keyframes toastIn  { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }
@keyframes toastOut { from{opacity:1;transform:translateY(0)}    to{opacity:0;transform:translateY(16px)} }

/* ── Print ───────────────────────────────────────────────── */
@media (max-width: 900px) {
    .reports-wrapper {
        width: 100%;
    }

    .top-bar {
        align-items: flex-start;
        padding: 14px;
    }

    .top-bar h2 {
        font-size: 18px;
        line-height: 1.25;
    }

    .btn-print,
    .export-card {
        width: 100%;
    }

    .export-grid,
    .stat-grid,
    .content-grid {
        grid-template-columns: 1fr;
    }

    .export-card {
        min-height: auto;
    }

    .panel {
        padding: 14px;
        overflow-x: auto;
    }

    .report-table {
        min-width: 420px;
    }

    .trend-chart {
        min-width: 360px;
    }

    #exportToast {
        left: 14px;
        right: 14px;
        bottom: 16px;
        justify-content: center;
    }
}

@media print {
    .sidebar,.navbar,.export-section,.btn-print { display:none !important; }
    body { background:white !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .main { padding:0 !important; margin:0 !important; width:100%; }
    .top-bar { background:#f0fdf4 !important; border:1px solid #ccc !important; backdrop-filter:none !important; }
    .top-bar h2 { color:#004d26 !important; }
    .section-heading { color:#004d26 !important; }
    .section-heading::after { background:#ccc !important; }
    .stat-grid { display:grid !important; grid-template-columns:repeat(4,1fr) !important; }
    .content-grid { display:grid !important; grid-template-columns:1fr 1fr !important; }
    .stat-card { background:white !important; border:1.5px solid rgba(0,166,81,0.2) !important; border-top:4px solid #00a651 !important; box-shadow:none !important; break-inside:avoid; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .stat-card::after { display:none !important; }
    .panel { background:white !important; border:1px solid #ddd !important; box-shadow:none !important; break-inside:avoid; }
    .panel h4 { color:#004d26 !important; border-bottom-color:#eee !important; }
    .report-table th { background:#004d26 !important; color:white !important; }
    .trend-bar { background:#00a651 !important; }
    .usage-bar-fill { background:#00a651 !important; }
    .usage-bar-track { background:#e8f5e8 !important; }
}
</style>


<div class="reports-wrapper">

    <!-- ── Top Bar ──────────────────────────────────────── -->
    <div class="top-bar">
        <h2><i class="fa-solid fa-chart-pie"></i> Clinic Analytics & Reports</h2>
        <button class="btn-print" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Print Dashboard
        </button>
    </div>


    <!-- ── Export Cards ─────────────────────────────────── -->
    <div class="export-section">
        <div class="export-section-title">
            <i class="fa-solid fa-download"></i> Export Data
        </div>

        <div class="export-grid">

            <!-- Patients -->
            <button class="export-card ec-patients"
                    onclick="downloadPDF('patients', this)">
                <div class="export-card-icon">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div class="export-card-body">
                    <div class="export-card-label">Patients</div>
                    <div class="export-card-desc">
                        ID number, full name, gender, age, category, contact &amp; address
                    </div>
                </div>
                <div class="export-card-action">
                    <i class="fa-solid fa-file-pdf ec-dl-icon"></i>
                    <span class="spinner"></span>
                    <span>Download PDF</span>
                    <i class="fa-solid fa-arrow-down ec-dl-icon"></i>
                </div>
            </button>

            <!-- Appointments -->
            <button class="export-card ec-appointments"
                    onclick="downloadPDF('appointments', this)">
                <div class="export-card-icon">
                    <i class="fa-solid fa-calendar-check"></i>
                </div>
                <div class="export-card-body">
                    <div class="export-card-label">Appointments</div>
                    <div class="export-card-desc">
                        Patient name, date, time, reason &amp; status with summary
                    </div>
                </div>
                <div class="export-card-action">
                    <i class="fa-solid fa-file-pdf ec-dl-icon"></i>
                    <span class="spinner"></span>
                    <span>Download PDF</span>
                    <i class="fa-solid fa-arrow-down ec-dl-icon"></i>
                </div>
            </button>

            <!-- Medical Records -->
            <button class="export-card ec-records"
                    onclick="downloadPDF('consultations', this)">
                <div class="export-card-icon">
                    <i class="fa-solid fa-file-medical"></i>
                </div>
                <div class="export-card-body">
                    <div class="export-card-label">Medical Records</div>
                    <div class="export-card-desc">
                        Visit date, symptoms, diagnosis, treatment &amp; notes
                    </div>
                </div>
                <div class="export-card-action">
                    <i class="fa-solid fa-file-pdf ec-dl-icon"></i>
                    <span class="spinner"></span>
                    <span>Download PDF</span>
                    <i class="fa-solid fa-arrow-down ec-dl-icon"></i>
                </div>
            </button>

            <!-- Medicines -->
            <button class="export-card ec-medicines"
                    onclick="downloadPDF('medicines', this)">
                <div class="export-card-icon">
                    <i class="fa-solid fa-pills"></i>
                </div>
                <div class="export-card-body">
                    <div class="export-card-label">Medicines</div>
                    <div class="export-card-desc">
                        Name, category, quantity, unit, expiry date &amp; stock status
                    </div>
                </div>
                <div class="export-card-action">
                    <i class="fa-solid fa-file-pdf ec-dl-icon"></i>
                    <span class="spinner"></span>
                    <span>Download PDF</span>
                    <i class="fa-solid fa-arrow-down ec-dl-icon"></i>
                </div>
            </button>

        </div>
    </div>


    <!-- ── Stat Cards ────────────────────────────────────── -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-calendar-day"></i></div>
            <div class="stat-label">Today's Visits</div>
            <div class="stat-value"><?= $daily_visits ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-calendar-check"></i></div>
            <div class="stat-label">Monthly Visits</div>
            <div class="stat-value"><?= $monthly_visits ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
            <div class="stat-label">Total Patients</div>
            <div class="stat-value"><?= $total_patients ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-notes-medical"></i></div>
            <div class="stat-label">Total Consultations</div>
            <div class="stat-value"><?= $total_consults ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-calendar-alt"></i></div>
            <div class="stat-label">Total Appointments</div>
            <div class="stat-value"><?= $total_appointments ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="stat-label">Low Stock Items</div>
            <div class="stat-value"><?= $low_stock_count ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-ban"></i></div>
            <div class="stat-label">Out of Stock</div>
            <div class="stat-value"><?= $out_of_stock_count ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div class="stat-label">Expiring Soon</div>
            <div class="stat-value"><?= $expiring_count ?></div>
        </div>
    </div>


    <!-- ── Trends ────────────────────────────────────────── -->
    <p class="section-heading"><i class="fa-solid fa-chart-line"></i> Trends & Activity</p>
    <div class="content-grid">

        <div class="panel">
            <h4><i class="fa-solid fa-chart-bar"></i> Monthly Visit Trend (Last 6 Months)</h4>
            <?php if (!empty($trend_data)): ?>
                <?php $max_trend = max($trend_data) ?: 1; ?>
                <div class="trend-chart">
                    <?php foreach ($trend_labels as $i => $label): ?>
                        <?php $pct = round(($trend_data[$i] / $max_trend) * 100); ?>
                        <div class="trend-bar-group">
                            <span class="trend-bar-value"><?= $trend_data[$i] ?></span>
                            <div class="trend-bar" style="height:<?= max($pct,4) ?>%;"></div>
                            <span class="trend-bar-month"><?= $label ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state"><i class="fa-solid fa-chart-bar"></i>No consultation data yet.</div>
            <?php endif; ?>
        </div>

        <div class="panel">
            <h4><i class="fa-solid fa-list-check"></i> Appointment Status Breakdown</h4>
            <?php if ($app_stats->num_rows > 0): ?>
                <table class="report-table">
                    <thead><tr><th>Status</th><th>Count</th></tr></thead>
                    <tbody>
                        <?php while ($row = $app_stats->fetch_assoc()): ?>
                        <tr>
                            <td><span class="status-pill pill-<?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                            <td><strong><?= $row['count'] ?></strong></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state"><i class="fa-solid fa-calendar-xmark"></i>No appointments found.</div>
            <?php endif; ?>
        </div>

    </div>


    <!-- ── Medicine & Inventory ───────────────────────────── -->
    <p class="section-heading"><i class="fa-solid fa-pills"></i> Medicine & Inventory</p>
    <div class="content-grid">

        <div class="panel">
            <h4><i class="fa-solid fa-prescription-bottle-medical"></i> Top Dispensed Medicines</h4>
            <?php if ($top_medicines && $top_medicines->num_rows > 0): ?>
                <?php while ($row = $top_medicines->fetch_assoc()):
                    $pct = $max_dispensed > 0 ? round(($row['total_dispensed'] / $max_dispensed) * 100) : 0; ?>
                    <div class="usage-bar-wrap">
                        <div class="usage-bar-label">
                            <span><?= htmlspecialchars($row['medicine_name']) ?></span>
                            <span><?= $row['total_dispensed'] ?> units</span>
                        </div>
                        <div class="usage-bar-track">
                            <div class="usage-bar-fill" style="width:<?= $pct ?>%;"></div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state"><i class="fa-solid fa-prescription-bottle"></i>No dispense data yet.</div>
            <?php endif; ?>
        </div>

        <div class="panel">
            <h4><i class="fa-solid fa-triangle-exclamation"></i> Low & Out-of-Stock Medicines</h4>
            <?php if ($low_stock_list->num_rows > 0): ?>
                <table class="report-table">
                    <thead><tr><th>Medicine</th><th>Remaining</th></tr></thead>
                    <tbody>
                        <?php while ($row = $low_stock_list->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['medicine_name']) ?></td>
                            <td>
                                <?php if ((int) $row['quantity'] <= 0): ?>
                                    <span class="qty-zero">Out of Stock</span>
                                <?php else: ?>
                                    <span class="qty-low"><?= $row['quantity'] ?> <?= htmlspecialchars($row['unit']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state"><i class="fa-solid fa-circle-check"></i>All medicines are well-stocked!</div>
            <?php endif; ?>
        </div>

    </div>


    <!-- ── Patients & Consultations ──────────────────────── -->
    <p class="section-heading"><i class="fa-solid fa-users"></i> Patients & Consultations</p>
    <div class="content-grid">

        <div class="panel">
            <h4><i class="fa-solid fa-tags"></i> Patient Category Breakdown</h4>
            <?php if ($patient_categories->num_rows > 0): ?>
                <table class="report-table">
                    <thead><tr><th>Category</th><th>Count</th></tr></thead>
                    <tbody>
                        <?php while ($row = $patient_categories->fetch_assoc()): ?>
                        <tr>
                            <td><span class="category-pill"><?= htmlspecialchars($row['category']) ?></span></td>
                            <td><strong><?= $row['count'] ?></strong></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state"><i class="fa-solid fa-users"></i>No patient data available.</div>
            <?php endif; ?>
        </div>

        <div class="panel">
            <h4><i class="fa-solid fa-clock-rotate-left"></i> Recent Consultations</h4>
            <?php if ($recent_consults->num_rows > 0): ?>
                <table class="report-table">
                    <thead><tr><th>Date</th><th>Patient</th><th>Diagnosis</th></tr></thead>
                    <tbody>
                        <?php while ($row = $recent_consults->fetch_assoc()): ?>
                        <tr>
                            <td style="white-space:nowrap;color:#9ca3af;font-size:12px;"><?= date("M d, Y", strtotime($row['visit_date'])) ?></td>
                            <td><strong><?= htmlspecialchars($row['full_name']) ?></strong></td>
                            <td style="color:#6b7280;"><?= htmlspecialchars($row['diagnosis']) ?: '—' ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state"><i class="fa-solid fa-notes-medical"></i>No consultations recorded yet.</div>
            <?php endif; ?>
        </div>

    </div>

</div><!-- /.reports-wrapper -->


<!-- Toast -->
<div id="exportToast">
    <i class="fa-solid fa-circle-check"></i>
    <span id="exportToastMsg">Downloading…</span>
</div>


<script>
/* ============================================================
 * Export PDF
 * ============================================================ */
function showToast(msg) {
    var toast = document.getElementById('exportToast');
    document.getElementById('exportToastMsg').innerText = msg;
    toast.style.display   = 'flex';
    toast.style.animation = 'toastIn 0.4s ease';
    setTimeout(function () {
        toast.style.animation = 'toastOut 0.4s ease forwards';
        setTimeout(function () { toast.style.display = 'none'; }, 400);
    }, 3000);
}

function downloadPDF(type, cardEl) {
    if (cardEl.classList.contains('loading')) return;
    cardEl.classList.add('loading');

    var names = {
        patients:      'Patients',
        appointments:  'Appointments',
        consultations: 'Medical_Records',
        medicines:     'Medicines',
    };

    fetch('export.php?type=' + type)
        .then(function (res) {
            var ct = res.headers.get('Content-Type') || '';
            if (ct.includes('application/json')) {
                return res.json().then(function (j) {
                    throw new Error(j.error || 'PDF generation failed.');
                });
            }
            if (!res.ok) throw new Error('Server error ' + res.status);
            return res.blob();
        })
        .then(function (blob) {
            var url  = URL.createObjectURL(blob);
            var link = document.createElement('a');
            link.href     = url;
            link.download = 'Clinic_' + (names[type] || type) + '_'
                          + new Date().toISOString().slice(0,10) + '.pdf';
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
            showToast((names[type] || type).replace('_',' ') + ' PDF downloaded!');
        })
        .catch(function (err) { alert('Export failed: ' + err.message); })
        .finally(function () { cardEl.classList.remove('loading'); });
}
</script>
