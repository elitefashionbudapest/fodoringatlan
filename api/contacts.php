<?php
/**
 * Fodor Review OS — Contacts API
 * Table: contacts (id, name, email, phone, agent_id, office_id,
 *                  transaction_type, transaction_date, notes, created_at)
 *
 * GET    /api/contacts.php          — paginated list with filters/search
 * GET    /api/contacts.php?id=X     — single contact with review_requests history
 * POST   /api/contacts.php          — create contact
 * PUT    /api/contacts.php?id=X     — update contact
 * DELETE /api/contacts.php?id=X     — hard delete (GDPR)
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

require_auth();
rate_limit_check('contacts');

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int) sanitize_input($_GET['id']) : null;

// ---------------------------------------------------------------------------
// GET
// ---------------------------------------------------------------------------
if ($method === 'GET') {

    // Single contact with review history
    if ($id) {
        $contact = db_fetch_one(
            "SELECT c.*,
                    a.name  AS agent_name,
                    o.name  AS office_name
             FROM   contacts c
             LEFT JOIN agents  a ON a.id = c.agent_id
             LEFT JOIN offices o ON o.id = c.office_id
             WHERE  c.id = ?",
            [$id]
        );

        if (!$contact) {
            json_error('Contact not found', 404);
        }

        // Review requests history for this contact
        $history = db_fetch_all(
            "SELECT rr.id,
                    rr.channel,
                    rr.state,
                    rr.star_rating,
                    rr.sent_at,
                    rr.opened_at,
                    rr.clicked_at,
                    rr.published_at,
                    rr.created_at,
                    a.name AS agent_name,
                    t.name AS template_name
             FROM   review_requests rr
             LEFT JOIN agents          a ON a.id  = rr.agent_id
             LEFT JOIN email_templates t ON t.id  = rr.template_id
             WHERE  rr.contact_id = ?
             ORDER  BY rr.sent_at DESC",
            [$id]
        );

        $contact['review_requests'] = $history;
        json_response($contact);
    }

    // Paginated list
    $page     = max(1, (int) sanitize_input($_GET['page']     ?? '1'));
    $per_page = min(100, max(1, (int) sanitize_input($_GET['per_page'] ?? '20')));
    $offset   = ($page - 1) * $per_page;

    $where_clauses = [];
    $where_params  = [];

    // Full-text search on name, email, phone
    if (isset($_GET['q']) && sanitize_input($_GET['q']) !== '') {
        $q = '%' . sanitize_input($_GET['q']) . '%';
        $where_clauses[] = '(c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)';
        $where_params[]  = $q;
        $where_params[]  = $q;
        $where_params[]  = $q;
    }

    if (isset($_GET['agent_id']) && $_GET['agent_id'] !== '') {
        $where_clauses[] = 'c.agent_id = ?';
        $where_params[]  = (int) sanitize_input($_GET['agent_id']);
    }

    if (isset($_GET['office_id']) && $_GET['office_id'] !== '') {
        $where_clauses[] = 'c.office_id = ?';
        $where_params[]  = (int) sanitize_input($_GET['office_id']);
    }

    if (isset($_GET['transaction_type']) && $_GET['transaction_type'] !== '') {
        $where_clauses[] = 'c.transaction_type = ?';
        $where_params[]  = sanitize_input($_GET['transaction_type']);
    }

    $where_sql = !empty($where_clauses)
        ? 'WHERE ' . implode(' AND ', $where_clauses)
        : '';

    // Total count for pagination meta
    $count_row = db_fetch_one(
        "SELECT COUNT(*) AS total FROM contacts c {$where_sql}",
        $where_params
    );
    $total = (int) ($count_row['total'] ?? 0);

    // Fetch page
    $contacts = db_fetch_all(
        "SELECT c.*,
                a.name AS agent_name,
                o.name AS office_name
         FROM   contacts c
         LEFT JOIN agents  a ON a.id = c.agent_id
         LEFT JOIN offices o ON o.id = c.office_id
         {$where_sql}
         ORDER  BY c.created_at DESC
         LIMIT  ? OFFSET ?",
        array_merge($where_params, [$per_page, $offset])
    );

    json_response([
        'data'       => $contacts,
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $per_page,
        'last_page'  => (int) ceil($total / $per_page),
    ]);
}

// ---------------------------------------------------------------------------
// POST — create
// ---------------------------------------------------------------------------
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        json_error('Invalid JSON body', 400);
    }

    $name  = sanitize_input($input['name']  ?? '');
    $email = sanitize_input($input['email'] ?? '');
    $phone = sanitize_input($input['phone'] ?? '');

    if ($name === '') {
        json_error('Field "name" is required', 422);
    }
    if ($email === '' && $phone === '') {
        json_error('At least one of "email" or "phone" is required', 422);
    }

    // Validate email format
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('Field "email" is not a valid email address', 422);
    }

    // Validate phone: digits, optional leading +, min 7 chars
    if ($phone !== '' && !preg_match('/^\+?[0-9]{7,20}$/', $phone)) {
        json_error('Field "phone" must be numeric digits with an optional leading "+"', 422);
    }

    $data = [
        'name'             => $name,
        'email'            => $email !== '' ? $email : null,
        'phone'            => $phone !== '' ? $phone : null,
        'agent_id'         => isset($input['agent_id'])  && $input['agent_id']  !== '' ? (int) $input['agent_id']  : null,
        'office_id'        => isset($input['office_id']) && $input['office_id'] !== '' ? (int) $input['office_id'] : null,
        'transaction_type' => sanitize_input($input['transaction_type'] ?? ''),
        'transaction_date' => sanitize_input($input['transaction_date'] ?? ''),
        'notes'            => sanitize_input($input['notes']            ?? ''),
        'created_at'       => date('Y-m-d H:i:s'),
    ];

    // Validate transaction_date format if provided
    if ($data['transaction_date'] !== '') {
        $parsed = date_create($data['transaction_date']);
        if (!$parsed) {
            json_error('Field "transaction_date" must be a valid date (e.g. 2025-01-31)', 422);
        }
        $data['transaction_date'] = date_format($parsed, 'Y-m-d');
    } else {
        $data['transaction_date'] = null;
    }

    // Remove empty optional fields
    foreach (['transaction_type', 'notes'] as $opt) {
        if ($data[$opt] === '') {
            $data[$opt] = null;
        }
    }

    // Verify agent/office references if provided
    if ($data['agent_id'] !== null) {
        $agent_check = db_fetch_one("SELECT id FROM agents WHERE id = ?", [$data['agent_id']]);
        if (!$agent_check) {
            json_error('Referenced agent_id does not exist', 422);
        }
    }
    if ($data['office_id'] !== null) {
        $office_check = db_fetch_one(
            "SELECT id FROM offices WHERE id = ? AND (status IS NULL OR status != 'deleted')",
            [$data['office_id']]
        );
        if (!$office_check) {
            json_error('Referenced office_id does not exist', 422);
        }
    }

    $new_id = db_insert('contacts', $data);
    audit_log('create', 'contact', $new_id, ['name' => $name, 'email' => $email]);

    // Queue review request — uses automation template if provided, else fallback
    $review_request_queued = false;
    $automation_id = isset($input['automation_id']) ? (int)$input['automation_id'] : 0;

    if ($data['agent_id'] && ($data['email'] || $data['phone'])) {
        $agent = db_fetch_one(
            'SELECT name, review_link, phone FROM agents WHERE id = ?',
            [$data['agent_id']]
        );

        if ($agent && !empty($agent['review_link'])) {
            $link       = $agent['review_link'];
            $first_name = explode(' ', trim($data['name']))[0];

            // Resolve automation → template
            $automation  = $automation_id
                ? db_fetch_one('SELECT * FROM automations WHERE id = ?', [$automation_id])
                : null;
            $template_id = $automation ? (int)($automation['template_id'] ?? 0) : 0;
            $channel     = $automation ? ($automation['channel'] ?? 'email') : 'email';
            $delay_hours = $automation ? (int)($automation['delay_hours'] ?? 0) : 0;
            $template    = $template_id
                ? db_fetch_one('SELECT * FROM email_templates WHERE id = ?', [$template_id])
                : null;

            // Variable substitution helper
            $vars = [
                'ügyfél_keresztnév' => $first_name,
                'ügyfél_teljes_név' => $data['name'],
                'ügynök_neve'       => $agent['name'],
                'ügynök_telefon'    => $agent['phone'] ?? '',
                'review_link'       => $link,
                'iroda_neve'        => 'Fodor Ingatlan Közvetítő Kft.',
                'dátum'             => date('Y. F j.'),
            ];
            $subst = function(string $text) use ($vars): string {
                foreach ($vars as $k => $v) {
                    $text = str_replace('{' . $k . '}', $v, $text);
                }
                return $text;
            };

            if ($template) {
                $subject   = $subst($template['subject']   ?? '');
                $body_html = $subst($template['body_html'] ?? '');
                $body_text = $subst($template['body_text'] ?? '');
            } else {
                // Fallback: plain email
                $subject   = 'Köszönjük a bizalmát, ' . $first_name . '!';
                $body_html = '<html><body style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:24px;color:#1a2a3a">'
                    . '<h2>Köszönjük a bizalmát!</h2>'
                    . '<p>Kedves <strong>' . htmlspecialchars($first_name) . '</strong>!</p>'
                    . '<p>Kérnénk, értékelje tapasztalatait:</p>'
                    . '<p style="text-align:center;margin:32px 0"><a href="' . htmlspecialchars($link) . '" style="background:#1a2a3a;color:#F5E6C8;padding:14px 28px;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px">Értékelés írása ➜</a></p>'
                    . '<p style="font-size:13px;color:#888">Üdvözlettel,<br><strong>' . htmlspecialchars($agent['name']) . '</strong></p>'
                    . '</body></html>';
                $body_text = "Kedves {$first_name}! Kérjük, értékelje tapasztalatait: {$link}";
            }

            $scheduled_at = $delay_hours > 0
                ? date('Y-m-d H:i:s', strtotime("+{$delay_hours} hours"))
                : date('Y-m-d H:i:s');

            $request_id = db_insert('review_requests', [
                'contact_id'    => $new_id,
                'agent_id'      => $data['agent_id'],
                'template_id'   => $template_id ?: null,
                'automation_id' => $automation_id ?: null,
                'channel'       => $channel,
                'state'         => 'sent',
                'sent_at'       => $scheduled_at,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            if ($data['email'] && in_array($channel, ['email', 'email+sms', 'mindkettő'])) {
                db_insert('send_queue', [
                    'request_id'   => $request_id,
                    'channel'      => 'email',
                    'to_address'   => $data['email'],
                    'to_name'      => $data['name'],
                    'subject'      => $subject,
                    'body_html'    => $body_html,
                    'body_text'    => $body_text,
                    'scheduled_at' => $scheduled_at,
                    'status'       => 'queued',
                ]);
            }

            if ($data['phone'] && in_array($channel, ['sms', 'email+sms', 'mindkettő'])) {
                $sms_body = $template ? $subst($template['body_text'] ?? $body_text) : $body_text;
                db_insert('send_queue', [
                    'request_id'   => $request_id,
                    'channel'      => 'sms',
                    'to_address'   => $data['phone'],
                    'to_name'      => $data['name'],
                    'subject'      => '',
                    'body_html'    => '',
                    'body_text'    => $sms_body,
                    'scheduled_at' => $scheduled_at,
                    'status'       => 'queued',
                ]);
            }

            log_event('info', 'Review request queued', [
                'contact_id'    => $new_id,
                'request_id'    => $request_id,
                'automation_id' => $automation_id,
                'channel'       => $channel,
                'delay_hours'   => $delay_hours,
            ]);
            $review_request_queued = true;
        }
    }

    $created = db_fetch_one(
        "SELECT c.*, a.name AS agent_name, o.name AS office_name
         FROM   contacts c
         LEFT JOIN agents  a ON a.id = c.agent_id
         LEFT JOIN offices o ON o.id = c.office_id
         WHERE  c.id = ?",
        [$new_id]
    );
    $created['review_request_queued'] = $review_request_queued;
    json_response($created, 201);
}

// ---------------------------------------------------------------------------
// PUT — update
// ---------------------------------------------------------------------------
if ($method === 'PUT') {
    if (!$id) {
        json_error('Query parameter "id" is required', 400);
    }

    $existing = db_fetch_one("SELECT id FROM contacts WHERE id = ?", [$id]);
    if (!$existing) {
        json_error('Contact not found', 404);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        json_error('Invalid JSON body', 400);
    }

    $allowed = [
        'name', 'email', 'phone', 'agent_id', 'office_id',
        'transaction_type', 'transaction_date', 'notes',
    ];

    $data = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $input)) {
            $data[$field] = $input[$field] !== null
                ? sanitize_input((string) $input[$field])
                : null;
        }
    }

    // Validations on provided fields
    if (isset($data['email']) && $data['email'] !== null && $data['email'] !== '') {
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            json_error('Field "email" is not a valid email address', 422);
        }
    }

    if (isset($data['phone']) && $data['phone'] !== null && $data['phone'] !== '') {
        if (!preg_match('/^\+?[0-9]{7,20}$/', $data['phone'])) {
            json_error('Field "phone" must be numeric digits with an optional leading "+"', 422);
        }
    }

    if (isset($data['transaction_date']) && $data['transaction_date'] !== null && $data['transaction_date'] !== '') {
        $parsed = date_create($data['transaction_date']);
        if (!$parsed) {
            json_error('Field "transaction_date" must be a valid date (e.g. 2025-01-31)', 422);
        }
        $data['transaction_date'] = date_format($parsed, 'Y-m-d');
    }

    // Cast integer FK fields
    foreach (['agent_id', 'office_id'] as $fk) {
        if (isset($data[$fk]) && $data[$fk] !== null) {
            $data[$fk] = (int) $data[$fk];
        }
    }

    if (empty($data)) {
        json_error('No valid fields provided for update', 422);
    }

    db_update('contacts', $data, 'id = :wid', [':wid' => $id]);
    audit_log('update', 'contact', $id, array_keys($data));

    $updated = db_fetch_one(
        "SELECT c.*, a.name AS agent_name, o.name AS office_name
         FROM   contacts c
         LEFT JOIN agents  a ON a.id = c.agent_id
         LEFT JOIN offices o ON o.id = c.office_id
         WHERE  c.id = ?",
        [$id]
    );
    json_response($updated);
}

// ---------------------------------------------------------------------------
// DELETE — hard delete (GDPR right to erasure)
// ---------------------------------------------------------------------------
if ($method === 'DELETE') {
    if (!$id) {
        json_error('Query parameter "id" is required', 400);
    }

    $existing = db_fetch_one("SELECT id, name, email FROM contacts WHERE id = ?", [$id]);
    if (!$existing) {
        json_error('Contact not found', 404);
    }

    // GDPR: also delete associated review_requests
    db_run("DELETE FROM review_requests WHERE contact_id = ?", [$id]);
    db_run("DELETE FROM contacts WHERE id = ?", [$id]);

    // Audit with anonymised info only
    audit_log('gdpr_delete', 'contact', $id, [
        'note' => 'Hard delete per GDPR right to erasure. All associated review_requests removed.',
    ]);

    json_response(['message' => 'Contact and associated data permanently deleted (GDPR)', 'id' => $id]);
}

// ---------------------------------------------------------------------------
// Fallback
// ---------------------------------------------------------------------------
json_error('Method not allowed', 405);
