<?php
/**
 * Fodor Review OS — Web Installer
 *
 * Feltöltés: install/index.php
 * Elérés:    https://yourdomain.com/install/
 * Fontos:    Töröld az install/ mappát telepítés után!
 */
session_start();

// ─── Paths ────────────────────────────────────────────────────────────────────
$ROOT      = dirname(__DIR__);
$LOCK      = __DIR__ . '/installed.lock';
$SCHEMA    = $ROOT . '/data/schema.sql';
$CONFIG    = $ROOT . '/api/config.php';
$DATA_DIR  = $ROOT . '/data';
$DB_FILE   = $ROOT . '/data/fodor.db';
$LOGO_FILE = $ROOT . '/logo.png';

// ─── Helpers ─────────────────────────────────────────────────────────────────
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function val(string $k, string $def = ''): string { return $_SESSION['d'][$k] ?? $def; }
function ferr(string $k): string {
    $e = $_SESSION['ferrs'][$k] ?? '';
    return $e ? '<p class="field-err">' . h($e) . '</p>' : '';
}

// ─── Already installed? ───────────────────────────────────────────────────────
if (file_exists($LOCK)) {
    page_out('Már telepítve', '
      <div class="card warn">
        <div class="warn-icon">⚠</div>
        <h2>A Fodor Review OS már telepítve van.</h2>
        <p>Biztonsági okokból <strong>törölje az <code>install/</code> mappát</strong> a szerverről azonnal!</p>
      </div>');
    exit;
}

// ─── Router ───────────────────────────────────────────────────────────────────
$step = 1;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = (int)($_POST['_step'] ?? 1);
    if (!isset($_SESSION['d'])) $_SESSION['d'] = [];
    foreach ($_POST as $k => $v) {
        if ($k[0] !== '_') $_SESSION['d'][$k] = is_string($v) ? trim($v) : $v;
    }
    if (isset($_POST['_install'])) {
        $errs = validate(6, $_POST);
        if ($errs) {
            $_SESSION['ferrs'] = $errs;
            $step = 6;
        } else {
            unset($_SESSION['ferrs']);
            $result = do_install($ROOT, $SCHEMA, $CONFIG, $DATA_DIR, $DB_FILE, $LOGO_FILE);
            if ($result['ok']) {
                @file_put_contents($LOCK, date('c'));
                @file_put_contents(__DIR__ . '/.htaccess', "Require all denied\nDeny from all\n");
            }
            result_page($result);
            exit;
        }
    } else {
        $errs = validate($posted, $_POST);
        if ($errs) {
            $_SESSION['ferrs'] = $errs;
            $step = $posted;
        } else {
            unset($_SESSION['ferrs']);
            $step = $posted + 1;
        }
    }
} else {
    $step = max(1, min(6, (int)($_GET['step'] ?? 1)));
    unset($_SESSION['ferrs']);
}

// ─── Validate step data ───────────────────────────────────────────────────────
function validate(int $step, array $p): array {
    $e = [];
    if ($step === 2) {
        if (empty($p['app_url'])) $e['app_url'] = 'Az alkalmazás URL kötelező';
        elseif (!preg_match('#^https?://#i', $p['app_url'])) $e['app_url'] = 'http:// vagy https:// szükséges';
    }
    if ($step === 3) {
        if (empty($p['smtp_host'])) $e['smtp_host'] = 'SMTP szerver kötelező';
        if (empty($p['smtp_user'])) $e['smtp_user'] = 'SMTP felhasználónév kötelező';
        if (empty($p['smtp_pass'])) $e['smtp_pass'] = 'SMTP jelszó kötelező';
        if (!empty($p['smtp_port']) && !ctype_digit($p['smtp_port'])) $e['smtp_port'] = 'Számot adj meg';
    }
    if ($step === 6) {
        if (empty($p['admin_name'])) $e['admin_name'] = 'Kötelező';
        if (empty($p['admin_email']) || !filter_var($p['admin_email'], FILTER_VALIDATE_EMAIL))
            $e['admin_email'] = 'Érvényes email cím szükséges';
        if (empty($p['admin_pass']) || strlen($p['admin_pass']) < 8)
            $e['admin_pass'] = 'Minimum 8 karakter';
        if (($p['admin_pass'] ?? '') !== ($p['admin_pass2'] ?? ''))
            $e['admin_pass2'] = 'A jelszavak nem egyeznek';
    }
    return $e;
}

