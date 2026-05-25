<?php
/**
 * Fodor Review OS — Configuration Template
 *
 * Másold: api/config.php  (ez a fájl nem kerül gitbe)
 * Vagy:   futtasd az install/index.php telepítőt — az automatikusan megírja.
 */

// ── DATABASE ──────────────────────────────────────────────────────────────────
define('DB_PATH', dirname(__DIR__) . '/data/fodor.db');

// ── SMTP ─────────────────────────────────────────────────────────────────────
define('SMTP_HOST',      'mail.example.com');
define('SMTP_USER',      'info@example.com');
define('SMTP_PASS',      'CONFIGURE_ME');
define('SMTP_PORT',      587);
define('SMTP_FROM_NAME', 'Fodor Ingatlan');
define('SMTP_SECURE',    'tls');  // 'tls' or 'ssl'

// ── TWILIO ───────────────────────────────────────────────────────────────────
define('TWILIO_SID',   'CONFIGURE_ME');
define('TWILIO_TOKEN', 'CONFIGURE_ME');
define('TWILIO_FROM',  '+36XXXXXXXXX');

// ── GOOGLE ───────────────────────────────────────────────────────────────────
define('GOOGLE_API_KEY', '');  // Empty → mock data used

// ── RECAPTCHA v2 ─────────────────────────────────────────────────────────────
define('RECAPTCHA_SITE_KEY',   '');
define('RECAPTCHA_SECRET_KEY', '');

// ── APPLICATION ───────────────────────────────────────────────────────────────
define('APP_URL',   'https://yourdomain.com');  // No trailing slash
define('APP_ENV',   'production');
define('LOG_PATH',  dirname(__DIR__) . '/data/app.log');
define('LOG_LEVEL', 'info');

// ── RATE LIMITING ─────────────────────────────────────────────────────────────
define('RATE_LIMIT_MAX',    60);
define('RATE_LIMIT_WINDOW', 60);
