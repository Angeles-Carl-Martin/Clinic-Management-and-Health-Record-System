<?php
session_start();
require "db.php";

$error = "";
$success = "";
$step = 1; 

/* ─── ATTEMPT LIMIT HELPER ─── */
define('FP_MAX_ATTEMPTS', 5);
define('FP_LOCKOUT_SECONDS', 300);

function fp_isLocked(string $key): bool {
    $data = $_SESSION['fp_attempts'][$key] ?? null;
    if (!$data) return false;
    if ($data['count'] >= FP_MAX_ATTEMPTS) {
        if (time() - $data['last'] < FP_LOCKOUT_SECONDS) return true;
        unset($_SESSION['fp_attempts'][$key]);
    }
    return false;
}
function fp_record(string $key): void {
    if (!isset($_SESSION['fp_attempts'][$key]))
        $_SESSION['fp_attempts'][$key] = ['count' => 0, 'last' => time()];
    $_SESSION['fp_attempts'][$key]['count']++;
    $_SESSION['fp_attempts'][$key]['last'] = time();
}
function fp_remaining(string $key): int {
    return max(0, FP_LOCKOUT_SECONDS - (time() - ($_SESSION['fp_attempts'][$key]['last'] ?? time())));
}
function fp_attemptsLeft(string $key): int {
    return max(0, FP_MAX_ATTEMPTS - ($_SESSION['fp_attempts'][$key]['count'] ?? 0));
}

