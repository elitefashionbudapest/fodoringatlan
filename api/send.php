<?php
/**
 * Fodor Review OS — Send API
 * Handles email and SMS sending via PHPMailer + Twilio.
 *
 * POST /api/send.php  — send email and/or SMS immediately
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

require_once __DIR__ . '/../vendor/phpmailer/phpmailer.php';
require_once __DIR__ . '/../vendor/phpmailer/smtp.php';
require_once __DIR__ . '/../vendor/phpmailer/exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_auth();
rate_limit_check('send');

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    json_error('Method not allowed', 405);
}

// ─── Parse body ─────────────────────────────────────────────────────────────

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    json_error('Invalid JSON body');
}

$channel    = sanitize_input($body['channel']    ?? '');
$to_email   = sanitize_input($body['to_email']   ?? '');
$to_name    = sanitize_input($body['to_name']    ?? '');
$to_phone   = sanitize_input($body['to_phone']   ?? '');
$subject    = sanitize_input($body['subject']    ?? '');
$body_html  = $body['body_html']  ?? '';
$body_text  = sanitize_input($body['body_text']  ?? '');
$request_id = isset($body['request_id']) ? (int)$body['request_id'] : null;

$allowed_channels = ['email', 'sms', 'email+sms'];
if (!in_array($channel, $allowed_channels, true)) {
    json_error('channel must be one of: ' . implode(', ', $allowed_channels));
}

if (($channel === 'email' || $channel === 'email+sms') && (!$to_email || !$subject)) {
    json_error('to_email and subject are required for email channel');
}

if (($channel === 'sms' || $channel === 'email+sms') && !$to_phone) {
    json_error('to_phone is required for sms channel');
}

// ─── Template variable substitution ─────────────────────────────────────────

/**
 * Replace {variable_name} placeholders with values from $vars.
 * For HTML body, values are HTML-escaped.
 */
function substitute_vars(string $template, array $vars, bool $escape_html = false): string
{
    foreach ($vars as $key => $value) {
        $safe = $escape_html ? htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') : (string)$value;
        $template = str_replace('{{' . $key . '}}', $safe, $template);
    }
    return $template;
}

// Build substitution vars from contact/request context if request_id given
$sub_vars = [];
if ($request_id) {
    $req = db_fetch_one(
        'SELECT rr.*, c.name AS contact_name, c.email AS contact_email, c.phone AS contact_phone,
                a.name AS agent_name, a.email AS agent_email, a.review_link AS agent_review_link,
                a.signature AS agent_signature,
                o.name AS office_name, o.address AS office_address
         FROM review_requests rr
         LEFT JOIN contacts c ON c.id = rr.contact_id
         LEFT JOIN agents a   ON a.id = rr.agent_id
         LEFT JOIN offices o  ON o.id = a.office_id
         WHERE rr.id = ?',
        [$request_id]
    );
    if ($req) {
        $first_name = explode(' ', trim($req['contact_name'] ?? ''))[0] ?? '';

        // Build click-tracking URL; falls back to direct link if no token
        $nps_token   = $req['nps_token'] ?? '';
        $direct_link = $req['agent_review_link'] ?? '';
        $tracking_url = !empty($nps_token)
            ? APP_URL . '/click.php?t=' . urlencode($nps_token)
            : $direct_link;

        // NPS pre-filter link
        $nps_url      = !empty($nps_token)
            ? APP_URL . '/nps.php?t=' . urlencode($nps_token)
            : '';
        $nps_link_html = !empty($nps_url)
            ? '<a href="' . htmlspecialchars($nps_url, ENT_QUOTES) . '" style="display:inline-block;background:#1F2D3D;color:#F5F0E6;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;">Értékelés küldése →</a>'
            : '';

        $sub_vars = [
            // ASCII aliases (legacy)
            'nev'              => $first_name,
            'ugyfelnev'        => $req['contact_name']      ?? '',
            'ugynok_nev'       => $req['agent_name']        ?? '',
            'ugynok_alairas'   => $req['agent_signature']   ?? ($req['agent_name'] ?? ''),
            'iroda_neve'       => $req['office_name']        ?? '',
            'iroda_cim'        => $req['office_address']     ?? '',
            'review_link'      => $tracking_url,
            'nps_link'         => $nps_url,
            'nps_link_html'    => $nps_link_html,
            // Hungarian names matching email_templates variables field
            'ügyfél_keresztnév' => $first_name,
            'ügyfél_teljes_név' => $req['contact_name']      ?? '',
            'ügynök_neve'       => $req['agent_name']        ?? '',
            'ügynök_email'      => $req['agent_email']       ?? '',
            'ügynök_telefon'    => $req['agent_phone']       ?? '',
            'iroda_neve'        => $req['office_name']        ?? '',
            'iroda_cím'         => $req['office_address']     ?? '',
            'dátum'             => date('Y. F j.'),
        ];
    }
}