// ─── Installation logic ───────────────────────────────────────────────────────
function do_install(string $root, string $schema, string $config, string $data_dir, string $db_file, string $logo_file): array {
    $d   = $_SESSION['d'] ?? [];
    $log = [];

    // 1. data/ dir
    if (!is_dir($data_dir)) {
        if (!mkdir($data_dir, 0755, true))
            return inst_fail('A data/ könyvtár létrehozása sikertelen — ellenőrizd a szerveren az írási jogokat.');
    }
    if (!is_writable($data_dir))
        return inst_fail('A data/ könyvtár nem írható. Állítsd a jogot 755-re.');
    $log[] = ['ok', 'data/ könyvtár elérhető'];

    // 2. Write config.php
    $cfg = build_config($d);
    if (file_put_contents($config, $cfg) === false)
        return inst_fail('api/config.php írása sikertelen — nincs írási jog a api/ könyvtárban.');
    $log[] = ['ok', 'api/config.php megírva'];

    // 3. DB from schema.sql
    if (!file_exists($schema))
        return inst_fail('data/schema.sql nem található — töltsd fel az egész projektet.');
    $sql = file_get_contents($schema);
    if ($sql === false)
        return inst_fail('data/schema.sql olvasása sikertelen.');

    // Backup existing DB
    if (file_exists($db_file)) {
        rename($db_file, $db_file . '.bak.' . time());
        $log[] = ['info', 'Meglévő adatbázis mentve (.bak)'];
    }

    try {
        $pdo = new PDO('sqlite:' . $db_file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        // Strip PRAGMAs (already run above) then split + execute
        $stmts = preg_split('/;\s*\n/', $sql);
        foreach ($stmts as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || stripos($stmt, 'PRAGMA') === 0) continue;
            $pdo->exec($stmt);
        }
        $log[] = ['ok', 'Adatbázis séma és seed adatok: ok'];
    } catch (PDOException $ex) {
        return inst_fail('Adatbázis hiba: ' . $ex->getMessage());
    }

    // 4. Replace templates with correct versions
    try {
        $pdo->exec('DELETE FROM email_templates');
        $pdo->exec("DELETE FROM sqlite_sequence WHERE name='email_templates'");

        $logo_b64  = file_exists($logo_file)
            ? 'data:image/png;base64,' . base64_encode(file_get_contents($logo_file))
            : '';
        $logo_html = $logo_b64
            ? '<img src="' . $logo_b64 . '" alt="Fodor Ingatlan" height="28" style="display:block;height:28px;max-width:140px;">'
            : '<div style="font-size:14px;letter-spacing:2px;color:#B8935A;font-weight:700;text-transform:uppercase;">FODOR INGATLAN</div>';

        insert_templates($pdo, $logo_html);

        $pdo->exec("INSERT OR REPLACE INTO sqlite_sequence (name, seq) VALUES ('email_templates', 9)");
        $log[] = ['ok', 'Email/SMS sablonok beállítva (3 db)'];
    } catch (PDOException $ex) {
        return inst_fail('Sablon hiba: ' . $ex->getMessage());
    }

    // 5. Generate fresh API token
    try {
        $raw_token  = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $raw_token);
        $pdo->exec('DELETE FROM api_tokens');
        $pdo->exec("DELETE FROM sqlite_sequence WHERE name='api_tokens'");
        $pdo->prepare("INSERT INTO api_tokens (token_hash, name) VALUES (?, 'Admin API Token')")
            ->execute([$token_hash]);
        $log[] = ['ok', 'API token generálva'];
    } catch (PDOException $ex) {
        return inst_fail('API token hiba: ' . $ex->getMessage());
    }

    // 6. Admin user
    try {
        $pw_hash = password_hash($d['admin_pass'], PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("INSERT INTO users (name, email, password_hash, role, active) VALUES (?, ?, ?, 'admin', 1)")
            ->execute([$d['admin_name'], $d['admin_email'], $pw_hash]);
        $log[] = ['ok', 'Admin felhasználó létrehozva: ' . $d['admin_email']];
    } catch (PDOException $ex) {
        return inst_fail('Admin user hiba: ' . $ex->getMessage());
    }

    return [
        'ok'      => true,
        'log'     => $log,
        'token'   => $raw_token,
        'app_url' => rtrim($d['app_url'] ?? '', '/'),
        'email'   => $d['admin_email'] ?? '',
    ];
}

function inst_fail(string $msg): array { return ['ok' => false, 'error' => $msg]; }

// ─── Config file builder ──────────────────────────────────────────────────────
function build_config(array $d): string {
    $app_url    = rtrim($d['app_url']    ?? 'https://example.com', '/');
    $app_env    = $d['app_env']          ?? 'production';
    $smtp_host  = $d['smtp_host']        ?? '';
    $smtp_user  = $d['smtp_user']        ?? '';
    $smtp_pass  = $d['smtp_pass']        ?? '';
    $smtp_port  = (int)($d['smtp_port']  ?? 587);
    $smtp_name  = $d['smtp_from_name']   ?? 'Fodor Ingatlan';
    $smtp_sec   = $d['smtp_secure']      ?? 'tls';
    $twilio_sid = $d['twilio_sid']       ?? '';
    $twilio_tok = $d['twilio_token']     ?? '';
    $twilio_frm = $d['twilio_from']      ?? '';
    $google_key = $d['google_api_key']   ?? '';
    $rcap_site  = $d['recaptcha_site']   ?? '';
    $rcap_sec   = $d['recaptcha_secret'] ?? '';
    $log_level  = $d['log_level']        ?? 'info';

    $esc = fn(string $s) => addslashes($s);

    return "<?php\n" .
"/**\n * Fodor Review OS — Application Configuration\n * Generálva: " . date('Y-m-d H:i:s') . "\n */\n\n" .
"// ── DATABASE ─────────────────────────────────────────────────────────────────\n" .
"define('DB_PATH', dirname(__DIR__) . '/data/fodor.db');\n\n" .
"// ── SMTP ─────────────────────────────────────────────────────────────────────\n" .
"define('SMTP_HOST',      '" . $esc($smtp_host) . "');\n" .
"define('SMTP_USER',      '" . $esc($smtp_user) . "');\n" .
"define('SMTP_PASS',      '" . $esc($smtp_pass) . "');\n" .
"define('SMTP_PORT',      $smtp_port);\n" .
"define('SMTP_FROM_NAME', '" . $esc($smtp_name) . "');\n" .
"define('SMTP_SECURE',    '" . $esc($smtp_sec) . "');  // 'tls' or 'ssl'\n\n" .
"// ── TWILIO ───────────────────────────────────────────────────────────────────\n" .
"define('TWILIO_SID',   '" . $esc($twilio_sid) . "');\n" .
"define('TWILIO_TOKEN', '" . $esc($twilio_tok) . "');\n" .
"define('TWILIO_FROM',  '" . $esc($twilio_frm) . "');\n\n" .
"// ── GOOGLE ───────────────────────────────────────────────────────────────────\n" .
"define('GOOGLE_API_KEY', '" . $esc($google_key) . "');\n\n" .
"// ── RECAPTCHA v2 ─────────────────────────────────────────────────────────────\n" .
"define('RECAPTCHA_SITE_KEY',   '" . $esc($rcap_site) . "');\n" .
"define('RECAPTCHA_SECRET_KEY', '" . $esc($rcap_sec) . "');\n\n" .
"// ── APPLICATION ──────────────────────────────────────────────────────────────\n" .
"define('APP_URL',   '" . $esc($app_url) . "');  // No trailing slash\n" .
"define('APP_ENV',   '" . $esc($app_env) . "');\n" .
"define('LOG_PATH',  dirname(__DIR__) . '/data/app.log');\n" .
"define('LOG_LEVEL', '" . $esc($log_level) . "');\n\n" .
"// ── RATE LIMITING ────────────────────────────────────────────────────────────\n" .
"define('RATE_LIMIT_MAX',    60);\n" .
"define('RATE_LIMIT_WINDOW', 60);\n";
}

