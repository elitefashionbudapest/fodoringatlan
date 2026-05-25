<?php
/**
 * Fodor Review OS — Agents API
 * Table: agents (id, office_id, name, role, phone, email, review_link,
 *                signature, personalized_msg, status, created_at)
 *
 * GET    /api/agents.php                      — list all agents (+ optional filters)
 * GET    /api/agents.php?id=X                 — single agent with stats
 * POST   /api/agents.php                      — create agent
 * PUT    /api/agents.php?id=X                 — update agent
 * DELETE /api/agents.php?id=X                 — soft delete (status='inactive')
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

require_auth();
rate_limit_check('agents');

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int) sanitize_input($_GET['id']) : null;

// ---------------------------------------------------------------------------
// GET
// ---------------------------------------------------------------------------
if ($method === 'GET') {

    // Single agent with stats
    if ($id) {
        $agent = db_fetch_one(
            "SELECT ag.*,
                    o.name AS office_name,
                    o.address AS office_address
             FROM   agents ag
             LEFT JOIN offices o ON o.id = ag.office_id
             WHERE  ag.id = ?",
            [$id]
        );

        if (!$agent) {
            json_error('Agent not found', 404);
        }

        // Stats: sent requests, published reviews, avg star, conversion rate
        $stats = db_fetch_one(
            "SELECT
                COUNT(*)                                         AS sent_count,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published_count,
                AVG(CASE WHEN star_rating IS NOT NULL THEN star_rating END) AS avg_star
             FROM review_requests
             WHERE agent_id = ?",
            [$id]
        );

        $sent      = (int)   ($stats['sent_count']      ?? 0);
        $published = (int)   ($stats['published_count'] ?? 0);
        $avg_star  = $stats['avg_star'] !== null ? round((float) $stats['avg_star'], 2) : null;
        $conversion_rate = $sent > 0 ? round(($published / $sent) * 100, 1) : 0.0;

        $agent['stats'] = [
            'sent_count'      => $sent,
            'published_count' => $published,
            'avg_star'        => $avg_star,
            'conversion_rate' => $conversion_rate, // percent
        ];

        json_response($agent);
    }

    // List — build WHERE clause from optional filters
    $where_clauses  = [];
    $where_params   = [];

    if (isset($_GET['office_id']) && $_GET['office_id'] !== '') {
        $where_clauses[] = 'ag.office_id = ?';
        $where_params[]  = (int) sanitize_input($_GET['office_id']);
    }

    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $status_filter   = sanitize_input($_GET['status']);
        $where_clauses[] = 'ag.status = ?';
        $where_params[]  = $status_filter;
    }

    $where_sql = !empty($where_clauses)
        ? 'WHERE ' . implode(' AND ', $where_clauses)
        : '';

    $agents = db_fetch_all(
        "SELECT ag.*,
                o.name AS office_name
         FROM   agents ag
         LEFT JOIN offices o ON o.id = ag.office_id
         {$where_sql}
         ORDER  BY ag.name",
        $where_params
    );

    json_response(['data' => $agents, 'total' => count($agents)]);
}

// ---------------------------------------------------------------------------
// POST — create
// ---------------------------------------------------------------------------
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        json_error('Invalid JSON body', 400);
    }

    $name      = sanitize_input($input['name']      ?? '');
    $office_id = isset($input['office_id']) ? (int) $input['office_id'] : 0;
    $email     = sanitize_input($input['email']     ?? '');

    if ($name === '') {
        json_error('Field "name" is required', 422);
    }
    if ($office_id <= 0) {
        json_error('Field "office_id" is required and must be a positive integer', 422);
    }
    if ($email === '') {
        json_error('Field "email" is required', 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('Field "email" is not a valid email address', 422);
    }

    // Verify office exists
    $office = db_fetch_one(
        "SELECT id FROM offices WHERE id = ? AND (status IS NULL OR status != 'deleted')",
        [$office_id]
    );
    if (!$office) {
        json_error('Referenced office_id does not exist', 422);
    }

    $data = [
        'office_id'       => $office_id,
        'name'            => $name,
        'role'            => sanitize_input($input['role']             ?? ''),
        'phone'           => sanitize_input($input['phone']            ?? ''),
        'email'           => $email,
        'review_link'     => sanitize_input($input['review_link']     ?? ''),
        'signature'       => sanitize_input($input['signature']        ?? ''),
        'personalized_msg'=> sanitize_input($input['personalized_msg'] ?? ''),
        'status'          => 'active',
        'created_at'      => date('Y-m-d H:i:s'),
    ];

    // Strip empty optional strings
    foreach (['role', 'phone', 'review_link', 'signature', 'personalized_msg'] as $opt) {
        if ($data[$opt] === '') {
            unset($data[$opt]);
        }
    }

    $new_id = db_insert('agents', $data);
    audit_log('create', 'agent', $new_id, $data);

    $created = db_fetch_one(
        "SELECT ag.*, o.name AS office_name
         FROM   agents ag
         LEFT JOIN offices o ON o.id = ag.office_id
         WHERE  ag.id = ?",
        [$new_id]
    );
    json_response($created, 201);
}

// ---------------------------------------------------------------------------
// PUT — update
// ---------------------------------------------------------------------------
if ($method === 'PUT') {
    if (!$id) {
        json_error('Query parameter "id" is required', 400);
    }

    $existing = db_fetch_one("SELECT id FROM agents WHERE id = ?", [$id]);
    if (!$existing) {
        json_error('Agent not found', 404);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        json_error('Invalid JSON body', 400);
    }

    $allowed = [
        'office_id', 'name', 'role', 'phone', 'email',
        'review_link', 'signature', 'personalized_msg', 'status',
    ];

    $data = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $input)) {
            $data[$field] = sanitize_input((string) $input[$field]);
        }
    }

    // Validate email if being updated
    if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        json_error('Field "email" is not a valid email address', 422);
    }

    // Cast office_id back to int
    if (isset($data['office_id'])) {
        $data['office_id'] = (int) $data['office_id'];
        if ($data['office_id'] <= 0) {
            json_error('Field "office_id" must be a positive integer', 422);
        }
        $office = db_fetch_one(
            "SELECT id FROM offices WHERE id = ? AND (status IS NULL OR status != 'deleted')",
            [$data['office_id']]
        );
        if (!$office) {
            json_error('Referenced office_id does not exist', 422);
        }
    }

    // Validate status value
    if (isset($data['status']) && !in_array($data['status'], ['active', 'inactive'], true)) {
        json_error('Field "status" must be "active" or "inactive"', 422);
    }

    if (empty($data)) {
        json_error('No valid fields provided for update', 422);
    }

    db_update('agents', $data, 'id = ?', [$id]);
    audit_log('update', 'agent', $id, $data);

    $updated = db_fetch_one(
        "SELECT ag.*, o.name AS office_name
         FROM   agents ag
         LEFT JOIN offices o ON o.id = ag.office_id
         WHERE  ag.id = ?",
        [$id]
    );
    json_response($updated);
}

// ---------------------------------------------------------------------------
// DELETE — soft delete (set status='inactive')
// ---------------------------------------------------------------------------
if ($method === 'DELETE') {
    if (!$id) {
        json_error('Query parameter "id" is required', 400);
    }

    $existing = db_fetch_one("SELECT id, status FROM agents WHERE id = ?", [$id]);
    if (!$existing) {
        json_error('Agent not found', 404);
    }

    if ($existing['status'] === 'inactive') {
        json_error('Agent is already inactive', 409);
    }

    db_update('agents', ['status' => 'inactive'], 'id = ?', [$id]);
    audit_log('deactivate', 'agent', $id, []);

    json_response(['message' => 'Agent deactivated', 'id' => $id]);
}

// ---------------------------------------------------------------------------
// Fallback
// ---------------------------------------------------------------------------
json_error('Method not allowed', 405);
