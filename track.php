<?php
/**
 * Email open tracking pixel.
 * Embedded as a 1x1 transparent GIF in outgoing emails.
 *
 * URL: /track.php?t=REQUEST_TOKEN
 *
 * No auth required — called by email clients.
 * No user input used in queries beyond the sanitized token.
 */

require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/db.php';

// Output the GIF immediately regardless of what happens next
// This ensures email clients don't time out waiting for a response
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Mon, 01 Jan 2001 00:00:00 GMT');

// Minimal 1x1 transparent GIF (binary)
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
flush();

// Now do the tracking in the background (after response is sent)
$token = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['t'] ?? '');
if (empty($token)) exit;

// Find the request
$request = db_fetch_one(
    'SELECT id, state, opened_at FROM review_requests WHERE nps_token = ?',
    [$token]
);

if ($request && empty($request['opened_at'])) {
    db_run(
        'UPDATE review_requests SET opened_at = datetime(\'now\'), state = \'opened\' WHERE id = ? AND opened_at IS NULL',
        [$request['id']]
    );
    log_event('debug', 'Email megnyitva (pixel)', ['request_id' => $request['id']]);
}
exit;
