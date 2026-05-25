<?php
/**
 * Public NPS pre-filter page.
 * Clients land here from their review request email/SMS.
 * Shows a 1-10 score selector, verifies reCAPTCHA v2, then redirects.
 *
 * URL: /nps.php?t=REQUEST_TOKEN
 */

require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/db.php';

$token = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['t'] ?? '');

if (empty($token)) {
    http_response_code(400);
    die('Érvénytelen hivatkozás.');
}

// Load the request by token
$request = db_fetch_one(
    'SELECT rr.*, c.name AS client_name, c.email AS client_email,
            a.name AS agent_name, a.review_link, a.personalized_msg,
            o.name AS office_name
     FROM review_requests rr
     JOIN contacts c ON rr.contact_id = c.id
     JOIN agents a   ON rr.agent_id   = a.id
     JOIN offices o  ON a.office_id   = o.id
     WHERE rr.nps_token = ?
       AND rr.state IN (\'sent\', \'opened\')',
    [$token]
);

if (!$request) {
    http_response_code(404);
    die('Ez a hivatkozás már nem érvényes vagy lejárt.');
}

// Mark as opened if not yet
if ($request['state'] === 'sent' && empty($request['opened_at'])) {
    db_run(
        'UPDATE review_requests SET opened_at = datetime(\'now\'), state = \'opened\' WHERE id = ?',
        [$request['id']]
    );
}

$submitted   = false;
$error       = '';
$redirect_url = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $score     = (int)($_POST['score'] ?? 0);
    $recaptcha = $_POST['g-recaptcha-response'] ?? '';

    if ($score < 1 || $score > 10) {
        $error = 'Kérjük, válasszon pontszámot 1 és 10 között.';
    } elseif (empty($recaptcha)) {
        $error = 'A reCAPTCHA ellenőrzés szükséges.';
    } else {
        // Verify reCAPTCHA
        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'secret'   => RECAPTCHA_SECRET_KEY,
                'response' => $recaptcha,
                'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!($resp['success'] ?? false)) {
            $error = 'reCAPTCHA ellenőrzés sikertelen. Kérjük, próbálja újra.';
        } else {
            // Save score and update state
            $threshold = 9; // default; load from automation if linked
            if ($request['automation_id']) {
                $auto = db_fetch_one('SELECT nps_threshold FROM automations WHERE id = ?', [$request['automation_id']]);
                if ($auto) $threshold = (int)$auto['nps_threshold'];
            }

            $new_state    = $score >= $threshold ? 'nps_done_positive' : 'nps_done_negative';
            $redirect_url = $score >= $threshold
                ? ($request['review_link'] ?: 'https://g.page/r/fodor-review')
                : ''; // internal path — no redirect for negatives

            db_run(
                'UPDATE review_requests SET nps_score = ?, state = ?, nps_at = datetime(\'now\') WHERE id = ?',
                [$score, $new_state, $request['id']]
            );

            $al_state = $score >= $threshold ? 'converted' : 'negative_path';
            db_run(
                "UPDATE automation_logs SET state = ?
                 WHERE contact_id = (SELECT contact_id FROM review_requests WHERE id = ?)
                   AND state = 'waiting_nps'",
                [$al_state, $request['id']]
            );

            log_event('info', 'NPS válasz érkezett', [
                'request_id' => $request['id'],
                'score'      => $score,
                'state'      => $new_state,
            ]);

            $submitted = true;

            if ($score >= $threshold && !empty($redirect_url)) {
                header('Location: ' . $redirect_url);
                exit;
            }
        }
    }
}

