<?php
/* ============================================================
 * export.php — Unified PDF Export Handler
 *
 * Types:
 *   patient_pdf   → Individual patient profile + consultations
 *   patients      → All patients directory
 *   appointments  → All appointments + status summary
 *   consultations → All medical records
 *   medicines     → Inventory with color-coded stock status
 * ============================================================ */

ob_start();
session_start();
require "db.php";
require_staff_login();

/* Protect — must be logged in */
if (!isset($_SESSION['username'])) {
    http_response_code(403);
    exit('Access denied.');
}

/** @var mysqli $conn */

// Set local timezone — Philippines Standard Time (UTC+8)
date_default_timezone_set('Asia/Manila');

$type = $_GET['type'] ?? '';

// ── Load TCPDF ────────────────────────────────────────────────
foreach ([
    __DIR__ . '/vendor/tecnickcom/tcpdf/tcpdf.php',
    __DIR__ . '/tcpdf/tcpdf.php',
    '/usr/share/php/tcpdf/tcpdf.php',
] as $path) {
    if (file_exists($path)) { require_once $path; break; }
}

if (!class_exists('TCPDF')) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['error' => 'TCPDF not found. Run: composer require tecnickcom/tcpdf']);
    exit();
}


/* ============================================================
 * SHARED HELPERS
 * ============================================================ */

/**
 * Create a configured TCPDF instance.
 * Landscape A4 = 297mm × 210mm usable area (minus 24mm margins) = 273mm wide
 * Portrait  A4 = 210mm × 297mm usable area (minus 30mm margins) = 180mm wide
 */
function makePDF(string $title, bool $landscape = true): TCPDF {
    $pdf = new TCPDF($landscape ? 'L' : 'P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Clinic System');
    $pdf->SetAuthor('Clinic Management System');
    $pdf->SetTitle($title);
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(true);
    $pdf->setFooterData([0, 77, 38], [0, 77, 38]);
    $pdf->setFooterFont(['helvetica', 'I', 7]);
    $pdf->setFooterMargin(8);
    return $pdf;
}

/** Usable page width (total width minus left+right margins). */
function pageW(TCPDF $pdf): float {
    $m = $pdf->getMargins();
    return $pdf->getPageWidth() - $m['left'] - $m['right'];
}

/** Left margin shorthand. */
function marginL(TCPDF $pdf): float {
    return $pdf->getMargins()['left'];
}

/** Dark-green banner header + subtitle. */
function drawPageHeader(TCPDF $pdf, string $subtitle): void {
    $w = pageW($pdf);
    $x = marginL($pdf);
    $m = $pdf->getMargins();

    $pdf->SetFillColor(0, 77, 38);
    $pdf->Rect($x, $m['top'], $w, 22, 'F');

    $pdf->SetFont('helvetica', 'B', 15);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY($x, $m['top'] + 2);
    $pdf->Cell($w, 8, 'CLINIC MANAGEMENT SYSTEM', 0, 1, 'C');

    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetXY($x, $m['top'] + 11);
    $pdf->Cell($w, 6, $subtitle, 0, 1, 'C');

    $pdf->Ln(5);
}

/** Mid-green labeled section bar. */
function drawSectionBar(TCPDF $pdf, string $label): void {
    $pdf->SetFillColor(0, 102, 51);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetX(marginL($pdf));
    $pdf->Cell(pageW($pdf), 7, '  ' . strtoupper($label), 0, 1, 'L', true);
    $pdf->Ln(2);
}

/** Dark-green table header row. */
function drawTableHeader(TCPDF $pdf, array $labels, array $widths, array $aligns = []): void {
    $pdf->SetFillColor(0, 77, 38);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 7.5);
    $pdf->SetX(marginL($pdf));
    foreach ($labels as $i => $lbl) {
        $align = $aligns[$i] ?? 'C';
        $pdf->Cell($widths[$i], 7, $lbl, 1, 0, $align, true);
    }
    $pdf->Ln();
}