/* ============================================================
 * PASSWORD VALIDATION HELPER
 * Returns an error string, or null if the password is valid.
 * ============================================================ */
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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // --- STEP 1: FIND USER ---
    if (isset($_POST['find_user'])) {
        $lockKey = 'fp_find';
        if (fp_isLocked($lockKey)) {
            $mins = ceil(fp_remaining($lockKey) / 60);
            $error = "Too many attempts. Please wait {$mins} minute(s) before trying again.";
            $step = 1;
        } else {
        $username = trim($_POST['username']);
        $stmt = $conn->prepare("SELECT username, sec_question1, sec_question2 FROM patients WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($user = $res->fetch_assoc()) {
            unset($_SESSION['fp_attempts']['fp_find']);
            $_SESSION['reset_patient'] = $user['username'];
            $_SESSION['q1'] = $user['sec_question1'];
            $_SESSION['q2'] = $user['sec_question2'];
            $step = 2;
        } else {
            fp_record($lockKey);
            $left = fp_attemptsLeft($lockKey);
            $error = "Patient username not found." . ($left > 0 ? " {$left} attempt(s) remaining." : " Temporarily locked.");
        }
        } // end lockout check
    }

    // --- STEP 3: VERIFY SECURITY QUESTIONS ---
    if (isset($_POST['verify_questions'])) {
        $lockKey = 'fp_questions_' . ($_SESSION['reset_patient'] ?? 'x');
        if (fp_isLocked($lockKey)) {
            $mins = ceil(fp_remaining($lockKey) / 60);
            $error = "Too many attempts. Please wait {$mins} minute(s).";
            $step = 3;
        } else {
        $ans1 = strtolower(trim($_POST['ans1']));
        $ans2 = strtolower(trim($_POST['ans2']));
        $username = $_SESSION['reset_patient'];

        $stmt = $conn->prepare("SELECT sec_answer1, sec_answer2 FROM patients WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        if ($row && password_verify($ans1, $row['sec_answer1']) && password_verify($ans2, $row['sec_answer2'])) {
            unset($_SESSION['fp_attempts'][$lockKey]);
            $step = 5;
        } else {
            fp_record($lockKey);
            $left = fp_attemptsLeft($lockKey);
            $error = "Incorrect answers to security questions." . ($left > 0 ? " {$left} attempt(s) remaining." : " Temporarily locked.");
            $step = 3;
        }
        } // end lockout check
    }

    // --- STEP 4: VERIFY RECOVERY CODE ---
    if (isset($_POST['verify_code'])) {
        $lockKey = 'fp_code_' . ($_SESSION['reset_patient'] ?? 'x');
        if (fp_isLocked($lockKey)) {
            $mins = ceil(fp_remaining($lockKey) / 60);
            $error = "Too many attempts. Please wait {$mins} minute(s).";
            $step = 4;
        } else {
        $code = strtoupper(str_replace('-', '', trim($_POST['recovery_code'])));
        $username = $_SESSION['reset_patient'];
        
        $stmt = $conn->prepare("SELECT recovery_code FROM patients WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res->fetch_assoc();

        $is_valid = false;
        if ($user && !empty($user['recovery_code'])) {
            $db_codes = json_decode($user['recovery_code'], true);

            if (is_array($db_codes)) {
                $key = array_search($code, $db_codes);
                if ($key !== false) {
                    $is_valid = true;
                    unset($db_codes[$key]);
                    $new_codes_json = json_encode(array_values($db_codes));
                    $update_stmt = $conn->prepare("UPDATE patients SET recovery_code = ? WHERE username = ?");
                    $update_stmt->bind_param("ss", $new_codes_json, $username);
                    $update_stmt->execute();
                }
            } else {
                if (strtoupper(trim($user['recovery_code'])) === $code) {
                    $is_valid = true;
                    $update_stmt = $conn->prepare("UPDATE patients SET recovery_code = NULL WHERE username = ?");
                    $update_stmt->bind_param("s", $username);
                    $update_stmt->execute();
                }
            }
        }

        if ($is_valid) {
            unset($_SESSION['fp_attempts'][$lockKey]);
            $step = 5; 
        } else {
            fp_record($lockKey);
            $left = fp_attemptsLeft($lockKey);
            $error = "Invalid or expired Recovery Code." . ($left > 0 ? " {$left} attempt(s) remaining." : " Temporarily locked.");
            $step = 4; 
        }
        } // end lockout check
    }

    // --- STEP 5: RESET PASSWORD ---
    if (isset($_POST['do_reset'])) {
        $lockKey = 'fp_reset_' . ($_SESSION['reset_patient'] ?? 'x');
        if (fp_isLocked($lockKey)) {
            $mins = ceil(fp_remaining($lockKey) / 60);
            $error = "Too many attempts. Please wait {$mins} minute(s).";
            $step = 5;
        } else {
        $pass = trim($_POST['password']);
        $conf_pass = trim($_POST['confirm_password']);
        $username = $_SESSION['reset_patient'];

        /* Validate password strength */
        $pass_error = validatePassword($pass);
        if ($pass_error) {
            $error = $pass_error;
            $step = 5;
        } elseif ($pass !== $conf_pass) {
            fp_record($lockKey);
            $error = "Passwords do not match.";
            $step = 5;
        } else {
            // Check if same as old password
            $checkStmt = $conn->prepare("SELECT password FROM patients WHERE username = ?");
            $checkStmt->bind_param("s", $username);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            $row = $result->fetch_assoc();

            if (password_verify($pass, $row['password'])) {
                fp_record($lockKey);
                $error = "New password cannot be the same as your old password.";
                $step = 5;
            } else {
                $new_pass = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE patients SET password = ? WHERE username = ?");
                $stmt->bind_param("ss", $new_pass, $username);
                
                if ($stmt->execute()) {
                    $success = "Password reset successful! <a href='login.php'>Login here</a>";
                    unset($_SESSION['reset_patient']);
                    unset($_SESSION['fp_attempts']);
                    $step = 0;
                } else { 
                    $error = "Failed to update password."; 
                }
            }
        }
        } // end lockout check
    }
}