// ─── Template insertion ───────────────────────────────────────────────────────
function insert_templates(PDO $pdo, string $logo_html): void {
    // ── Template 1: email_ertekeles_ugyletat_utan ─────────────────────────────
    $html1 =
'<!DOCTYPE html><html lang="hu"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>' .
'<body style="margin:0;padding:0;background:#F4F1EA;font-family:Arial,Helvetica,sans-serif;">' .
'<table width="100%" cellpadding="0" cellspacing="0" style="background:#F4F1EA;padding:32px 16px;">' .
'<tr><td align="center">' .
'<table width="580" cellpadding="0" cellspacing="0" style="max-width:580px;width:100%;">' .

'<!-- HEADER -->' .
'<tr><td style="background:#1F2D3D;border-radius:10px 10px 0 0;padding:24px 36px;">' .
'<table width="100%" cellpadding="0" cellspacing="0"><tr>' .
'<td valign="middle">' . $logo_html . '</td>' .
'<td align="right" valign="middle" style="font-size:11px;color:#8A9BAC;font-family:Arial;">Fodor Ingatlan</td>' .
'</tr></table>' .
'</td></tr>' .

'<!-- HERO -->' .
'<tr><td style="background:#243447;padding:28px 36px 24px;border-bottom:2px solid #B8935A;">' .
'<div style="font-size:22px;color:#F5F0E6;font-weight:700;line-height:1.3;font-family:Georgia,serif;">Köszönjük a bizalmát!</div>' .
'<div style="font-size:13px;color:#8A9BAC;margin-top:6px;line-height:1.5;">Rövid Google értékelést kérünk Öntől</div>' .
'</td></tr>' .

'<!-- BODY -->' .
'<tr><td style="background:#FFFFFF;padding:32px 36px;">' .
'<p style="margin:0 0 20px;font-size:15px;color:#1F2D3D;font-weight:600;">Kedves {{ugyfelnev}}!</p>' .
'<p style="margin:0 0 14px;font-size:14px;color:#3A4A5C;line-height:1.8;">Köszönjük a bizalmát és a közös munkát. Öröm számunkra, hogy segíthettünk Önnek az ingatlanügylet sikeres lezárásában.</p>' .
'<p style="margin:0 0 14px;font-size:14px;color:#3A4A5C;line-height:1.8;">A Fodor Ingatlan Közvetítő Kft.-nél arra törekszünk, hogy ügyfeleink ne csupán eredményes, hanem valóban nyugodt és pozitív élményként éljék meg az ingatlanközvetítés folyamatát.</p>' .
'<p style="margin:0 0 28px;font-size:14px;color:#3A4A5C;line-height:1.8;">Amennyiben elégedett volt szolgáltatásunkkal, nagyra értékelnénk, ha megosztaná tapasztalatait egy Google értékelés formájában. Néhány kedves mondat sokat segít azoknak is, akik jelenleg keresik a számukra megfelelő ingatlanirodát.</p>' .

'<table cellpadding="0" cellspacing="0" style="margin:0 auto 28px;">' .
'<tr><td align="center">{{review_link_html}}</td></tr></table>' .

'<p style="margin:0;font-size:11.5px;color:#9AA3AC;text-align:center;line-height:1.6;">A gombra kattintva közvetlenül a Google értékelő felületre jut.</p>' .
'</td></tr>' .

'<!-- FOOTER -->' .
'<tr><td style="background:#F4F1EA;border-top:1px solid #E2DAC8;border-radius:0 0 10px 10px;padding:20px 36px;">' .
'<table width="100%" cellpadding="0" cellspacing="0"><tr>' .
'<td valign="middle">' .
'<div style="font-size:13px;font-weight:700;color:#1F2D3D;">{{ugynok_nev}}</div>' .
'<div style="font-size:11.5px;color:#6E7A88;margin-top:2px;">Ingatlanközvetítő · Fodor Ingatlan Kft.</div>' .
'<div style="font-size:11.5px;color:#B8935A;margin-top:2px;">{{ugynok_telefon}}</div>' .
'</td>' .
'<td align="right" valign="middle">' . $logo_html . '</td>' .
'</tr></table>' .
'<p style="margin:14px 0 0;font-size:10.5px;color:#B0A898;text-align:center;line-height:1.6;">Ezt az üzenetet azért kapta, mert ingatlanügyletet kötött a Fodor Ingatlan Közvetítő Kft.-vel.</p>' .
'</td></tr>' .

'</table></td></tr></table></body></html>';

    $text1 = "Kedves {{ugyfelnev}}!\n\nKöszönjük a bizalmát és a közös munkát. Öröm számunkra, hogy segíthettünk Önnek az ingatlanügylet sikeres lezárásában.\n\nA Fodor Ingatlan Közvetítő Kft.-nél arra törekszünk, hogy ügyfeleink ne csupán eredményes, hanem valóban nyugodt és pozitív élményként éljék meg az ingatlanközvetítés folyamatát.\n\nAmennyiben elégedett volt szolgáltatásunkkal, nagyra értékelnénk, ha megosztaná tapasztalatait egy Google értékelés formájában:\n\n{{review_link}}\n\nKöszönjük!\n\n{{ugynok_nev}}\nIngatlanközvetítő · Fodor Ingatlan Kft.\n{{ugynok_telefon}}";

    $pdo->prepare("INSERT INTO email_templates (id,name,channel,subject,body_html,body_text,variables) VALUES (1,?,?,?,?,?,?)")
        ->execute([
            'email_ertekeles_ugyletat_utan',
            'email',
            'Köszönjük a bizalmát, {{nev}}!',
            $html1,
            $text1,
            json_encode(['nev','ugynok_nev','ugynok_telefon','review_link_html','review_link'], JSON_UNESCAPED_UNICODE),
        ]);

    // ── Template 8: emlekezetes_ertekeles (cron uses this name!) ─────────────
    $html8 =
'<!DOCTYPE html><html lang="hu"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>' .
'<body style="margin:0;padding:0;background:#F4F1EA;font-family:Arial,Helvetica,sans-serif;">' .
'<table width="100%" cellpadding="0" cellspacing="0" style="background:#F4F1EA;padding:32px 16px;">' .
'<tr><td align="center">' .
'<table width="580" cellpadding="0" cellspacing="0" style="max-width:580px;width:100%;">' .

'<tr><td style="background:#1F2D3D;border-radius:10px 10px 0 0;padding:24px 36px;">' .
'<table width="100%" cellpadding="0" cellspacing="0"><tr>' .
'<td valign="middle">' . $logo_html . '</td>' .
'<td align="right" valign="middle" style="font-size:11px;color:#8A9BAC;">Fodor Ingatlan</td>' .
'</tr></table>' .
'</td></tr>' .

'<tr><td style="background:#243447;padding:28px 36px 24px;border-bottom:2px solid #B8935A;">' .
'<div style="font-size:21px;color:#F5F0E6;font-weight:700;line-height:1.3;font-family:Georgia,serif;">Még egy percet kérnék…</div>' .
'<div style="font-size:13px;color:#8A9BAC;margin-top:6px;">Rövid emlékeztető az értékelés kapcsán</div>' .
'</td></tr>' .

'<tr><td style="background:#FFFFFF;padding:32px 36px;">' .
'<p style="margin:0 0 16px;font-size:15px;color:#1F2D3D;font-weight:600;">Kedves {{nev}}!</p>' .
'<p style="margin:0 0 14px;font-size:14px;color:#3A4A5C;line-height:1.7;">Néhány napja küldtem Önnek egy üzenetet, amelyben Google értékelést kértem a közösen lezárt ügylettel kapcsolatban.</p>' .
'<p style="margin:0 0 24px;font-size:14px;color:#3A4A5C;line-height:1.7;">Tudom, mennyire elfoglalt — csak egyetlen percet kérnék. Az Ön visszajelzése más ügyfeleknek is segít a döntésben.</p>' .

'<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;"><tr>' .
'<td style="background:#F0F4F8;border-radius:6px;padding:16px 18px;">' .
'<div style="font-size:12.5px;color:#1F2D3D;font-weight:600;margin-bottom:4px;">{{ugynok_nev}} személyes üzenete:</div>' .
'<div style="font-size:13px;color:#3A4A5C;line-height:1.65;font-style:italic;">„Ha csak egy mondatban írja le, milyen volt velünk dolgozni — az is sokat jelent számunkra."</div>' .
'</td></tr></table>' .

'<table cellpadding="0" cellspacing="0" style="margin:0 auto 20px;">' .
'<tr><td align="center">{{review_link_html}}</td></tr></table>' .

'<p style="margin:0;font-size:11.5px;color:#9AA3AC;text-align:center;">Ez az utolsó emlékeztető — ígérem, nem zavarjuk többet ezzel.</p>' .
'</td></tr>' .

'<tr><td style="background:#F4F1EA;border-top:1px solid #E2DAC8;border-radius:0 0 10px 10px;padding:20px 36px;">' .
'<table width="100%" cellpadding="0" cellspacing="0"><tr>' .
'<td valign="middle">' .
'<div style="font-size:13px;font-weight:700;color:#1F2D3D;">{{ugynok_nev}}</div>' .
'<div style="font-size:11.5px;color:#6E7A88;margin-top:2px;">Ingatlanközvetítő · Fodor Ingatlan Kft.</div>' .
'<div style="font-size:11.5px;color:#B8935A;margin-top:2px;">{{ugynok_telefon}}</div>' .
'</td>' .
'<td align="right" valign="middle">' . $logo_html . '</td>' .
'</tr></table>' .
'<p style="margin:14px 0 0;font-size:10.5px;color:#B0A898;text-align:center;line-height:1.6;">Ezt az üzenetet azért kapta, mert ingatlanügyletet kötött a Fodor Ingatlan Közvetítő Kft.-vel.</p>' .
'</td></tr>' .

'</table></td></tr></table></body></html>';

    $text8 = "Kedves {{nev}}!\n\nNéhány napja küldtem Önnek egy üzenetet Google értékelés kapcsán. Ha van egy perced:\n\n{{review_link}}\n\nEz az utolsó emlékeztető.\n\n{{ugynok_nev}}\nIngatlanközvetítő · Fodor Ingatlan Kft.\n{{ugynok_telefon}}";

    $pdo->prepare("INSERT INTO email_templates (id,name,channel,subject,body_html,body_text,variables) VALUES (8,?,?,?,?,?,?)")
        ->execute([
            'emlekezetes_ertekeles',
            'email',
            'Emlékeztető: értékelje {{nev}} a Fodor Ingatlannal szerzett tapasztalatait',
            $html8,
            $text8,
            json_encode(['nev','ugynok_nev','ugynok_telefon','review_link_html','review_link'], JSON_UNESCAPED_UNICODE),
        ]);

    // ── Template 9: sms_ertekeles_ugyletat_utan ───────────────────────────────
    $sms9 = 'Kedves {{nev}}! Köszönjük, hogy a Fodor Ingatlant választotta. Ha elégedett volt {{ügynök_neve}} munkájával, kérjük, értékeljen minket Google-on: {{review_link}} – Fodor Ingatlan';

    $pdo->prepare("INSERT INTO email_templates (id,name,channel,subject,body_html,body_text,variables) VALUES (9,?,?,NULL,NULL,?,?)")
        ->execute([
            'sms_ertekeles_ugyletat_utan',
            'sms',
            $sms9,
            json_encode(['nev','ügynök_neve','review_link'], JSON_UNESCAPED_UNICODE),
        ]);
}