// Apply substitution
// nps_link_html contains actual HTML markup — insert raw, escape everything else
if ($sub_vars) {
    $html_vars = $sub_vars;
    unset($html_vars['nps_link_html']);

    $body_html = substitute_vars($body_html, $html_vars, true);
    $body_html = str_replace('{{nps_link_html}}', $sub_vars['nps_link_html'] ?? '', $body_html);

    $body_text = substitute_vars($body_text, $sub_vars, false);
    $subject   = substitute_vars($subject,   $sub_vars, false);
}

// Auto-inject tracking pixel into email HTML
if (($channel === 'email' || $channel === 'email+sms') && !empty($body_html) && !empty($request_id)) {
    $nps_token_for_pixel = $sub_vars['nps_token'] ?? '';
    if (empty($nps_token_for_pixel) && $request_id) {
        $rr_row = db_fetch_one('SELECT nps_token FROM review_requests WHERE id = ?', [$request_id]);
        $nps_token_for_pixel = $rr_row['nps_token'] ?? '';
    }
    if (!empty($nps_token_for_pixel)) {
        $pixel = '<img src="' . htmlspecialchars(APP_URL . '/track.php?t=' . urlencode($nps_token_for_pixel), ENT_QUOTES) . '" width="1" height="1" alt="" style="display:none;">';
        if (stripos($body_html, '</body>') !== false) {
            $body_html = str_ireplace('</body>', $pixel . '</body>', $body_html);
        } else {
            $body_html .= $pixel;
        }
    }
}

// ─── Send ────────────────────────────────────────────────────────────────────

$email_result = ['success' => false, 'message_id' => null, 'error' => null];
$sms_result   = ['success' => false, 'message_id' => null, 'error' => null];

if ($channel === 'email' || $channel === 'email+sms') {
    $email_result = send_email([
        'to_email'  => $to_email,
        'to_name'   => $to_name,
        'subject'   => $subject,
        'body_html' => $body_html,
        'body_text' => $body_text,
    ]);

    _log_send_result('email', $to_email, $email_result, $request_id);
}

if ($channel === 'sms' || $channel === 'email+sms') {
    $sms_result = send_sms($to_phone, $body_text ?: strip_tags($body_html));

    _log_send_result('sms', $to_phone, $sms_result, $request_id);
}

// ─── Response ────────────────────────────────────────────────────────────────

$overall_success = true;
if (($channel === 'email' || $channel === 'email+sms') && !$email_result['success']) {
    $overall_success = false;
}
if (($channel === 'sms' || $channel === 'email+sms') && !$sms_result['success']) {
    $overall_success = false;
}

$message_id = $email_result['message_id'] ?? $sms_result['message_id'] ?? null;

if (!$overall_success) {
    $errors = array_filter([
        $email_result['error'] ?? null,
        $sms_result['error']   ?? null,
    ]);
    json_error('Send failed: ' . implode('; ', $errors), 502);
}

json_response([
    'success'    => true,
    'message_id' => $message_id,
    'email'      => $channel !== 'sms'   ? $email_result : null,
    'sms'        => $channel !== 'email' ? $sms_result   : null,
]);

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Send email via PHPMailer / SMTP.
 *
 * @param  array $data  [to_email, to_name, subject, body_html, body_text]
 * @return array        [success, message_id, error]
 */
