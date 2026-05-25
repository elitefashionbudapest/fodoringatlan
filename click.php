<?php
/**
 * Review link click tracker.
 * Embedded as the {{review_link}} in outgoing emails/SMS.
 *
 * URL: /click.php?t=NPS_TOKEN
 *
 * Records clicked_at on the review_request, then 302-redirects
 * to the agent's actual Google review link.
 * No auth required — called by email clients / end users.
 */

require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/db.php';

$token = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['t'] ?? '');

if (empty($token)) {
    http_response_code(400);
    exit('Érvénytelen hivatkozás.');
}

$request = db_fetch_one(
    'SELECT rr.id, rr.state, rr.clicked_at,
            a.review_link
     FROM review_requests rr
     JOIN agents a ON rr.agent_id = a.id
     WHERE rr.nps_token = ?',
    [$token]
);

if (!$request || empty($request['review_link'])) {
    // Token unknown or no review link — redirect to homepage gracefully
    header('Location: https://fodoringatlan.hu');
    exit;
}

// Record first click only
if (empty($request['clicked_at'])) {
    db_run(
        "UPDATE review_requests
         SET clicked_at = datetime('now'),
             state = CASE
                 WHEN state IN ('sent','opened','nps_done','nps_done_positive')
                 THEN 'waiting'
                 ELSE state
             END
         WHERE id = ? AND clicked_at IS NULL",
        [$request['id']]
    );
    log_event('info', 'Review link kattintás rögzítve', ['request_id' => $request['id']]);
}

// 302 redirect to Google review page
header('Location: ' . $request['review_link']);
exit;
