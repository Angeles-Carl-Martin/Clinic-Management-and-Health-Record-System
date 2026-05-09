<?php
session_start();
require "db.php";

/* 1. SESSION REDIRECT */
if (isset($_SESSION['username'])) {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'patient') {
        header("Location: p_dashboard.php");
        exit();
    } elseif ($role === 'guest') {
        unset($_SESSION['username'], $_SESSION['role'], $_SESSION['patient_id']);
    } else {
        header("Location: dashboard.php");
        exit();
    }
}

$error = "";

/* ─── ATTEMPT LIMIT HELPER ─── */
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_SECONDS', 300);

function isLockedOut(string $key): bool {
    $data = $_SESSION['login_attempts'][$key] ?? null;
    if (!$data) return false;
    if ($data['count'] >= MAX_LOGIN_ATTEMPTS) {
        if (time() - $data['last_attempt'] < LOCKOUT_SECONDS) return true;
        unset($_SESSION['login_attempts'][$key]);
    }
    return false;
}

function recordFailedAttempt(string $key): void {
    if (!isset($_SESSION['login_attempts'][$key])) {
        $_SESSION['login_attempts'][$key] = ['count' => 0, 'last_attempt' => time()];
    }
    $_SESSION['login_attempts'][$key]['count']++;
    $_SESSION['login_attempts'][$key]['last_attempt'] = time();
}

function resetAttempts(string $key): void {
    unset($_SESSION['login_attempts'][$key]);
}

function remainingLockout(string $key): int {
    $data = $_SESSION['login_attempts'][$key] ?? null;
    if (!$data) return 0;
    return max(0, LOCKOUT_SECONDS - (time() - $data['last_attempt']));
}

/**
 * Smart password check — works for both plain text and hashed passwords.
 * If plain text is detected, it auto-upgrades the hash in the DB.
 */
