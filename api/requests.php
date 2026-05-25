<?php
/**
 * Fodor Review OS — Review Requests API
 * Manages the full review request lifecycle.
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = sanitize_input($_GET['action'] ?? '');
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ─── PUBLIC ENDPOINTS (no auth) ─────────────────────────────────────────────

// GET /api/requests.php?action=tracking&token=XXXX — 1×1 pixel tracker
if ($method === 'GET' && $action === 'tracking') {
    $token = sanitize_input($_GET['token'] ?? '');
    if (!$token) {
        // Still serve the pixel to avoid leaking info
        _send_tracking_pixel();
    }

    $rr = db_fetch_one(
        'SELECT id, opened_at FROM review_requests WHERE nps_token = ?',
        [$token]
    );

    if ($rr && !$rr['opened_at']) {
        db_update('review_requests', [
            'opened_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$rr['id']]);

        log_event('info', 'Email opened (tracking pixel)', ['request_id' => $rr['id']]);
    }

    _send_tracking_pixel();
    // _send_tracking_pixel() exits
}

// POST /api/requests.php?action=nps — NPS response (public with reCAPTCHA)
if ($method === 'POST' && $action === 'nps') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        json_error('Invalid JSON body');
    }

    $token     = sanitize_input($body['token']     ?? '');
    $score     = isset($body['score']) ? (int)$body['score'] : null;
    $recaptcha = sanitize_input($body['recaptcha'] ?? '');

    if (!$token || $score === null || !$recaptcha) {
        json_error('token, score and recaptcha are required');
    }

    if ($score < 0 || $score > 10) {
        json_error('score must be between 0 and 10');
    }

    // Verify reCAPTCHA v2
    $recaptcha_secret = defined('RECAPTCHA_SECRET_KEY') ? RECAPTCHA_SECRET_KEY : '';
    if (empty($recaptcha_secret)) {
        log_event('warning', 'RECAPTCHA_SECRET not configured — skipping verification', []);
    } else {
        $verify_url = 'https://www.google.com/recaptcha/api/siteverify';
        $verify_data = http_build_query([
            'secret'   => $recaptcha_secret,
            'response' => $recaptcha,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded',
                'content' => $verify_data,
                'timeout' => 10,
            ],
        ]);
        $verify_response = @file_get_contents($verify_url, false, $ctx);
        $verify_result   = $verify_response ? json_decode($verify_response, true) : null;

        if (!$verify_result || !$verify_result['success']) {
            log_event('warning', 'reCAPTCHA verification failed', [
                'token'          => $token,
                'error_codes'    => $verify_result['error-codes'] ?? [],
            ]);
            json_error('reCAPTCHA verification failed', 403);
        }
    }

    // Find request by token
    $rr = db_fetch_one(
        'SELECT id, state, nps_score FROM review_requests WHERE nps_token = ?',
        [$token]
    );
    if (!$rr) {
        json_error('Invalid token', 404);
    }

    if ($rr['nps_score'] !== null) {
        json_error('NPS score already submitted', 409);
    }

    // Determine NPS threshold (configurable, default 7)
    $nps_threshold = defined('NPS_THRESHOLD') ? (int)NPS_THRESHOLD : 7;

    // Update state based on score:
    // score >= threshold → promoter → route to Google review ('nps_passed')
    // score < threshold  → detractor → block public review ('blocked')
    $new_state = $score >= $nps_threshold ? 'nps_done_positive' : 'nps_done_negative';

    db_update('review_requests', [
        'nps_score' => $score,
        'state'     => $new_state,
        'nps_at'    => date('Y-m-d H:i:s'),
    ], 'id = ?', [$rr['id']]);

    $al_state = $score >= $nps_threshold ? 'converted' : 'negative_path';
    db_run(
        "UPDATE automation_logs SET state = ?
         WHERE contact_id = (SELECT contact_id FROM review_requests WHERE id = ?)
           AND state = 'waiting_nps'",
        [$al_state, $rr['id']]
    );

    log_event('info', 'NPS score received', [
        'request_id' => $rr['id'],
        'score'      => $score,
        'state'      => $new_state,
    ]);

    json_response([
        'state'     => $new_state,
        'redirect'  => $new_state === 'nps_done_positive' ? 'google' : 'internal',
        'message'   => $new_state === 'nps_done_positive'
            ? 'Köszönjük! Átirányítjuk a Google értékelésre.'
            : 'Köszönjük visszajelzését! Hamarosan felvesszük Önnel a kapcsolatot.',
    ]);
}

// ─── AUTHENTICATED ENDPOINTS ─────────────────────────────────────────────────

require_auth();
rate_limit_check('requests');

// ─── GET ────────────────────────────────────────────────────────────────────

if ($method === 'GET') {

    // GET /api/requests.php?id=X — single request with full timeline
    if ($id > 0) {
        $rr = db_fetch_one(
            "SELECT rr.*,
                    c.name  AS contact_name,
                    c.email AS contact_email,
                    c.phone AS contact_phone,
                    a.name  AS agent_name,
                    o.name  AS office_name
             FROM review_requests rr
             LEFT JOIN contacts c ON c.id = rr.contact_id
             LEFT JOIN agents a   ON a.id = rr.agent_id
             LEFT JOIN offices o  ON o.id = a.office_id
             WHERE rr.id = ?",
            [$id]
        );
        if (!$rr) {
            json_error('Review request not found', 404);
        }

        // Timeline: automation_log events
        $timeline = db_fetch_all(
            'SELECT al.state, al.created_at, al.queue_id
             FROM automation_logs al
             WHERE al.contact_id = ?
             ORDER BY al.created_at ASC',
            [$rr['contact_id']]
        );

        $follow_ups = db_fetch_all(
            'SELECT * FROM follow_ups WHERE request_id = ? ORDER BY due_at ASC',
            [$id]
        );

        json_response([
            'request'    => $rr,
            'timeline'   => $timeline,
            'follow_ups' => $follow_ups,
        ]);
    }

    // GET /api/requests.php — list with filters
    $where    = ['1=1'];
    $params   = [];
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $limit    = min((int)($_GET['limit'] ?? 20), 100);
    $offset   = ($page - 1) * $limit;

    if (!empty($_GET['state'])) {
        $where[]  = 'rr.state = ?';
        $params[] = sanitize_input($_GET['state']);
    }
    if (!empty($_GET['agent_id'])) {
        $where[]  = 'rr.agent_id = ?';
        $params[] = (int)$_GET['agent_id'];
    }
    if (!empty($_GET['office_id'])) {
        $where[]  = 'a.office_id = ?';
        $params[] = (int)$_GET['office_id'];
    }
    if (!empty($_GET['date_from'])) {
        $where[]  = 'rr.created_at >= ?';
        $params[] = sanitize_input($_GET['date_from']) . ' 00:00:00';
    }
    if (!empty($_GET['date_to'])) {
        $where[]  = 'rr.created_at <= ?';
        $params[] = sanitize_input($_GET['date_to']) . ' 23:59:59';
    }

    $where_sql = implode(' AND ', $where);

    $count_row = db_fetch_one(
        "SELECT COUNT(*) AS cnt
         FROM review_requests rr
         LEFT JOIN agents a ON a.id = rr.agent_id
         WHERE $where_sql",
        $params
    );
    $total = (int)($count_row['cnt'] ?? 0);

    $requests = db_fetch_all(
        "SELECT rr.*,
                c.name  AS contact_name,
                ag.name AS agent_name,
                o.name  AS office_name
         FROM review_requests rr
         LEFT JOIN contacts c ON c.id = rr.contact_id
         LEFT JOIN agents ag  ON ag.id = rr.agent_id
         LEFT JOIN offices o  ON o.id  = ag.office_id
         WHERE $where_sql
         ORDER BY rr.created_at DESC
         LIMIT ? OFFSET ?",
        array_merge($params, [$limit, $offset])
    );

    json_response([
        'requests'   => $requests,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total'       => $total,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

// ─── POST ?action=run — run automation for existing contact ─────────────────

if ($method === 'POST' && $action === 'run') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $contact_id    = isset($body['contact_id'])    ? (int)$body['contact_id']    : 0;
    $automation_id = isset($body['automation_id']) ? (int)$body['automation_id'] : 0;

    if (!$contact_id)    json_error('contact_id is required');
    if (!$automation_id) json_error('automation_id is required');

    $contact = db_fetch_one(
        'SELECT c.*, a.name AS agent_name, a.review_link, a.phone AS agent_phone
         FROM contacts c
         LEFT JOIN agents a ON a.id = c.agent_id
         WHERE c.id = ?', [$contact_id]
    );
    if (!$contact) json_error('Contact not found', 404);
    if (empty($contact['agent_id'])) json_error('Contact has no agent assigned', 422);
    if (empty($contact['review_link'])) json_error('Agent has no review_link set', 422);

    $auto = db_fetch_one('SELECT * FROM automations WHERE id = ? AND active = 1', [$automation_id]);
    if (!$auto) json_error('Automation not found or inactive', 404);

    $template_id  = (int)($auto['template_id']  ?? 0);
    $channel      = $auto['channel'] ?? 'email';
    $delay_hours  = (int)($auto['delay_hours']  ?? 0);
    $template     = $template_id
        ? db_fetch_one('SELECT * FROM email_templates WHERE id = ?', [$template_id])
        : null;

    $first_name   = explode(' ', trim($contact['name']))[0];
    $scheduled_at = date('Y-m-d H:i:s', time() + ($delay_hours * 3600));

    $subst = function(string $text) use ($contact, $auto, $first_name): string {
        $vars = [
            'ügyfél_keresztnév' => $first_name,
            'ügyfél_teljes_név' => $contact['name'],
            'ügynök_neve'       => $contact['agent_name'] ?? '',
            'ügynök_telefon'    => $contact['agent_phone'] ?? '',
            'review_link'       => $contact['review_link'],
            'iroda_neve'        => 'Fodor Ingatlan Közvetítő Kft.',
            'dátum'             => date('Y. F j.'),
        ];
        foreach ($vars as $k => $v) {
            $text = str_replace('{{' . $k . '}}', $v, $text);
        }
        return $text;
    };

    $subject   = $template ? $subst($template['subject']   ?? '') : 'Értékelés kérés — Fodor Ingatlan';
    $body_html = $template ? $subst($template['body_html'] ?? '') : '';
    $body_text = $template ? $subst($template['body_text'] ?? '') : "Kedves {$first_name}! Kérjük, értékelje tapasztalatait: " . $contact['review_link'];

    $request_id = db_insert('review_requests', [
        'contact_id'    => $contact_id,
        'agent_id'      => $contact['agent_id'],
        'template_id'   => $template_id ?: null,
        'automation_id' => $automation_id,
        'channel'       => $channel,
        'state'         => 'sent',
        'sent_at'       => $scheduled_at,
        'created_at'    => date('Y-m-d H:i:s'),
    ]);

    if ($contact['email'] && in_array($channel, ['email','email+sms','mindkettő'])) {
        db_insert('send_queue', [
            'request_id'   => $request_id,
            'channel'      => 'email',
            'to_address'   => $contact['email'],
            'to_name'      => $contact['name'],
            'subject'      => $subject,
            'body_html'    => $body_html,
            'body_text'    => $body_text,
            'scheduled_at' => $scheduled_at,
            'status'       => 'queued',
        ]);
    }

    if ($contact['phone'] && in_array($channel, ['sms','email+sms','mindkettő'])) {
        db_insert('send_queue', [
            'request_id'   => $request_id,
            'channel'      => 'sms',
            'to_address'   => $contact['phone'],
            'to_name'      => $contact['name'],
            'subject'      => '',
            'body_html'    => '',
            'body_text'    => $body_text,
            'scheduled_at' => $scheduled_at,
            'status'       => 'queued',
        ]);
    }

    log_event('info', 'Automation run for existing contact', [
        'contact_id'    => $contact_id,
        'automation_id' => $automation_id,
        'request_id'    => $request_id,
        'channel'       => $channel,
    ]);

    json_response(['success' => true, 'request_id' => $request_id, 'scheduled_at' => $scheduled_at]);
}

// ─── POST ───────────────────────────────────────────────────────────────────

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        json_error('Invalid JSON body');
    }

    $contact_id      = isset($body['contact_id'])   ? (int)$body['contact_id']   : 0;
    $agent_id        = isset($body['agent_id'])      ? (int)$body['agent_id']      : 0;
    $template_id     = isset($body['template_id'])   ? (int)$body['template_id']   : 0;
    $channel         = sanitize_input($body['channel'] ?? 'email');
    $automation_id   = isset($body['automation_id']) ? (int)$body['automation_id'] : null;
    $send_immediately = !empty($body['send_immediately']);

    if (!$contact_id || !$agent_id || !$template_id) {
        json_error('contact_id, agent_id and template_id are required');
    }

    $allowed_channels = ['email', 'sms', 'mindkettő', 'email+sms'];
    if (!in_array($channel, $allowed_channels, true)) {
        json_error('channel must be one of: ' . implode(', ', $allowed_channels));
    }
    // Normalize legacy 'email+sms' → 'mindkettő'
    if ($channel === 'email+sms') {
        $channel = 'mindkettő';
    }

    // Verify referenced records exist
    if (!db_fetch_one('SELECT id FROM contacts WHERE id = ?', [$contact_id])) {
        json_error('Contact not found', 404);
    }
    if (!db_fetch_one('SELECT id FROM agents WHERE id = ?', [$agent_id])) {
        json_error('Agent not found', 404);
    }
    if (!db_fetch_one('SELECT id FROM email_templates WHERE id = ?', [$template_id])) {
        json_error('Template not found', 404);
    }

    $delay_hours = 0;
    if ($automation_id) {
        $auto = db_fetch_one('SELECT delay_hours FROM automations WHERE id = ?', [$automation_id]);
        if (!$auto) {
            json_error('Automation not found', 404);
        }
        $delay_hours = (int)($auto['delay_hours'] ?? 0);
    }

    $scheduled_at = date('Y-m-d H:i:s', time() + ($delay_hours * 3600));

    // Generate unique tracking token
    $token = bin2hex(random_bytes(24));

    // Insert review_request with state='pending'
    $request_id = db_insert('review_requests', [
        'contact_id'    => $contact_id,
        'agent_id'      => $agent_id,
        'template_id'   => $template_id,
        'automation_id' => $automation_id,
        'channel'       => $channel,
        'state'         => 'pending',
        'nps_token'     => $token,
        'created_at'    => date('Y-m-d H:i:s'),
    ]);

    // Fetch contact and template content for send_queue
    $contact_data = db_fetch_one('SELECT name, email, phone FROM contacts WHERE id = ?', [$contact_id]);
    $tpl = db_fetch_one('SELECT subject, body_html, body_text FROM email_templates WHERE id = ?', [$template_id]);

    // Queue in send_queue — one entry per channel
    $queue_id = 0;
    if (in_array($channel, ['email', 'email+sms', 'mindkettő'])) {
        $queue_id = db_insert('send_queue', [
            'request_id'   => $request_id,
            'channel'      => 'email',
            'to_address'   => $contact_data['email'] ?? '',
            'to_name'      => $contact_data['name']  ?? '',
            'subject'      => $tpl['subject']   ?? null,
            'body_html'    => $tpl['body_html'] ?? null,
            'body_text'    => $tpl['body_text'] ?? null,
            'scheduled_at' => $scheduled_at,
            'status'       => 'queued',
        ]);
    }
    if (in_array($channel, ['sms', 'email+sms', 'mindkettő'])) {
        $sms_qid = db_insert('send_queue', [
            'request_id'   => $request_id,
            'channel'      => 'sms',
            'to_address'   => $contact_data['phone'] ?? '',
            'to_name'      => $contact_data['name']  ?? '',
            'body_text'    => $tpl['body_text'] ?? null,
            'scheduled_at' => $scheduled_at,
            'status'       => 'queued',
        ]);
        if (!$queue_id) $queue_id = $sms_qid;
    }

    // 7-day follow-up check
    $due_at = date('Y-m-d H:i:s', strtotime($scheduled_at) + (7 * 86400));
    db_insert('follow_ups', [
        'request_id' => $request_id,
        'type'       => 'manual',
        'due_at'     => $due_at,
    ]);

    // If send_immediately, trigger internal send logic
    if ($send_immediately) {
        _trigger_send($queue_id, $request_id, $contact_id, $template_id, $channel, $token);
    }

    audit_log('request_created', 'review_requests', $request_id, [
        'contact_id'    => $contact_id,
        'agent_id'      => $agent_id,
        'channel'       => $channel,
        'send_immediately' => $send_immediately,
    ]);

    log_event('info', 'Review request created', [
        'request_id' => $request_id,
        'queue_id'   => $queue_id,
    ]);

    json_response(['request_id' => $request_id, 'queue_id' => $queue_id], 201);
}

// ─── PUT ────────────────────────────────────────────────────────────────────

if ($method === 'PUT') {
    if (!$id) {
        json_error('id is required');
    }

    $rr = db_fetch_one('SELECT id, state FROM review_requests WHERE id = ?', [$id]);
    if (!$rr) {
        json_error('Review request not found', 404);
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        json_error('Invalid JSON body');
    }

    $update = [];

    $allowed_states = ['pending','sent','opened','nps_done','nps_done_positive','nps_done_negative','waiting','published','internal','disappeared','bounced','blocked'];
    if (isset($body['state'])) {
        $s = sanitize_input($body['state']);
        if (!in_array($s, $allowed_states, true)) {
            json_error('Invalid state. Allowed: ' . implode(', ', $allowed_states));
        }
        $update['state'] = $s;
    }
    if (isset($body['nps_score'])) {
        $score = (int)$body['nps_score'];
        if ($score < 0 || $score > 10) {
            json_error('nps_score must be between 0 and 10');
        }
        $update['nps_score'] = $score;
    }
    if (isset($body['star_rating'])) {
        $star = (int)$body['star_rating'];
        if ($star < 1 || $star > 5) {
            json_error('star_rating must be between 1 and 5');
        }
        $update['star_rating'] = $star;
    }
    if (isset($body['published_at'])) {
        $update['published_at'] = sanitize_input($body['published_at']);
        if (!isset($update['state'])) {
            $update['state'] = 'published';
        }
    }

    if (empty($update)) {
        json_error('No fields to update');
    }

    db_update('review_requests', $update, 'id = ?', [$id]);

    audit_log('request_updated', 'review_requests', $id, $update);
    log_event('info', 'Review request updated', ['id' => $id, 'fields' => array_keys($update)]);

    json_response(['id' => $id, 'message' => 'Request updated']);
}

json_error('Method not allowed', 405);

// ─── HELPERS ────────────────────────────────────────────────────────────────

/**
 * Output a 1×1 transparent GIF and exit.
 * This function always exits and never returns.
 */
