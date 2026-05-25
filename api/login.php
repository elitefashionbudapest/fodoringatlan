<?php
/**
 * Fodor Review OS — Login API
 * POST /api/login.php  {email, password} → {token, user, expires_at}
 * DELETE /api/login.php  (with Bearer token) → logout
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/db.php';

// need these helpers but NOT require_auth (this IS auth)
require_once __DIR__ . '/config.php';

// We need json_response/json_error/sanitize_input/get_client_ip/log_event from auth.php
// but NOT require_auth(). Load auth.php - it's safe since require_auth() is just a function def.
require_once __DIR__ . '/auth.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $email    = trim($body['email']    ?? '');
    $password = $body['password']      ?? '';

    if (!$email || !$password) {
        json_error('Email és jelszó kötelező.', 400);
    }

    $user = db_fetch_one(
        'SELECT id, name, email, password_hash, role, office_id, active FROM users WHERE email = ? LIMIT 1',
        [$email]
    );

    // Constant-time: always run password_hash on failure path
    if (!$user) {
        password_hash('dummy_timing_protection', PASSWORD_DEFAULT);
        log_event('warn', 'Login failed — unknown email', ['email' => $email, 'ip' => get_client_ip()]);
        json_error('Hibás email cím vagy jelszó.', 401);
    }

    if (!(int)$user['active'] || !password_verify($password, $user['password_hash'])) {
        log_event('warn', 'Login failed — wrong password', ['email' => $email, 'ip' => get_client_ip()]);
        json_error('Hibás email cím vagy jelszó.', 401);
    }

    // Clean expired sessions for this user
    db_run("DELETE FROM sessions WHERE user_id = ? AND expires_at < datetime('now')", [$user['id']]);

    // Create new session token
    $raw_token  = bin2hex(random_bytes(32));
    $token_hash = hash('sha256', $raw_token);
    $expires_at = gmdate('Y-m-d\TH:i:s\Z', strtotime('+8 hours'));

    db_run(
        'INSERT INTO sessions (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
        [$user['id'], $token_hash, $expires_at]
    );

    log_event('info', 'User logged in', ['user_id' => $user['id'], 'email' => $email]);

    unset($user['password_hash']);
    json_response([
        'token'      => $raw_token,
        'user'       => $user,
        'expires_at' => $expires_at,
    ]);
}

if ($method === 'DELETE') {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(\S+)$/i', trim($authHeader), $m)) {
        $hash = hash('sha256', $m[1]);
        db_run('DELETE FROM sessions WHERE token_hash = ?', [$hash]);
    }
    json_response(['success' => true]);
}

json_error('Method not allowed', 405);
