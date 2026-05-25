<?php
/**
 * Fodor Review OS — Reviews API
 * Manages google_reviews table and Google Places sync.
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = sanitize_input($_GET['action'] ?? '');
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// The tracking pixel and NPS endpoints are public — all others require auth
require_auth();
rate_limit_check('reviews');

// ─── GET ────────────────────────────────────────────────────────────────────

if ($method === 'GET') {

    // GET /api/reviews.php?action=sync_all — trigger sync for all offices
    if ($action === 'sync_all') {
        $offices = db_fetch_all(
            "SELECT id FROM offices WHERE google_place_id IS NOT NULL AND google_place_id != ''",
            []
        );

        $results = [];
        foreach ($offices as $office) {
            $results[] = _sync_office((int)$office['id']);
        }

        log_event('info', 'Sync all offices triggered', ['count' => count($results)]);
        json_response(['synced' => count($results), 'results' => $results]);
    }

    // GET /api/reviews.php?action=recent&limit=5 — recent reviews for dashboard stream
    if ($action === 'recent') {
        $limit   = min((int)($_GET['limit'] ?? 5), 50);
        $reviews = db_fetch_all(
            'SELECT gr.*, o.name AS office_name
             FROM google_reviews gr
             LEFT JOIN offices o ON o.id = gr.office_id
             ORDER BY gr.published_at DESC
             LIMIT ?',
            [$limit]
        );
        json_response(['reviews' => $reviews]);
    }

    // GET /api/reviews.php — list with filters
    $where    = ['1=1'];
    $params   = [];
    $page     = max(1, (int)($_GET['page']  ?? 1));
    $limit    = min((int)($_GET['limit'] ?? 20), 100);
    $offset   = ($page - 1) * $limit;

    if (!empty($_GET['office_id'])) {
        $where[]  = 'gr.office_id = ?';
        $params[] = (int)$_GET['office_id'];
    }
    if (!empty($_GET['min_star'])) {
        $where[]  = 'gr.star_rating >= ?';
        $params[] = (int)$_GET['min_star'];
    }
    if (!empty($_GET['max_star'])) {
        $where[]  = 'gr.star_rating <= ?';
        $params[] = (int)$_GET['max_star'];
    }
    if (!empty($_GET['q'])) {
        $q        = '%' . sanitize_input($_GET['q']) . '%';
        $where[]  = '(gr.author_name LIKE ? OR gr.review_text LIKE ?)';
        $params[] = $q;
        $params[] = $q;
    }

    $where_sql = implode(' AND ', $where);

    $count_row = db_fetch_one(
        "SELECT COUNT(*) AS cnt FROM google_reviews gr WHERE $where_sql",
        $params
    );
    $total = (int)($count_row['cnt'] ?? 0);

    $reviews = db_fetch_all(
        "SELECT gr.*, o.name AS office_name
         FROM google_reviews gr
         LEFT JOIN offices o ON o.id = gr.office_id
         WHERE $where_sql
         ORDER BY gr.published_at DESC
         LIMIT ? OFFSET ?",
        array_merge($params, [$limit, $offset])
    );

    json_response([
        'reviews'    => $reviews,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total'       => $total,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

// ─── POST ───────────────────────────────────────────────────────────────────

if ($method === 'POST') {

    // POST /api/reviews.php?action=sync&office_id=X — sync from Google Places API
    if ($action === 'sync') {
        $office_id = isset($_GET['office_id']) ? (int)$_GET['office_id'] : 0;
        if (!$office_id) {
            json_error('office_id is required');
        }

        $result = _sync_office($office_id);
        json_response($result);
    }

    json_error('Unknown action', 400);
}

// ─── PUT ────────────────────────────────────────────────────────────────────

if ($method === 'PUT') {
    if (!$id) {
        json_error('id is required');
    }

    $review = db_fetch_one('SELECT id FROM google_reviews WHERE id = ?', [$id]);
    if (!$review) {
        json_error('Review not found', 404);
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        json_error('Invalid JSON body');
    }

    $update = [];
    if (isset($body['reply_text'])) {
        $update['reply_text'] = sanitize_input($body['reply_text']);
        $update['reply_at']   = date('Y-m-d H:i:s');
    }

    if (empty($update)) {
        json_error('No fields to update');
    }

    $update['updated_at'] = date('Y-m-d H:i:s');
    db_update('google_reviews', $update, 'id = ?', [$id]);

    audit_log('review_replied', 'google_reviews', $id, ['reply_at' => $update['reply_at']]);
    log_event('info', 'Review reply added', ['id' => $id]);

    json_response(['id' => $id, 'message' => 'Reply saved']);
}

json_error('Method not allowed', 405);

// ─── HELPERS ────────────────────────────────────────────────────────────────

/**
 * Sync reviews for one office from Google Places API (or insert mock data).
 */
