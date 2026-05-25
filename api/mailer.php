<?php
/**
 * Fodor Review OS — Shared mailer helpers
 * Used by contacts.php (immediate send) and cron/process_queue.php
 */

function mailer_send_email(array $data): array
{
    $base = __DIR__ . '/../vendor/phpmailer';
    if (!file_exists($base . '/phpmailer.php')) {
        return ['success' => false, 'error' => 'PHPMailer hiányzik'];
    }
    require_once $base . '/phpmailer.php';
    require_once $base . '/smtp.php';
    require_once $base . '/exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->Port       = (int)SMTP_PORT;
        $mail->Timeout    = 15;
        $mail->SMTPDebug  = PHPMailer\PHPMailer\SMTP::DEBUG_OFF;
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];

        $secure = defined('SMTP_SECURE') ? strtolower(SMTP_SECURE) : 'tls';
        $mail->SMTPSecure = ($secure === 'ssl')
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

        $from_name = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Fodor Ingatlan';
        $mail->setFrom(SMTP_USER, $from_name);
        $mail->addAddress($data['to_email'], $data['to_name'] ?? '');
        $mail->addReplyTo(SMTP_USER, $from_name);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $data['subject'];
        $mail->Body    = $data['body_html'];
        $mail->AltBody = $data['body_text'] ?: strip_tags($data['body_html']);
        $mail->send();
        return ['success' => true, 'error' => null];
    } catch (\Exception $e) {
        return ['success' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage()];
    }
}

function mailer_send_sms(string $to, string $body): array
{
    if (!defined('TWILIO_SID') || TWILIO_SID === 'CONFIGURE_ME' || empty(TWILIO_SID)) {
        return ['success' => false, 'error' => 'Twilio nincs konfigurálva'];
    }
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_SID . '/Messages.json';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['To' => $to, 'From' => TWILIO_FROM, 'Body' => $body]),
        CURLOPT_USERPWD        => TWILIO_SID . ':' . TWILIO_TOKEN,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return ['success' => false, 'error' => $err];
    $data = json_decode($resp, true);
    if ($code >= 200 && $code < 300 && isset($data['sid'])) {
        return ['success' => true, 'error' => null];
    }
    return ['success' => false, 'error' => $data['message'] ?? "HTTP {$code}"];
}

/**
 * Build substitution vars for a queue entry row (joined with contacts/agents/offices/review_requests).
 */
function mailer_build_vars(array $entry): array
{
    $first_name  = explode(' ', trim($entry['contact_name'] ?? ''))[0] ?? '';
    $nps_token   = $entry['nps_token'] ?? '';
    $direct_link = $entry['agent_review_link'] ?? '';
    $base_url    = defined('APP_URL') ? APP_URL : '';

    $tracking_url = !empty($nps_token)
        ? $base_url . '/click.php?t=' . urlencode($nps_token)
        : $direct_link;
    $nps_url = !empty($nps_token)
        ? $base_url . '/nps.php?t=' . urlencode($nps_token)
        : '';
    $nps_link_html = !empty($nps_url)
        ? '<a href="' . htmlspecialchars($nps_url, ENT_QUOTES) . '" style="display:inline-block;background:#1F2D3D;color:#F5F0E6;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;">Értékelés küldése →</a>'
        : '';

    return [
        'nev'            => $first_name,
        'ugyfelnev'      => $entry['contact_name']    ?? '',
        'ugynok_nev'     => $entry['agent_name']      ?? '',
        'ugynok_alairas' => $entry['agent_signature'] ?? ($entry['agent_name'] ?? ''),
        'iroda_neve'     => $entry['office_name']     ?? '',
        'iroda_cim'      => $entry['office_address']  ?? '',
        'review_link'    => $tracking_url,
        'nps_link'       => $nps_url,
        'nps_link_html'  => $nps_link_html,
    ];
}

function mailer_apply_vars(string $text, array $vars, bool $escape_html = false): string
{
    $html_link = $vars['nps_link_html'] ?? '';
    unset($vars['nps_link_html']);
    foreach ($vars as $k => $v) {
        $val  = $escape_html ? htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') : (string)$v;
        $text = str_replace('{{' . $k . '}}', $val, $text);
    }
    return str_replace('{{nps_link_html}}', $html_link, $text);
}

/**
 * Process and immediately send all queued entries for a given request_id.
 * Returns ['sent' => N, 'failed' => N, 'errors' => [...]]
 */
function mailer_flush_request(int $request_id): array
{
    $rows = db_fetch_all(
        "SELECT sq.*,
                c.name        AS contact_name,
                a.name        AS agent_name,
                a.signature   AS agent_signature,
                a.review_link AS agent_review_link,
                o.name        AS office_name,
                o.address     AS office_address,
                rr.nps_token
         FROM send_queue sq
         LEFT JOIN review_requests rr ON rr.id = sq.request_id
         LEFT JOIN contacts c ON c.id = rr.contact_id
         LEFT JOIN agents   a ON a.id = rr.agent_id
         LEFT JOIN offices  o ON o.id = a.office_id
         WHERE sq.request_id = ? AND sq.status = 'queued'",
        [$request_id]
    );

    $sent = 0; $failed = 0; $errors = [];

    foreach ($rows as $entry) {
        $vars      = mailer_build_vars($entry);
        $subject   = mailer_apply_vars($entry['subject']   ?? '', $vars, false);
        $body_html = mailer_apply_vars($entry['body_html'] ?? '', $vars, true);
        $body_text = mailer_apply_vars($entry['body_text'] ?? '', $vars, false);

        // Inject tracking pixel
        $nps_token = $entry['nps_token'] ?? '';
        if ($entry['channel'] === 'email' && !empty($body_html) && !empty($nps_token)) {
            $pixel = '<img src="' . htmlspecialchars(APP_URL . '/track.php?t=' . urlencode($nps_token), ENT_QUOTES) . '" width="1" height="1" alt="" style="display:none;">';
            $body_html = stripos($body_html, '</body>') !== false
                ? str_ireplace('</body>', $pixel . '</body>', $body_html)
                : $body_html . $pixel;
        }

        $result = $entry['channel'] === 'sms'
            ? mailer_send_sms($entry['to_address'] ?? '', $body_text)
            : mailer_send_email([
                'to_email'  => $entry['to_address'] ?? '',
                'to_name'   => $entry['to_name']    ?? '',
                'subject'   => $subject,
                'body_html' => $body_html,
                'body_text' => $body_text,
              ]);

        if ($result['success']) {
            db_run("UPDATE send_queue SET status='sent', sent_at=datetime('now') WHERE id=?", [(int)$entry['id']]);
            db_run("UPDATE review_requests SET state='sent', sent_at=datetime('now') WHERE id=? AND state IN ('pending','queued')", [$request_id]);
            $sent++;
        } else {
            db_run("UPDATE send_queue SET status='failed', error_msg=? WHERE id=?", [$result['error'], (int)$entry['id']]);
            $errors[] = $result['error'];
            $failed++;
        }
    }

    return ['sent' => $sent, 'failed' => $failed, 'errors' => $errors];
}
