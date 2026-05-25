<?php
/**
 * One-time template fix: updates email_templates in DB.
 * Run once, then DELETE this file from the server.
 */
define('ALLOWED_SECRET', 'fodor-fix-2026');
if (($_GET['key'] ?? '') !== ALLOWED_SECRET) {
    http_response_code(403);
    exit('Forbidden. Usage: ?key=fodor-fix-2026');
}

require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/db.php';

// Detect logo
$logo_file = __DIR__ . '/logo.png';
$logo_b64  = file_exists($logo_file)
    ? 'data:image/png;base64,' . base64_encode(file_get_contents($logo_file))
    : null;
$logo_html = $logo_b64
    ? '<img src="' . $logo_b64 . '" alt="Fodor Ingatlan" height="28" style="display:block;height:28px;max-width:140px;">'
    : '<span style="font-size:15px;font-weight:700;color:#F5F0E6;letter-spacing:0.5px;">Fodor Ingatlan</span>';

$html1 =
'<!DOCTYPE html><html lang="hu"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>' .
'<body style="margin:0;padding:0;background:#F4F1EA;font-family:Arial,Helvetica,sans-serif;">' .
'<table width="100%" cellpadding="0" cellspacing="0" style="background:#F4F1EA;padding:32px 16px;">' .
'<tr><td align="center">' .
'<table width="580" cellpadding="0" cellspacing="0" style="max-width:580px;width:100%;">' .
'<tr><td style="background:#1F2D3D;border-radius:10px 10px 0 0;padding:24px 36px;">' .
'<table width="100%" cellpadding="0" cellspacing="0"><tr>' .
'<td valign="middle">' . $logo_html . '</td>' .
'<td align="right" valign="middle" style="font-size:11px;color:#8A9BAC;font-family:Arial;">Fodor Ingatlan</td>' .
'</tr></table></td></tr>' .
'<tr><td style="background:#243447;padding:28px 36px 24px;border-bottom:2px solid #B8935A;">' .
'<div style="font-size:22px;color:#F5F0E6;font-weight:700;line-height:1.3;font-family:Georgia,serif;">Köszönjük a bizalmát!</div>' .
'<div style="font-size:13px;color:#8A9BAC;margin-top:6px;line-height:1.5;">Rövid Google értékelést kérünk Öntől</div>' .
'</td></tr>' .
'<tr><td style="background:#FFFFFF;padding:32px 36px;">' .
'<p style="margin:0 0 20px;font-size:15px;color:#1F2D3D;font-weight:600;">Kedves {{ugyfelnev}}!</p>' .
'<p style="margin:0 0 14px;font-size:14px;color:#3A4A5C;line-height:1.8;">Köszönjük a bizalmát és a közös munkát. Öröm számunkra, hogy segíthettünk Önnek az ingatlanügylet sikeres lezárásában.</p>' .
'<p style="margin:0 0 14px;font-size:14px;color:#3A4A5C;line-height:1.8;">A Fodor Ingatlan Közvetítő Kft.-nél arra törekszünk, hogy ügyfeleink ne csupán eredményes, hanem valóban nyugodt és pozitív élményként éljék meg az ingatlanközvetítés folyamatát.</p>' .
'<p style="margin:0 0 28px;font-size:14px;color:#3A4A5C;line-height:1.8;">Amennyiben elégedett volt szolgáltatásunkkal, nagyra értékelnénk, ha megosztaná tapasztalatait egy Google értékelés formájában. Néhány kedves mondat sokat segít azoknak is, akik jelenleg keresik a számukra megfelelő ingatlanirodát.</p>' .
'<table cellpadding="0" cellspacing="0" style="margin:0 auto 28px;">' .
'<tr><td align="center">{{review_link_html}}</td></tr></table>' .
'<p style="margin:0;font-size:11.5px;color:#9AA3AC;text-align:center;line-height:1.6;">A gombra kattintva közvetlenül a Google értékelő felületre jut.</p>' .
'</td></tr>' .
'<tr><td style="background:#F4F1EA;border-top:1px solid #E2DAC8;border-radius:0 0 10px 10px;padding:20px 36px;">' .
'<table width="100%" cellpadding="0" cellspacing="0"><tr>' .
'<td valign="middle">' .
'<div style="font-size:13px;font-weight:700;color:#1F2D3D;">{{ugynok_nev}}</div>' .
'<div style="font-size:11.5px;color:#6E7A88;margin-top:2px;">Ingatlanközvetítő · Fodor Ingatlan Kft.</div>' .
'<div style="font-size:11.5px;color:#B8935A;margin-top:2px;">{{ugynok_telefon}}</div>' .
'</td><td align="right" valign="middle">' . $logo_html . '</td>' .
'</tr></table>' .
'<p style="margin:14px 0 0;font-size:10.5px;color:#B0A898;text-align:center;line-height:1.6;">Ezt az üzenetet azért kapta, mert ingatlanügyletet kötött a Fodor Ingatlan Közvetítő Kft.-vel.</p>' .
'</td></tr>' .
'</table></td></tr></table></body></html>';

