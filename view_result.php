<?php
require_once 'includes/config.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Reload pages restored from the browser back-forward cache after logout/timeout.
header('Clear-Site-Data: "cache"');

$timeout_notice = isset($_GET['timeout']) && $_GET['timeout'] === '1';
$logout_notice = isset($_GET['logged_out']) && $_GET['logged_out'] === '1';
$roll_no_input = isset($_POST['roll_no']) ? sanitize_input($_POST['roll_no']) : (isset($_GET['roll_no']) ? sanitize_input($_GET['roll_no']) : '');
$error = '';
$step = 'search'; // 'search' | 'otp' | 'result'
$result_session_timeout = 120;
$student_name = '';
$display_email = '';
$all_results = []; // will hold grouped results
$otp_cooldown_seconds = 0;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['timeout'] ?? '') === '1') {
    invalidate_result_session($pdo);
    header('Location: index.php', true, 303);
    exit();
}

// Manual logout uses the same invalidation path as automatic expiry.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'result_logout' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    invalidate_result_session($pdo);
    header('Location: index.php', true, 303);
    exit();
}

// If session expired, clear OTP flags
if (isset($_SESSION['otp_verified_at']) &&
    (time() - (int) $_SESSION['otp_verified_at']) >= $result_session_timeout) {
    invalidate_result_session($pdo);
}

// If already verified via session, skip OTP
if (isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'] === true &&
    isset($_SESSION['otp_verified_roll'])) {
    $roll_no = $_SESSION['otp_verified_roll'];
    $step = 'result';
    // Fetch all results for this roll number
    $stmt = $pdo->prepare("
        SELECT sr.*, e.exam_name, e.batch, e.session, e.publish_date, e.id as exam_id
        FROM student_results sr
        JOIN exams e ON sr.exam_id = e.id
        WHERE sr.roll_no = ? AND e.status = 1
        ORDER BY e.publish_date DESC
    ");
    $stmt->execute([$roll_no]);
    $all_rows = $stmt->fetchAll();
    if ($all_rows) {
        // Group by exam_id
        foreach ($all_rows as $row) {
            $exam_id = $row['exam_id'];
            if (!isset($all_results[$exam_id])) {
                $all_results[$exam_id] = [
                    'exam_name' => $row['exam_name'],
                    'batch' => $row['batch'],
                    'session' => $row['session'],
                    'publish_date' => $row['publish_date'],
                    'subjects' => []
                ];
            }
            $all_results[$exam_id]['subjects'][] = [
                'subject_name' => $row['subject_name'],
                'semester' => $row['semester'],
                'grade' => $row['grade'],
                'verification_code' => $row['verification_code']
            ];
        }
        // Fetch student name
        $stmt2 = $pdo->prepare("SELECT student_name FROM students WHERE roll_no = ?");
        $stmt2->execute([$roll_no]);
        $student = $stmt2->fetch();
        $student_name = $student ? $student['student_name'] : 'Student';
    } else {
        // No results found, go back to search
        $step = 'search';
        $error = "No results found for this roll number.";
    }
}

// Process roll number search (when user submits from home page)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($roll_no_input) && $step !== 'result') {
    check_rate_limit($pdo);

    // Check student exists in master table
    $stmt = $pdo->prepare("SELECT student_name, email FROM students WHERE roll_no = ?");
    $stmt->execute([$roll_no_input]);
    $student = $stmt->fetch();

    if ($student) {
        // Also check if this student has any results
        $stmt2 = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM student_results sr\n            JOIN exams e ON sr.exam_id = e.id\n            WHERE sr.roll_no = ? AND e.status = 1\n        ");
        $stmt2->execute([$roll_no_input]);
        if ($stmt2->fetchColumn() > 0) {
            $_SESSION['otp_roll_no'] = $roll_no_input;
            $_SESSION['otp_exam_id'] = 0; // generic OTP for roll number
            $_SESSION['otp_verified'] = false;
            $roll_no = $roll_no_input;
            $student_name = $student['student_name'];
            $student_email = $student['email'];
            // Mask email
            $email_parts = explode('@', $student_email);
            $username = $email_parts[0];
            $domain = $email_parts[1] ?? '';
            $masked_email = (strlen($username) > 2) ? substr($username, 0, 2) . str_repeat('*', strlen($username)-2) : $username;
            $masked_email .= '@' . $domain;
            $step = 'otp';
            $display_email = $masked_email;
            $error = null;
        } else {
            $error = "No results found for this roll number.";
            $step = 'search';
        }
    } else {
        $error = "Roll number not found in our system.";
        $step = 'search';
    }
}