// ─── Step renderers ───────────────────────────────────────────────────────────
function render_step(int $step, string $root, string $schema, string $config, string $data_dir, string $db_file): string {
    switch ($step) {
        case 1: return step1_req($root, $schema, $config, $data_dir, $db_file);
        case 2: return step2_app();
        case 3: return step3_smtp();
        case 4: return step4_twilio();
        case 5: return step5_google();
        case 6: return step6_admin();
        default: return step1_req($root, $schema, $config, $data_dir, $db_file);
    }
}

// ─── Step 1: Requirements ─────────────────────────────────────────────────────
function step1_req(string $root, string $schema, string $config, string $data_dir, string $db_file): string {
    $checks = [];
    // PHP version
    $php_ok = version_compare(PHP_VERSION, '8.0.0', '>=');
    $checks[] = check_row($php_ok, 'PHP verzió', 'PHP ' . PHP_VERSION . ($php_ok ? ' (8.0+ szükséges)' : ' — 8.0+ szükséges!'));
    // PDO SQLite
    $pdo_ok = extension_loaded('pdo_sqlite');
    $checks[] = check_row($pdo_ok, 'PDO SQLite', $pdo_ok ? 'Elérhető' : 'Hiányzik — engedélyezd a pdo_sqlite PHP kiterjesztést');
    // data/ writable
    $data_exists  = is_dir($data_dir);
    $data_writable = $data_exists ? is_writable($data_dir) : is_writable(dirname($data_dir));
    $checks[] = check_row($data_writable, 'data/ könyvtár', $data_writable ? ($data_exists ? 'Létezik és írható' : 'Létrehozható') : 'Nem írható — chmod 755 szükséges');
    // schema.sql
    $schema_ok = file_exists($schema);
    $checks[] = check_row($schema_ok, 'data/schema.sql', $schema_ok ? 'Megtalálva' : 'Hiányzik — töltsd fel a projektet teljesen');
    // api/ writable
    $api_dir = dirname($config);
    $api_ok  = is_writable($api_dir);
    $checks[] = check_row($api_ok, 'api/ könyvtár', $api_ok ? 'Írható' : 'Nem írható — chmod 755');
    // config.php
    $cfg_ok = !file_exists($config) || is_writable($config);
    $checks[] = check_row($cfg_ok, 'api/config.php', !file_exists($config) ? 'Nem létezik (friss telepítés)' : ($cfg_ok ? 'Felülírható' : 'Nem írható'));

    $all_ok  = $php_ok && $pdo_ok && $data_writable && $schema_ok && $api_ok && $cfg_ok;
    $btn_dis = $all_ok ? '' : 'disabled';

    $warn = !$all_ok ? '<div class="alert alert-err">⚠ Néhány követelmény nem teljesül — javítsd a piros sorokat a folytatás előtt.</div>' : '<div class="alert alert-ok">✅ Minden követelmény teljesül. Folytathatod a telepítést.</div>';

    return step_page(1, 'Követelmények ellenőrzése', '
      ' . $warn . '
      <table class="req-table">
        <thead><tr><th>Ellenőrzés</th><th>Eredmény</th></tr></thead>
        <tbody>' . implode('', $checks) . '</tbody>
      </table>
      <form method="post">
        <input type="hidden" name="_step" value="1">
        <div class="btn-row"><button type="submit" class="btn-primary" ' . $btn_dis . '>Következő →</button></div>
      </form>');
}

function check_row(bool $ok, string $label, string $detail): string {
    $icon  = $ok ? '<span class="chk ok">✓</span>' : '<span class="chk fail">✗</span>';
    $class = $ok ? '' : ' class="row-fail"';
    return "<tr$class><td>$icon $label</td><td>" . h($detail) . '</td></tr>';
}

// ─── Step 2: App settings ─────────────────────────────────────────────────────
function step2_app(): string {
    return step_page(2, 'Alkalmazás beállítások', '
      <form method="post">
        <input type="hidden" name="_step" value="2">
        <div class="field">
          <label>Alkalmazás URL <span class="req">*</span></label>
          <input type="url" name="app_url" value="' . h(val('app_url', 'https://')) . '" placeholder="https://fodoringatlan.hu" required>
          <small>Perjel nélkül a végén. Ez kerül a tracking linkekbe (email pixelek, NPS, kattintás).</small>
          ' . ferr('app_url') . '
        </div>
        <div class="field">
          <label>Környezet</label>
          <select name="app_env">
            <option value="production"' . (val('app_env','production')==='production' ? ' selected' : '') . '>production (éles)</option>
            <option value="development"' . (val('app_env')==='development' ? ' selected' : '') . '>development (debug üzenetek láthatók)</option>
          </select>
        </div>
        <div class="field">
          <label>Log szint</label>
          <select name="log_level">
            <option value="info"' . (val('log_level','info')==='info' ? ' selected' : '') . '>info (ajánlott)</option>
            <option value="debug"' . (val('log_level')==='debug' ? ' selected' : '') . '>debug (részletes)</option>
            <option value="error"' . (val('log_level')==='error' ? ' selected' : '') . '>error (csak hibák)</option>
          </select>
        </div>
        <div class="btn-row">
          <a href="?step=1" class="btn-sec">← Vissza</a>
          <button type="submit" class="btn-primary">Következő →</button>
        </div>
      </form>');
}

// ─── Step 3: SMTP ─────────────────────────────────────────────────────────────
function step3_smtp(): string {
    return step_page(3, 'Email — SMTP beállítások', '
      <form method="post">
        <input type="hidden" name="_step" value="3">
        <div class="grid-2">
          <div class="field">
            <label>SMTP szerver <span class="req">*</span></label>
            <input type="text" name="smtp_host" value="' . h(val('smtp_host','mail.fodoringatlan.hu')) . '" placeholder="mail.fodoringatlan.hu" required>
            ' . ferr('smtp_host') . '
          </div>
          <div class="field">
            <label>Port</label>
            <input type="number" name="smtp_port" value="' . h(val('smtp_port','587')) . '" placeholder="587">
          </div>
        </div>
        <div class="grid-2">
          <div class="field">
            <label>Felhasználónév (email) <span class="req">*</span></label>
            <input type="email" name="smtp_user" value="' . h(val('smtp_user','info@fodoringatlan.hu')) . '" placeholder="info@fodoringatlan.hu" required>
            ' . ferr('smtp_user') . '
          </div>
          <div class="field">
            <label>Jelszó <span class="req">*</span></label>
            <input type="password" name="smtp_pass" value="' . h(val('smtp_pass')) . '" placeholder="SMTP jelszó" required>
            ' . ferr('smtp_pass') . '
          </div>
        </div>
        <div class="grid-2">
          <div class="field">
            <label>Feladó neve</label>
            <input type="text" name="smtp_from_name" value="' . h(val('smtp_from_name','Fodor Ingatlan')) . '" placeholder="Fodor Ingatlan">
          </div>
          <div class="field">
            <label>Titkosítás</label>
            <select name="smtp_secure">
              <option value="tls"' . (val('smtp_secure','tls')==='tls' ? ' selected' : '') . '>TLS / STARTTLS (587)</option>
              <option value="ssl"' . (val('smtp_secure')==='ssl' ? ' selected' : '') . '>SSL (465)</option>
            </select>
          </div>
        </div>
        <div class="btn-row">
          <a href="?step=2" class="btn-sec">← Vissza</a>
          <button type="submit" class="btn-primary">Következő →</button>
        </div>
      </form>');
}

// ─── Step 4: Twilio SMS ───────────────────────────────────────────────────────
function step4_twilio(): string {
    return step_page(4, 'SMS — Twilio (opcionális)', '
      <p class="hint">Ha nem tervezel SMS küldést, hagyd üresen és kattints a "Kihagyás" gombra.</p>
      <form method="post">
        <input type="hidden" name="_step" value="4">
        <div class="field">
          <label>Account SID</label>
          <input type="text" name="twilio_sid" value="' . h(val('twilio_sid')) . '" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
          <small>Twilio Console → Account Info → Account SID</small>
        </div>
        <div class="field">
          <label>Auth Token</label>
          <input type="password" name="twilio_token" value="' . h(val('twilio_token')) . '" placeholder="Auth Token">
        </div>
        <div class="field">
          <label>Feladó telefonszám</label>
          <input type="text" name="twilio_from" value="' . h(val('twilio_from')) . '" placeholder="+36XXXXXXXXX">
          <small>E.164 formátum, pl. +36201234567. Twilio-n megvásárolt szám.</small>
        </div>
        <div class="btn-row">
          <a href="?step=3" class="btn-sec">← Vissza</a>
          <button type="submit" name="_skip_twilio" value="1" class="btn-sec">Kihagyás →</button>
          <button type="submit" class="btn-primary">Következő →</button>
        </div>
      </form>');
}

// ─── Step 5: Google API + reCAPTCHA ──────────────────────────────────────────
function step5_google(): string {
    return step_page(5, 'Google API + reCAPTCHA (opcionális)', '
      <p class="hint">Google értékelések szinkronizálásához kell az API kulcs. reCAPTCHA az NPS formhoz. Mindkettő elhagyható.</p>
      <form method="post">
        <input type="hidden" name="_step" value="5">
        <div class="field">
          <label>Google Places API kulcs</label>
          <input type="text" name="google_api_key" value="' . h(val('google_api_key')) . '" placeholder="AIzaSy...">
          <small>Google Cloud Console → APIs &amp; Services → Credentials. Üresen mock adatokat használ.</small>
        </div>
        <div class="field">
          <label>reCAPTCHA v2 — Site Key</label>
          <input type="text" name="recaptcha_site" value="' . h(val('recaptcha_site')) . '" placeholder="6Lc...">
        </div>
        <div class="field">
          <label>reCAPTCHA v2 — Secret Key</label>
          <input type="password" name="recaptcha_secret" value="' . h(val('recaptcha_secret')) . '" placeholder="6Lc...">
          <small>Google reCAPTCHA Admin Console. Üresen hagyva az NPS formból kimarad az ellenőrzés.</small>
        </div>
        <div class="btn-row">
          <a href="?step=4" class="btn-sec">← Vissza</a>
          <button type="submit" name="_skip_google" value="1" class="btn-sec">Kihagyás →</button>
          <button type="submit" class="btn-primary">Következő →</button>
        </div>
      </form>');
}

// ─── Step 6: Admin account ────────────────────────────────────────────────────
function step6_admin(): string {
    return step_page(6, 'Admin fiók létrehozása', '
      <form method="post">
        <input type="hidden" name="_step" value="6">
        <div class="field">
          <label>Teljes név <span class="req">*</span></label>
          <input type="text" name="admin_name" value="' . h(val('admin_name','Fodor Zsolt')) . '" placeholder="Fodor Zsolt" required>
          ' . ferr('admin_name') . '
        </div>
        <div class="field">
          <label>Email cím <span class="req">*</span></label>
          <input type="email" name="admin_email" value="' . h(val('admin_email','info@fodoringatlan.hu')) . '" placeholder="info@fodoringatlan.hu" required>
          ' . ferr('admin_email') . '
        </div>
        <div class="grid-2">
          <div class="field">
            <label>Jelszó <span class="req">*</span></label>
            <input type="password" name="admin_pass" placeholder="Min. 8 karakter" required>
            ' . ferr('admin_pass') . '
          </div>
          <div class="field">
            <label>Jelszó megerősítése <span class="req">*</span></label>
            <input type="password" name="admin_pass2" placeholder="Ismételd meg" required>
            ' . ferr('admin_pass2') . '
          </div>
        </div>
        <div class="summary-box">
          <div class="summary-title">Telepítési összefoglaló</div>
          <div class="summary-row"><span>App URL:</span><strong>' . h(val('app_url')) . '</strong></div>
          <div class="summary-row"><span>Környezet:</span><strong>' . h(val('app_env','production')) . '</strong></div>
          <div class="summary-row"><span>SMTP:</span><strong>' . h(val('smtp_user')) . ' @ ' . h(val('smtp_host')) . ':' . h(val('smtp_port','587')) . '</strong></div>
          <div class="summary-row"><span>SMS (Twilio):</span><strong>' . (val('twilio_sid') ? '✅ Beállítva' : '— Kihagyva') . '</strong></div>
          <div class="summary-row"><span>Google API:</span><strong>' . (val('google_api_key') ? '✅ Beállítva' : '— Mock adatok') . '</strong></div>
          <div class="summary-row"><span>reCAPTCHA:</span><strong>' . (val('recaptcha_site') ? '✅ Beállítva' : '— Kikapcsolva') . '</strong></div>
        </div>
        <div class="btn-row">
          <a href="?step=5" class="btn-sec">← Vissza</a>
          <button type="submit" name="_install" value="1" class="btn-install">🚀 Telepítés indítása</button>
        </div>
      </form>');
}

// ─── Result page ──────────────────────────────────────────────────────────────
function result_page(array $res): void {
    if (!$res['ok']) {
        echo page_out('Telepítési hiba', '
          <div class="card err-card">
            <div class="err-icon">✗</div>
            <h2>Telepítés sikertelen</h2>
            <p class="err-msg">' . h($res['error']) . '</p>
            <a href="?step=1" class="btn-primary" style="display:inline-block;margin-top:16px;">← Vissza</a>
          </div>');
        return;
    }

    $log_html = '';
    foreach ($res['log'] as $entry) {
        [$type, $msg] = $entry;
        $log_html .= '<div class="log-' . $type . '">' . ($type === 'ok' ? '✅' : 'ℹ') . ' ' . h($msg) . '</div>';
    }

    echo page_out('Sikeres telepítés', '
      <div class="card success-card">
        <div class="success-icon">✓</div>
        <h2>A Fodor Review OS sikeresen telepítve!</h2>
      </div>

      <div class="card">
        <h3>Telepítési napló</h3>
        <div class="log-box">' . $log_html . '</div>
      </div>

      <div class="card token-card">
        <h3>🔑 API Token</h3>
        <p>Ezt a tokent <strong>most jegyezd fel</strong> — csak egyszer látható!</p>
        <div class="token-box">' . h($res['token']) . '</div>
        <small>Használat: <code>Authorization: Bearer ' . h(substr($res['token'], 0, 8)) . '...</code> fejléccel minden API kérésnél.</small>
      </div>

      <div class="card">
        <h3>Következő lépések</h3>
        <ol class="next-steps">
          <li><strong>Töröld az <code>install/</code> mappát</strong> a szerverről — biztonsági kockázat!</li>
          <li>Nyisd meg: <a href="' . h($res['app_url']) . '" target="_blank">' . h($res['app_url']) . '</a></li>
          <li>Belépés: <strong>' . h($res['email']) . '</strong> a megadott jelszóval</li>
          <li>Cron jobokat állíts be (lásd README / dokumentáció):
            <ul>
              <li><code>php cron/process_queue.php</code> — 15 percenként</li>
              <li><code>php cron/sync_reviews.php</code> — óránként</li>
              <li><code>php cron/check_published.php</code> — 6 óránként</li>
            </ul>
          </li>
          <li>Töltsd fel a <code>logo.png</code> fájlt a gyökérkönyvtárba (ha még nem tetted)</li>
        </ol>
      </div>
    ');
}

// ─── HTML helpers ─────────────────────────────────────────────────────────────
function step_page(int $active, string $title, string $content): string {
    $steps = ['Követelmények','Alkalmazás','Email (SMTP)','SMS (Twilio)','Google API','Admin fiók'];
    $prog  = '';
    foreach ($steps as $i => $label) {
        $n = $i + 1;
        $cls = $n < $active ? 'done' : ($n === $active ? 'active' : 'todo');
        $prog .= '<div class="step-item ' . $cls . '">';
        $prog .= '<div class="step-num">' . ($n < $active ? '✓' : $n) . '</div>';
        $prog .= '<div class="step-label">' . h($label) . '</div>';
        $prog .= '</div>';
    }
    return page_out($title,
        '<div class="stepper">' . $prog . '</div>' .
        '<div class="card"><h2>' . h($title) . '</h2>' . $content . '</div>');
}

function page_out(string $title, string $body): string {
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="hu">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($title) ?> — Fodor Review OS Installer</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; padding: 0; background: #F8F5EE; font-family: -apple-system, 'Segoe UI', Arial, sans-serif; color: #1F2D3D; font-size: 15px; }
    .wrap { max-width: 760px; margin: 0 auto; padding: 32px 16px 64px; }
    .top-bar { background: #1F2D3D; padding: 18px 32px; display: flex; align-items: center; gap: 12px; }
    .top-bar .logo { font-size: 13px; letter-spacing: 2px; color: #B8935A; font-weight: 700; text-transform: uppercase; }
    .top-bar .sub  { font-size: 12px; color: #6E8098; margin-left: 8px; }
    .stepper { display: flex; gap: 0; margin-bottom: 24px; background: #fff; border-radius: 10px; overflow: hidden; border: 1px solid #E8E0D0; }
    .step-item { flex: 1; padding: 12px 8px; text-align: center; border-right: 1px solid #E8E0D0; transition: background .2s; }
    .step-item:last-child { border-right: none; }
    .step-num  { width: 28px; height: 28px; border-radius: 50%; margin: 0 auto 4px; font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
    .step-label { font-size: 11px; color: #6E7A88; line-height: 1.3; }
    .step-item.done  { background: #F0F9F0; } .step-item.done .step-num  { background: #2E7D32; color: #fff; }
    .step-item.active { background: #FBF7EF; } .step-item.active .step-num { background: #B8935A; color: #fff; } .step-item.active .step-label { color: #B8935A; font-weight: 600; }
    .step-item.todo  .step-num { background: #E8E0D0; color: #9AA3AC; }
    .card { background: #fff; border-radius: 10px; border: 1px solid #E8E0D0; padding: 28px 32px; margin-bottom: 20px; }
    .card h2 { margin: 0 0 20px; font-size: 20px; color: #1F2D3D; }
    .card h3 { margin: 0 0 16px; font-size: 16px; color: #1F2D3D; }
    .field { margin-bottom: 18px; }
    .field label { display: block; font-size: 13px; font-weight: 600; color: #3A4A5C; margin-bottom: 6px; }
    .field input, .field select { width: 100%; padding: 10px 12px; border: 1px solid #D0C9BC; border-radius: 6px; font-size: 14px; color: #1F2D3D; background: #FAFAF8; outline: none; transition: border .2s; }
    .field input:focus, .field select:focus { border-color: #B8935A; background: #fff; }
    .field small { display: block; margin-top: 5px; font-size: 12px; color: #8A9BAC; line-height: 1.5; }
    .field-err { margin-top: 4px; font-size: 12px; color: #c0392b; }
    .req { color: #B8935A; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media(max-width:540px) { .grid-2 { grid-template-columns: 1fr; } }
    .btn-row { display: flex; gap: 10px; justify-content: flex-end; margin-top: 24px; flex-wrap: wrap; }
    .btn-primary, .btn-sec, .btn-install { padding: 11px 24px; border-radius: 7px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; text-decoration: none; display: inline-flex; align-items: center; }
    .btn-primary { background: #B8935A; color: #fff; }
    .btn-primary:hover { background: #a07d4a; }
    .btn-primary:disabled { background: #D0C9BC; cursor: not-allowed; }
    .btn-sec { background: #E8E0D0; color: #3A4A5C; }
    .btn-sec:hover { background: #D8D0C0; }
    .btn-install { background: #1F2D3D; color: #F5F0E6; font-size: 15px; padding: 13px 28px; }
    .btn-install:hover { background: #2A3D52; }
    .hint { font-size: 13px; color: #6E7A88; background: #F0F4F8; border-radius: 6px; padding: 10px 14px; margin-bottom: 18px; }
    .alert { border-radius: 8px; padding: 12px 16px; margin-bottom: 18px; font-size: 14px; }
    .alert-ok  { background: #F0F9F0; color: #2E7D32; border: 1px solid #A5D6A7; }
    .alert-err { background: #FFF5F5; color: #c0392b; border: 1px solid #FFCDD2; }
    .req-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .req-table th { text-align: left; padding: 8px 12px; background: #F8F5EE; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: #6E7A88; border-bottom: 2px solid #E8E0D0; }
    .req-table td { padding: 10px 12px; border-bottom: 1px solid #F0EDE6; }
    .req-table .row-fail td { background: #FFF5F5; }
    .chk { font-weight: 700; margin-right: 6px; }
    .chk.ok   { color: #2E7D32; }
    .chk.fail { color: #c0392b; }
    .summary-box { background: #F8F5EE; border-radius: 8px; padding: 16px 20px; margin: 16px 0; }
    .summary-title { font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: #8A9BAC; margin-bottom: 10px; }
    .summary-row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid #E8E0D0; font-size: 13px; }
    .summary-row:last-child { border-bottom: none; }
    .summary-row span { color: #6E7A88; }
    .warn { text-align: center; padding: 40px 32px; }
    .warn-icon { font-size: 48px; margin-bottom: 16px; }
    .warn h2 { color: #c0392b; margin-bottom: 12px; }
    .success-card { text-align: center; background: linear-gradient(135deg, #1F2D3D, #2A3D52); color: #F5F0E6; border: none; }
    .success-icon { font-size: 56px; margin-bottom: 16px; color: #B8935A; }
    .success-card h2 { color: #F5F0E6; margin: 0; }
    .err-card { text-align: center; }
    .err-icon { font-size: 48px; color: #c0392b; margin-bottom: 12px; }
    .err-icon { display: block; }
    .err-msg { color: #c0392b; background: #FFF5F5; border-radius: 6px; padding: 12px 16px; font-size: 14px; }
    .log-box { background: #F8F5EE; border-radius: 6px; padding: 14px; font-size: 13px; line-height: 2; }
    .log-ok   { color: #2E7D32; }
    .log-info { color: #5A7A9C; }
    .token-card { border: 2px solid #B8935A; }
    .token-box { background: #1F2D3D; color: #B8935A; font-family: monospace; font-size: 13px; padding: 14px 16px; border-radius: 6px; word-break: break-all; margin: 12px 0; letter-spacing: .5px; }
    .next-steps { padding-left: 20px; line-height: 2; font-size: 14px; }
    .next-steps li { margin-bottom: 6px; }
    .next-steps code { background: #F0EDE6; padding: 2px 6px; border-radius: 4px; font-size: 12.5px; }
    code { background: #F0EDE6; padding: 2px 5px; border-radius: 4px; font-size: 13px; }
  </style>
</head>
<body>
  <div class="top-bar">
    <div class="logo">Fodor Ingatlan</div>
    <div class="sub">Review OS — Installer</div>
  </div>
  <div class="wrap">
    <?= $body ?>
  </div>
</body>
</html>
    <?php
    return ob_get_clean();
}

// ─── Entry point ──────────────────────────────────────────────────────────────
echo render_step($step, $ROOT, $SCHEMA, $CONFIG, $DATA_DIR, $DB_FILE);
