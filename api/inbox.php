<?php
/**
 * Fodor Review OS — Inbox API
 * Combined inbox: low-star Google reviews + follow-up pending review requests.
 *
 * GET /api/inbox.php         — list inbox items
 * PUT /api/inbox.php?id=X&type=review   — mark review as replied
 * PUT /api/inbox.php?id=X&type=request  — update request state / assign agent
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

require_auth();
rate_limit_check('inbox');

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$type   = sanitize_input($_GET['type'] ?? 'all');

// ─── GET ─────────────────────────────────────────────────────────────────────

if ($method === 'GET') {

    // Query filters
    $filter_type       = sanitize_input($_GET['type']          ?? 'all');
    $min_star          = isset($_GET['min_star'])   ? (int)$_GET['min_star']   : null;
    $max_star          = isset($_GET['max_star'])   ? (int)$_GET['max_star']   : null;
    $filter_office_id  = isset($_GET['office_id'])  ? (int)$_GET['office_id']  : null;
    $filter_agent_id   = isset($_GET['agent_id'])   ? (int)$_GET['agent_id']   : null;
    $unresolved_only   = !empty($_GET['unresolved_only']);
    $page              = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
    $per_page          = 25;
    $offset            = ($page - 1) * $per_page;

    $items = [];

    // ── 1. Google reviews (star_rating <= 3, need reply) ──────────────────────
    if ($filter_type === 'all' || $filter_type === 'reviews') {

        $r_where  = ['gr.star_rating <= 3'];
        $r_params = [];

        if ($min_star !== null) {
            $r_where[]  = 'gr.star_rating >= ?';
            $r_params[] = $min_star;
        }
        if ($max_star !== null) {
            $r_where[]  = 'gr.star_rating <= ?';
            $r_params[] = $max_star;
        }
        if ($filter_office_id) {
            $r_where[]  = 'o.id = ?';
            $r_params[] = $filter_office_id;
        }
        if ($filter_agent_id) {
            $r_where[]  = 'a.id = ?';
            $r_params[] = $filter_agent_id;
        }
        if ($unresolved_only) {
            $r_where[] = 'gr.reply_at IS NULL';
        }

        $r_where_sql = 'WHERE ' . implode(' AND ', $r_where);

        $reviews = db_fetch_all(
            "SELECT
                'review'           AS type,
                gr.id,
                gr.author          AS contact_name,
                gr.star_rating     AS star,
                gr.review_text     AS excerpt,
                gr.published_at    AS created_at,
                gr.reply_at,
                gr.reply_text,
                o.name             AS office_name,
                a.name             AS agent_name,
                gr.office_id,
                NULL               AS agent_id,
                NULL               AS state
             FROM google_reviews gr
             LEFT JOIN offices o ON o.id = gr.office_id
             LEFT JOIN agents a  ON a.id = o.main_agent_id
             $r_where_sql
             ORDER BY gr.published_at DESC
             LIMIT ? OFFSET ?",
            array_merge($r_params, [$per_page, $offset])
        );

        foreach ($reviews as &$row) {
            $row['sla_status']   = _sla_status_review($row);
            $row['time_since']   = _human_time_since($row['created_at']);
            $row['msg_excerpt']  = mb_substr($row['excerpt'] ?? '', 0, 120);
        }
        unset($row);

        $items = array_merge($items, $reviews);
    }

    // ── 2. Review requests: sent > 48h ago, no star yet (waiting follow-up) ──
    if ($filter_type === 'all' || $filter_type === 'requests') {

        $q_where  = ["rr.state NOT IN ('internal','cancelled')"];
        $q_params = [];

        if ($filter_office_id) {
            $q_where[]  = 'o.id = ?';
            $q_params[] = $filter_office_id;
        }
        if ($filter_agent_id) {
            $q_where[]  = 'a.id = ?';
            $q_params[] = $filter_agent_id;
        }
        if ($unresolved_only) {
            $q_where[] = "rr.state != 'published'";
        }

        $q_where_sql = 'WHERE ' . implode(' AND ', $q_where);

        $requests = db_fetch_all(
            "SELECT
                'request'          AS type,
                rr.id,
                rr.contact_id,
                c.name             AS contact_name,
                rr.star_rating     AS star,
                c.email            AS excerpt,
                rr.created_at,
                rr.sent_at,
                rr.state,
                rr.channel,
                rr.opened_at,
                rr.clicked_at,
                rr.published_at,
                o.name             AS office_name,
                a.name             AS agent_name,
                o.id               AS office_id,
                a.id               AS agent_id,
                rr.automation_id,
                au.name            AS automation_name,
                (SELECT MAX(rr2.sent_at)
                 FROM review_requests rr2
                 WHERE rr2.contact_id = rr.contact_id
                   AND rr2.sent_at IS NOT NULL)  AS last_contact_at,
                (SELECT al.state
                 FROM automation_logs al
                 WHERE al.contact_id = rr.contact_id
                   AND al.state IN ('running','waiting_nps','negative_path')
                 ORDER BY al.started_at DESC LIMIT 1) AS active_automation_state,
                (SELECT au2.name
                 FROM automation_logs al2
                 JOIN automations au2 ON au2.id = al2.automation_id
                 WHERE al2.contact_id = rr.contact_id
                   AND al2.state IN ('running','waiting_nps','negative_path')
                 ORDER BY al2.started_at DESC LIMIT 1) AS active_automation_name,
                NULL               AS reply_at,
                NULL               AS reply_text
             FROM review_requests rr
             LEFT JOIN contacts c    ON c.id  = rr.contact_id
             LEFT JOIN agents a      ON a.id  = rr.agent_id
             LEFT JOIN offices o     ON o.id  = a.office_id
             LEFT JOIN automations au ON au.id = rr.automation_id
             $q_where_sql
             ORDER BY rr.created_at DESC
             LIMIT ? OFFSET ?",
            array_merge($q_params, [$per_page, $offset])
        );

        foreach ($requests as &$row) {
            $row['sla_status']  = 'ok';
            $row['time_since']  = _human_time_since($row['sent_at'] ?? $row['created_at']);
            $row['msg_excerpt'] = mb_substr($row['excerpt'] ?? '', 0, 120);

            $lc = $row['last_contact_at'] ?? null;
            if ($lc) {
                $diff = max(0, time() - strtotime($lc));
                $row['days_since_contact'] = (int) floor($diff / 86400);
            } else {
                $row['days_since_contact'] = null;
            }
        }
        unset($row);

        $items = array_merge($items, $requests);
    }

    // Sort combined list: SLA breaches first, then by created_at desc
    usort($items, function ($a, $b) {
        $order = ['breach' => 0, 'sla-due' => 1, 'ok' => 2];
        $sa    = $order[$a['sla_status']] ?? 2;
        $sb    = $order[$b['sla_status']] ?? 2;
        if ($sa !== $sb) {
            return $sa - $sb;
        }
        return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
    });

    json_response([
        'items'    => $items,
        'total'    => count($items),
        'page'     => $page,
        'per_page' => $per_page,
    ]);
}

// ─── PUT ─────────────────────────────────────────────────────────────────────

if ($method === 'PUT') {
    if (!$id) {
        json_error('id is required');
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    // Mark review as replied
    if ($type === 'review') {
        $existing = db_fetch_one('SELECT id FROM google_reviews WHERE id = ?', [$id]);
        if (!$existing) {
            json_error('Review not found', 404);
        }

        $reply_text = sanitize_input($body['reply_text'] ?? '');
        if (!$reply_text) {
            json_error('reply_text is required');
        }

        db_run(
            "UPDATE google_reviews SET reply_text = ?, reply_at = datetime('now') WHERE id = ?",
            [$reply_text, $id]
        );

        log_event('info', 'Review marked as replied', ['review_id' => $id]);
        json_response(['success' => true, 'id' => $id, 'message' => 'Review marked as replied']);
    }

    // Update review request
    if ($type === 'request') {
        $existing = db_fetch_one('SELECT id, state FROM review_requests WHERE id = ?', [$id]);
        if (!$existing) {
            json_error('Review request not found', 404);
        }

        $allowed_states = ['pending', 'sent', 'waiting', 'published', 'disappeared', 'failed'];
        $update         = [];

        if (isset($body['state'])) {
            $new_state = sanitize_input($body['state']);
            if (!in_array($new_state, $allowed_states, true)) {
                json_error('Invalid state. Allowed: ' . implode(', ', $allowed_states));
            }
            $update['state'] = $new_state;
        }

        if (isset($body['agent_id'])) {
            $agent_id = (int)$body['agent_id'];
            $agent    = db_fetch_one('SELECT id FROM agents WHERE id = ?', [$agent_id]);
            if (!$agent) {
                json_error('Agent not found', 404);
            }
            $update['agent_id'] = $agent_id;
        }

        if (isset($body['note'])) {
            $update['note'] = sanitize_input($body['note']);
        }

        if (empty($update)) {
            json_error('No fields to update');
        }

        db_update('review_requests', $update, 'id = :wid', [':wid' => $id]);

        log_event('info', 'Review request updated via inbox', ['request_id' => $id, 'update' => $update]);
        json_response(['success' => true, 'id' => $id, 'message' => 'Request updated']);
    }

    json_error('type must be "review" or "request"');
}

json_error('Method not allowed', 405);

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Calculate SLA status for a Google review row.
 * - star <= 2 and no reply within 4h → 'breach'
 * - star <= 3 and no reply within 2h → 'sla-due'
 * - otherwise → 'ok'
 */
function _sla_status_review(array $row): string
{
    if ($row['reply_at']) {
        return 'ok';
    }

    $created_ts  = strtotime($row['created_at'] ?? 'now');
    $hours_since = (time() - $created_ts) / 3600;
    $star        = (int)($row['star'] ?? 5);

    if ($star <= 2 && $hours_since >= 4) {
        return 'breach';
    }
    if ($star <= 3 && $hours_since >= 2) {
        return 'sla-due';
    }
    return 'ok';
}

/**
 * Return a human-readable "time since" string from a datetime string.
 * E.g. "4ó 12p", "2 nap", "3 hét"
 */
function _human_time_since(?string $datetime): string
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