$first_name = explode(' ', $request['client_name'])[0] ?? $request['client_name'];
$agent_name = $request['agent_name'];
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fodor Ingatlan · Visszajelzés</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=DM+Serif+Display&display=swap" rel="stylesheet">
<script src="https://www.google.com/recaptcha/api.js" async defer></script>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body {
    margin: 0; padding: 0;
    font-family: 'DM Sans', system-ui, sans-serif;
    background: #F8F5EE;
    min-height: 100vh;
    display: flex; align-items: center; justify-content: center;
    padding: 24px;
    color: #1F2D3D;
  }
  .card {
    background: #fff;
    border: 1px solid #E6DECF;
    border-radius: 16px;
    padding: 40px;
    max-width: 520px;
    width: 100%;
    box-shadow: 0 4px 24px rgba(31,45,61,0.08);
  }
  .logo { display: flex; align-items: center; gap: 12px; margin-bottom: 32px; }
  .logo-text { font-family: 'DM Serif Display', serif; font-size: 20px; color: #1F2D3D; letter-spacing: 1px; }
  .logo-sub { font-size: 10px; color: #B8935A; letter-spacing: 2px; margin-top: 2px; }
  h1 { font-family: 'DM Serif Display', serif; font-size: 24px; color: #162232; margin: 0 0 8px; }
  .subtitle { font-size: 14px; color: #6E7A88; margin-bottom: 32px; line-height: 1.6; }
  .scores {
    display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 16px;
  }
  .score-btn {
    width: 44px; height: 44px; border-radius: 8px;
    border: 1.5px solid #E6DECF;
    background: #FBF7EE; color: #1F2D3D;
    font-size: 15px; font-weight: 600; cursor: pointer;
    font-family: inherit; transition: all 0.15s;
  }
  .score-btn:hover, .score-btn.active {
    background: #B8935A; border-color: #B8935A; color: #fff;
  }
  .score-labels { display: flex; justify-content: space-between; font-size: 11px; color: #6E7A88; margin-bottom: 24px; }
  .recaptcha-wrap { margin-bottom: 20px; }
  .submit-btn {
    width: 100%; padding: 14px;
    background: #1F2D3D; color: #F5F0E6;
    border: none; border-radius: 8px;
    font-size: 15px; font-weight: 600; cursor: pointer; font-family: inherit;
    transition: background 0.2s;
  }
  .submit-btn:hover { background: #162232; }
  .error { background: #F6E5E3; color: #B4584F; border-radius: 8px; padding: 12px 16px; font-size: 13px; margin-bottom: 16px; }
  .success { text-align: center; padding: 20px 0; }
  .success-icon { font-size: 48px; margin-bottom: 16px; }
  .success h2 { font-family: 'DM Serif Display', serif; font-size: 22px; color: #162232; margin: 0 0 8px; }
  .success p { font-size: 14px; color: #6E7A88; line-height: 1.6; }
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <svg width="36" height="36" viewBox="0 0 40 40">
      <circle cx="20" cy="20" r="18" fill="none" stroke="#B8935A" stroke-width="2"/>
      <path d="M14 14 L20 10 L26 14 L26 22 L20 26 L14 22 Z" fill="none" stroke="#B8935A" stroke-width="1.6"/>
      <circle cx="20" cy="18" r="2.2" fill="#B8935A"/>
    </svg>
    <div>
      <div class="logo-text">FODOR INGATLAN</div>
      <div class="logo-sub">VISSZAJELZÉS</div>
    </div>
  </div>

  <?php if ($submitted && empty($redirect_url)): ?>
    <div class="success">
      <div class="success-icon">🙏</div>
      <h2>Köszönjük visszajelzését!</h2>
      <p>
        Értékes véleményét megkaptuk és hamarosan felvesszük Önnel a kapcsolatot.<br>
        <strong><?= htmlspecialchars($agent_name) ?></strong> kollégánk rövid időn belül visszahív.
      </p>
    </div>
  <?php else: ?>
    <h1>Hogyan volt elégedett?</h1>
    <p class="subtitle">
      Kedves <?= htmlspecialchars($first_name) ?>! Kérjük, értékelje
      <strong><?= htmlspecialchars($agent_name) ?></strong> kollégánkkal és az irodánkkal való együttműködést
      1-től 10-ig terjedő skálán.
    </p>

    <?php if ($error): ?>
      <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" id="npsForm">
      <div class="scores" id="scores">
        <?php for ($i = 1; $i <= 10; $i++): ?>
          <button type="button" class="score-btn" data-score="<?= $i ?>"><?= $i ?></button>
        <?php endfor; ?>
      </div>
      <div class="score-labels">
        <span>1 = Egyáltalán nem ajánlanám</span>
        <span>10 = Biztosan ajánlanám</span>
      </div>
      <input type="hidden" name="score" id="scoreInput" value="0">
      <div class="recaptcha-wrap">
        <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars(RECAPTCHA_SITE_KEY) ?>"></div>
      </div>
      <button type="submit" class="submit-btn">Küldés →</button>
    </form>

    <script>
      const btns = document.querySelectorAll('.score-btn');
      const input = document.getElementById('scoreInput');
      btns.forEach(btn => {
        btn.addEventListener('click', function() {
          btns.forEach(b => b.classList.remove('active'));
          this.classList.add('active');
          input.value = this.dataset.score;
        });
      });
      document.getElementById('npsForm').addEventListener('submit', function(e) {
        if (input.value === '0') {
          e.preventDefault();
          alert('Kérjük, válasszon pontszámot!');
        }
      });
    </script>
  <?php endif; ?>
</div>
</body>
</html>