/**
 * Draw a data row with alternating zebra bg and custom row bg override.
 * Handles automatic page break + header redraw via $redrawFn callback.
 *
 * @param array         $cells     Cell text values
 * @param array         $widths    Column widths (mm)
 * @param int           $rowIdx    0-based row index for zebra stripe
 * @param array         $labels    Column headers (for page-break redraw)
 * @param callable      $redrawFn  fn(TCPDF): void — redraws page header + table header
 * @param array|null    $bgOverride  Optional [r,g,b] to override zebra color
 * @param array         $aligns    Optional per-cell alignment
 */
function drawDataRow(
    TCPDF    $pdf,
    array    $cells,
    array    $widths,
    int      $rowIdx,
    array    $labels,
    callable $redrawFn,
    ?array   $bgOverride = null,
    array    $aligns = []
): void {
    $x = marginL($pdf);

    // Measure required row height
    $rowH = 6;
    foreach ($cells as $ci => $txt) {
        $lines = $pdf->getNumLines((string) $txt, $widths[$ci] - 2); // -2 for padding
        $rowH  = max($rowH, $lines * 5);
    }
    $rowH = max($rowH, 7); // minimum row height

    // Page break check
    if ($pdf->GetY() + $rowH > $pdf->getPageHeight() - 22) {
        $pdf->AddPage();
        $redrawFn($pdf);
        drawTableHeader($pdf, $labels, $widths, $aligns);
    }

    $bg = $bgOverride ?? ($rowIdx % 2 === 0 ? [255, 255, 255] : [245, 250, 245]);
    $pdf->SetFillColor(...$bg);
    $pdf->SetTextColor(40, 40, 40);
    $pdf->SetFont('helvetica', '', 8);

    $rowY = $pdf->GetY();
    $pdf->SetX($x);
    foreach ($cells as $ci => $txt) {
        $cx = $x + array_sum(array_slice($widths, 0, $ci));
        $pdf->SetXY($cx, $rowY);
        $align = $aligns[$ci] ?? 'L';
        $pdf->MultiCell($widths[$ci], 5, (string) $txt, 1, $align, true, 0);
    }
    $pdf->SetXY($x, $rowY + $rowH);
}

/** Summary count badge (light green full-width bar). */
function drawSummaryBadge(TCPDF $pdf, string $text): void {
    $pdf->SetFillColor(240, 253, 244);
    $pdf->SetDrawColor(187, 247, 208);
    $pdf->SetTextColor(0, 77, 38);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetX(marginL($pdf));
    $pdf->Cell(pageW($pdf), 9, $text, 1, 1, 'C', true);
    $pdf->Ln(3);
}

/** Generation timestamp footnote. */
function drawFooterNote(TCPDF $pdf): void {
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->SetTextColor(120, 130, 120);
    $pdf->SetX(marginL($pdf));
    $pdf->Cell(pageW($pdf), 5,
        'Generated on ' . date('F j, Y \a\t g:i A') . '  •  Clinic Management System',
        0, 1, 'C'
    );
}

/** Stream PDF as a browser download. */
function streamPDF(TCPDF $pdf, string $filename): void {
    ob_end_clean();
    $pdf->Output($filename, 'D');
    exit();
}


/* ============================================================
 * 1. INDIVIDUAL PATIENT PDF
 * ============================================================ */