function _sync_office(int $office_id): array
{
    $office = db_fetch_one(
        'SELECT id, name, google_place_id FROM offices WHERE id = ?',
        [$office_id]
    );
    if (!$office) {
        return ['office_id' => $office_id, 'error' => 'Office not found'];
    }

    $api_key = defined('GOOGLE_API_KEY') ? GOOGLE_API_KEY : (getenv('GOOGLE_API_KEY') ?: '');

    if (empty($api_key) || empty($office['google_place_id'])) {
        // Insert mock data for development/demo
        $inserted = _insert_mock_reviews($office_id);
        log_event('info', 'Mock reviews inserted (no API key)', [
            'office_id' => $office_id,
            'count'     => $inserted,
        ]);
        return [
            'office_id' => $office_id,
            'source'    => 'mock',
            'inserted'  => $inserted,
        ];
    }

    // Call Google Places Details API
    $url = sprintf(
        'https://maps.googleapis.com/maps/api/place/details/json?place_id=%s&fields=reviews,rating,user_ratings_total&key=%s',
        urlencode($office['google_place_id']),
        urlencode($api_key)
    );

    $ctx      = stream_context_create(['http' => ['timeout' => 10]]);
    $response = @file_get_contents($url, false, $ctx);

    if ($response === false) {
        log_event('error', 'Google Places API request failed', ['office_id' => $office_id]);
        return ['office_id' => $office_id, 'error' => 'Google Places API unreachable'];
    }

    $data = json_decode($response, true);
    if (($data['status'] ?? '') !== 'OK') {
        log_event('error', 'Google Places API error', [
            'office_id' => $office_id,
            'status'    => $data['status'] ?? 'unknown',
        ]);
        return ['office_id' => $office_id, 'error' => 'Google Places API: ' . ($data['status'] ?? 'unknown')];
    }

    $result   = $data['result'] ?? [];
    $reviews  = $result['reviews'] ?? [];
    $inserted = 0;
    $skipped  = 0;

    foreach ($reviews as $r) {
        $author       = sanitize_input($r['author_name'] ?? '');
        $published_at = isset($r['time'])
            ? date('Y-m-d H:i:s', (int)$r['time'])
            : date('Y-m-d H:i:s');

        // Deduplicate by author + published_at
        $existing = db_fetch_one(
            'SELECT id FROM google_reviews WHERE office_id = ? AND author_name = ? AND published_at = ?',
            [$office_id, $author, $published_at]
        );

        if ($existing) {
            $skipped++;
            continue;
        }

        db_insert('google_reviews', [
            'office_id'    => $office_id,
            'author_name'  => $author,
            'star_rating'  => (int)($r['rating'] ?? 0),
            'review_text'  => sanitize_input($r['text'] ?? ''),
            'published_at' => $published_at,
            'source'       => 'google',
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
        $inserted++;
    }

    // Update office avg_rating and review_count
    $avg_rating        = (float)($result['rating'] ?? 0);
    $user_ratings_total = (int)($result['user_ratings_total'] ?? 0);
    db_update('offices', [
        'avg_rating'   => $avg_rating,
        'review_count' => $user_ratings_total,
        'synced_at'    => date('Y-m-d H:i:s'),
    ], 'id = ?', [$office_id]);

    log_event('info', 'Google Places sync completed', [
        'office_id' => $office_id,
        'inserted'  => $inserted,
        'skipped'   => $skipped,
    ]);

    audit_log('reviews_synced', 'offices', $office_id, [
        'inserted'  => $inserted,
        'skipped'   => $skipped,
        'avg_rating' => $avg_rating,
    ]);

    return [
        'office_id'  => $office_id,
        'source'     => 'google',
        'inserted'   => $inserted,
        'skipped'    => $skipped,
        'avg_rating' => $avg_rating,
        'total'      => $user_ratings_total,
    ];
}

/**
 * Insert 5 realistic Hungarian real estate mock reviews for an office.
 * Returns the count of newly inserted rows (skips duplicates).
 */
function _insert_mock_reviews(int $office_id): int
{
    $mock = [
        [
            'author_name'  => 'Kovács Erzsébet',
            'star_rating'  => 5,
            'review_text'  => 'Kiváló szolgáltatás! Az ingatlan adásvétele gördülékenyen zajlott, minden kérdésemre azonnal választ kaptam. Nagyon elégedett vagyok a csapattal, mindenkinek ajánlom őket.',
            'published_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
        ],
        [
            'author_name'  => 'Nagy Péter',
            'star_rating'  => 5,
            'review_text'  => 'Professzonális és megbízható iroda. Segítettek megtalálni az álomotthonomat, a folyamat végig átlátható volt. Különösen hálás vagyok a gyors ügyintézésért.',
            'published_at' => date('Y-m-d H:i:s', strtotime('-10 days')),
        ],
        [
            'author_name'  => 'Szabó Mária',
            'star_rating'  => 4,
            'review_text'  => 'Összességében jó tapasztalat volt. A munkatársak szakértők, bár a papírmunka kicsit tovább tartott a vártnál. Az eredmény viszont kitűnő lett, megkaptuk az ingatlanunkat.',
            'published_at' => date('Y-m-d H:i:s', strtotime('-18 days')),
        ],
        [
            'author_name'  => 'Tóth Gábor',
            'star_rating'  => 5,
            'review_text'  => 'Fodor Ingatlan csapata rendkívül segítőkész volt az egész folyamat során. Lakásvásárlás közben rengeteg stresszt vesznek le az ember válláról. Megbízható, ajánlott iroda!',
            'published_at' => date('Y-m-d H:i:s', strtotime('-25 days')),
        ],
        [
            'author_name'  => 'Horváth Katalin',
            'star_rating'  => 5,
            'review_text'  => 'Elképesztően jó tapasztalat! Eladtuk a régi házunkat és vettünk egy új lakást, mindezt ugyanazon az irodán keresztül. Minden zökkenőmentesen ment. Köszönjük a csapatnak!',
            'published_at' => date('Y-m-d H:i:s', strtotime('-40 days')),
        ],
    ];

    $inserted = 0;
    foreach ($mock as $r) {
        $existing = db_fetch_one(
            'SELECT id FROM google_reviews WHERE office_id = ? AND author_name = ? AND published_at = ?',
            [$office_id, $r['author_name'], $r['published_at']]
        );
        if ($existing) {
            continue;
        }

        db_insert('google_reviews', array_merge($r, [
            'office_id'  => $office_id,
            'source'     => 'mock',
            'created_at' => date('Y-m-d H:i:s'),
        ]));
        $inserted++;
    }

    return $inserted;
}
