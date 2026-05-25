<?php
/**
 * Fodor Review OS — Authentication & Request Utilities
 *
 * Provides:
 *   - Bearer token authentication middleware (require_auth)
 *   - Client IP detection (get_client_ip)
 *   - SQLite-backed rate limiting (rate_limit_check)
 *   - Input sanitisation (sanitize_input)
 *   - JSON response helpers (json_response, json_error)
 *   - reCAPTCHA v2 verification (verify_recaptcha)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';


// ----------------------------------------------------------------
// Bearer Token Authentication
// ----------------------------------------------------------------

/**
 * Enforce Bearer token authentication.
 *
 * Reads the Authorization header, SHA-256 hashes the token,
 * and looks it up in the api_tokens table.
 * On success, updates last_used_at and returns.
 * On failure, sends a 401 JSON error and exits.
 */
function require_auth(): void
{
    $authHeader = '';

    // PHP-FPM / CGI sometimes exposes this as HTTP_AUTHORIZATION
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        // Apache with certain mod_rewrite configurations
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $apacheHeaders = apache_request_headers();
        $authHeader    = $apacheHeaders['Authorization'] ?? '';
    }

    if ($authHeader === '') {
        json_error('Hiányzó azonosítás (Authorization header).', 401);
    }

    // Expect "Bearer <token>"
    if (!preg_match('/^Bearer\s+(\S+)$/i', trim($authHeader), $matches)) {
        json_error('Érvénytelen Authorization formátum. Várható: Bearer <token>', 401);
    }

    $rawToken  = $matches[1];
    $tokenHash = hash('sha256', $rawToken);

    // 1. Check sessions table (user login tokens with expiry)
    $session = db_fetch_one(
        "SELECT id FROM sessions WHERE token_hash = ? AND expires_at > datetime('now') LIMIT 1",
        [$tokenHash]
    );
    if ($session !== false) {
        return; // authenticated via session
    }

    // 2. Fallback: api_tokens (system / cron tokens)
    $tokenRow = db_fetch_one(
        'SELECT id FROM api_tokens WHERE token_hash = :hash LIMIT 1',
        [':hash' => $tokenHash]
    );

    if ($tokenRow === false) {
        log_event('error', 'Failed auth attempt', ['ip' => get_client_ip()]);
        json_error('Érvénytelen vagy lejárt token.', 401);
    }

    // Update last_used_at (fire-and-forget — ignore failure)
    try {
        db_update(
            'api_tokens',
            ['last_used_at' => gmdate('Y-m-d\TH:i:s\Z')],
            'id = :id',
            [':id' => $tokenRow['id']]
        );
    } catch (Throwable) {
        // Non-fatal — authentication already confirmed
    }
}


// ----------------------------------------------------------------
// Client IP
// ----------------------------------------------------------------

/**
 * Return the real client IP address.
 *
 * Considers X-Forwarded-For when set, but validates each candidate
 * against FILTER_VALIDATE_IP to prevent header injection.
 * Falls back to REMOTE_ADDR.
 *
 * @return string  A valid IP address string
 */
function get_client_ip(): string
{
    // Candidates from trusted proxy headers (ordered by specificity)
    $candidates = [];

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // May contain a comma-separated chain: client, proxy1, proxy2
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach ($parts as $part) {
            $candidates[] = trim($part);
        }
    }

    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $candidates[] = trim($_SERVER['HTTP_X_REAL_IP']);
    }

    // Always add REMOTE_ADDR as the final fallback
    $candidates[] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    foreach ($candidates as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
    }

    // If only private/reserved IPs are present (e.g. local dev), return REMOTE_ADDR verbatim
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    return filter_var($remoteAddr, FILTER_VALIDATE_IP) !== false
        ? $remoteAddr
        : '127.0.0.1';
}


// ----------------------------------------------------------------
// Rate Limiting
// ----------------------------------------------------------------

/**
 * Check and enforce rate limits for the given endpoint.
 *
 * Uses the rate_limits table keyed by (SHA-256 of IP, endpoint).
 * On the first request within a window, a row is inserted.
 * Subsequent requests increment the counter.
 * If the counter exceeds RATE_LIMIT_MAX within RATE_LIMIT_WINDOW seconds,
 * this function sends a 429 response and exits.
 *
 * @param string  $endpoint  A short identifier, e.g. '/api/reviews'
 * @return bool   Always true when not rate-limited (exits otherwise)
 */
