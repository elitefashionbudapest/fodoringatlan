<?php
/**
 * Fodor Review OS — Verify API
 * SLA monitoring, flagged requests, publication tracking, and timeline view.
 *
 * GET  /api/verify.php                     — dashboard: flagged, waiting, disappeared, SLA stats
 * GET  /api/verify.php?request_id=X        — full timeline for a single request
 * POST /api/verify.php?action=resolve&id=X — resolve a follow_up
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

require_auth();
rate_limit_check('verify');

$method     = $_SERVER['REQUEST_METHOD'];
$action     = sanitize_input($_GET['action']     ?? '');
$request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
$id         = isset($_GET['id'])         ? (int)$_GET['id']         : 0;

// ─── GET ─────────────────────────────────────────────────────────────────────

if ($method === 'GET') {

    // GET ?request_id=X — full timeline for one request
    if ($request_id) {
        $req = db_fetch_one(
            'SELECT rr.*, c.name AS contact_name, c.email AS contact_email,
                    a.name AS agent_name, o.name AS office_name
             FROM review_requests rr
             LEFT JOIN contacts c ON c.id = rr.contact_id
             LEFT JOIN agents a   ON a.id = rr.agent_id
             LEFT JOIN offices o  ON o.id = a.office_id
             WHERE rr.id = ?',
            [$request_id]
        );

        if (!$req) {
            json_error('Review request not found', 404);
        }

        $timeline = _build_timeline($request_id, $req);
        json_response(['request' => $req, 'timeline' => $timeline]);
    }

    // GET — dashboard view

    // ── SLA monitor: all sent requests with tracking status ──────────────────
    $sla_rows = db_fetch_all(
        "SELECT rr.id, rr.state, rr.star_rating, rr.sent_at, rr.opened_at,
                rr.clicked_at, rr.published_at, rr.created_at,
                c.name  AS contact_name,
                a.name  AS agent_name,
                o.name  AS office_name
         FROM review_requests rr
         LEFT JOIN contacts c ON c.id = rr.contact_id
         LEFT JOIN agents a   ON a.id = rr.agent_id
         LEFT JOIN offices o  ON o.id = a.office_id
         WHERE rr.state NOT IN ('internal','cancelled')
         ORDER BY rr.sent_at DESC
         LIMIT 100"
    );

    $sla_monitor = [];
    foreach ($sla_rows as $row) {
        $row['wait_duration'] = _human_duration_since($row['sent_at'] ?? $row['created_at']);
        $row['sla_status']    = _sla_status_request($row);
        $sla_monitor[] = $row;
    }

    // ── Link clicked but not yet published (pending confirmation) ────────────
    $waiting_publish = db_fetch_all(
        "SELECT rr.id, rr.state, rr.sent_at, rr.clicked_at, rr.created_at,
                c.name  AS contact_name,
                a.name  AS agent_name,
                o.name  AS office_name
         FROM review_requests rr
         LEFT JOIN contacts c ON c.id = rr.contact_id
         LEFT JOIN agents a   ON a.id = rr.agent_id
         LEFT JOIN offices o  ON o.id = a.office_id
         WHERE rr.clicked_at IS NOT NULL
           AND rr.state NOT IN ('published','internal','cancelled')
         ORDER BY rr.clicked_at DESC"
    );

    foreach ($waiting_publish as &$row) {
        $row['wait_duration'] = _human_duration_since($row['clicked_at']);
    }
    unset($row);

    // ── Not opened: sent > 5 days ago, no open/click ─────────────────────────
    $not_opened = db_fetch_all(
        "SELECT rr.id, rr.state, rr.sent_at, rr.created_at,
                c.name  AS contact_name,
                a.name  AS agent_name,
                o.name  AS office_name
         FROM review_requests rr
         LEFT JOIN contacts c ON c.id = rr.contact_id
         LEFT JOIN agents a   ON a.id = rr.agent_id
         LEFT JOIN offices o  ON o.id = a.office_id
         WHERE rr.opened_at IS NULL
           AND rr.clicked_at IS NULL
           AND rr.state = 'sent'
           AND rr.sent_at < datetime('now', '-5 days')
         ORDER BY rr.sent_at ASC"
    );

    foreach ($not_opened as &$row) {
        $row['wait_duration'] = _human_duration_since($row['sent_at']);
    }
    unset($row);

    // ── SLA stats (last 30 days) ─────────────────────────────────────────────
    $sla_stats = _compute_sla_stats();

    json_response([
        'sla_monitor'      => $sla_monitor,
        'waiting_publish'  => $waiting_publish,
        'not_opened'       => $not_opened,
        'sla_stats'        => $sla_stats,
    ]);
}

// ─── POST ─────────────────────────────────────────────────────────────────────

if ($method === 'POST') {

    // POST ?action=mark_published&id=X — manually confirm review appeared on Google
    if ($action === 'mark_published') {
        if (!$id) { json_error('id is required'); }
        $rr = db_fetch_one('SELECT id, state FROM review_requests WHERE id = ?', [$id]);
        if (!$rr) { json_error('Review request not found', 404); }
        db_run(
            "UPDATE review_requests SET state = 'published', published_at = datetime('now') WHERE id = ?",
            [$id]
        );
        log_event('info', 'Request manually marked as published', ['request_id' => $id]);
        json_response(['success' => true, 'id' => $id]);
    }

    // POST ?action=resolve&id=X — resolve a follow_up
    if ($action === 'resolve') {
        if (!$id) {
            json_error('id is required');
        }

        $follow_up = db_fetch_one('SELECT id, resolved_at FROM follow_ups WHERE id = ?', [$id]);
        if (!$follow_up) {
            json_error('Follow-up not found', 404);
        }
        if (!empty($follow_up['resolved_at'])) {
            json_error('Follow-up already resolved', 409);
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        db_run(
            "UPDATE follow_ups SET resolved_at = datetime('now'), resolved_by = ? WHERE id = ?",
            [sanitize_input($body['resolved_by'] ?? 'manual'), $id]
        );

        log_event('info', 'Follow-up resolved', ['follow_up_id' => $id]);
        json_response(['success' => true, 'id' => $id, 'message' => 'Follow-up resolved']);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Build a chronological timeline array for a review request.
 * Sources: review_request milestones, automation_logs, follow_ups.
 *
 * Each item: [time, date, label, desc, ok, gold]
 */