function _send_tracking_pixel(): never
{
    // 1×1 transparent GIF (35 bytes)
    $gif = base64_decode(
        'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'
    );
    header('Content-Type: image/gif');
    header('Content-Length: ' . strlen($gif));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $gif;
    exit;
}

/**
 * Trigger immediate send by marking the queue item as 'ready' and
 * updating the review_request to 'sent'. In a real implementation
 * this would hand off to a mailer/SMS worker; here we update state
 * and log to simulate dispatch.
 */
function _trigger_send(
    int $queue_id,
    int $request_id,
    int $contact_id,
    int $template_id,
    string $channel,
    string $token
): void {
    $contact  = db_fetch_one('SELECT name, email, phone FROM contacts WHERE id = ?', [$contact_id]);
    $template = db_fetch_one('SELECT name FROM email_templates WHERE id = ?', [$template_id]);

    db_update('send_queue', [
        'status'  => 'sent',
        'sent_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$queue_id]);

    db_update('review_requests', [
        'state'   => 'sent',
        'sent_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$request_id]);

    log_event('info', 'Review request dispatched', [
        'queue_id'      => $queue_id,
        'request_id'    => $request_id,
        'contact_email' => $contact['email'] ?? '',
        'channel'       => $channel,
        'template'      => $template['name'] ?? '',
        'token'         => $token,
    ]);
}
