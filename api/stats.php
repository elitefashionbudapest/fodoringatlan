<?php
/**
 * Fodor Review OS — Stats API
 * Aggregate KPI dashboard statistics from all tables.
 * SQLite-compatible queries only (no MySQL DATE_FORMAT/DATE_SUB/NOW/TIMESTAMPDIFF).
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

require_auth();
rate_limit_check('stats');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

// ─── KPI ────────────────────────────────────────────────────────────────────

// New reviews this calendar month
$new_reviews_month_row = db_fetch_one(
    "SELECT COUNT(*) AS cnt FROM google_reviews
     WHERE strftime('%Y-%m', published_at) = strftime('%Y-%m', 'now')",
    []
);
$new_reviews_month = (int)($new_reviews_month_row['cnt'] ?? 0);

// Average star rating (all google reviews)
$avg_star_row = db_fetch_one(
    'SELECT ROUND(AVG(star_rating), 2) AS avg FROM google_reviews',
    []
);
$avg_star = (float)($avg_star_row['avg'] ?? 0);

// Conversion rate = published / total review_requests
$conv_row = db_fetch_one(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN state = 'published' THEN 1 ELSE 0 END) AS published
     FROM review_requests",
    []
);
$conv_total     = (int)($conv_row['total'] ?? 0);
$conv_published = (int)($conv_row['published'] ?? 0);
$conversion_rate = $conv_total > 0
    ? round($conv_published / $conv_total * 100, 1)
    : 0.0;

// Total sent review requests
$total_sent_row = db_fetch_one(
    "SELECT COUNT(*) AS cnt FROM review_requests WHERE state != 'pending'",
    []
);
$total_sent = (int)($total_sent_row['cnt'] ?? 0);

// Total reviews in google_reviews
$total_reviews_row = db_fetch_one('SELECT COUNT(*) AS cnt FROM google_reviews', []);
$total_reviews = (int)($total_reviews_row['cnt'] ?? 0);

$published_rate = $conversion_rate;

// SLA compliance: % of negative requests (star_rating <= 3) followed up within 4 hours
$sla_row = db_fetch_one(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE
            WHEN fu.resolved_at IS NOT NULL
             AND CAST((julianday(fu.resolved_at) - julianday(rr.created_at)) * 24 AS INTEGER) <= 4
            THEN 1 ELSE 0
        END) AS compliant
     FROM review_requests rr
     LEFT JOIN follow_ups fu ON fu.request_id = rr.id
     WHERE rr.star_rating <= 3 AND rr.star_rating IS NOT NULL",
    []
);
$sla_total     = (int)($sla_row['total'] ?? 0);
$sla_compliant = (int)($sla_row['compliant'] ?? 0);
$sla_compliance = $sla_total > 0
    ? round($sla_compliant / $sla_total * 100, 1)
    : 100.0;

// Active automations
$active_auto_row = db_fetch_one(
    'SELECT COUNT(*) AS cnt FROM automations WHERE active = 1',
    []
);
$active_automations = (int)($active_auto_row['cnt'] ?? 0);

$kpi = [
    'new_reviews_month'  => $new_reviews_month,
    'avg_star'           => $avg_star,
    'conversion_rate'    => $conversion_rate,
    'total_sent'         => $total_sent,
    'total_reviews'      => $total_reviews,
    'published_rate'     => $published_rate,
    'sla_compliance'     => $sla_compliance,
    'active_automations' => $active_automations,
];

// ─── FUNNEL 30D ─────────────────────────────────────────────────────────────

$funnel_30d = db_fetch_all(
    "SELECT
        date(rr.created_at)                                                  AS day,
        COUNT(*)                                                             AS requests,
        SUM(CASE WHEN rr.opened_at IS NOT NULL THEN 1 ELSE 0 END)           AS opened,
        SUM(CASE WHEN rr.nps_score IS NOT NULL THEN 1 ELSE 0 END)           AS nps_done,
        SUM(CASE WHEN rr.state = 'published' THEN 1 ELSE 0 END)             AS published
     FROM review_requests rr
     WHERE rr.created_at >= datetime('now', '-30 days')
     GROUP BY date(rr.created_at)
     ORDER BY day ASC",
    []
);

// ─── CHANNEL BREAKDOWN ──────────────────────────────────────────────────────

$channel_raw = db_fetch_all(
    "SELECT channel, COUNT(*) AS cnt
     FROM review_requests
     GROUP BY channel",
    []
);
$channel_total = array_sum(array_column($channel_raw, 'cnt'));
$channel_breakdown = array_map(function ($row) use ($channel_total) {
    return [
        'channel' => $row['channel'],
        'count'   => (int)$row['cnt'],
        'pct'     => $channel_total > 0
            ? round($row['cnt'] / $channel_total * 100, 1)
            : 0,
    ];
}, $channel_raw);

// ─── STAR DISTRIBUTION ──────────────────────────────────────────────────────

$star_raw = db_fetch_all(
    'SELECT star_rating, COUNT(*) AS cnt
     FROM google_reviews
     WHERE star_rating BETWEEN 1 AND 5
     GROUP BY star_rating',
    []
);
$star_distribution = ['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0];
foreach ($star_raw as $row) {
    $star_distribution[(string)(int)$row['star_rating']] = (int)$row['cnt'];
}

// ─── OFFICE COMPARE ─────────────────────────────────────────────────────────

$office_compare = db_fetch_all(
    "SELECT
        o.name AS office_name,
        ROUND(AVG(gr.star_rating), 2)                               AS avg_star,
        COUNT(DISTINCT gr.id)                                       AS reviews,
        CASE WHEN COUNT(rr.id) > 0
             THEN ROUND(
                 SUM(CASE WHEN rr.state = 'published' THEN 1 ELSE 0 END)
                 * 100.0 / COUNT(rr.id), 1)
             ELSE 0 END                                             AS conv_rate
     FROM offices o
     LEFT JOIN google_reviews gr ON gr.office_id = o.id
     LEFT JOIN agents a ON a.office_id = o.id
     LEFT JOIN review_requests rr ON rr.agent_id = a.id
     GROUP BY o.id, o.name
     ORDER BY avg_star DESC",
    []
);

// ─── AGENT LEADERBOARD ──────────────────────────────────────────────────────
// Note: google_reviews link to office, not agent. Use review_requests.star_rating
// (set when published) for per-agent averages.

$agent_raw = db_fetch_all(
    "SELECT
        a.id,
        a.name                                                           AS agent_name,
        COUNT(rr.id)                                                     AS req_count,
        SUM(CASE WHEN rr.state = 'published' THEN 1 ELSE 0 END)         AS pub_count,
        ROUND(AVG(CASE WHEN rr.star_rating IS NOT NULL
                       THEN CAST(rr.star_rating AS REAL) END), 2)       AS avg_star,
        CASE WHEN COUNT(rr.id) > 0
             THEN ROUND(
                 SUM(CASE WHEN rr.state = 'published' THEN 1 ELSE 0 END)
                 * 100.0 / COUNT(rr.id), 1)
             ELSE 0 END                                                  AS conv
     FROM agents a
     LEFT JOIN review_requests rr ON rr.agent_id = a.id
     GROUP BY a.id, a.name
     ORDER BY avg_star DESC, conv DESC",
    []
);

$agent_leaderboard = [];
foreach ($agent_raw as $row) {
    $avg  = (float)($row['avg_star'] ?? 0);
    $conv = (float)($row['conv'] ?? 0);

    // Trend: last 30d vs prior 30d published rate
    $trend_row = db_fetch_one(
        "SELECT
            SUM(CASE WHEN created_at >= datetime('now', '-30 days')
                      AND state = 'published' THEN 1 ELSE 0 END) AS recent_pub,
            SUM(CASE WHEN created_at >= datetime('now', '-60 days')
                      AND created_at < datetime('now', '-30 days')
                      AND state = 'published' THEN 1 ELSE 0 END) AS prev_pub,
            SUM(CASE WHEN created_at >= datetime('now', '-30 days')
                      THEN 1 ELSE 0 END)                          AS recent_total,
            SUM(CASE WHEN created_at >= datetime('now', '-60 days')
                      AND created_at < datetime('now', '-30 days')
                      THEN 1 ELSE 0 END)                          AS prev_total
         FROM review_requests WHERE agent_id = ?",
        [$row['id']]
    );

    $recent_conv = $trend_row && $trend_row['recent_total'] > 0
        ? $trend_row['recent_pub'] / $trend_row['recent_total'] * 100
        : 0;
    $prev_conv = $trend_row && $trend_row['prev_total'] > 0
        ? $trend_row['prev_pub'] / $trend_row['prev_total'] * 100
        : 0;
    $improving = $recent_conv > $prev_conv;

    if ($avg >= 4.9 && $conv >= 70) {
        $status = 'top';
    } elseif ($conv >= 65 && $improving) {
        $status = 'rising';
    } elseif ($conv < 50 || ($avg > 0 && $avg < 4.0)) {
        $status = 'attention';
    } else {
        $status = 'stable';
    }

    $agent_leaderboard[] = [
        'agent_name' => $row['agent_name'],
        'requests'   => (int)$row['req_count'],
        'reviews'    => (int)($row['pub_count'] ?? 0),
        'avg_star'   => $avg,
        'conv'       => $conv,
        'status'     => $status,
    ];
}

// ─── RECENT TREND (last 14 days of google reviews) ───────────────────────────

$recent_trend = db_fetch_all(
    "SELECT date(published_at) AS date, COUNT(*) AS count
     FROM google_reviews
     WHERE published_at >= datetime('now', '-14 days')
     GROUP BY date(published_at)
     ORDER BY date ASC",
    []
);

// ─── RESPONSE ───────────────────────────────────────────────────────────────

json_response([
    'kpi'               => $kpi,
    'funnel_30d'        => $funnel_30d,
    'channel_breakdown' => $channel_breakdown,
    'star_distribution' => $star_distribution,
    'office_compare'    => $office_compare,
    'agent_leaderboard' => $agent_leaderboard,
    'recent_trend'      => $recent_trend,
]);