function _build_timeline(int $request_id, array $req): array
{
    $events = [];

    // Milestone fields from review_requests
    $milestones = [
        'created_at'   => ['label' => 'Létrehozva',               'gold' => false],
        'sent_at'      => ['label' => 'Email kiküldve',            'gold' => false],
        'opened_at'    => ['label' => 'Email megnyitva',           'gold' => false],
        'clicked_at'   => ['label' => 'Link megnyitva (kattintás)','gold' => false],
        'published_at' => ['label' => 'Értékelés megjelent',      'gold' => true],
    ];

    foreach ($milestones as $field => $meta) {
        if (!empty($req[$field])) {
            $ts       = strtotime($req[$field]);
            $events[] = [
                'time'  => date('H:i', $ts),
                'date'  => date('Y-m-d', $ts),
                'label' => $meta['label'],
                'desc'  => '',
                'ok'    => true,
                'gold'  => $meta['gold'],
            ];
        }
    }

    // Automation logs for this request's contact
    if (!empty($req['contact_id'])) {
        $logs = db_fetch_all(
            'SELECT al.state, al.started_at, al.notes, au.name AS automation_name
             FROM automation_logs al
             LEFT JOIN automations au ON au.id = al.automation_id
             WHERE al.contact_id = ?
             ORDER BY al.started_at ASC',
            [$req['contact_id']]
        );

        foreach ($logs as $log) {
            $ts       = strtotime($log['started_at']);
            $events[] = [
                'time'  => date('H:i', $ts),
                'date'  => date('Y-m-d', $ts),
                'label' => 'Automatizáció: ' . ($log['automation_name'] ?? 'ismeretlen'),
                'desc'  => $log['state'] . ($log['notes'] ? ' — ' . $log['notes'] : ''),
                'ok'    => !in_array($log['state'], ['failed', 'error'], true),
                'gold'  => $log['state'] === 'converted',
            ];
        }
    }

    // Follow-ups
    $follow_ups = db_fetch_all(
        'SELECT * FROM follow_ups WHERE request_id = ? ORDER BY due_at ASC',
        [$request_id]
    );

    foreach ($follow_ups as $fu) {
        $ts       = strtotime($fu['due_at']);
        $resolved = !empty($fu['resolved_at']);
        $events[] = [
            'time'  => date('H:i', $ts),
            'date'  => date('Y-m-d', $ts),
            'label' => 'Follow-up: ' . ($fu['type'] ?? ''),
            'desc'  => $resolved ? 'Lezárva' : 'Függőben',
            'ok'    => $resolved,
            'gold'  => false,
        ];
    }

    // Sort chronologically
    usort($events, fn($a, $b) => strcmp($a['date'] . $a['time'], $b['date'] . $b['time']));

    return $events;
}

