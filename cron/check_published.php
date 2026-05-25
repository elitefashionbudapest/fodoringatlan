#!/usr/bin/env php
<?php
/**
 * Fodor Review OS — Cron: check_published.php
 * Checks whether review requests have resulted in a published Google review.
 * Matches by author name + approximate date against the Places API.
 * Marks disappeared requests and inserts alert follow-ups.
 *
 * Run: php cron/check_published.php
 * Recommended schedule: every 6 hours
 */

// ─── CLI guard ───────────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script may only be run from the command line.');
}

require __DIR__ . '/../api/config.php';
require __DIR__ . '/../api/db.php';

// ─── Main ────────────────────────────────────────────────────────────────────

$start_time          = microtime(true);
$checked             = 0;
$marked_published    = 0;
$marked_disappeared  = 0;
$skipped_no_key      = 0;
$errors              = [];

log_event('info', 'check_published: start');

// No API key → log and exit gracefully
if (!defined('GOOGLE_API_KEY') || GOOGLE_API_KEY === '') {
    echo "No API key, skipping verification.\n";
    log_event('warning', 'check_published: no GOOGLE_API_KEY, skipping verification');
    exit(0);
}

// Fetch candidates:
// - state='waiting'  (NPS done, user said they'd publish)
// - state='sent' AND sent_at < 3 days ago (waiting but not responded)
$candidates = db_fetch_all(
    "SELECT rr.id, rr.state, rr.sent_at, rr.created_at, rr.agent_id,
            c.name  AS contact_name,
            c.id    AS contact_id,
            o.google_place_id
     FROM review_requests rr
     LEFT JOIN contacts c ON c.id = rr.contact_id
     LEFT JOIN agents a   ON a.id = rr.agent_id
     LEFT JOIN offices o  ON o.id = a.office_id
     WHERE (
         rr.state = 'waiting'
         OR (rr.state = 'sent' AND rr.sent_at < datetime('now', '-3 days'))
     )
       AND rr.published_at IS NULL
       AND o.google_place_id IS NOT NULL
       AND o.google_place_id != ''
     ORDER BY rr.sent_at ASC"
);

echo "Found " . count($candidates) . " request(s) to check.\n";

// Cache Places API responses per place_id to avoid redundant calls
$places_cache = [];

