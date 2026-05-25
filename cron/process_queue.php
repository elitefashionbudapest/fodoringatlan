#!/usr/bin/env php
<?php
/**
 * Fodor Review OS — Cron: process_queue.php
 * Processes queued send_queue entries (email + SMS).
 *
 * Run: php cron/process_queue.php
 * Recommended schedule: every 15 minutes
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script may only be run from the command line.');
}

require __DIR__ . '/../api/config.php';
require __DIR__ . '/../api/db.php';
require __DIR__ . '/../api/mailer.php';

$start_time         = microtime(true);
$emails_sent        = 0;
$sms_sent           = 0;
$failed             = 0;
$follow_ups_created = 0;

log_event('info', 'process_queue: start');

// ── 1. Fetch queued entries due for sending ─────────────────────────────────

$pending = db_fetch_all(
    "SELECT sq.*,
            c.name       AS contact_name,
            a.name       AS agent_name,
            a.signature  AS agent_signature,
            a.review_link AS agent_review_link,
            o.name       AS office_name,
            o.address    AS office_address,
            rr.nps_token
     FROM send_queue sq
     LEFT JOIN review_requests rr ON rr.id = sq.request_id
     LEFT JOIN contacts c ON c.id = rr.contact_id
     LEFT JOIN agents   a ON a.id = rr.agent_id
     LEFT JOIN offices  o ON o.id = a.office_id
     WHERE sq.status = 'queued'
       AND sq.scheduled_at <= datetime('now')
     ORDER BY sq.scheduled_at ASC
     LIMIT 100"
);

echo "Found " . count($pending) . " queued entries.\n";

foreach ($pending as $entry) {
    $queue_id = (int)$entry['id'];
    $channel  = $entry['channel'];

    echo "Processing queue entry [{$queue_id}] channel={$channel}...\n";

    // Build substitution vars (same as send.php)
    $first_name  = explode(' ', trim($entry['contact_name'] ?? ''))[0] ?? '';
    $nps_token   = $entry['nps_token'] ?? '';
    $direct_link = $entry['agent_review_link'] ?? '';
    $tracking_url = !empty($nps_token)
        ? APP_URL . '/click.php?t=' . urlencode($nps_token)
        : $direct_link;
    $nps_url = !empty($nps_token)
        ? APP_URL . '/nps.php?t=' . urlencode($nps_token)
        : '';
    $nps_link_html = !empty($nps_url)
        ? '<a href="' . htmlspecialchars($nps_url, ENT_QUOTES) . '" style="display:inline-block;background:#1F2D3D;color:#F5F0E6;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;">Értékelés küldése →</a>'
        : '';

    // Text/subject vars (no HTML escaping needed)
    $text_vars = [
        'nev'            => $first_name,
        'ugyfelnev'      => $entry['contact_name']    ?? '',
        'ugynok_nev'     => $entry['agent_name']      ?? '',
        'ugynok_alairas' => $entry['agent_signature'] ?? ($entry['agent_name'] ?? ''),
        'iroda_neve'     => $entry['office_name']     ?? '',
        'iroda_cim'      => $entry['office_address']  ?? '',
        'review_link'    => $tracking_url,
        'nps_link'       => $nps_url,
        'nps_link_html'  => $nps_link_html,
    ];

    $subject   = $entry['subject']   ?? 'Értékelési kérés';
    $body_html = $entry['body_html'] ?? '';
    $body_text = $entry['body_text'] ?? '';

    // Apply substitution: HTML body escapes scalars but inserts nps_link_html as raw HTML
    $html_vars = $text_vars;
    unset($html_vars['nps_link_html']);

    foreach ($html_vars as $k => $v) {
        $body_html = str_replace('{{' . $k . '}}', htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), $body_html);
    }
    $body_html = str_replace('{{nps_link_html}}', $nps_link_html, $body_html);

    foreach ($text_vars as $k => $v) {
        $body_text = str_replace('{{' . $k . '}}', (string)$v, $body_text);
        $subject   = str_replace('{{' . $k . '}}', (string)$v, $subject);
    }

    if (!$body_text) {
        $body_text = strip_tags($body_html);
    }

    // Auto-inject tracking pixel into email HTML (after </body> or at end)
    if ($channel === 'email' && !empty($body_html) && !empty($nps_token)) {
        $pixel = '<img src="' . htmlspecialchars(APP_URL . '/track.php?t=' . urlencode($nps_token), ENT_QUOTES) . '" width="1" height="1" alt="" style="display:none;">';
        if (stripos($body_html, '</body>') !== false) {
            $body_html = str_ireplace('</body>', $pixel . '</body>', $body_html);
        } else {
            $body_html .= $pixel;
        }
    }

    $success   = false;
    $error_msg = null;

    // ── Send email ────────────────────────────────────────────────────────────
    if ($channel === 'email') {
        $to_address = $entry['to_address'] ?? '';
        if ($to_address) {
            $result = mailer_send_email([
                'to_email'  => $to_address,
                'to_name'   => $entry['to_name'] ?? '',
                'subject'   => $subject,
                'body_html' => $body_html,
                'body_text' => $body_text,
            ]);
            if ($result['success']) {
                $success = true;
                $emails_sent++;
                echo "  [OK] Email → {$to_address}\n";
            } else {
                $error_msg = $result['error'];
                echo "  [FAIL] Email: {$error_msg}\n";
            }
        } else {
            $error_msg = 'No email address';
            echo "  [FAIL] Missing to_address for queue entry {$queue_id}\n";
        }
    }

    // ── Send SMS ──────────────────────────────────────────────────────────────
    if ($channel === 'sms') {
        $to_address = $entry['to_address'] ?? '';
        if ($to_address) {
            $result = mailer_send_sms($to_address, $body_text);
            if ($result['success']) {
                $success = true;
                $sms_sent++;
                echo "  [OK] SMS → {$to_address}\n";
            } else {
                $error_msg = $result['error'];
                echo "  [FAIL] SMS: {$error_msg}\n";
            }
        } else {
            $error_msg = 'No phone number';
            echo "  [FAIL] Missing to_address for queue entry {$queue_id}\n";
        }
    }

    // ── Update queue status ───────────────────────────────────────────────────
    if ($success) {
        db_run(
            "UPDATE send_queue SET status = 'sent', sent_at = datetime('now') WHERE id = ?",
            [$queue_id]
        );

        if (!empty($entry['request_id'])) {
            db_run(
                "UPDATE review_requests SET state = 'sent', sent_at = datetime('now')
                 WHERE id = ? AND state = 'pending'",
                [(int)$entry['request_id']]
            );
        }

        log_event('info', 'process_queue: sent', ['queue_id' => $queue_id, 'channel' => $channel]);

    } else {
        db_run(
            "UPDATE send_queue SET status = 'failed', error_msg = ? WHERE id = ?",
            [$error_msg, $queue_id]
        );
        $failed++;
        log_event('error', 'process_queue: failed', ['queue_id' => $queue_id, 'error' => $error_msg]);
    }
}

// ── 2. Queue 5-day follow-up emails for non-responders ──────────────────────

$followup_tpl = db_fetch_one(
    "SELECT id, subject, body_html, body_text FROM email_templates WHERE name = 'emlekezetes_ertekeles' LIMIT 1"
);

if ($followup_tpl) {
    $followup_candidates = db_fetch_all(
        "SELECT rr.id, rr.nps_token,
                c.name    AS contact_name,
                c.email   AS contact_email,
                a.name    AS agent_name,
                a.signature  AS agent_signature,
                a.review_link AS agent_review_link,
                o.name    AS office_name,
                o.address AS office_address
         FROM review_requests rr
         LEFT JOIN contacts c ON c.id = rr.contact_id
         LEFT JOIN agents   a ON a.id = rr.agent_id
         LEFT JOIN offices  o ON o.id = a.office_id
         WHERE rr.state IN ('sent', 'opened')
           AND rr.sent_at < datetime('now', '-5 days')
           AND rr.nps_score IS NULL
           AND rr.star_rating IS NULL
           AND rr.clicked_at IS NULL
           AND c.email IS NOT NULL
           AND NOT EXISTS (
               SELECT 1 FROM follow_ups fu
               WHERE fu.request_id = rr.id
                 AND fu.type = 'reminder'
                 AND fu.resolved_at IS NULL
           )"
    );

    echo "\n5-day follow-ups: " . count($followup_candidates) . " eligible request(s).\n";

    foreach ($followup_candidates as $req) {
        // Build substitution vars
        $first_name  = explode(' ', trim($req['contact_name'] ?? ''))[0] ?? '';
        $nps_token   = $req['nps_token'] ?? '';
        $tracking_url = !empty($nps_token)
            ? APP_URL . '/click.php?t=' . urlencode($nps_token)
            : ($req['agent_review_link'] ?? '');
        $nps_url = !empty($nps_token)
            ? APP_URL . '/nps.php?t=' . urlencode($nps_token)
            : '';
        $nps_link_html = !empty($nps_url)
            ? '<a href="' . htmlspecialchars($nps_url, ENT_QUOTES) . '" style="display:inline-block;background:#1F2D3D;color:#F5F0E6;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;">Értékelés küldése →</a>'
            : '';

        $text_vars = [
            'nev'            => $first_name,
            'ugyfelnev'      => $req['contact_name']    ?? '',
            'ugynok_nev'     => $req['agent_name']      ?? '',
            'ugynok_alairas' => $req['agent_signature'] ?? ($req['agent_name'] ?? ''),
            'iroda_neve'     => $req['office_name']     ?? '',
            'iroda_cim'      => $req['office_address']  ?? '',
            'review_link'    => $tracking_url,
            'nps_link'       => $nps_url,
            'nps_link_html'  => $nps_link_html,
        ];
        $html_vars = $text_vars;
        unset($html_vars['nps_link_html']);

        $subject   = $followup_tpl['subject']   ?? 'Emlékeztető: értékelés';
        $body_html = $followup_tpl['body_html'] ?? '';
        $body_text = $followup_tpl['body_text'] ?? '';

        foreach ($html_vars as $k => $v) {
            $body_html = str_replace('{{' . $k . '}}', htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), $body_html);
        }
        $body_html = str_replace('{{nps_link_html}}', $nps_link_html, $body_html);

        foreach ($text_vars as $k => $v) {
            $body_text = str_replace('{{' . $k . '}}', (string)$v, $body_text);
            $subject   = str_replace('{{' . $k . '}}', (string)$v, $subject);
        }

        db_insert('send_queue', [
            'request_id'   => $req['id'],
            'channel'      => 'email',
            'to_address'   => $req['contact_email'],
            'to_name'      => $req['contact_name'],
            'subject'      => $subject,
            'body_html'    => $body_html,
            'body_text'    => $body_text,
            'scheduled_at' => date('Y-m-d H:i:s'),
        ]);

        db_insert('follow_ups', [
            'request_id' => $req['id'],
            'due_at'     => date('Y-m-d H:i:s'),
            'type'       => 'reminder',
        ]);

        $follow_ups_created++;
        echo "  Queued follow-up for request #{$req['id']} → {$req['contact_email']}\n";
        log_event('info', 'process_queue: 5-day follow-up queued', [
            'request_id' => $req['id'],
            'to'         => $req['contact_email'],
        ]);
    }
} else {
    echo "\nSkipping 5-day follow-ups: template 'emlekezetes_ertekeles' not found.\n";
}

// ── 3. Create escalation tasks for 7-day non-responders ─────────────────────

$stale = db_fetch_all(
    "SELECT rr.id
     FROM review_requests rr
     WHERE rr.state IN ('sent', 'opened')
       AND rr.sent_at < datetime('now', '-7 days')
       AND rr.published_at IS NULL
       AND rr.nps_score IS NULL
       AND NOT EXISTS (
           SELECT 1 FROM follow_ups fu
           WHERE fu.request_id = rr.id
             AND fu.type = 'escalation'
             AND fu.resolved_at IS NULL
       )"
);

echo "\n7-day escalations: " . count($stale) . " stale request(s).\n";

foreach ($stale as $req) {
    db_insert('follow_ups', [
        'request_id' => $req['id'],
        'due_at'     => date('Y-m-d H:i:s', time() + 86400),
        'type'       => 'escalation',
    ]);

    db_run(
        "UPDATE review_requests SET state = 'waiting' WHERE id = ?",
        [$req['id']]
    );

    echo "  Escalation created for request #{$req['id']}\n";
    log_event('info', 'process_queue: 7-day escalation created', ['request_id' => $req['id']]);
}

// ── Summary ──────────────────────────────────────────────────────────────────

$elapsed = round(microtime(true) - $start_time, 2);
echo "\nDone: {$emails_sent} emails, {$sms_sent} SMS, {$failed} failed, {$follow_ups_created} follow-ups. ({$elapsed}s)\n";
log_event('info', 'process_queue: complete', [
    'emails_sent'        => $emails_sent,
    'sms_sent'           => $sms_sent,
    'failed'             => $failed,
    'follow_ups_created' => $follow_ups_created,
    'elapsed_sec'        => $elapsed,
]);

exit($failed > 0 ? 1 : 0);