if ($type === 'patient_pdf' && isset($_GET['patient_id'])) {
    $patient_id = (int) $_GET['patient_id'];

    $stmt = $conn->prepare("
        SELECT id_number, full_name, gender, birthdate,
               category, contact_number, address
        FROM   patients WHERE patient_id = ? AND status = 1
    ");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();

    if (!$patient) {
        ob_end_clean();
        http_response_code(404);
        echo json_encode(['error' => 'Patient not found.']);
        exit();
    }

    $cStmt = $conn->prepare("
        SELECT visit_date, symptoms, diagnosis, treatment, notes
        FROM   consultations
        WHERE  patient_id = ? AND status = 1
        ORDER  BY visit_date DESC
    ");
    $cStmt->bind_param("i", $patient_id);
    $cStmt->execute();
    $consultations = $cStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $age = 'N/A';
    if (!empty($patient['birthdate'])) {
        $age = (new DateTime($patient['birthdate']))->diff(new DateTime())->y . ' yrs old';
    }

    // Portrait for individual patient card
    $pdf = makePDF('Patient Profile — ' . $patient['full_name'], false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->AddPage();

    $pw = pageW($pdf);
    $mx = marginL($pdf);

    // Banner
    $pdf->SetFillColor(0, 77, 38);
    $pdf->Rect($mx, 15, $pw, 22, 'F');
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY($mx, 18);
    $pdf->Cell($pw, 7, 'CLINIC MANAGEMENT SYSTEM', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetXY($mx, 26);
    $pdf->Cell($pw, 5, 'Patient Profile & Consultation History', 0, 1, 'C');
    $pdf->Ln(5);

    // Patient info section
    drawSectionBar($pdf, 'Patient Information');

    $colW   = ($pw / 2) - 5;
    $startY = $pdf->GetY();
    $lineH  = 7;

    $fields = [
        ['Patient ID',     $patient['id_number']],
        ['Full Name',      $patient['full_name']],
        ['Gender',         $patient['gender']],
        ['Date of Birth',  $patient['birthdate'] ? date('F j, Y', strtotime($patient['birthdate'])) : 'N/A'],
        ['Age',            $age],
        ['Category',       $patient['category']],
        ['Contact Number', $patient['contact_number']],
    ];

    foreach ($fields as $i => $f) {
        $fx = $i % 2 === 0 ? $mx : $mx + $colW + 10;
        $fy = $startY + floor($i / 2) * $lineH;
        $pdf->SetXY($fx, $fy);
        $pdf->SetFont('helvetica', 'B', 7.5);
        $pdf->SetTextColor(100, 120, 100);
        $pdf->Cell(32, $lineH, strtoupper($f[0]) . ':', 0, 0);
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetTextColor(30, 50, 30);
        $pdf->Cell($colW - 32, $lineH, $f[1] ?? 'N/A', 0, 0);
    }

    $addrY = $startY + ceil(count($fields) / 2) * $lineH;
    $pdf->SetXY($mx, $addrY);
    $pdf->SetFont('helvetica', 'B', 7.5);
    $pdf->SetTextColor(100, 120, 100);
    $pdf->Cell(32, $lineH, 'ADDRESS:', 0, 0);
    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetTextColor(30, 50, 30);
    $pdf->MultiCell($pw - 32, $lineH, $patient['address'] ?? 'N/A', 0, 'L');

    $pdf->Ln(3);
    $pdf->SetDrawColor(187, 227, 187);
    $pdf->SetLineWidth(0.4);
    $pdf->Line($mx, $pdf->GetY(), $mx + $pw, $pdf->GetY());
    $pdf->Ln(4);

    // Consultations
    $total = count($consultations);
    drawSectionBar($pdf, 'Consultation History  (' . $total . ' record' . ($total !== 1 ? 's' : '') . ')');

    if (empty($consultations)) {
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->SetX($mx);
        $pdf->Cell($pw, 10, 'No consultation records found for this patient.', 0, 1, 'C');
    } else {
        // Landscape-friendly widths totaling ~180mm (portrait page width)
        $cW = [24, 34, 42, 42, 38];
        $cL = ['DATE', 'SYMPTOMS', 'DIAGNOSIS', 'TREATMENT', 'NOTES'];

        drawTableHeader($pdf, $cL, $cW);

        foreach ($consultations as $ri => $c) {
            $cells = [
                date('M d, Y', strtotime($c['visit_date'])),
                $c['symptoms']  ?? '',
                $c['diagnosis'] ?? '',
                $c['treatment'] ?? '',
                $c['notes']     ?? '',
            ];

            $rowH = 7;
            foreach ($cells as $ci => $txt) {
                $rowH = max($rowH, $pdf->getNumLines($txt, $cW[$ci] - 2) * 5);
            }

            if ($pdf->GetY() + $rowH > $pdf->getPageHeight() - 22) {
                $pdf->AddPage();
                drawSectionBar($pdf, 'Consultation History (continued)');
                drawTableHeader($pdf, $cL, $cW);
            }

            $bg   = $ri % 2 === 0 ? [255,255,255] : [245,250,245];
            $rowY = $pdf->GetY();
            $pdf->SetFillColor(...$bg);
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(40, 40, 40);
            $pdf->SetX($mx);
            foreach ($cells as $ci => $txt) {
                $cx = $mx + array_sum(array_slice($cW, 0, $ci));
                $pdf->SetXY($cx, $rowY);
                $pdf->MultiCell($cW[$ci], 5, $txt, 1, 'L', true, 0);
            }
            $pdf->SetXY($mx, $rowY + $rowH);
        }
    }

    drawFooterNote($pdf);
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $patient['full_name']);
    streamPDF($pdf, 'Patient_' . $safe . '_' . $patient['id_number'] . '.pdf');
}


/* ============================================================
 * 2. PATIENTS DIRECTORY PDF
 *    Landscape A4 → 273mm usable
 *    Columns: ID(24) + Name(52) + Gender(18) + Age(14) + Category(24) + Contact(32) + Address(109) = 273
 * ============================================================ */
if ($type === 'patients') {
    $result  = $conn->query("
        SELECT id_number, full_name, gender,
               IFNULL(TIMESTAMPDIFF(YEAR, birthdate, CURDATE()), 'N/A') AS age,
               category, contact_number, address
        FROM   patients WHERE status = 1
        ORDER  BY full_name ASC
    ");
    $allRows = $result->fetch_all(MYSQLI_ASSOC);
    $total   = count($allRows);

    $pdf      = makePDF('Patients Report');
    $subtitle = 'Patient Directory  —  ' . date('F j, Y');
    $pdf->AddPage();
    drawPageHeader($pdf, $subtitle);
    drawSummaryBadge($pdf, 'Total Active Patients: ' . $total);
    drawSectionBar($pdf, 'Patient List');

    // Widths must sum to exactly pageW = 273mm
    $widths = [24, 52, 18, 14, 24, 32, 109];
    $labels = ['ID NUMBER', 'FULL NAME', 'GENDER', 'AGE', 'CATEGORY', 'CONTACT', 'ADDRESS'];
    $aligns = ['L', 'L', 'C', 'C', 'C', 'L', 'L'];

    drawTableHeader($pdf, $labels, $widths, $aligns);

    $redrawFn = function (TCPDF $p) use ($subtitle): void {
        drawPageHeader($p, $subtitle . ' (continued)');
    };

    foreach ($allRows as $ri => $row) {
        drawDataRow($pdf, [
            $row['id_number'],
            $row['full_name'],
            $row['gender'],
            $row['age'] !== 'N/A' ? $row['age'] . ' yrs' : 'N/A',
            $row['category'],
            $row['contact_number'],
            $row['address'],
        ], $widths, $ri, $labels, $redrawFn, null, $aligns);
    }

    drawFooterNote($pdf);
    streamPDF($pdf, 'Patients_Report_' . date('Y-m-d') . '.pdf');
}


/* ============================================================
 * 3. APPOINTMENTS PDF
 *    Landscape A4 → 273mm usable
 *    Columns: ID(18) + Name(56) + Date(28) + Time(22) + Reason(115) + Status(34) = 273
 * ============================================================ */
if ($type === 'appointments') {
    $result  = $conn->query("
        SELECT a.appointment_id, p.full_name,
               a.appointment_date, a.appointment_time,
               a.reason, a.status
        FROM   appointments a
        JOIN   patients p ON a.patient_id = p.patient_id
        WHERE  (a.status1 = '1' OR a.status1 = 'Active') AND p.status = 1
        ORDER  BY a.appointment_date DESC
    ");
    $allRows = $result->fetch_all(MYSQLI_ASSOC);
    $total   = count($allRows);

    // Count by status
    $statusCounts = [];
    foreach ($allRows as $r) {
        $statusCounts[$r['status']] = ($statusCounts[$r['status']] ?? 0) + 1;
    }

    $pdf      = makePDF('Appointments Report');
    $subtitle = 'Appointments Report  —  ' . date('F j, Y');
    $pdf->AddPage();
    drawPageHeader($pdf, $subtitle);

    $pw = pageW($pdf);
    $mx = marginL($pdf);

    // ── Status breakdown section ──────────────────────────
    drawSectionBar($pdf, 'Status Summary');

    if (!empty($statusCounts)) {
        // Each pill: fixed 60mm wide, max 4 per row
        $pillW   = 60;
        $pillH   = 9;
        $perRow  = 4;
        $count   = 0;
        $pdf->SetX($mx);

        foreach ($statusCounts as $status => $cnt) {
            // Color per status
            $colors = [
                'Pending'   => [[254,243,199],[161,98,7]],
                'Approved'  => [[207,250,254],[22,78,99]],
                'Completed' => [[220,252,231],[20,83,45]],
                'Cancelled' => [[254,226,226],[127,29,29]],
                'Postponed' => [[243,244,246],[55,65,81]],
            ];
            [$bg, $fg] = $colors[$status] ?? [[240,253,244],[0,77,38]];

            $pdf->SetFillColor(...$bg);
            $pdf->SetDrawColor(...$fg);
            $pdf->SetTextColor(...$fg);
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->Cell($pillW, $pillH, $status . ':  ' . $cnt, 1, 0, 'C', true);

            $count++;
            if ($count % $perRow === 0) {
                $pdf->Ln();
                $pdf->SetX($mx);
            }
        }

        // End the row if last row wasn't full
        if ($count % $perRow !== 0) $pdf->Ln();
        $pdf->Ln(3);
    } else {
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->SetX($mx);
        $pdf->Cell($pw, 8, 'No appointment data available.', 0, 1, 'C');
        $pdf->Ln(2);
    }

    // ── Total badge ───────────────────────────────────────
    drawSummaryBadge($pdf, 'Total Appointments: ' . $total);
    drawSectionBar($pdf, 'Appointment List');

    // Columns: 18 + 56 + 28 + 22 + 115 + 34 = 273
    $widths = [18, 56, 28, 22, 115, 34];
    $labels = ['APPT ID', 'PATIENT NAME', 'DATE', 'TIME', 'REASON', 'STATUS'];
    $aligns = ['C', 'L', 'C', 'C', 'L', 'C'];

    drawTableHeader($pdf, $labels, $widths, $aligns);

    $redrawFn = function (TCPDF $p) use ($subtitle): void {
        drawPageHeader($p, $subtitle . ' (continued)');
    };

    foreach ($allRows as $ri => $row) {
        // DateTime::createFromFormat avoids timezone drift that strtotime()
        // causes when parsing a bare time string (e.g. "08:00:00") with no date.
        $rawTime   = $row['appointment_time'];
        $timeObj   = DateTime::createFromFormat('H:i:s', $rawTime)
                  ?: DateTime::createFromFormat('H:i',   $rawTime);
        $timeLabel = $timeObj ? $timeObj->format('g:i A') : $rawTime;

        drawDataRow($pdf, [
            $row['appointment_id'],
            $row['full_name'],
            date('M d, Y', strtotime($row['appointment_date'])),
            $timeLabel,
            $row['reason'],
            $row['status'],
        ], $widths, $ri, $labels, $redrawFn, null, $aligns);
    }

    drawFooterNote($pdf);
    streamPDF($pdf, 'Appointments_Report_' . date('Y-m-d') . '.pdf');
}


/* ============================================================
 * 4. MEDICAL RECORDS PDF
 *    Landscape A4 → 273mm usable
 *    Columns: ID(16) + Name(48) + Date(24) + Symptoms(50) + Diagnosis(50) + Treatment(50) + Notes(35) = 273
 * ============================================================ */
if ($type === 'consultations') {
    $result  = $conn->query("
        SELECT c.consultation_id, p.full_name,
               c.visit_date, c.symptoms,
               c.diagnosis, c.treatment, c.notes
        FROM   consultations c
        JOIN   patients p ON c.patient_id = p.patient_id
        WHERE  c.status = 1 AND p.status = 1
        ORDER  BY c.visit_date DESC
    ");
    $allRows = $result->fetch_all(MYSQLI_ASSOC);
    $total   = count($allRows);

    $pdf      = makePDF('Medical Records Report');
    $subtitle = 'Medical Records  —  ' . date('F j, Y');
    $pdf->AddPage();
    drawPageHeader($pdf, $subtitle);
    drawSummaryBadge($pdf, 'Total Consultation Records: ' . $total);
    drawSectionBar($pdf, 'Consultation Records');

    $widths = [16, 48, 24, 50, 50, 50, 35];
    $labels = ['REC ID', 'PATIENT NAME', 'VISIT DATE', 'SYMPTOMS', 'DIAGNOSIS', 'TREATMENT', 'NOTES'];
    $aligns = ['C', 'L', 'C', 'L', 'L', 'L', 'L'];

    drawTableHeader($pdf, $labels, $widths, $aligns);

    $redrawFn = function (TCPDF $p) use ($subtitle): void {
        drawPageHeader($p, $subtitle . ' (continued)');
    };

    foreach ($allRows as $ri => $row) {
        drawDataRow($pdf, [
            $row['consultation_id'],
            $row['full_name'],
            date('M d, Y', strtotime($row['visit_date'])),
            $row['symptoms']  ?? '',
            $row['diagnosis'] ?? '',
            $row['treatment'] ?? '',
            $row['notes']     ?? '',
        ], $widths, $ri, $labels, $redrawFn, null, $aligns);
    }

    drawFooterNote($pdf);
    streamPDF($pdf, 'Medical_Records_' . date('Y-m-d') . '.pdf');
}


/* ============================================================
 * 5. MEDICINES INVENTORY PDF
 *    Landscape A4 → 273mm usable
 *    Columns: ID(18) + Name(78) + Category(38) + Qty(18) + Unit(20) + Expiry(34) + Status(67) = 273
 * ============================================================ */
if ($type === 'medicines') {
    $result  = $conn->query("
        SELECT medicine_id, medicine_name, category,
               quantity, unit, expiration_date
        FROM   medicines
        WHERE  status = 1
        ORDER  BY medicine_name ASC
    ");
    $allRows = $result->fetch_all(MYSQLI_ASSOC);
    $total   = count($allRows);

    // Inventory counters
    $outOfStock   = 0;
    $lowStock     = 0;
    $expiringSoon = 0;
    $today        = new DateTime();

    foreach ($allRows as $r) {
        $qty = (int) $r['quantity'];
        if ($qty <= 0)      { $outOfStock++; continue; }
        if ($qty <= 10)     $lowStock++;
        if (!empty($r['expiration_date'])) {
            $diff = (int) $today->diff(new DateTime($r['expiration_date']))->days;
            if ($diff <= 30) $expiringSoon++;
        }
    }

    $pdf      = makePDF('Medicines Report');
    $subtitle = 'Medicines & Inventory Report  —  ' . date('F j, Y');
    $pdf->AddPage();
    drawPageHeader($pdf, $subtitle);

    $pw = pageW($pdf);
    $mx = marginL($pdf);

    // ── Inventory alert summary ───────────────────────────
    drawSectionBar($pdf, 'Inventory Summary');

    $boxW  = round($pw / 3);
    $boxH  = 16;
    $baseY = $pdf->GetY();

    $boxes = [
        ['label' => 'OUT OF STOCK',  'value' => (string)$outOfStock,   'bg' => [254,226,226], 'fg' => [153,27,27]],
        ['label' => 'LOW STOCK',     'value' => (string)$lowStock,     'bg' => [254,252,232], 'fg' => [133,77,14]],
        ['label' => 'EXPIRING SOON', 'value' => (string)$expiringSoon, 'bg' => [255,237,213], 'fg' => [154,52,18]],
    ];

    foreach ($boxes as $i => $box) {
        $bx = $mx + ($i * $boxW);
        // Draw colored rectangle
        $pdf->SetFillColor(...$box['bg']);
        $pdf->SetDrawColor(...$box['fg']);
        $pdf->Rect($bx, $baseY, $boxW, $boxH, 'DF');
        // Big number
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetTextColor(...$box['fg']);
        $pdf->SetXY($bx, $baseY + 1);
        $pdf->Cell($boxW, 8, $box['value'], 0, 0, 'C');
        // Label below number
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetXY($bx, $baseY + 9);
        $pdf->Cell($boxW, 5, $box['label'], 0, 0, 'C');
    }

    $pdf->SetXY($mx, $baseY + $boxH);
    $pdf->Ln(4);

    drawSummaryBadge($pdf, 'Total Medicine Items: ' . $total);
    drawSectionBar($pdf, 'Medicine Inventory');

    $widths = [18, 78, 38, 18, 20, 34, 67];
    $labels = ['MED ID', 'MEDICINE NAME', 'CATEGORY', 'QTY', 'UNIT', 'EXPIRY DATE', 'STATUS'];
    $aligns = ['C', 'L', 'L', 'C', 'C', 'C', 'C'];

    drawTableHeader($pdf, $labels, $widths, $aligns);

    $redrawFn = function (TCPDF $p) use ($subtitle): void {
        drawPageHeader($p, $subtitle . ' (continued)');
    };

    foreach ($allRows as $ri => $row) {
        $qty = (int) $row['quantity'];

        // Stock status
        if ($qty <= 0) {
            $stockStatus = 'Out of Stock';
            $bgColor     = [255, 235, 235];
        } elseif (!empty($row['expiration_date'])
            && (int) $today->diff(new DateTime($row['expiration_date']))->days <= 30) {
            $stockStatus = 'Expiring Soon';
            $bgColor     = [255, 245, 220];
        } elseif ($qty <= 10) {
            $stockStatus = 'Low Stock';
            $bgColor     = [255, 253, 220];
        } else {
            $stockStatus = 'In Stock';
            $bgColor     = null; // use zebra
        }

        $expLabel = !empty($row['expiration_date'])
            ? date('M d, Y', strtotime($row['expiration_date']))
            : 'N/A';

        drawDataRow($pdf, [
            $row['medicine_id'],
            $row['medicine_name'],
            $row['category'] ?? '',
            (string) $qty,
            $row['unit'],
            $expLabel,
            $stockStatus,
        ], $widths, $ri, $labels, $redrawFn, $bgColor, $aligns);
    }

    drawFooterNote($pdf);
    streamPDF($pdf, 'Medicines_Report_' . date('Y-m-d') . '.pdf');
}


/* ============================================================
 * FALLBACK
 * ============================================================ */
ob_end_clean();
http_response_code(400);
header('Content-Type: application/json');
echo json_encode(['error' => 'Invalid export type: ' . htmlspecialchars($type)]);
exit();