function checkPassword(string $input, string $stored, mysqli $conn, string $table, string $id_col, int $id): bool {
    // Already hashed
    if (password_verify($input, $stored)) {
        return true;
    }
    // Plain text fallback
    if ($input === $stored) {
        // Auto-upgrade to hash
        $newHash = password_hash($input, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE {$table} SET password = ? WHERE {$id_col} = ?");
        $stmt->bind_param("si", $newHash, $id);
        $stmt->execute();
        return true;
    }
    return false;
}

/* 2. LOGIN LOGIC */
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $login_type     = $_POST['login_type'] ?? '';
    $username       = trim($_POST['username']);
    $password_input = trim($_POST['password']);

    if (empty($login_type)) {
        $error = "Please select a login type.";

    } else {

        /* ================= STAFF LOGIN ================= */
        if ($login_type === "staff") {
            $lockKey = 'staff_' . $username;

            if (isLockedOut($lockKey)) {
                $mins  = ceil(remainingLockout($lockKey) / 60);
                $error = "Too many failed attempts. Please try again in {$mins} minute(s).";
            } else {
                $stmt = $conn->prepare("SELECT * FROM staff WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();

                    if (checkPassword($password_input, $user['password'], $conn, 'staff', 'staff_id', $user['staff_id'])) {
                        resetAttempts($lockKey);
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['fullname']  = $user['full_name'];
                        $_SESSION['role']      = $user['role'];
                        $_SESSION['staff_id']  = $user['staff_id'];
                        header("Location: dashboard.php");
                        exit();
                    } else {
                        recordFailedAttempt($lockKey);
                        $left  = MAX_LOGIN_ATTEMPTS - ($_SESSION['login_attempts'][$lockKey]['count'] ?? 0);
                        $error = "Invalid Staff Credentials. " . ($left > 0 ? "{$left} attempt(s) remaining." : "Account temporarily locked.");
                    }
                } else {
                    recordFailedAttempt($lockKey);
                    $left  = MAX_LOGIN_ATTEMPTS - ($_SESSION['login_attempts'][$lockKey]['count'] ?? 0);
                    $error = "User not found in Staff records. " . ($left > 0 ? "{$left} attempt(s) remaining." : "Account temporarily locked.");
                }
            }
        }

        /* ================= PATIENT LOGIN ================= */
        if ($login_type === "patient") {
            $lockKey = 'patient_' . $username;

            if (isLockedOut($lockKey)) {
                $mins  = ceil(remainingLockout($lockKey) / 60);
                $error = "Too many failed attempts. Please try again in {$mins} minute(s).";
            } else {
                $stmt = $conn->prepare("SELECT * FROM patients WHERE username = ? AND username IS NOT NULL");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    $patient = $result->fetch_assoc();

                    if (!empty($patient['password']) && checkPassword($password_input, $patient['password'], $conn, 'patients', 'patient_id', $patient['patient_id'])) {
                        resetAttempts($lockKey);
                        $_SESSION['username']   = $patient['username'];
                        $_SESSION['fullname']   = $patient['full_name'];
                        $_SESSION['role']       = "patient";
                        $_SESSION['patient_id'] = $patient['patient_id'];
                        header("Location: p_dashboard.php");
                        exit();
                    } else {
                        recordFailedAttempt($lockKey);
                        $left  = MAX_LOGIN_ATTEMPTS - ($_SESSION['login_attempts'][$lockKey]['count'] ?? 0);
                        $error = "Invalid Patient Credentials. " . ($left > 0 ? "{$left} attempt(s) remaining." : "Account temporarily locked.");
                    }
                } else {
                    recordFailedAttempt($lockKey);
                    $left  = MAX_LOGIN_ATTEMPTS - ($_SESSION['login_attempts'][$lockKey]['count'] ?? 0);
                    $error = "Patient account not found or credentials not set up. Please contact the clinic or use Guest access.";
                }
            }
        }

        /* ================= GUEST ACCESS ================= */
        if ($login_type === "guest") {
            $lockKey = 'guest_' . $username;

            if (isLockedOut($lockKey)) {
                $mins  = ceil(remainingLockout($lockKey) / 60);
                $error = "Too many failed attempts. Please try again in {$mins} minute(s).";
            } else {
                $stmt = $conn->prepare("SELECT * FROM patients WHERE id_number = ? AND full_name = ?");
                $stmt->bind_param("ss", $username, $password_input);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    $patient = $result->fetch_assoc();
                    resetAttempts($lockKey);
                    $_SESSION['username']   = "Guest-" . $patient['id_number'];
                    $_SESSION['fullname']   = $patient['full_name'];
                    $_SESSION['role']       = "guest";
                    $_SESSION['patient_id'] = $patient['patient_id'];
                    header("Location: p_view_records.php");
                    exit();
                } else {
                    recordFailedAttempt($lockKey);
                    $left  = MAX_LOGIN_ATTEMPTS - ($_SESSION['login_attempts'][$lockKey]['count'] ?? 0);
                    $error = "No matching record found. Please check your ID and Name. " . ($left > 0 ? "{$left} attempt(s) remaining." : "Temporarily locked.");
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SPIST Clinic | Login Portal</title>
    <link rel="icon" type="image/png" href="SPISTLOGOPNG.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, rgba(0,102,51,0.5), rgba(0,166,81,0.5), rgba(0,102,51,0.5)) no-repeat,
                        url("clinic background.jpg") no-repeat center center fixed;
            background-size: 400% 400%, cover;
            animation: gradientShift 15s ease infinite;
            display: flex;
            flex-direction: column;
            height: 100vh;
        }

        @keyframes gradientShift {
            0%   { background-position: 0% 50%, center center; }
            50%  { background-position: 100% 50%, center center; }
            100% { background-position: 0% 50%, center center; }
        }

        .navbar {
            width: 100%;
            padding: 15px 40px;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(12px);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-sizing: border-box;
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .nav-brand img {
            width: 50px;
            height: auto;
            border-radius: 50%;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }

        .nav-brand h1 {
            font-size: 24px;
            margin: 0;
            letter-spacing: 1px;
            color: white;
            text-shadow: 0 1px 2px rgba(0,0,0,0.5), 0 0 10px rgba(0,166,81,0.3);
        }

        .main-content {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .login-card {
            background: rgba(255,255,255,0.96);
            padding: 40px;
            width: 400px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.5);
            text-align: center;
            animation: slideUp 0.7s ease-out;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(40px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        h2 {
            color: #006633;
            margin-bottom: 10px;
            font-weight: 700;
            font-size: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        h2 i { color: #00a651; font-size: 24px; }

        .subtitle {
            color: #666;
            font-size: 14px;
            margin-bottom: 25px;
            line-height: 1.5;
            font-style: italic;
            border-top: 1px solid #eee;
            padding-top: 15px;
        }

        .input-wrapper {
            position: relative;
            margin-bottom: 15px;
        }

        input, select {
            width: 100%;
            padding: 14px 15px;
            border-radius: 8px;
            border: 1px solid #ddd;
            font-size: 14px;
            box-sizing: border-box;
            transition: all 0.3s ease;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #00a651;
            box-shadow: 0 0 8px rgba(0,166,81,0.2);
        }

        .eye-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #777;
            border: none;
            background: none;
            font-size: 16px;
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            background: #006633;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            font-size: 16px;
            cursor: pointer;
            transition: 0.3s;
            margin-top: 10px;
        }

        .btn-login:hover {
            background: #004d26;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .error-msg {
            background: #fde8e8;
            color: #c81e1e;
            padding: 10px;
            border-radius: 6px;
            font-size: 13px;
            margin-bottom: 15px;
            border-left: 4px solid #c81e1e;
        }

        .footer-text {
            margin-top: 25px;
            font-size: 14px;
            color: #666;
            text-align: center;
        }

        .footer-text a {
            color: #006633;
            font-weight: bold;
            text-decoration: none;
            transition: color 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .footer-text a:hover {
            color: #004d26;
            text-decoration: underline;
        }

        #patientRecoveryLink a {
            color: #666;
            font-size: 13px;
            text-decoration: none;
            transition: 0.3s;
        }

        #patientRecoveryLink a:hover {
            color: #006633;
            text-decoration: underline;
        }

        /* ── Guest Terms Modal ── */
        .terms-overlay {
            display: none;
            position: fixed; inset: 0; z-index: 9999;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(4px);
            justify-content: center;
            align-items: center;
        }
        .terms-overlay.active { display: flex; }

        .terms-modal {
            background: #fff;
            border-radius: 16px;
            padding: 28px;
            width: 90%;
            max-width: 460px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.4);
        }

        .terms-modal h3 {
            color: #006633;
            font-size: 16px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .terms-scroll {
            max-height: 200px;
            overflow-y: auto;
            font-size: 12.5px;
            color: #444;
            line-height: 1.7;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 14px;
            background: #f9fffe;
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
            margin-bottom: 16px;
            cursor: pointer;
        }

        .terms-check-row input[type="checkbox"] {
            width: 17px;
            height: 17px;
            min-width: 17px;
            margin-top: 1px;
            accent-color: #006633;
            cursor: pointer;
        }

        .terms-btn-row { display: flex; gap: 10px; }

        .terms-btn-row button {
            flex: 1;
            padding: 12px;
            border-radius: 8px;
            font-weight: bold;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: 0.2s;
        }

        .btn-terms-cancel { background: #eee; color: #555; }
        .btn-terms-cancel:hover { background: #ddd; }
        .btn-terms-proceed { background: #006633; color: white; opacity: 0.5; }
        .btn-terms-proceed:not(:disabled) { opacity: 1; }
        .btn-terms-proceed:not(:disabled):hover { background: #004d26; }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="nav-brand">
        <img src="SPISTLOGOPNG.png" alt="SPIST Logo">
        <h1>SPIST CLINIC PORTAL</h1>
    </div>
</nav>

<div class="main-content">
    <div class="login-card">
        <h2><i class="fas fa-lock"></i> Clinic Login</h2>
        <p class="subtitle">Southern Philippines Institute of Science and Technology Management System</p>

        <?php if (!empty($error)): ?>
            <div class="error-msg">
                <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="loginForm">
            <div class="input-wrapper">
                <select name="login_type" id="login_type" required onchange="updatePlaceholders()">
                    <option value="" disabled selected>Select User Role</option>
                    <option value="staff">Staff (Admin / Nurse)</option>
                    <option value="patient">Patient (With Account)</option>
                    <option value="guest">Guest (Track Records Only)</option>
                </select>
            </div>

            <div class="input-wrapper">
                <input type="text" name="username" id="user_input" placeholder="Username" required>
            </div>

            <div class="input-wrapper" id="pass_container">
                <input type="password" name="password" id="password" placeholder="Password" required>
                <button type="button" class="eye-toggle" id="eyeBtn" onclick="togglePass()">
                    <i id="eyeIcon" class="fa-solid fa-eye"></i>
                </button>
            </div>

            <button type="submit" class="btn-login" id="submitBtn">SIGN IN</button>
        </form>

        <div id="patientRecoveryLink" style="margin-top: 15px; display: none;">
            <a href="forgot_password.php">
                <i class="fa-solid fa-unlock-keyhole"></i> Forgot Password?
            </a>
        </div>

        <div class="footer-text">
            Don't have a patient account? <a href="register.php"><i class="fas fa-user-plus"></i> Register Here</a>
        </div>
    </div>
</div>

<!-- Guest Terms Modal -->
<div class="terms-overlay" id="guestTermsOverlay">
    <div class="terms-modal">
        <h3><i class="fas fa-shield-alt"></i> Data Privacy & Non-Disclosure Agreement</h3>
        <div class="terms-scroll">
            By accessing your records through the Guest Track feature, you acknowledge and agree that:<br><br>
            1. <strong>Data Access:</strong> You are accessing your own personal health records stored in the SPIST Clinic system. This access is strictly for your personal review.<br><br>
            2. <strong>Confidentiality:</strong> The records displayed are confidential medical information. You must not share, photograph, or distribute this information to unauthorized parties.<br><br>
            3. <strong>Non-Disclosure:</strong> You agree not to disclose the health information of other patients or any information you may inadvertently access.<br><br>
            4. <strong>Authorized Use Only:</strong> This guest access is intended solely for the individual patient identified by the provided ID number and name. Unauthorized access attempts are prohibited.<br><br>
            5. <strong>Data Privacy Act:</strong> This system is compliant with applicable data privacy laws. Misuse of this access may result in legal consequences.
        </div>
        <label class="terms-check-row">
            <input type="checkbox" id="guestTermsCheck" onchange="toggleGuestProceed(this)">
            <span>I have read and agree to the <strong>Data Privacy &amp; Non-Disclosure Agreement</strong>.</span>
        </label>
        <div class="terms-btn-row">
            <button class="btn-terms-cancel" onclick="closeGuestTerms()">Cancel</button>
            <button class="btn-terms-proceed" id="guestProceedBtn" disabled onclick="submitGuestForm()">
                <i class="fas fa-check-circle"></i> Agree & Proceed
            </button>
        </div>
    </div>
</div>

<script>
    function togglePass() {
        const passField = document.getElementById("password");
        const icon = document.getElementById("eyeIcon");
        if (passField.type === "password") {
            passField.type = "text";
            icon.classList.replace("fa-eye", "fa-eye-slash");
        } else {
            passField.type = "password";
            icon.classList.replace("fa-eye-slash", "fa-eye");
        }
    }

    function updatePlaceholders() {
        const role       = document.getElementById("login_type").value;
        const userInput  = document.getElementById("user_input");
        const passInput  = document.getElementById("password");
        const eyeBtn     = document.getElementById("eyeBtn");
        const submitBtn  = document.getElementById("submitBtn");
        const recovery   = document.getElementById("patientRecoveryLink");

        if (role === 'guest') {
            userInput.placeholder = "Enter Patient ID (e.g. 00-0000-00)";
            passInput.placeholder = "Enter Your Full Name";
            passInput.type        = "text";
            eyeBtn.style.display  = "none";
            submitBtn.textContent = "VIEW MY RECORDS";
            recovery.style.display = "none";
        } else {
            userInput.placeholder = "Username";
            passInput.placeholder = "Password";
            passInput.type        = "password";
            eyeBtn.style.display  = "block";
            submitBtn.textContent = "SIGN IN";
            recovery.style.display = (role === 'patient') ? 'block' : 'none';
        }
    }


    function formatGuestIdInput(input) {
        var digits = input.value.replace(/\D/g, '').slice(0, 8);
        var formatted = digits;
        if (digits.length > 2) formatted = digits.slice(0, 2) + '-' + digits.slice(2);
        if (digits.length > 6) formatted = digits.slice(0, 2) + '-' + digits.slice(2, 6) + '-' + digits.slice(6);
        input.value = formatted;
    }

    document.getElementById('user_input').addEventListener('input', function() {
        if (document.getElementById('login_type').value === 'guest') {
            formatGuestIdInput(this);
        }
    });
    /* Guest Terms Logic */
    var _guestFormPending = false;

    document.getElementById('loginForm').addEventListener('submit', function(e) {
        const role = document.getElementById('login_type').value;
        if (role === 'guest' && !_guestFormPending) {
            e.preventDefault();
            document.getElementById('guestTermsCheck').checked = false;
            document.getElementById('guestProceedBtn').disabled = true;
            document.getElementById('guestTermsOverlay').classList.add('active');
        }
    });

    function closeGuestTerms() {
        document.getElementById('guestTermsOverlay').classList.remove('active');
        _guestFormPending = false;
    }

    function toggleGuestProceed(cb) {
        document.getElementById('guestProceedBtn').disabled = !cb.checked;
    }

    function submitGuestForm() {
        _guestFormPending = true;
        document.getElementById('guestTermsOverlay').classList.remove('active');
        document.getElementById('loginForm').submit();
    }

    document.getElementById('guestTermsOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeGuestTerms();
    });
</script>

</body>
</html>