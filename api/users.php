<?php
/**
 * Fodor Review OS — Users API
 * GET    /api/users.php          → list all users
 * GET    /api/users.php?id=X     → single user
 * POST   /api/users.php          → create user {name, email, password, role, office_id}
 * PUT    /api/users.php?id=X     → update user (password optional)
 * DELETE /api/users.php?id=X     → deactivate user (soft delete)
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

require_auth();
rate_limit_check('users');

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ─── GET ─────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if ($id) {
        $user = db_fetch_one(
            'SELECT id, name, email, role, office_id, active, created_at FROM users WHERE id = ?',
            [$id]
        );
        if (!$user) json_error('Felhasználó nem található', 404);
        json_response($user);
    }

    $users = db_fetch_all(
        'SELECT u.id, u.name, u.email, u.role, u.office_id, u.active, u.created_at,
                o.name AS office_name
         FROM users u
         LEFT JOIN offices o ON o.id = u.office_id
         ORDER BY u.name ASC'
    );
    json_response(['data' => $users]);
}

// ─── POST (create) ────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $name      = sanitize_input($body['name']     ?? '');
    $email     = sanitize_input($body['email']    ?? '');
    $password  = $body['password']                ?? '';
    $role      = sanitize_input($body['role']     ?? 'agent');
    $office_id = isset($body['office_id']) ? (int)$body['office_id'] : null;

    if (!$name || !$email || !$password) {
        json_error('Név, email és jelszó kötelező.');
    }

    $allowed_roles = ['admin', 'agent', 'viewer'];
    if (!in_array($role, $allowed_roles, true)) {
        json_error('Érvénytelen szerepkör. Lehetséges: admin, agent, viewer.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('Érvénytelen email cím formátum.');
    }

    $existing = db_fetch_one('SELECT id FROM users WHERE email = ?', [$email]);
    if ($existing) {
        json_error('Ez az email cím már foglalt.', 409);
    }

    if (strlen($password) < 8) {
        json_error('A jelszónak legalább 8 karakter hosszúnak kell lennie.');
    }

    $hash   = password_hash($password, PASSWORD_DEFAULT);
    $new_id = db_insert('users', [
        'name'          => $name,
        'email'         => $email,
        'password_hash' => $hash,
        'role'          => $role,
        'office_id'     => $office_id,
        'active'        => 1,
    ]);

    log_event('info', 'User created', ['id' => $new_id, 'email' => $email, 'role' => $role]);
    json_response(['success' => true, 'id' => $new_id, 'message' => 'Felhasználó létrehozva'], 201);
}

// ─── PUT (update) ─────────────────────────────────────────────────────────────
if ($method === 'PUT') {
    if (!$id) json_error('id szükséges');

    $existing = db_fetch_one('SELECT id FROM users WHERE id = ?', [$id]);
    if (!$existing) json_error('Felhasználó nem található', 404);

    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $update = [];

    if (isset($body['name']))  $update['name']  = sanitize_input($body['name']);
    if (isset($body['email'])) {
        if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) json_error('Érvénytelen email cím.');
        $update['email'] = sanitize_input($body['email']);
    }
    if (isset($body['role'])) {
        $allowed_roles = ['admin', 'agent', 'viewer'];
        if (!in_array($body['role'], $allowed_roles, true)) json_error('Érvénytelen szerepkör.');
        $update['role'] = $body['role'];
    }
    if (isset($body['office_id'])) $update['office_id'] = (int)$body['office_id'] ?: null;
    if (isset($body['active']))    $update['active']    = $body['active'] ? 1 : 0;

    if (!empty($body['password'])) {
        if (strlen($body['password']) < 8) json_error('A jelszónak legalább 8 karakter hosszúnak kell lennie.');
        $update['password_hash'] = password_hash($body['password'], PASSWORD_DEFAULT);
        // Invalidate existing sessions on password change
        db_run('DELETE FROM sessions WHERE user_id = ?', [$id]);
    }

    if (empty($update)) json_error('Nincs frissítendő mező.');

    db_update('users', $update, 'id = :wid', [':wid' => $id]);
    log_event('info', 'User updated', ['id' => $id, 'fields' => array_keys($update)]);
    json_response(['success' => true, 'id' => $id, 'message' => 'Felhasználó frissítve']);
}

// ─── DELETE (deactivate) ─────────────────────────────────────────────────────
if ($method === 'DELETE') {
    if (!$id) json_error('id szükséges');

    $existing = db_fetch_one('SELECT id, email FROM users WHERE id = ?', [$id]);
    if (!$existing) json_error('Felhasználó nem található', 404);

    db_run('UPDATE users SET active = 0 WHERE id = ?', [$id]);
    db_run('DELETE FROM sessions WHERE user_id = ?', [$id]);

    log_event('info', 'User deactivated', ['id' => $id, 'email' => $existing['email']]);
    json_response(['success' => true, 'message' => 'Felhasználó deaktiválva']);
}

json_error('Method not allowed', 405);