$text1 = "Kedves {{ugyfelnev}}!\n\nKöszönjük a bizalmát és a közös munkát. Öröm számunkra, hogy segíthettünk Önnek az ingatlanügylet sikeres lezárásában.\n\nA Fodor Ingatlan Közvetítő Kft.-nél arra törekszünk, hogy ügyfeleink ne csupán eredményes, hanem valóban nyugodt és pozitív élményként éljék meg az ingatlanközvetítés folyamatát.\n\nAmennyiben elégedett volt szolgáltatásunkkal, nagyra értékelnénk, ha megosztaná tapasztalatait egy Google értékelés formájában:\n\n{{review_link}}\n\nKöszönjük!\n\n{{ugynok_nev}}\nIngatlanközvetítő · Fodor Ingatlan Kft.\n{{ugynok_telefon}}";

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
'</tr></table></td></tr>' .
'<tr><td style="background:#243447;padding:28px 36px 24px;border-bottom:2px solid #B8935A;">' .
'<div style="font-size:21px;color:#F5F0E6;font-weight:700;line-height:1.3;font-family:Georgia,serif;">Még egy percet kérnék…</div>' .
'<div style="font-size:13px;color:#8A9BAC;margin-top:6px;">Rövid emlékeztető az értékelés kapcsán</div>' .
'</td></tr>' .
'<tr><td style="background:#FFFFFF;padding:32px 36px;">' .
'<p style="margin:0 0 16px;font-size:15px;color:#1F2D3D;font-weight:600;">Kedves {{nev}}!</p>' .
'<p style="margin:0 0 14px;font-size:14px;color:#3A4A5C;line-height:1.7;">Néhány napja küldtem Önnek egy üzenetet Google értékelés kapcsán.</p>' .
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
'</td><td align="right" valign="middle">' . $logo_html . '</td>' .
'</tr></table>' .
'<p style="margin:14px 0 0;font-size:10.5px;color:#B0A898;text-align:center;line-height:1.6;">Ezt az üzenetet azért kapta, mert ingatlanügyletet kötött a Fodor Ingatlan Közvetítő Kft.-vel.</p>' .
'</td></tr>' .
'</table></td></tr></table></body></html>';

$text8 = "Kedves {{nev}}!\n\nNéhány napja küldtem Önnek egy üzenetet Google értékelés kapcsán. Ha van egy perced:\n\n{{review_link}}\n\nEz az utolsó emlékeztető.\n\n{{ugynok_nev}}\nIngatlanközvetítő · Fodor Ingatlan Kft.\n{{ugynok_telefon}}";

$vars = json_encode(['nev','ugynok_nev','ugynok_telefon','review_link_html','review_link'], JSON_UNESCAPED_UNICODE);

db_run('UPDATE email_templates SET subject=?, body_html=?, body_text=?, variables=? WHERE id=1', [
    'Köszönjük a bizalmát, {{ugyfelnev}}!', $html1, $text1, $vars
]);
db_run('UPDATE email_templates SET subject=?, body_html=?, body_text=?, variables=? WHERE id=8', [
    'Emlékeztető: értékelje {{nev}} a Fodor Ingatlannal szerzett tapasztalatait', $html8, $text8, $vars
]);

header('Content-Type: text/plain; charset=utf-8');
echo "Template 1 és 8 frissítve.\nTöröld ezt a fájlt a szerverről!\n";
