<?php
require_once __DIR__ . '/cors.php';

// Catch any uncaught exception/error and return JSON instead of empty response
set_exception_handler(function(Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => 500], JSON_UNESCAPED_UNICODE);
    exit;
});

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

require_auth();
rate_limit_check('settings');

$method = $_SERVER['REQUEST_METHOD'];
$action = sanitize_input($_GET['action'] ?? '');

// Settings stored in a key-value table in SQLite
// We also support reading/writing config values at runtime

switch ($method) {
    case 'GET':
        if ($action === 'logs') {
            // Return last N lines of log file
            $lines = (int)($_GET['lines'] ?? 50);
            $lines = min($lines, 500);
            if (!file_exists(LOG_PATH)) {
                json_response(['logs' => []]);
            }
            $file = file(LOG_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $recent = array_slice($file, -$lines);
            $parsed = array_map(function($line) {
                $decoded = json_decode($line, true);
                return $decoded ?: ['raw' => $line];
            }, $recent);
            json_response(['logs' => array_reverse($parsed)]);
        }

        if ($action === 'cron_status') {
            // Check last run times from a simple marker file
            $crons = ['sync_reviews', 'process_queue', 'check_published'];
            $status = [];
            foreach ($crons as $cron) {
                $marker = __DIR__ . '/../data/cron_' . $cron . '.last';
                $status[$cron] = [
                    'last_run' => file_exists($marker) ? date('Y-m-d H:i:s', filemtime($marker)) : null,
                    'last_output' => file_exists($marker) ? trim(file_get_contents($marker)) : 'Még nem futott',
                ];
            }
            json_response(['crons' => $status]);
        }

        // GET /api/settings.php — return masked config values
        json_response([
            'smtp' => [
                'host'      => SMTP_HOST,
                'user'      => SMTP_USER,
                'pass'      => str_repeat('*', max(4, strlen(SMTP_PASS) - 2)) . substr(SMTP_PASS, -2),
                'port'      => SMTP_PORT,
                'from_name' => SMTP_FROM_NAME,
                'secure'    => SMTP_SECURE,
            ],
            'twilio' => [
                'sid'  => defined('TWILIO_SID') ? substr(TWILIO_SID, 0, 6) . '...' : '',
                'from' => defined('TWILIO_FROM') ? TWILIO_FROM : '',
            ],
            'google' => [
                'api_key_set'   => !empty(GOOGLE_API_KEY),
                'api_key_prefix' => !empty(GOOGLE_API_KEY) ? substr(GOOGLE_API_KEY, 0, 6) . '...' : '',
            ],
            'recaptcha' => [
                'site_key'   => RECAPTCHA_SITE_KEY,
                'secret_set' => !empty(RECAPTCHA_SECRET_KEY),
            ],
            'app' => [
                'env'       => APP_ENV,
                'log_level' => LOG_LEVEL,
            ],
        ]);
        break;

    case 'POST':
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        if ($action === 'save_config') {
            $cfg_path = __DIR__ . '/config.php';
            if (!file_exists($cfg_path)) {
                json_error('config.php nem található — futtasd az installert újra', 500);
            }
            if (!is_writable($cfg_path)) {
                json_error('config.php nem írható — chmod 644 szükséges', 500);
            }

            $field_map = [
                'smtp_host'      => 'SMTP_HOST',
                'smtp_user'      => 'SMTP_USER',
                'smtp_pass'      => 'SMTP_PASS',
                'smtp_port'      => 'SMTP_PORT',
                'smtp_from_name' => 'SMTP_FROM_NAME',
                'smtp_secure'    => 'SMTP_SECURE',
                'twilio_sid'     => 'TWILIO_SID',
                'twilio_token'   => 'TWILIO_TOKEN',
                'twilio_from'    => 'TWILIO_FROM',
                'google_api_key' => 'GOOGLE_API_KEY',
                'app_url'        => 'APP_URL',
            ];

            $content = file_get_contents($cfg_path);
            if ($content === false) {
                json_error('config.php olvasása sikertelen', 500);
            }
            $updated = [];

            foreach ($body as $key => $val) {
                $key = strtolower(sanitize_input((string)$key));
                if (!isset($field_map[$key])) continue;
                $val = (string)$val;
                // Skip: empty, all-asterisks, or masked pattern (e.g. "****XX")
                if ($val === '') continue;
                if (preg_match('/^\*+$/', $val)) continue;
                if (preg_match('/^\*{4,}[^*]{1,3}$/', $val)) continue;

                $const        = $field_map[$key];
                $const_quoted = preg_quote($const, '/');
                $val_to_write = $val;

                // Use preg_replace_callback to avoid PCRE backreference issues in replacement string.
                // Pattern matches both quoted strings and unquoted integers (e.g. define('PORT', 587))
                $new = preg_replace_callback(
                    "/define\('{$const_quoted}',\s*(?:'[^']*'|[0-9]+)\)/",
                    function() use ($const, $val_to_write) {
                        return "define('" . $const . "', '" . addslashes($val_to_write) . "')";
                    },
                    $content
                );

                if ($new !== null && $new !== $content) {
                    $content = $new;
                    $updated[] = $key;
                }
            }

            if (empty($updated)) {
                json_response(['success' => true, 'message' => 'Nincs változtatás (értékek megegyeznek).', 'updated' => []]);
            }

            if (file_put_contents($cfg_path, $content) === false) {
                json_error('Írási hiba — config.php nem mentve', 500);
            }

            log_event('info', 'config.php frissítve', ['keys' => $updated]);
            json_response(['success' => true, 'message' => 'Beállítások mentve.', 'updated' => $updated]);
        }

        if ($action === 'test_sms') {
            if (!defined('TWILIO_SID') || TWILIO_SID === 'CONFIGURE_ME' || empty(TWILIO_SID)) {
                json_error('Twilio nincs konfigurálva', 400);
            }
            $to = sanitize_input($body['test_phone'] ?? '');
            if (empty($to)) {
                json_error('Telefonszám szükséges', 400);
            }
            // Convert 06... → +36...
            $to = preg_replace('/^06/', '+36', $to);
            $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_SID . '/Messages.json';
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'To'   => $to,
                    'From' => TWILIO_FROM,
                    'Body' => 'Fodor Review OS — teszt SMS. Ha megkapja, az integráció működik.',
                ]),
                CURLOPT_USERPWD        => TWILIO_SID . ':' . TWILIO_TOKEN,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $result = json_decode($resp, true);
            if ($code >= 400) {
                json_error('Twilio hiba: ' . ($result['message'] ?? "HTTP {$code}"), 500);
            }
            log_event('info', 'Teszt SMS elküldve', ['to' => $to, 'sid' => $result['sid'] ?? '']);
            json_response(['success' => true, 'sid' => $result['sid'] ?? '', 'to' => $to]);
        }

        if ($action === 'test_smtp') {
            $phpmailer_base = __DIR__ . '/../vendor/phpmailer';
            if (!file_exists($phpmailer_base . '/PHPMailer.php')) {
                json_error('PHPMailer hiányzik: töltsd fel a vendor/phpmailer/ mappát a szerverre.', 500);
            }
            require_once $phpmailer_base . '/Exception.php';
            require_once $phpmailer_base . '/SMTP.php';
            require_once $phpmailer_base . '/PHPMailer.php';

            $to = sanitize_input($body['test_email'] ?? '');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                json_error('Érvénytelen email cím', 400);
            }

            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USER;
                $mail->Password   = SMTP_PASS;
                $mail->SMTPSecure = SMTP_SECURE === 'ssl'
                    ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                    : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = SMTP_PORT;
                $mail->SMTPDebug  = 0;
                $mail->CharSet    = 'UTF-8';
                $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
                $mail->addAddress($to);
                $mail->Subject = 'Fodor Review OS · SMTP teszt';
                $mail->isHTML(true);
                $mail->Body = '<p>Ez egy SMTP tesztüzenet a Fodor Review OS rendszertől.</p>';
                $mail->send();
                log_event('info', 'SMTP test sikeres', ['to' => $to]);
                json_response(['success' => true, 'message' => 'Teszt email elküldve: ' . $to]);
            } catch (PHPMailer\PHPMailer\Exception $e) {
                log_event('error', 'SMTP teszt sikertelen', ['error' => $e->getMessage()]);
                json_error('SMTP hiba: ' . $e->getMessage(), 500);
            }
        }

        if ($action === 'test_google') {
            if (empty(GOOGLE_API_KEY)) {
                json_error('Google API kulcs nincs beállítva a config.php-ban', 400);
            }
            $place_id = sanitize_input($body['place_id'] ?? '');
            if (empty($place_id)) {
                json_error('Place ID szükséges', 400);
            }
            $url = 'https://maps.googleapis.com/maps/api/place/details/json?place_id='
                . urlencode($place_id)
                . '&fields=name,rating,user_ratings_total&key='
                . GOOGLE_API_KEY;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT        => 10,
            ]);
            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($err) {
                json_error('cURL hiba: ' . $err, 500);
            }
            $data = json_decode($resp, true);
            if (($data['status'] ?? '') !== 'OK') {
                json_error('Google API hiba: ' . ($data['status'] ?? 'ismeretlen'), 400);
            }
            json_response(['success' => true, 'place' => $data['result'] ?? []]);
        }

        if ($action === 'test_twilio') {
            if (empty(TWILIO_SID) || TWILIO_SID === 'CONFIGURE_ME') {
                json_error('Twilio nincs konfigurálva', 400);
            }
            $to = sanitize_input($body['test_phone'] ?? '');
            if (empty($to)) {
                json_error('Telefonszám szükséges', 400);
            }
            $url  = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_SID . '/Messages.json';
            $data = http_build_query([
                'To'   => $to,
                'From' => TWILIO_FROM,
                'Body' => 'Fodor Review OS teszt SMS.',
            ]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $data,
                CURLOPT_USERPWD        => TWILIO_SID . ':' . TWILIO_TOKEN,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $result = json_decode($resp, true);
            if ($code >= 400) {
                json_error('Twilio hiba: ' . ($result['message'] ?? 'ismeretlen'), 500);
            }
            json_response(['success' => true, 'sid' => $result['sid'] ?? '']);
        }

        // General settings update — write instructions since we can't hot-reload config.php
        // Instead, store overrides in SQLite and read them at boot
        $allowed_keys = ['smtp_host', 'smtp_user', 'smtp_pass', 'smtp_port', 'smtp_from_name',
                         'twilio_sid', 'twilio_token', 'twilio_from',
                         'google_api_key', 'recaptcha_site_key', 'recaptcha_secret_key',
                         'log_level', 'app_env'];

        $saved = [];
        foreach ($body as $key => $val) {
            $key = strtolower(sanitize_input($key));
            if (!in_array($key, $allowed_keys, true)) continue;
            $val = sanitize_input((string)$val);
            // Upsert into settings table
            $existing = db_fetch_one('SELECT id FROM app_settings WHERE key = ?', [$key]);
            if ($existing) {
                db_run('UPDATE app_settings SET value = ?, updated_at = datetime(\'now\') WHERE key = ?', [$val, $key]);
            } else {
                db_insert('app_settings', ['key' => $key, 'value' => $val, 'updated_at' => date('Y-m-d H:i:s')]);
            }
            $saved[] = $key;
        }

        log_event('info', 'Beállítások frissítve', ['keys' => $saved]);

        json_response([
            'success' => true,
            'message' => 'Beállítások mentve. A config.php-ban lévő értékek felülírásához szerkeszd manuálisan a fájlt.',
            'saved'   => $saved,
            'note'    => 'A megváltozott értékek a következő API hívástól lesznek aktívak (szerver-újraindítás szükséges a konstansokhoz).',
        ]);
        break;

    default:
        json_error('Nem engedélyezett metódus', 405);
}
