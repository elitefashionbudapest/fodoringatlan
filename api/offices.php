<?php
/**
 * Fodor Review OS — Offices API
 * Table: offices (id, name, address, google_place_id, google_verified,
 *                 main_agent_id, avg_rating, review_count, created_at)
 *
 * GET    /api/offices.php        — list all offices
 * GET    /api/offices.php?id=X   — single office with agent list
 * POST   /api/offices.php        — create office
 * PUT    /api/offices.php?id=X   — update office
 * DELETE /api/offices.php?id=X   — soft/hard delete
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

require_auth();
rate_limit_check('offices');

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int) sanitize_input($_GET['id']) : null;

// ---------------------------------------------------------------------------
// GET
// ---------------------------------------------------------------------------
if ($method === 'GET') {

    // Single office
    if ($id) {
        $office = db_fetch_one(
            "SELECT o.*,
                    a.name  AS main_agent_name,
                    a.email AS main_agent_email
             FROM   offices o
             LEFT JOIN agents a ON a.id = o.main_agent_id
             WHERE  o.id = ?",
            [$id]
        );

        if (!$office) {
            json_error('Office not found', 404);
        }

        // Fetch all agents belonging to this office
        $agents = db_fetch_all(
            "SELECT id, name, role, phone, email, review_link, status
             FROM   agents
             WHERE  office_id = ? AND status != 'inactive'
             ORDER  BY name",
            [$id]
        );

        $office['agents'] = $agents;
        json_response($office);
    }

    // List all offices
    $offices = db_fetch_all(
        "SELECT o.*,
                a.name AS main_agent_name
         FROM   offices o
         LEFT JOIN agents a ON a.id = o.main_agent_id
         ORDER  BY o.name"
    );

    json_response(['data' => $offices, 'total' => count($offices)]);
}

// ---------------------------------------------------------------------------
// POST — create
// ---------------------------------------------------------------------------
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        json_error('Invalid JSON body', 400);
    }

    $name    = sanitize_input($input['name']    ?? '');
    $address = sanitize_input($input['address'] ?? '');

    if ($name === '') {
        json_error('Field "name" is required', 422);
    }
    if ($address === '') {
        json_error('Field "address" is required', 422);
    }

    $data = [
        'name'             => $name,
        'address'          => $address,
        'google_place_id'  => sanitize_input($input['google_place_id']  ?? ''),
        'google_verified'  => isset($input['google_verified']) ? (int) $input['google_verified'] : 0,
        'main_agent_id'    => isset($input['main_agent_id'])   ? (int) $input['main_agent_id']   : null,
        'avg_rating'       => isset($input['avg_rating'])      ? (float) $input['avg_rating']    : null,
        'review_count'     => isset($input['review_count'])    ? (int) $input['review_count']    : 0,
        'created_at'       => date('Y-m-d H:i:s'),
    ];

    // Remove empty optional fields so they use DB defaults
    foreach (['google_place_id', 'main_agent_id', 'avg_rating'] as $optional) {
        if ($data[$optional] === '' || $data[$optional] === null) {
            unset($data[$optional]);
        }
    }

    $new_id = db_insert('offices', $data);
    audit_log('create', 'office', $new_id, $data);

    $created = db_fetch_one("SELECT * FROM offices WHERE id = ?", [$new_id]);
    json_response($created, 201);
}

// ---------------------------------------------------------------------------
// PUT — update
// ---------------------------------------------------------------------------
if ($method === 'PUT') {
    if (!$id) {
        json_error('Query parameter "id" is required', 400);
    }

    $existing = db_fetch_one(
        "SELECT id FROM offices WHERE id = ?",
        [$id]
    );
    if (!$existing) {
        json_error('Office not found', 404);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        json_error('Invalid JSON body', 400);
    }

    $allowed = [
        'name', 'address', 'google_place_id', 'google_verified',
        'main_agent_id', 'avg_rating', 'review_count',
    ];

    $data = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $input)) {
            $data[$field] = sanitize_input((string) $input[$field]);
        }
    }

    // Cast numeric fields back after sanitize
    foreach (['google_verified', 'main_agent_id', 'review_count'] as $int_field) {
        if (isset($data[$int_field])) {
            $data[$int_field] = (int) $data[$int_field];
        }
    }
    if (isset($data['avg_rating'])) {
        $data['avg_rating'] = (float) $data['avg_rating'];
    }

    if (empty($data)) {
        json_error('No valid fields provided for update', 422);
    }

    db_update('offices', $data, 'id = ?', [$id]);
    audit_log('update', 'office', $id, $data);

    $updated = db_fetch_one(
        "SELECT o.*, a.name AS main_agent_name
         FROM   offices o
         LEFT JOIN agents a ON a.id = o.main_agent_id
         WHERE  o.id = ?",
        [$id]
    );
    json_response($updated);
}

// ---------------------------------------------------------------------------
// DELETE — soft or hard
// ---------------------------------------------------------------------------
if ($method === 'DELETE') {
    if (!$id) {
        json_error('Query parameter "id" is required', 400);
    }

    $existing = db_fetch_one(
        "SELECT id FROM offices WHERE id = ?",
        [$id]
    );
    if (!$existing) {
        json_error('Office not found', 404);
    }

    // Check if any reviews exist for this office
    $review_count = db_fetch_one(
        "SELECT COUNT(*) AS cnt
         FROM   review_requests rr
         JOIN   agents ag ON ag.id = rr.agent_id
         WHERE  ag.office_id = ?",
        [$id]
    );

    db_run("DELETE FROM offices WHERE id = ?", [$id]);
    audit_log('delete', 'office', $id, ['review_count' => $review_count ? (int) $review_count['cnt'] : 0]);
    json_response(['message' => 'Office permanently deleted', 'id' => $id]);
}

// ---------------------------------------------------------------------------
// Fallback
// ---------------------------------------------------------------------------
json_error('Method not allowed', 405);
