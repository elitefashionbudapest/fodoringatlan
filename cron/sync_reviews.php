#!/usr/bin/env php
<?php
/**
 * Fodor Review OS — Cron: sync_reviews.php
 * Fetches Google reviews for all offices via Places API and upserts into DB.
 *
 * Run: php cron/sync_reviews.php
 * Recommended schedule: every hour
 */

// ─── CLI guard ───────────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script may only be run from the command line.');
}

require __DIR__ . '/../api/config.php';
require __DIR__ . '/../api/db.php';

// ─── Main ────────────────────────────────────────────────────────────────────

$start_time      = microtime(true);
$offices_synced  = 0;
$reviews_inserted = 0;
$offices_updated  = 0;
$errors           = [];

log_event('info', 'sync_reviews: start');

$offices = db_fetch_all(
    "SELECT id, name, google_place_id
     FROM offices
     WHERE google_place_id IS NOT NULL
       AND google_place_id != ''
       AND (status IS NULL OR status != 'deleted')"
);

if (empty($offices)) {
    echo "No offices with google_place_id found. Exiting.\n";
    log_event('info', 'sync_reviews: no offices to sync');
    exit(0);
}

echo "Found " . count($offices) . " office(s) to sync.\n";

foreach ($offices as $office) {
    $place_id  = $office['google_place_id'];
    $office_id = (int)$office['id'];

    echo "Syncing office [{$office_id}] {$office['name']} (place_id: {$place_id})...\n";

    // If no API key configured, mock the response
    if (!defined('GOOGLE_API_KEY') || GOOGLE_API_KEY === '') {
        log_event('warning', 'sync_reviews: no GOOGLE_API_KEY, skipping', ['office_id' => $office_id]);
        echo "  [SKIP] No GOOGLE_API_KEY configured.\n";
        continue;
    }

    $result = _fetch_google_place_reviews($place_id);

    if (!$result['success']) {
        $errors[] = "Office {$office_id}: " . $result['error'];
        log_event('error', 'sync_reviews: API error', [
            'office_id' => $office_id,
            'error'     => $result['error'],
        ]);
        echo "  [ERROR] " . $result['error'] . "\n";
        continue;
    }

    $data = $result['data'];

    // Upsert reviews
    $new_count = 0;
    foreach ($data['reviews'] ?? [] as $review) {
        $author_name  = $review['author_name']           ?? 'Ismeretlen';
        $star_rating  = (int)($review['rating']          ?? 0);
        $review_text  = $review['text']                  ?? '';
        $review_time  = isset($review['time'])
            ? date('Y-m-d H:i:s', (int)$review['time'])
            : date('Y-m-d H:i:s');
        $profile_url  = $review['author_url']            ?? '';

        // Check if review exists (match by office + author + approximate time within 1 day)
        $existing = db_fetch_one(
            "SELECT id FROM google_reviews
             WHERE office_id = ?
               AND author_name = ?
               AND date(review_at) = date(?)",
            [$office_id, $author_name, $review_time]
        );

        if (!$existing) {
            db_insert('google_reviews', [
                'office_id'   => $office_id,
                'author_name' => $author_name,
                'star_rating' => $star_rating,
                'review_text' => $review_text,
                'review_at'   => $review_time,
                'profile_url' => $profile_url,
                'synced_at'   => date('Y-m-d H:i:s'),
            ]);
            $new_count++;
            $reviews_inserted++;
        } else {
            // Update text/rating in case it was edited
            db_update('google_reviews', [
                'star_rating' => $star_rating,
                'review_text' => $review_text,
                'synced_at'   => date('Y-m-d H:i:s'),
            ], 'id = ?', [$existing['id']]);
        }
    }

    // Update office avg_rating and review_count
    $avg_rating   = isset($data['rating'])              ? (float)$data['rating']              : null;
    $review_count = isset($data['user_ratings_total'])  ? (int)$data['user_ratings_total']    : null;

    $office_update = ['synced_at' => date('Y-m-d H:i:s')];
    if ($avg_rating !== null) {
        $office_update['avg_rating'] = $avg_rating;
    }
    if ($review_count !== null) {
        $office_update['review_count'] = $review_count;
    }

    db_update('offices', $office_update, 'id = ?', [$office_id]);
    $offices_synced++;
    $offices_updated++;

    echo "  Done. New reviews: {$new_count}, avg_rating: {$avg_rating}, total: {$review_count}\n";

    log_event('info', 'sync_reviews: office synced', [
        'office_id'       => $office_id,
        'new_reviews'     => $new_count,
        'avg_rating'      => $avg_rating,
        'total_reviews'   => $review_count,
    ]);

    // Polite delay to avoid API rate limiting
    usleep(200000); // 200ms
}

$elapsed = round(microtime(true) - $start_time, 2);

$summary = "Synced {$offices_synced} offices, inserted {$reviews_inserted} reviews, updated {$offices_updated} offices. ({$elapsed}s)";
echo "\n{$summary}\n";

if ($errors) {
    echo "Errors (" . count($errors) . "):\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}

log_event('info', 'sync_reviews: complete', [
    'offices_synced'   => $offices_synced,
    'reviews_inserted' => $reviews_inserted,
    'offices_updated'  => $offices_updated,
    'elapsed_sec'      => $elapsed,
    'error_count'      => count($errors),
]);

exit(empty($errors) ? 0 : 1);

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Fetch place details (reviews, rating, user_ratings_total) from Google Places API.
 *
 * @return array [success, data, error]
 */
function _fetch_google_place_reviews(string $place_id): array
{
    $url = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query([
        'place_id' => $place_id,
        'fields'   => 'reviews,rating,user_ratings_total',
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
        return ['success' => false, 'data' => null, 'error' => "cURL error: {$curl_err}"];
    }

    if ($http_code !== 200) {
        return ['success' => false, 'data' => null, 'error' => "HTTP {$http_code}"];
    }

    $json = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'data' => null, 'error' => 'Invalid JSON response'];
    }

    if (($json['status'] ?? '') !== 'OK') {
        $msg = $json['error_message'] ?? ($json['status'] ?? 'Unknown API error');
        return ['success' => false, 'data' => null, 'error' => "API status: {$msg}"];
    }

    $result = $json['result'] ?? [];

    return [
        'success' => true,
        'data'    => [
            'reviews'             => $result['reviews']             ?? [],
            'rating'              => $result['rating']              ?? null,
            'user_ratings_total'  => $result['user_ratings_total']  ?? null,
        ],
        'error'   => null,
    ];
}