function send_email(array $data): array
{
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->Port       = (int)SMTP_PORT;
        $mail->SMTPDebug  = (defined('APP_ENV') && APP_ENV === 'development') ? SMTP::DEBUG_SERVER : SMTP::DEBUG_OFF;

        // Encryption
        $secure = defined('SMTP_SECURE') ? strtolower(SMTP_SECURE) : 'tls';
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        // From / To
        $from_name = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Fodor Ingatlan';
        $mail->setFrom(SMTP_USER, $from_name);
        $mail->addAddress($data['to_email'], $data['to_name'] ?? '');
        $mail->addReplyTo(SMTP_USER, $from_name);

        // Anti-spam headers
        $mail->XMailer = ' ';  // suppress "X-Mailer: PHPMailer" fingerprint
        $unsubscribe_email = 'mailto:' . SMTP_USER . '?subject=leiratkozas';
        $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribe_email . '>');
        $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        $mail->addCustomHeader('X-Entity-Ref-ID', bin2hex(random_bytes(8)));
        $mail->addCustomHeader('Feedback-ID', 'fodor-review:fodoringatlan:PHPMailer');

        // Content
        $mail->isHTML(true);
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'quoted-printable';
        $mail->Subject  = $data['subject'];
        $mail->Body     = $data['body_html'];
        $mail->AltBody  = $data['body_text'] ?: strip_tags($data['body_html']);

        $mail->send();

        $message_id = $mail->getLastMessageID();
        log_event('info', 'Email sent', [
            'to'         => $data['to_email'],
            'subject'    => $data['subject'],
            'message_id' => $message_id,
        ]);

        return ['success' => true, 'message_id' => $message_id, 'error' => null];

    } catch (Exception $e) {
        $error = $mail->ErrorInfo;
        log_event('error', 'Email send failed', [
            'to'    => $data['to_email'],
            'error' => $error,
        ]);
        return ['success' => false, 'message_id' => null, 'error' => $error];
    }
}

/**
 * Send SMS via Twilio REST API.
 *
 * @param  string $to    Destination phone number (E.164 format)
 * @param  string $body  SMS text content
 * @return array         [success, message_id, error]
 */
function send_sms(string $to, string $body): array
{
    $sid   = TWILIO_SID;
    $token = TWILIO_TOKEN;
    $from  = TWILIO_FROM;

    $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'To'   => $to,
            'From' => $from,
            'Body' => $body,
        ]),
        CURLOPT_USERPWD          => "{$sid}:{$token}",
        CURLOPT_HTTPAUTH         => CURLAUTH_BASIC,
        CURLOPT_SSL_VERIFYPEER   => true,
        CURLOPT_TIMEOUT          => 30,
        CURLOPT_HTTPHEADER       => ['Accept: application/json'],
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        log_event('error', 'SMS cURL error', ['to' => $to, 'error' => $curl_err]);
        return ['success' => false, 'message_id' => null, 'error' => $curl_err];
    }

    $data = json_decode($response, true);

    if ($http_code >= 200 && $http_code < 300 && isset($data['sid'])) {
        log_event('info', 'SMS sent', ['to' => $to, 'sid' => $data['sid']]);
        return ['success' => true, 'message_id' => $data['sid'], 'error' => null];
    }

    $error_msg = $data['message'] ?? "HTTP {$http_code}";
    log_event('error', 'SMS send failed', ['to' => $to, 'status' => $http_code, 'response' => $data]);
    return ['success' => false, 'message_id' => null, 'error' => $error_msg];
}

/**
 * Update send_queue status after a send attempt.
 */
function _log_send_result(string $channel, string $to, array $result, ?int $request_id): void
{
    if ($result['success']) {
        // Update queue entry if request_id given
        if ($request_id) {
            db_run(
                "UPDATE send_queue SET status = 'sent', sent_at = datetime('now'), message_id = ?
                 WHERE request_id = ? AND channel = ? AND status = 'queued'
                 ORDER BY id DESC LIMIT 1",
                [$result['message_id'] ?? '', $request_id, $channel]
            );
        }
    } else {
        if ($request_id) {
            db_run(
                "UPDATE send_queue SET status = 'failed', error_msg = ?
                 WHERE request_id = ? AND channel = ? AND status = 'queued'
                 ORDER BY id DESC LIMIT 1",
                [$result['error'], $request_id, $channel]
            );
        }
    }
}