if ($step === 'otp' && $roll_no) {
    $stmt = $pdo->prepare("SELECT GREATEST(0, 60 - TIMESTAMPDIFF(SECOND, created_at, NOW())) FROM otp_verifications WHERE roll_no = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$roll_no]);
    $otp_cooldown_seconds = (int) $stmt->fetchColumn();
}

include 'includes/header.php';
?>

<script>
window.addEventListener('pageshow', function (event) {
    if (event.persisted) window.location.reload();
});
</script>

<main class="portal-main result-page py-5">
    <div class="container">
        <?php include 'includes/disclaimer.php'; ?>
        <!-- Header / Search Area -->
        <div class="row justify-content-center mb-4 mb-md-5 no-print">
            <div class="col-12 col-md-8 text-center">
                <?php if ($step === 'search'): ?>
                <nav aria-label="breadcrumb" class="mb-3 mb-md-4">
                    <ol class="breadcrumb justify-content-center flex-wrap">
                        <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                        <li class="breadcrumb-item active">Search Results</li>
                    </ol>
                </nav>
                <h2 class="page-title fw-bold mb-4 animate__animated animate__fadeInDown">Enter Your Roll Number</h2>
                <div class="card border-0 shadow-sm rounded-4 p-3 p-md-4 glass-card search-result-card animate__animated animate__zoomIn">
                    <?php if ($timeout_notice): ?>
                        <div class="alert alert-warning small py-2" role="alert">
                            <i class="fas fa-clock me-2"></i>
                            Your result session expired. Please search your roll number again.
                        </div>
                    <?php endif; ?>
                    <?php if ($logout_notice): ?>
                        <div class="alert alert-success small py-2" role="alert">
                            <i class="fas fa-right-from-bracket me-2"></i>
                            You have been logged out. Please search your roll number and request a new OTP.
                        </div>
                    <?php endif; ?>
                    <form action="" method="POST" id="searchForm">
                        <div class="row g-2 justify-content-center">
                            <div class="col-12">
                                <div class="input-group input-group-lg shadow-sm rounded-pill overflow-hidden">
                                    <span class="input-group-text bg-white border-end-0 ps-3 ps-md-4"><i class="fas fa-id-card text-primary"></i></span>
                                    <input type="text" name="roll_no" class="form-control border-start-0 px-3" placeholder="Enter Roll Number" value="<?php echo htmlspecialchars($roll_no_input); ?>" required>
                                    <button type="submit" class="btn btn-primary px-4 px-md-5 fw-bold">Search</button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <?php if ($step === 'otp'): ?>
                <nav aria-label="breadcrumb" class="mb-3 mb-md-4">
                    <ol class="breadcrumb justify-content-center flex-wrap">
                        <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                        <li class="breadcrumb-item active">OTP Verification</li>
                    </ol>
                </nav>
                <h2 class="page-title fw-bold mb-4 animate__animated animate__fadeInDown">Verify Your Identity</h2>
                <div class="card border-0 shadow-sm rounded-4 p-3 p-md-4 glass-card otp-card animate__animated animate__zoomIn">
                    <div class="otp-intro">
                        <div class="otp-shield"><i class="fas fa-shield-halved" aria-hidden="true"></i></div>
                        <div>
                            <p class="mb-1 text-muted small">We found a result for</p>
                            <h3 class="otp-student-name"><?php echo sanitize_input($student_name); ?></h3>
                            <p class="mb-0 text-muted small">Roll number: <strong><?php echo sanitize_input($roll_no); ?></strong></p>
                        </div>
                    </div>
                    <div class="otp-email-note"><i class="fas fa-envelope me-2" aria-hidden="true"></i> A verification code will be sent to <strong id="maskedEmail"><?php echo $display_email; ?></strong></div>
                    <div class="row g-2 justify-content-center otp-actions">
                        <div class="col-12 col-md-4">
                            <button id="sendOtpBtn" class="btn btn-warning w-100 rounded-pill fw-bold">
                                <i class="fas fa-envelope me-2"></i> Send OTP
                            </button>
                        </div>
                        <div class="col-12 col-md-7 d-none" id="otpInputGroup">
                            <div class="input-group otp-input-group">
                                <input type="text" id="otpInput" class="form-control text-center" placeholder="Enter 6-digit OTP" maxlength="6" pattern="[0-9]{6}">
                                <button id="verifyOtpBtn" class="btn btn-success" disabled>Verify</button>
                            </div>
                            <div class="mt-3">
                                <div class="result-otp-disclaimer">
                                    <div class="result-otp-disclaimer-title">
                                        <strong>Disclaimer:</strong> The result displayed here is provisional and subject to change. The final result, as printed on the official marksheet with seal of COE, will be considered the authentic and final outcome.
                                    </div>
                                    <div class="result-otp-consent">
                                        <input class="result-otp-consent-checkbox" type="checkbox" id="disclaimerCheckbox">
                                        <label class="result-otp-consent-label" for="disclaimerCheckbox">
                                            I have read and understood the above disclaimer.
                                        </label>
                                    </div>
                                </div>
                                <button id="resendOtpBtn" class="btn btn-link btn-sm text-muted mt-2" disabled>
                                    Resend OTP (wait <span id="resendTimer">60</span>s)
                                </button>
                            </div>
                        </div>
                        <div id="otpMessage" class="col-12 mt-2"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if($error && $step !== 'result'): ?>
            <div class="row justify-content-center no-print">
                <div class="col-12 col-md-6">
                    <div class="alert alert-danger border-0 shadow-sm rounded-4 text-center animate__animated animate__shakeX">
                        <i class="fas fa-exclamation-triangle me-2"></i> <?php echo $error; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($step === 'result' && !empty($all_results)): ?>
            <!-- Display Combined Results -->
            <div id="printableResult" class="row justify-content-center result-content animate__animated animate__fadeInUp">
                <div class="col-12 col-md-10">
                    <!-- Student Info -->
                    <div class="card marksheet-card border-0 shadow-lg bg-white overflow-hidden mb-4 student-summary-card">
                        <div class="marksheet-header py-3 px-3 px-md-4 text-center">
                            <h3 class="fw-bold mb-0" style="font-size: 1.3rem;">JECRC UNIVERSITY</h3>
                            <p class="mb-0 small text-uppercase">Computer Science & Engineering Department</p>
                        </div>
                        <div class="card-body p-3 p-md-4">
                            <div class="row g-3 mb-4 pb-3 border-bottom align-items-center">
                                <div class="col-12 col-md-6">
                                    <div class="mb-2">
                                        <span class="text-muted small text-uppercase fw-bold d-block">STUDENT NAME</span>
                                        <h5 class="fw-bold text-dark mb-0" style="font-size: 1.1rem;"><?php echo sanitize_input($student_name); ?></h5>
                                    </div>
                                    <div>
                                        <span class="text-muted small text-uppercase fw-bold d-block">ROLL NUMBER</span>
                                        <h6 class="fw-bold text-primary mb-0"><?php echo sanitize_input($roll_no); ?></h6>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Loop through each exam session (sorted by publish_date DESC) -->
                    <?php foreach ($all_results as $exam_id => $exam_data): ?>
                    <div class="card marksheet-card border-0 shadow-lg bg-white overflow-hidden mb-4">
                        <div class="card-header bg-light py-2 px-3 px-md-4">
                            <h5 class="fw-bold mb-0"><?php echo sanitize_input($exam_data['exam_name']); ?></h5>
                            <small class="text-muted">
                                Batch: <?php echo sanitize_input($exam_data['batch']); ?> | 
                                Session: <?php echo sanitize_input($exam_data['session']); ?> | 
                                Published: <?php echo date('d M Y', strtotime($exam_data['publish_date'])); ?>
                            </small>
                        </div>
                        <div class="card-body p-3 p-md-4">
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="py-2 px-2 px-md-3" style="width: 40%;">SUBJECT NAME</th>
                                            <th class="py-2 px-2 px-md-3 text-center" style="width: 30%;">SEMESTER</th>
                                            <th class="py-2 px-2 px-md-3 text-center" style="width: 30%;">GRADE</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($exam_data['subjects'] as $sub): 
                                            $grade = trim($sub['grade']);
                                            $badge_class = 'badge-grade-pass';
                                            if ($grade == 'F') $badge_class = 'badge-grade-fail';
                                            elseif ($grade == 'E') $badge_class = 'badge-grade-warning';
                                            $semester_display = !empty($sub['semester']) ? $sub['semester'] : '—';
                                        ?>
                                            <tr>
                                                <td class="py-2 px-2 px-md-3 fw-medium small"><?php echo sanitize_input($sub['subject_name']); ?></td>
                                                <td class="py-2 px-2 px-md-3 text-center small"><?php echo sanitize_input($semester_display); ?></td>
                                                <td class="py-2 px-2 px-md-3 text-center">
                                                    <span class="badge <?php echo $badge_class; ?> rounded-pill px-3 py-1 fw-bold" style="font-size: 0.8rem;">
                                                        <?php echo sanitize_input($grade); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                        </div>
                    </div>
                    <?php endforeach; ?>

                    <!-- Print & Session Timer -->
                    <div class="mt-4 no-print">
                        <div class="alert alert-info small mb-3" role="status">
                            <i class="fas fa-hourglass-half me-2"></i>
                            This result session will expire in <strong id="resultSessionTimer">2:00</strong>.
                        </div>
                        <div class="d-flex flex-column flex-md-row justify-content-center gap-2">
                            <button onclick="window.print()" class="btn btn-primary rounded-pill px-4 py-2 fw-bold w-100 w-md-auto">
                                <i class="fas fa-print me-2"></i> PRINT ALL RESULTS
                            </button>
                            <form action="view_result.php" method="POST" class="w-100 w-md-auto" id="resultLogoutForm">
                                <input type="hidden" name="action" value="result_logout">
                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                <button type="submit" class="btn btn-outline-danger rounded-pill px-4 py-2 fw-bold w-100">
                                    <i class="fas fa-right-from-bracket me-2"></i> LOGOUT
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php if ($step === 'result'): ?>
<script>
(function () {
    let remainingSeconds = <?php echo max(0, $result_session_timeout - (time() - (int) ($_SESSION['otp_verified_at'] ?? time()))); ?>;
    let timerHandle = null;
    const timer = document.getElementById('resultSessionTimer');
    const resultUrl = 'view_result.php?timeout=1';

    function updateTimer() {
        const minutes = Math.floor(remainingSeconds / 60);
        const seconds = String(remainingSeconds % 60).padStart(2, '0');
        if (timer) timer.textContent = minutes + ':' + seconds;

        if (remainingSeconds <= 0) {
            window.location.href = resultUrl;
            return;
        }

        remainingSeconds--;
        timerHandle = window.setTimeout(updateTimer, 1000);
    }

    const logoutForm = document.getElementById('resultLogoutForm');
    if (logoutForm) {
        logoutForm.addEventListener('submit', function () {
            if (timerHandle !== null) window.clearTimeout(timerHandle);
            const resultContent = document.getElementById('printableResult');
            if (resultContent) resultContent.style.display = 'none';
        });
    }

    updateTimer();
})();
</script>
<?php endif; ?>

<?php if ($step === 'otp'): ?>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    let cooldown = <?php echo $otp_cooldown_seconds; ?>;
    let timerInterval;

    function showMessage(msg, type = 'info') {
        const el = $('#otpMessage');
        el.html('<div class="alert alert-' + type + ' alert-dismissible fade show small py-2" role="alert">' + msg + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>');
    }

    function updateVerifyButtonState() {
        const otpEntered = $.trim($('#otpInput').val()) !== '';
        const disclaimerChecked = $('#disclaimerCheckbox').is(':checked');
        $('#verifyOtpBtn').prop('disabled', !(otpEntered && disclaimerChecked));
    }

    $('#otpInput').on('input change', updateVerifyButtonState);
    $('#disclaimerCheckbox').on('change', updateVerifyButtonState);
    updateVerifyButtonState();

    function enableResendButton() {
        $('#sendOtpBtn').prop('disabled', false).html('<i class="fas fa-envelope me-2"></i> Resend OTP');
        $('#resendOtpBtn').prop('disabled', false).html('Resend OTP');
        clearInterval(timerInterval);
    }

    function startCooldown(seconds) {
        clearInterval(timerInterval);
        let remaining = seconds;
        $('#sendOtpBtn').prop('disabled', true).html('<i class="fas fa-clock me-2"></i> Resend OTP in ' + remaining + 's');
        $('#resendOtpBtn').prop('disabled', true).html('Resend OTP (wait ' + remaining + 's)');
        timerInterval = setInterval(function() {
            remaining--;
            if (remaining <= 0) {
                enableResendButton();
            } else {
                $('#sendOtpBtn').html('<i class="fas fa-clock me-2"></i> Resend OTP in ' + remaining + 's');
                $('#resendOtpBtn').html('Resend OTP (wait ' + remaining + 's)');
            }
        }, 1000);
    }

    function requestOtp() {
        const buttons = $('#sendOtpBtn, #resendOtpBtn');
        buttons.prop('disabled', true);
        $('#sendOtpBtn').html('<i class="fas fa-spinner fa-spin me-2"></i> Sending...');
        $('#resendOtpBtn').html('<i class="fas fa-spinner fa-spin me-2"></i> Sending...');
        $.ajax({
            url: 'send_otp.php',
            method: 'POST',
            data: {
                roll_no: '<?php echo $roll_no; ?>',
                exam_id: 0,
                csrf_token: '<?php echo generate_csrf_token(); ?>'
            },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    showMessage(res.message, 'success');
                    $('#otpInputGroup').removeClass('d-none');
                    $('#disclaimerCheckbox').prop('checked', false);
                    updateVerifyButtonState();
                    startCooldown(Number(res.cooldown) || 60);
                } else {
                    showMessage(res.message, 'danger');
                    if (res.retry_after > 0) startCooldown(Number(res.retry_after));
                    else enableResendButton();
                }
            },
            error: function() {
                showMessage('An error occurred. Please try again.', 'danger');
                enableResendButton();
            }
        });
    }

    $('#sendOtpBtn').click(requestOtp);

    $('#verifyOtpBtn').click(function() {
        const otp = $('#otpInput').val().trim();
        const isDisclaimerChecked = $('#disclaimerCheckbox').is(':checked');
        
        // Final validation
        if (!/^\d{6}$/.test(otp)) {
            showMessage('Please enter a valid 6-digit OTP.', 'warning');
            return;
        }
        
        if (!isDisclaimerChecked) {
            showMessage('Please read and check the disclaimer to proceed.', 'warning');
            return;
        }
        
        const btn = $(this);
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Verifying...');
        $.ajax({
            url: 'verify_otp.php',
            method: 'POST',
            data: {
                roll_no: '<?php echo $roll_no; ?>',
                exam_id: 0,
                otp: otp,
                disclaimer_accepted: isDisclaimerChecked ? 1 : 0,
                csrf_token: '<?php echo generate_csrf_token(); ?>'
            },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    showMessage(res.message, 'success');
                    window.location.reload();
                } else {
                    showMessage(res.message, 'danger');
                    btn.prop('disabled', false).html('Verify');
                    updateVerifyButtonState();
                }
            },
            error: function() {
                showMessage('An error occurred. Please try again.', 'danger');
                btn.prop('disabled', false).html('Verify');
                updateVerifyButtonState();
            }
        });
    });

    $('#resendOtpBtn').click(function() {
        requestOtp();
    });

    if (cooldown > 0) {
        $('#otpInputGroup').removeClass('d-none');
        startCooldown(cooldown);
    }
});
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>