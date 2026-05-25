<?php
/**
 * Fodor Review OS — Automations API
 * Handles automation CRUD, toggling, and running automations for contacts.
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

require_auth();
rate_limit_check('automations');

$method = $_SERVER['REQUEST_METHOD'];
$action = sanitize_input($_GET['action'] ?? '');
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ─── GET ────────────────────────────────────────────────────────────────────

if ($method === 'GET') {

    // GET /api/automations.php?id=X — single automation with recent logs
    if ($id > 0) {
        $automation = db_fetch_one(
            'SELECT a.*, et.name AS template_name
             FROM automations a
             LEFT JOIN email_templates et ON et.id = a.template_id
             WHERE a.id = ?',
            [$id]
        );
        if (!$automation) {
            json_error('Automation not found', 404);
        }

        $logs = db_fetch_all(
            'SELECT al.*, c.name AS contact_name
             FROM automation_logs al
             LEFT JOIN contacts c ON c.id = al.contact_id
             WHERE al.automation_id = ?
             ORDER BY al.created_at DESC
             LIMIT 50',
            [$id]
        );

        json_response(['automation' => $automation, 'logs' => $logs]);
    }

    // GET /api/automations.php — list all automations with stats
    $where  = '';
    $params = [];

    if (isset($_GET['active'])) {
        $where    = 'WHERE a.active = ?';
        $params[] = (int)$_GET['active'];
    }

    $automations = db_fetch_all(
        "SELECT
            a.*,
            et.name AS template_name,
            COUNT(al.id)                                                      AS runs,
            COALESCE(SUM(CASE WHEN al.state = 'converted' THEN 1 ELSE 0 END), 0) AS conv_count,
            CASE WHEN COUNT(al.id) > 0
                 THEN ROUND(
                     SUM(CASE WHEN al.state = 'converted' THEN 1 ELSE 0 END) * 100.0
                     / COUNT(al.id), 1)
                 ELSE 0 END                                                   AS conv_rate
         FROM automations a
         LEFT JOIN email_templates et ON et.id = a.template_id
         LEFT JOIN automation_logs al ON al.automation_id = a.id
         $where
         GROUP BY a.id
         ORDER BY a.created_at DESC",
        $params
    );

    json_response(['automations' => $automations]);
}

// ─── POST ───────────────────────────────────────────────────────────────────

if ($method === 'POST') {

    // POST /api/automations.php?action=toggle&id=X
    if ($action === 'toggle' && $id > 0) {
        $automation = db_fetch_one('SELECT id, active, name FROM automations WHERE id = ?', [$id]);
        if (!$automation) {
            json_error('Automation not found', 404);
        }

        $new_active = $automation['active'] ? 0 : 1;
        db_run("UPDATE automations SET active = ? WHERE id = ?", [$new_active, $id]);

        audit_log(
            $new_active ? 'automation_activated' : 'automation_deactivated',
            'automations',
            $id,
            ['name' => $automation['name'], 'active' => $new_active]
        );

        log_event('info', 'Automation toggled', ['id' => $id, 'active' => $new_active]);

        json_response(['id' => $id, 'active' => $new_active]);
    }

    // POST /api/automations.php?action=run — run automation for a contact
    if ($action === 'run') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) {
            json_error('Invalid JSON body');
        }

        $automation_id = isset($body['automation_id']) ? (int)$body['automation_id'] : 0;
        $contact_id    = isset($body['contact_id'])    ? (int)$body['contact_id']    : 0;

        if (!$automation_id || !$contact_id) {
            json_error('automation_id and contact_id are required');
        }

        // 1. Load automation + contact + agent data
        $automation = db_fetch_one(
            'SELECT * FROM automations WHERE id = ? AND active = 1',
            [$automation_id]
        );
        if (!$automation) {
            json_error('Automation not found or inactive', 404);
        }

        $contact = db_fetch_one(
            'SELECT c.*, a.id AS agent_id, a.name AS agent_name, a.email AS agent_email
             FROM contacts c
             LEFT JOIN agents a ON a.id = c.agent_id
             WHERE c.id = ?',
            [$contact_id]
        );
        if (!$contact) {
            json_error('Contact not found', 404);
        }

        // 2. Calculate scheduled_at = now + delay_hours, skip weekends
        $delay_hours  = isset($automation['delay_hours']) ? (int)$automation['delay_hours'] : 0;
        $scheduled_ts = time() + ($delay_hours * 3600);
        $dow          = (int)date('N', $scheduled_ts); // 1=Mon … 7=Sun

        if ($dow === 6) {
            // Saturday → next Monday 10:00
            $scheduled_ts = strtotime('next Monday 10:00:00', $scheduled_ts);
        } elseif ($dow === 7) {
            // Sunday → next Monday 10:00
            $scheduled_ts = strtotime('next Monday 10:00:00', $scheduled_ts);
        }

        $scheduled_at = date('Y-m-d H:i:s', $scheduled_ts);

        // 3. Create review_request + tracking token
        $nps_token  = bin2hex(random_bytes(24));
        $channel    = $automation['channel'] ?? 'email';
        $request_id = db_insert('review_requests', [
            'contact_id'    => $contact_id,
            'agent_id'      => $contact['agent_id'] ?? null,
            'automation_id' => $automation_id,
            'channel'       => $channel,
            'state'         => 'pending',
            'nps_token'     => $nps_token,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        // 4. Queue in send_queue
        $tpl = $automation['template_id']
            ? db_fetch_one('SELECT subject, body_html, body_text FROM email_templates WHERE id = ?', [$automation['template_id']])
            : null;

        $to_address = $channel === 'sms' ? ($contact['phone'] ?? '') : ($contact['email'] ?? '');
        $queue_id   = db_insert('send_queue', [
            'request_id'   => $request_id,
            'channel'      => $channel === 'mindkettő' ? 'email' : $channel,
            'to_address'   => $to_address,
            'to_name'      => $contact['name'] ?? '',
            'subject'      => $tpl['subject']   ?? null,
            'body_html'    => $tpl['body_html'] ?? null,
            'body_text'    => $tpl['body_text'] ?? null,
            'scheduled_at' => $scheduled_at,
            'status'       => 'queued',
        ]);
        if ($channel === 'mindkettő' && !empty($contact['phone'])) {
            db_insert('send_queue', [
                'request_id'   => $request_id,
                'channel'      => 'sms',
                'to_address'   => $contact['phone'],
                'to_name'      => $contact['name'] ?? '',
                'body_text'    => $tpl['body_text'] ?? null,
                'scheduled_at' => $scheduled_at,
                'status'       => 'queued',
            ]);
        }

        // 5. Automation log
        $log_id = db_insert('automation_logs', [
            'automation_id' => $automation_id,
            'contact_id'    => $contact_id,
            'queue_id'      => $queue_id,
            'current_step'  => 'nps_sent',
            'state'         => 'waiting_nps',
        ]);

        // 6. Follow-up recheck in 7 days
        $due_at = date('Y-m-d H:i:s', $scheduled_ts + (7 * 86400));
        db_insert('follow_ups', [
            'request_id' => $request_id,
            'type'       => 'manual',
            'due_at'     => $due_at,
        ]);

        audit_log('automation_run', 'automations', $automation_id, [
            'contact_id'   => $contact_id,
            'queue_id'     => $queue_id,
            'scheduled_at' => $scheduled_at,
        ]);

        log_event('info', 'Automation run queued', [
            'automation_id' => $automation_id,
            'contact_id'    => $contact_id,
            'queue_id'      => $queue_id,
            'scheduled_at'  => $scheduled_at,
        ]);

        json_response([
            'success'      => true,
            'request_id'   => $request_id,
            'queue_id'     => $queue_id,
            'log_id'       => $log_id,
            'scheduled_at' => $scheduled_at,
        ]);
    }

    // POST /api/automations.php — create automation
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        json_error('Invalid JSON body');
    }

    $name         = sanitize_input($body['name']         ?? '');
    $trigger_type = sanitize_input($body['trigger_type'] ?? '');
    $template_id  = isset($body['template_id']) ? (int)$body['template_id'] : 0;

    if (!$name || !$trigger_type || !$template_id) {
        json_error('name, trigger_type and template_id are required');
    }

    $allowed_triggers = ['adásvétel', 'bérleti_aláírás', 'megtekintés', 'ünnep', 'inaktív', 'egyéb'];
    if (!in_array($trigger_type, $allowed_triggers, true)) {
        json_error('Invalid trigger_type. Allowed: ' . implode(', ', $allowed_triggers));
    }

    // Validate trigger_config as JSON if provided
    $trigger_config_raw = $body['trigger_config'] ?? null;
    $trigger_config     = null;
    if ($trigger_config_raw !== null) {
        if (is_array($trigger_config_raw)) {
            $trigger_config = json_encode($trigger_config_raw);
        } else {
            $decoded = json_decode($trigger_config_raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                json_error('trigger_config must be valid JSON');
            }
            $trigger_config = $trigger_config_raw;
        }
    }

    // Verify template exists
    $template = db_fetch_one('SELECT id FROM email_templates WHERE id = ?', [$template_id]);
    if (!$template) {
        json_error('Template not found', 404);
    }

    $new_id = db_insert('automations', [
        'name'           => $name,
        'trigger_type'   => $trigger_type,
        'trigger_config' => $trigger_config,
        'template_id'    => $template_id,
        'channel'        => sanitize_input($body['channel'] ?? 'email'),
        'delay_hours'    => isset($body['delay_hours']) ? (int)$body['delay_hours'] : 0,
        'active'         => isset($body['active']) ? (int)(bool)$body['active'] : 1,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);

    audit_log('automation_created', 'automations', $new_id, ['name' => $name]);
    log_event('info', 'Automation created', ['id' => $new_id, 'name' => $name]);

    json_response(['id' => $new_id, 'message' => 'Automation created'], 201);
}

// ─── PUT ────────────────────────────────────────────────────────────────────

if ($method === 'PUT') {
    if (!$id) {
        json_error('id is required');
    }

    $automation = db_fetch_one('SELECT id FROM automations WHERE id = ?', [$id]);
    if (!$automation) {
        json_error('Automation not found', 404);
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        json_error('Invalid JSON body');
    }

    $allowed_triggers = ['adásvétel', 'bérleti_aláírás', 'megtekintés', 'ünnep', 'inaktív', 'egyéb'];
    $update           = [];

    if (isset($body['name'])) {
        $update['name'] = sanitize_input($body['name']);
    }
    if (isset($body['trigger_type'])) {
        $v = sanitize_input($body['trigger_type']);
        if (!in_array($v, $allowed_triggers, true)) {
            json_error('Invalid trigger_type');
        }
        $update['trigger_type'] = $v;
    }
    if (isset($body['trigger_config'])) {
        $raw = $body['trigger_config'];
        if (is_array($raw)) {
            $update['trigger_config'] = json_encode($raw);
        } else {
            json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                json_error('trigger_config must be valid JSON');
            }
            $update['trigger_config'] = $raw;
        }
    }
    if (isset($body['template_id'])) {
        $tid = (int)$body['template_id'];
        if (!db_fetch_one('SELECT id FROM email_templates WHERE id = ?', [$tid])) {
            json_error('Template not found', 404);
        }
        $update['template_id'] = $tid;
    }
    if (isset($body['channel'])) {
        $update['channel'] = sanitize_input($body['channel']);
    }
    if (isset($body['delay_hours'])) {
        $update['delay_hours'] = (int)$body['delay_hours'];
    }
    if (isset($body['active'])) {
        $update['active'] = (int)(bool)$body['active'];
    }

    if (empty($update)) {
        json_error('No fields to update');
    }

    db_update('automations', $update, 'id = :wid', [':wid' => $id]);

    audit_log('automation_updated', 'automations', $id, $update);
    log_event('info', 'Automation updated', ['id' => $id]);

    json_response(['id' => $id, 'message' => 'Automation updated']);
}

// ─── DELETE ─────────────────────────────────────────────────────────────────

if ($method === 'DELETE') {
    if (!$id) {
        json_error('id is required');
    }

    $automation = db_fetch_one('SELECT id, name FROM automations WHERE id = ?', [$id]);
    if (!$automation) {
        json_error('Automation not found', 404);
    }

    // Block deletion if active logs exist
    $active_logs = db_fetch_one(
        "SELECT COUNT(*) AS cnt FROM automation_logs
         WHERE automation_id = ? AND state NOT IN ('converted','failed','skipped')",
        [$id]
    );
    if ($active_logs && $active_logs['cnt'] > 0) {
        json_error('Cannot delete automation with active logs', 409);
    }

    db_run('DELETE FROM automations WHERE id = ?', [$id]);

    audit_log('automation_deleted', 'automations', $id, ['name' => $automation['name']]);
    log_event('info', 'Automation deleted', ['id' => $id]);

    json_response(['message' => 'Automation deleted']);
}

json_error('Method not allowed', 405);
