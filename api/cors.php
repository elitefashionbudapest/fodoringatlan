<?php
/**
 * Fodor Review OS — CORS & Security Headers
 *
 * Include this file at the very top of every API endpoint,
 * before any output is produced.
 *
 * Sets:
 *   - Content-Type: application/json; charset=utf-8
 *   - Security headers (OWASP baseline)
 *   - Handles HTTP OPTIONS preflight and exits cleanly
 */

// ----------------------------------------------------------------
// Content-Type
// ----------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');

// ----------------------------------------------------------------
// Security headers
// ----------------------------------------------------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: default-src \'self\'');

// Prevent information leakage
header_remove('X-Powered-By');
header_remove('Server');

// ----------------------------------------------------------------
// CORS (restrict to same-origin by default; extend if needed)
// ----------------------------------------------------------------
// To allow a specific front-end origin, replace the wildcard check below:
//   $allowedOrigins = ['https://fodoringatlan.hu', 'https://app.fodoringatlan.hu'];
//
// For a fully internal admin API (no browser cross-origin calls),
// CORS headers are intentionally omitted — the CSP default-src 'self'
// already blocks unauthorised cross-origin requests from browsers.

// Uncomment and customise the block below if cross-origin requests are needed:
/*
$allowedOrigins = [
    'https://fodoringatlan.hu',
    'https://app.fodoringatlan.hu',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
}
*/

// ----------------------------------------------------------------
// OPTIONS preflight — answer and stop
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);  // No Content
    exit;
}