/**
 * Determine SLA status for a review_request row.
 * star <= 2 and unreplied > 4h → 'breach'
 * star <= 3 and unreplied > 2h → 'sla-due'
 */
function _sla_status_request(array $row): string
{
    $star      = (int)($row['star_rating'] ?? 0);
    $ref_time  = $row['sent_at'] ?? $row['created_at'] ?? null;

    if (!$ref_time) {
        return 'ok';
    }

    $hours_since = (time() - strtotime($ref_time)) / 3600;

    if ($star > 0 && $star <= 2 && $hours_since >= 4) {
        return 'breach';
    }
    if ($star > 0 && $star <= 3 && $hours_since >= 2) {
        return 'sla-due';
    }
    return 'ok';
}

/**
 * Return a human-readable duration since a datetime.
 * Format: "4ó 12p", "2 nap", "3 hét"
 */
function _human_duration_since(?string $datetime): string
{
    if (!$datetime) {
        return 'ismeretlen';
    }

    $diff = max(0, time() - strtotime($datetime));

    if ($diff < 60) {
        return 'most';
    }
    if ($diff < 3600) {
        $m = (int)floor($diff / 60);
        return "{$m}p";
    }
    if ($diff < 86400) {
        $h = (int)floor($diff / 3600);
        $m = (int)floor(($diff % 3600) / 60);
        return $m > 0 ? "{$h}ó {$m}p" : "{$h}ó";
    }
    if ($diff < 7 * 86400) {
        $d = (int)floor($diff / 86400);
        return "{$d} nap";
    }
    $w = (int)floor($diff / (7 * 86400));
    return "{$w} hét";
}

/**
 * Compute SLA compliance stats for the last 30 days.
 */
function _compute_sla_stats(): array
{
    // Requests sent in last 30 days with a star rating
    $requests = db_fetch_all(
        "SELECT rr.star_rating, rr.sent_at, rr.published_at,
                gr.reply_at
         FROM review_requests rr
         LEFT JOIN google_reviews gr ON gr.author = (
             SELECT c.name FROM contacts c WHERE c.id = rr.contact_id LIMIT 1
         )
         WHERE rr.sent_at >= datetime('now', '-30 days')
           AND rr.star_rating IS NOT NULL"
    );

    if (empty($requests)) {
        return [
            'compliance_rate'    => 0.0,
            'avg_response_hours' => 0.0,
            'breach_count'       => 0,
        ];
    }

    $total          = count($requests);
    $breaches       = 0;
    $response_hours = [];

    foreach ($requests as $r) {
        $star       = (int)$r['star_rating'];
        $sent_ts    = strtotime($r['sent_at']);
        $reply_ts   = $r['reply_at'] ? strtotime($r['reply_at']) : null;
        $hours_wait = $reply_ts ? ($reply_ts - $sent_ts) / 3600 : null;

        if ($hours_wait !== null) {
            $response_hours[] = $hours_wait;
        }

        // Count SLA breach: low star, no reply within threshold
        $elapsed = ($reply_ts ?? time()) - $sent_ts;
        $hours   = $elapsed / 3600;

        if ($star <= 2 && $hours > 4) {
            $breaches++;
        } elseif ($star === 3 && $hours > 2) {
            $breaches++;
        }
    }

    $compliance_rate    = $total > 0 ? round(($total - $breaches) / $total * 100, 1) : 0.0;
    $avg_response_hours = count($response_hours) > 0
        ? round(array_sum($response_hours) / count($response_hours), 2)
        : 0.0;

    return [
        'compliance_rate'    => $compliance_rate,
        'avg_response_hours' => $avg_response_hours,
        'breach_count'       => $breaches,
    ];
}