foreach ($candidates as $req) {
    $request_id    = (int)$req['id'];
    $contact_name  = $req['contact_name'] ?? '';
    $place_id      = $req['google_place_id'] ?? '';
    $sent_at       = $req['sent_at'] ?? $req['created_at'] ?? '';
    $sent_ts       = strtotime($sent_at);
    $days_since    = (time() - $sent_ts) / 86400;

    echo "Checking request [{$request_id}] contact=\"{$contact_name}\" sent={$sent_at}...\n";
    $checked++;

    if (!$place_id) {
        echo "  [SKIP] No google_place_id for office.\n";
        $skipped_no_key++;
        continue;
    }

    // Fetch reviews (from cache or API)
    if (!isset($places_cache[$place_id])) {
        $result = _fetch_place_reviews($place_id);
        if (!$result['success']) {
            $errors[] = "Request {$request_id}: " . $result['error'];
            echo "  [ERROR] " . $result['error'] . "\n";
            continue;
        }
        $places_cache[$place_id] = $result['reviews'];
    }

    $google_reviews = $places_cache[$place_id];

    // Match: author name contains contact name (case-insensitive), and review date within ±7 days of sent_at
    $found = false;
    foreach ($google_reviews as $gr) {
        $author = $gr['author_name'] ?? '';
        $gr_ts  = isset($gr['time']) ? (int)$gr['time'] : 0;

        $name_match = (
            stripos($author, $contact_name) !== false ||
            stripos($contact_name, $author) !== false
        );
        $date_diff  = abs($gr_ts - $sent_ts);
        $date_match = ($date_diff <= 7 * 86400); // within 7 days of sent_at

        if ($name_match && $date_match) {
            $found = true;

            // Upsert into google_reviews if not already there
            $existing_review = db_fetch_one(
                "SELECT id FROM google_reviews
                 WHERE office_id = (
                     SELECT o.id FROM offices o
                     JOIN agents ag ON ag.office_id = o.id
                     JOIN review_requests rr ON rr.agent_id = ag.id
                     WHERE rr.id = ? LIMIT 1
                 )
                 AND author_name = ?
                 AND date(review_at) = date(?)",
                [$request_id, $author, date('Y-m-d H:i:s', $gr_ts)]
            );

            if (!$existing_review) {
                $office_id = _get_office_id_for_request($request_id);
                if ($office_id) {
                    db_insert('google_reviews', [
                        'office_id'   => $office_id,
                        'author_name' => $author,
                        'star_rating' => (int)($gr['rating'] ?? 0),
                        'review_text' => $gr['text'] ?? '',
                        'review_at'   => $gr_ts ? date('Y-m-d H:i:s', $gr_ts) : date('Y-m-d H:i:s'),
                        'profile_url' => $gr['author_url'] ?? '',
                        'synced_at'   => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            break;
        }
    }

    if ($found) {
        db_update('review_requests', [
            'state'        => 'published',
            'published_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$request_id]);

        $marked_published++;
        echo "  [PUBLISHED] Review found on Google.\n";
        log_event('info', 'check_published: marked published', ['request_id' => $request_id]);

    } elseif ($days_since >= 14) {
        // Not found after 14 days → mark disappeared + create alert follow_up
        db_update('review_requests', [
            'state' => 'disappeared',
        ], 'id = ?', [$request_id]);

        // Insert alert follow_up if not already exists
        $existing_fu = db_fetch_one(
            "SELECT id FROM follow_ups
             WHERE contact_id = ? AND type = 'alert' AND state = 'pending'",
            [$req['contact_id']]
        );

        if (!$existing_fu) {
            db_insert('follow_ups', [
                'contact_id'  => $req['contact_id'],
                'agent_id'    => $req['agent_id'],
                'type'        => 'alert',
                'due_at'      => date('Y-m-d H:i:s'),
                'state'       => 'pending',
                'note'        => "Az értékelés 14 napja nem jelent meg a Google-on. Kérd meg személyesen a kontaktot!",
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        }

        $marked_disappeared++;
        echo "  [DISAPPEARED] 14+ days, no review found. Alert follow-up created.\n";
        log_event('warning', 'check_published: marked disappeared', [
            'request_id' => $request_id,
            'days_since' => round($days_since, 1),
        ]);

    } else {
        echo "  [WAITING] Not yet found ({$days_since} days since send).\n";
    }

    // Polite delay
    usleep(150000); // 150ms
}

// ── Summary ──────────────────────────────────────────────────────────────────

$elapsed = round(microtime(true) - $start_time, 2);
$summary = "Checked {$checked} requests: {$marked_published} published, {$marked_disappeared} disappeared. ({$elapsed}s)";
echo "\n{$summary}\n";

if ($errors) {
    echo "Errors (" . count($errors) . "):\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}

log_event('info', 'check_published: complete', [
    'checked'            => $checked,
    'marked_published'   => $marked_published,
    'marked_disappeared' => $marked_disappeared,
    'elapsed_sec'        => $elapsed,
    'error_count'        => count($errors),
]);

exit(empty($errors) ? 0 : 1);

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Fetch reviews array for a google_place_id.
 *
 * @return array [success, reviews, error]
 */
function _fetch_place_reviews(string $place_id): array
{
    $url = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query([
        'place_id' => $place_id,
        'fields'   => 'reviews',
        'key'      => GOOGLE_API_KEY,
        'language' => 'hu',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        return ['success' => false, 'reviews' => [], 'error' => "cURL: {$curl_err}"];
    }

    if ($http_code !== 200) {
        return ['success' => false, 'reviews' => [], 'error' => "HTTP {$http_code}"];
    }

    $json = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'reviews' => [], 'error' => 'Invalid JSON'];
    }

    if (($json['status'] ?? '') !== 'OK') {
        $msg = $json['error_message'] ?? ($json['status'] ?? 'API error');
        return ['success' => false, 'reviews' => [], 'error' => $msg];
    }

    return [
        'success' => true,
        'reviews' => $json['result']['reviews'] ?? [],
        'error'   => null,
    ];
}

/**
 * Resolve office_id for a given review_request id.
 */
function _get_office_id_for_request(int $request_id): ?int
{
    $row = db_fetch_one(
        'SELECT o.id
         FROM review_requests rr
         JOIN agents ag ON ag.id = rr.agent_id
         JOIN offices o  ON o.id = ag.office_id
         WHERE rr.id = ?
         LIMIT 1',
        [$request_id]
    );

    return $row ? (int)$row['id'] : null;
}
