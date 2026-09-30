<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

try {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
    $dotenv->required([
        'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_PORT',
        'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASSWORD',
        'SMTP_SECURE', 'FROM_EMAIL', 'FROM_NAME', 'ADMIN_SECURITY_EMAIL'
    ])->notEmpty();
} catch (Throwable $e) {
    error_log('Application configuration could not be loaded.');
    http_response_code(500);
    die('A system configuration error occurred. Please contact the administrator.');
}

// ============================================
// SECURITY HEADERS
// ============================================
if (!headers_sent()) {
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
}

// ============================================
// GLOBAL TIMEZONE / SESSION CONFIGURATION
// ============================================
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

$session_lifetime = (int) ($_ENV['SESSION_LIFETIME'] ?? 3600);
$is_https = in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1', 'https'], true)
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', (string) max(1440, $session_lifetime));
session_set_cookie_params([
    'lifetime' => $session_lifetime,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $is_https,
    'httponly' => true,
    'samesite' => 'Strict'
]);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Legacy constants are retained for compatibility with existing consumers.
define('DB_HOST', $_ENV['DB_HOST']);
define('DB_USER', $_ENV['DB_USER']);
define('DB_PASS', $_ENV['DB_PASSWORD']);
define('DB_NAME', $_ENV['DB_NAME']);
define('DB_PORT', (int) $_ENV['DB_PORT']);

try {
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        $options
    );
} catch (PDOException $e) {
    error_log("DB Connection failed: " . $e->getMessage());
    die("A system error occurred. Please try again later.");
}

require_once __DIR__ . '/security.php';

// Base URL (trailing slash required). Use the live deployment URL on production,
// and build the current localhost path automatically when running locally.
$configured_base_url = trim((string) ($_ENV['APP_URL'] ?? ''));
$current_host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$is_local_request = $current_host === 'localhost' || strpos($current_host, '127.0.0.1') === 0 || strpos($current_host, 'localhost:') === 0;
$is_local_configured = $configured_base_url !== '' && (bool) preg_match(
    '#^(https?://)?(localhost|127\\.0\\.0\\.1)(:\\d+)?(/|$)#i',
    $configured_base_url
);

if ($configured_base_url !== '' && !$is_local_request && !$is_local_configured) {
    $base_url = rtrim($configured_base_url, '/') . '/';
} else {
    $scheme = ((!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script_directory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $application_path = rtrim($script_directory === '/' ? '' : $script_directory, '/');
    $application_path = preg_replace('#/(admin|includes)$#', '', $application_path);
    $application_path = rtrim($application_path, '/');
    $base_url = rtrim($scheme . '://' . $host . $application_path, '/') . '/';
}


// SMTP / email configuration is loaded from the environment for OTP delivery.
define('SMTP_HOST', $_ENV['SMTP_HOST']);
define('SMTP_PORT', (int) $_ENV['SMTP_PORT']);
define('SMTP_USER', $_ENV['SMTP_USER']);
define('SMTP_PASS', $_ENV['SMTP_PASSWORD']);
define('SMTP_SECURE', $_ENV['SMTP_SECURE']);
define('FROM_EMAIL', $_ENV['FROM_EMAIL']);
define('FROM_NAME', $_ENV['FROM_NAME']);
define('ADMIN_SECURITY_EMAIL', trim((string) ($_ENV['ADMIN_SECURITY_EMAIL'] ?? SMTP_USER)));

// ============================================
// SECURITY FUNCTIONS
// ============================================

/**
 * Sanitizes input data
 */
function sanitize_input($data)
{
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Returns the grades accepted by result upload and edit forms.
 * 
 * @return array List of allowed grades
 */
function allowed_grades()
{
    return ['O', 'A+', 'A', 'B', 'C', 'D', 'E', 'F', 'DT', 'UFM'];
}

/**
 * Checks whether a grade is accepted by the result portal.
 * 
 * @param string $grade Grade to validate
 * @return bool True if grade is allowed
 */
function is_allowed_grade($grade)
{
    return in_array(strtoupper(trim($grade)), allowed_grades(), true);
}

/**
 * Generates a CSRF token and stores it in the session
 */
function generate_csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifies a CSRF token
 */
function verify_csrf_token($token)
{
    return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Invalidates the public result-view session and any outstanding OTPs.
 */
function invalidate_result_session($pdo)
{
    $roll_no = $_SESSION['otp_verified_roll'] ?? $_SESSION['otp_roll_no'] ?? null;
    if ($roll_no) {
        $stmt = $pdo->prepare("UPDATE otp_verifications SET is_used = 1 WHERE roll_no = ? AND is_used = 0");
        $stmt->execute([$roll_no]);
    }

    unset(
        $_SESSION['otp_verified'],
        $_SESSION['otp_verified_at'],
        $_SESSION['otp_verified_roll'],
        $_SESSION['otp_verified_exam'],
        $_SESSION['otp_roll_no'],
        $_SESSION['otp_exam_id']
    );
    session_regenerate_id(true);
}

/**
 * Saves activity log to the database
 */
function log_activity($pdo, $action, $admin_id = null)
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $stmt = $pdo->prepare("INSERT INTO activity_logs (admin_id, action, ip_address, user_agent) VALUES (?, ?, ?, ?)");
    $stmt->execute([$admin_id, $action, $ip, $ua]);
}

/**
 * Rate limiting check – blocks excessive requests
 */
function check_rate_limit($pdo)
{
    $ip          = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Bypass rate limiting for local development
    if (in_array($ip, ['127.0.0.1', '::1', '0.0.0.0'])) {
        return;
    }

    $limit       = 10; // 10 requests allowed
    $time_window = 60; // per 1 minute

    $stmt = $pdo->prepare("SELECT attempts, UNIX_TIMESTAMP(last_attempt) as last_time FROM rate_limits WHERE ip_address = ?");
    $stmt->execute([$ip]);
    $row = $stmt->fetch();

    if ($row) {
        if (time() - $row['last_time'] < $time_window) {
            if ($row['attempts'] >= $limit) {
                http_response_code(429);
                die("Too many requests. Please wait a minute and try again.");
            }
            $stmt = $pdo->prepare("UPDATE rate_limits SET attempts = attempts + 1 WHERE ip_address = ?");
            $stmt->execute([$ip]);
        } else {
            // Reset window
            $stmt = $pdo->prepare("UPDATE rate_limits SET attempts = 1, last_attempt = CURRENT_TIMESTAMP WHERE ip_address = ?");
            $stmt->execute([$ip]);
        }
    } else {
        $stmt = $pdo->prepare("INSERT INTO rate_limits (ip_address) VALUES (?)");
        $stmt->execute([$ip]);
    }
}