// FIX: Set step from GET only if no POST processing
if ($_SERVER["REQUEST_METHOD"] != "POST" && isset($_GET['method'])) {
    if ($_GET['method'] == 'questions') $step = 3;
    if ($_GET['method'] == 'code') $step = 4;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Account Recovery | SPIST Clinic</title>
    <link rel="icon" type="image/png" href="SPISTLOGOPNG.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body {
            margin: 0; padding: 0; font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, rgba(0,102,51,0.5), rgba(0,166,81,0.5), rgba(0,102,51,0.5)) no-repeat,
                        url("clinic background.jpg") no-repeat center center fixed;
            background-size: 400% 400%, cover;
            animation: gradientShift 15s ease infinite;
            display: flex; justify-content: center; align-items: center; height: 100vh;
        }
        @keyframes gradientShift { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        
        .login-card {
            background: rgba(255, 255, 255, 0.96);
            padding: 40px; width: 400px; border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.5); text-align: center;
        }

        h2 { color: #006633; font-size: 28px; margin-bottom: 10px; display: flex; align-items: center; justify-content: center; gap: 10px; }
        .subtitle { color: #666; font-size: 13px; margin-bottom: 20px; border-top: 1px solid #eee; padding-top: 15px; font-style: italic; }
        
        input {
            width: 100%; padding: 14px; margin-bottom: 15px; border-radius: 8px;
            border: 1px solid #ddd; box-sizing: border-box; transition: 0.3s;
        }
        
        .pass-container { position: relative; width: 100%; }
        .pass-container i {
            position: absolute; right: 15px; top: 18px;
            cursor: pointer; color: #777;
        }

        .btn-primary {
            width: 100%; padding: 14px; background: #006633; color: white; border: none;
            border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.3s;
        }
        .btn-primary:hover { background: #004d26; transform: translateY(-2px); }
        
        .btn-outline {
            width: 100%; padding: 12px; background: transparent; color: #006633;
            border: 2px solid #006633; border-radius: 8px; font-weight: bold; margin-top: 10px; cursor: pointer;
        }

        .error-msg { background: #fde8e8; color: #c81e1e; padding: 12px; border-radius: 8px; font-size: 13px; margin-bottom: 15px; border-left: 4px solid #c81e1e; text-align: left; }
        .success-msg { background: #f0fdf4; color: #166534; padding: 12px; border-radius: 8px; font-size: 14px; margin-bottom: 15px; }
        
        /* ── Password Strength Meter ──────────────────────── */
        .strength-meter {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-bottom: 12px;
        }

        .strength-bars {
            flex: 1;
            display: flex;
            gap: 4px;
            height: 5px;
        }

        .strength-bar {
            flex: 1;
            border-radius: 3px;
            background: #e0e0e0;
            transition: background .3s ease;
        }

        .strength-bar.active-weak    { background: #e53935; }
        .strength-bar.active-fair    { background: #fb8c00; }
        .strength-bar.active-good    { background: #fdd835; }
        .strength-bar.active-strong  { background: #43a047; }
        .strength-bar.active-vstrong { background: #1b5e20; }

        .strength-label { font-size: 12px; font-weight: 700; color: #888; transition: color .3s; min-width: 80px; text-align: right; }

        /* ── Password Requirements Checklist ─────────────────── */
        .pass-requirements {
            background: #f8f9fa;
            border: 1.5px solid #e8e8e8;
            border-radius: 9px;
            padding: 12px 14px;
            margin-bottom: 12px;
        }

        .pass-requirements p {
            font-size: 11px;
            font-weight: 700;
            color: #555;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 8px;
        }

        .req-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12.5px;
            color: #888;
            margin-bottom: 4px;
            transition: color .25s;
        }

        .req-item:last-child { margin-bottom: 0; }

        .req-item i {
            font-size: 13px;
            width: 14px;
            text-align: center;
            color: #ccc;
            transition: color .25s;
        }

        .req-item.met       { color: #2e7d32; }
        .req-item.met i     { color: #43a047; }
        .req-item.unmet i   { color: #e53935; }
        
        .step-text { color: #444; font-size: 14px; margin-bottom: 15px; line-height: 1.4; }
        .footer-link { margin-top: 25px; font-size: 14px; }
        .footer-link a { color: #006633; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>

    <div class="login-card">
        <h2><i class="fas fa-shield-alt"></i> Recovery</h2>
        <p class="subtitle">Patient Account Security Portal</p>

        <?php if($error): ?><div class="error-msg"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div><?php endif; ?>
        <?php if($success): ?><div class="success-msg"><i class="fas fa-check-circle"></i> <?= $success ?></div><?php endif; ?>

        <?php if($step == 1): ?>
            <form method="POST" action="forgot_password.php">
                <p class="step-text">Enter your username to find your account.</p>
                <input type="text" name="username" placeholder="Patient Username" required autofocus>
                <button type="submit" name="find_user" class="btn-primary">CONTINUE</button>
            </form>

        <?php elseif($step == 2): ?>
            <p class="step-text">Verify identity for <strong><?= $_SESSION['reset_patient'] ?></strong></p>
            <a href="?method=questions" style="text-decoration:none;"><button type="button" class="btn-primary" style="width:100%;">SECURITY QUESTIONS</button></a>
            <a href="?method=code" style="text-decoration:none;"><button type="button" class="btn-outline" style="width: 100%;">USE RECOVERY CODE</button></a>

        <?php elseif($step == 3): ?>
            <form method="POST" action="forgot_password.php">
                <p class="step-text" style="text-align:left;"><strong>Q1:</strong> <?= $_SESSION['q1'] ?></p>
                <input type="text" name="ans1" placeholder="Answer 1" required>
                <p class="step-text" style="text-align:left;"><strong>Q2:</strong> <?= $_SESSION['q2'] ?></p>
                <input type="text" name="ans2" placeholder="Answer 2" required>
                <button type="submit" name="verify_questions" class="btn-primary">VERIFY ANSWERS</button>
            </form>

        <?php elseif($step == 4): ?>
            <form method="POST" action="forgot_password.php">
                <p class="step-text">Enter your 8-character recovery code.</p>
                <input type="text" name="recovery_code" placeholder="XXXX-XXXX" required maxlength="10">
                <button type="submit" name="verify_code" class="btn-primary">VERIFY CODE</button>
            </form>

        <?php elseif($step == 5): ?>
            <form method="POST" action="forgot_password.php" id="resetForm">
                <p class="step-text">Identity verified. Create a new password.</p>
                
                <!-- Password field with strength meter -->
                <div class="pass-container">
                    <input type="password" name="password" id="pass" placeholder="New Password" required oninput="checkStrength(this, 'fp_strength')">
                    <i class="fas fa-eye" onclick="togglePass('pass', this)"></i>
                </div>

                <!-- Strength Meter -->
                <div class="strength-meter" id="fp_strength" style="display:none;">
                    <div class="strength-bars">
                        <div class="strength-bar" id="fp_bar1"></div>
                        <div class="strength-bar" id="fp_bar2"></div>
                        <div class="strength-bar" id="fp_bar3"></div>
                        <div class="strength-bar" id="fp_bar4"></div>
                        <div class="strength-bar" id="fp_bar5"></div>
                    </div>
                    <span class="strength-label" id="fp_strength_label"></span>
                </div>

                <!-- Requirements Checklist -->
                <div class="pass-requirements" id="fp_requirements" style="display:none;">
                    <p><i class="fa-solid fa-shield-halved"></i> Password Requirements</p>
                    <div class="req-item" id="fp_req_length"><i class="fa-solid fa-circle-xmark"></i> Minimum 8 characters (12+ recommended)</div>
                    <div class="req-item" id="fp_req_upper"><i class="fa-solid fa-circle-xmark"></i> At least 1 uppercase letter (A–Z)</div>
                    <div class="req-item" id="fp_req_lower"><i class="fa-solid fa-circle-xmark"></i> At least 1 lowercase letter (a–z)</div>
                    <div class="req-item" id="fp_req_number"><i class="fa-solid fa-circle-xmark"></i> At least 1 number (0–9)</div>
                    <div class="req-item" id="fp_req_special"><i class="fa-solid fa-circle-xmark"></i> At least 1 special character (!@#$%^&*)</div>
                </div>

                <!-- Confirm Password -->
                <div class="pass-container">
                    <input type="password" name="confirm_password" id="conf_pass" placeholder="Confirm New Password" required>
                    <i class="fas fa-eye" onclick="togglePass('conf_pass', this)"></i>
                </div>

                <button type="submit" name="do_reset" class="btn-primary">RESET PASSWORD</button>
            </form>
        <?php endif; ?>
        
        <div class="footer-link">
            <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
        </div>
    </div>

    <script>
        function togglePass(inputId, icon) {
            const field = document.getElementById(inputId);
            if (field.type === "password") {
                field.type = "text";
                icon.classList.remove("fa-eye");
                icon.classList.add("fa-eye-slash");
            } else {
                field.type = "password";
                icon.classList.remove("fa-eye-slash");
                icon.classList.add("fa-eye");
            }
        }

        /* ── Password Strength Checker ──────────────────── */
        function checkStrength(input, meterId) {
            const password = input.value;
            const prefix   = meterId.replace('_strength', ''); // 'fp'

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

        /* ── Update a single requirement checklist item ── */
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
    </script>
</body>
</html>