function rate_limit_check(string $endpoint): bool
{
    $ip       = get_client_ip();
    $ipHash   = hash('sha256', $ip);
    $now      = time();
    $windowSz = defined('RATE_LIMIT_WINDOW') ? (int) RATE_LIMIT_WINDOW : 60;
    $maxReq   = defined('RATE_LIMIT_MAX')    ? (int) RATE_LIMIT_MAX    : 60;

    try {
        $row = db_fetch_one(
            'SELECT id, requests, window_start FROM rate_limits WHERE ip_hash = :ip AND endpoint = :ep LIMIT 1',
            [':ip' => $ipHash, ':ep' => $endpoint]
        );

        if ($row === false) {
            // First request — create a new window
            db_insert('rate_limits', [
                'ip_hash'      => $ipHash,
                'endpoint'     => $endpoint,
                'requests'     => 1,
                'window_start' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            return true;
        }

        $windowStart = strtotime($row['window_start']);
        $elapsed     = $now - $windowStart;

        if ($elapsed >= $windowSz) {
            // Window expired — reset counter
            db_update(
                'rate_limits',
                ['requests' => 1, 'window_start' => gmdate('Y-m-d\TH:i:s\Z')],
                'id = :id',
                [':id' => $row['id']]
            );
            return true;
        }

        if ((int) $row['requests'] >= $maxReq) {
            $retryAfter = $windowSz - $elapsed;
            header('Retry-After: ' . $retryAfter);
            log_event('info', 'Rate limit exceeded', ['ip' => $ip, 'endpoint' => $endpoint]);
            json_error('Túl sok kérés. Kérjük, várjon ' . $retryAfter . ' másodpercet.', 429);
        }

        // Increment counter within the current window
        db_run(
            'UPDATE rate_limits SET requests = requests + 1 WHERE id = :id',
            [':id' => $row['id']]
        );

    } catch (Throwable $e) {
        // Rate-limit table failure must not block legitimate requests
        log_event('error', 'Rate limit check failed', ['error' => $e->getMessage()]);
    }

    return true;
}


// ----------------------------------------------------------------
// Input Sanitisation
// ----------------------------------------------------------------

/**
 * Sanitise a scalar input value.
 *
 * Strips HTML tags, encodes special characters, and trims whitespace.
 * Always returns a string; converts non-strings via strval().
 *
 * @param mixed  $val
 * @return string
 */
function sanitize_input(mixed $val): string
{
    if (!is_string($val)) {
        $val = (string) $val;
    }
    $val = strip_tags($val);
    $val = trim($val);
    $val = htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return $val;
}


// ----------------------------------------------------------------
// JSON Response Helpers
// ----------------------------------------------------------------

/**
 * Send a JSON response with the given HTTP status code and exit.
 *
 * @param mixed  $data  Any JSON-serialisable value
 * @param int    $code  HTTP status code (default 200)
 */
function json_response(mixed $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}


/**
 * Send a standardised JSON error response and exit.
 *
 * Response body:
 *   { "success": false, "error": "<message>", "code": <code> }
 *
 * @param string  $msg   Human-readable error message (Hungarian preferred)
 * @param int     $code  HTTP status code (default 400)
 */
function json_error(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(
        ['success' => false, 'error' => $msg, 'code' => $code],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}


// ----------------------------------------------------------------
// reCAPTCHA v2 Verification
// ----------------------------------------------------------------

/**
 * Verify a reCAPTCHA v2 response token against Google's API.
 *
 * Returns true on success, false on any failure (network error,
 * wrong token, hostname mismatch, etc.).
 *
 * @param string  $token  The g-recaptcha-response value from the form
 * @return bool
 */
function verify_recaptcha(string $token): bool
{
    if (!defined('RECAPTCHA_SECRET_KEY') || RECAPTCHA_SECRET_KEY === 'CONFIGURE_ME') {
        // Secret not configured — allow in development, deny in production
        if (defined('APP_ENV') && APP_ENV === 'development') {
            log_event('debug', 'reCAPTCHA skipped in development mode');
            return true;
        }
        log_event('error', 'RECAPTCHA_SECRET_KEY is not configured');
        return false;
    }

    if ($token === '') {
        return false;
    }

    $payload = http_build_query([
        'secret'   => RECAPTCHA_SECRET_KEY,
        'response' => $token,
        'remoteip' => get_client_ip(),
    ]);

    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n"
                             . "Content-Length: " . strlen($payload) . "\r\n",
            'content'       => $payload,
            'timeout'       => 5,
            'ignore_errors' => true,
        ],
    ]);

    $result = @file_get_contents(
        'https://www.google.com/recaptcha/api/siteverify',
        false,
        $context
    );

    if ($result === false) {
        log_event('error', 'reCAPTCHA: network request failed');
        return false;
    }

    $data = json_decode($result, true);

    if (!is_array($data)) {
        log_event('error', 'reCAPTCHA: invalid response from Google');
        return false;
    }

    if (isset($data['error-codes'])) {
        log_event('debug', 'reCAPTCHA error-codes', ['codes' => $data['error-codes']]);
    }

    return isset($data['success']) && $data['success'] === true;
}
