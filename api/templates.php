<?php
/**
 * Fodor Review OS — Email Templates API
 * Table: email_templates (id, name, channel, subject, body_html, body_text, variables, created_at)
 *
 * GET    /api/templates.php                        — list all templates
 * GET    /api/templates.php?id=X                   — single template
 * GET    /api/templates.php?action=variables       — list available variables
 * POST   /api/templates.php                        — create template
 * POST   /api/templates.php?action=preview&id=X    — render template with sample data
 * PUT    /api/templates.php?id=X                   — update template
 * DELETE /api/templates.php?id=X                   — delete if not in active automations
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

require_auth();
rate_limit_check('templates');

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id'])     ? (int) sanitize_input($_GET['id'])         : null;
$action = isset($_GET['action']) ? sanitize_input($_GET['action'])            : null;

// ---------------------------------------------------------------------------
// Available template variables (Hungarian UI labels)
// ---------------------------------------------------------------------------
const TEMPLATE_VARIABLES = [
    'ügyfél_keresztnév',
    'ügyfél_teljes_név',
    'ügynök_neve',
    'ügynök_email',
    'ügynök_telefon',
    'iroda_neve',
    'iroda_cím',
    'ingatlan_típus',
    'ingatlan_cím',
    'review_link',
    'nps_link',
    'csillag_szam',
    'dátum',
];

// Sample values used for template preview rendering (dynamic: cannot use const)
$TEMPLATE_SAMPLE_DATA = [
    'ügyfél_keresztnév' => 'Péter',
    'ügyfél_teljes_név' => 'Kovács Péter',
    'ügynök_neve'       => 'Fodor Zsolt',
    'ügynök_email'      => 'info@fodoringatlan.hu',
    'ügynök_telefon'    => '+36 20 355 6000',
    'iroda_neve'        => 'Fodor Ingatlanközvetítő Kft.',
    'iroda_cím'         => '1188 Budapest, Lea utca 30/a',
    'ingatlan_típus'    => 'lakás',
    'ingatlan_cím'      => '1188 Budapest, Lea utca 30/a',
    'review_link'       => 'https://g.page/r/Cdz0GBei70VkEBM/review',
    'nps_link'          => 'https://fodoringatlan.hu/nps?token=SAMPLE_TOKEN',
    'csillag_szam'      => '5',
    'dátum'             => date('Y. F j.'),
];

// ---------------------------------------------------------------------------
// substitute_vars — replaces {variable_name} placeholders in $text
// ---------------------------------------------------------------------------
function substitute_vars(string $text, array $vars): string
{
    foreach ($vars as $key => $value) {
        $text = str_replace('{' . $key . '}', (string) $value, $text);
    }
    return $text;
}

// ---------------------------------------------------------------------------
// GET
// ---------------------------------------------------------------------------
if ($method === 'GET') {

    // Return available variable list
    if ($action === 'variables') {
        json_response([
            'variables'    => TEMPLATE_VARIABLES,
            'sample_values'=> $TEMPLATE_SAMPLE_DATA,
        ]);
    }

    // Single template
    if ($id) {
        $template = db_fetch_one(
            "SELECT * FROM email_templates WHERE id = ?",
            [$id]
        );

        if (!$template) {
            json_error('Template not found', 404);
        }

        // Decode variables JSON if stored as string
        if (isset($template['variables']) && is_string($template['variables'])) {
            $decoded = json_decode($template['variables'], true);
            $template['variables'] = is_array($decoded) ? $decoded : [];
        }

        json_response($template);
    }

    // List all templates
    $templates = db_fetch_all(
        "SELECT id, name, channel, subject, body_text, created_at
         FROM   email_templates
         ORDER  BY name"
    );

    json_response(['data' => $templates, 'total' => count($templates)]);
}

// ---------------------------------------------------------------------------
// POST — create OR preview
// ---------------------------------------------------------------------------
if ($method === 'POST') {

    // --- Preview action ---
    if ($action === 'preview') {
        if (!$id) {
            json_error('Query parameter "id" is required for preview', 400);
        }

        $template = db_fetch_one(
            "SELECT * FROM email_templates WHERE id = ?",
            [$id]
        );
        if (!$template) {
            json_error('Template not found', 404);
        }

        // Allow caller to pass custom override values for preview
        $custom_data = [];
        $raw_input   = file_get_contents('php://input');
        if ($raw_input) {
            $parsed = json_decode($raw_input, true);
            if (is_array($parsed)) {
                foreach ($parsed as $k => $v) {
                    $custom_data[sanitize_input($k)] = sanitize_input((string) $v);
                }
            }
        }

        $vars           = array_merge($TEMPLATE_SAMPLE_DATA, $custom_data);
        $preview_html   = substitute_vars($template['body_html']  ?? '', $vars);
        $preview_text   = substitute_vars($template['body_text']  ?? '', $vars);
        $preview_subject= substitute_vars($template['subject']    ?? '', $vars);

        json_response([
            'template_id'     => $id,
            'template_name'   => $template['name'],
            'channel'         => $template['channel'],
            'preview_subject' => $preview_subject,
            'preview_html'    => $preview_html,
            'preview_text'    => $preview_text,
            'vars_used'       => $vars,
        ]);
    }

    // --- Create template ---
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        json_error('Invalid JSON body', 400);
    }

    $name      = sanitize_input($input['name']      ?? '');
    $channel   = sanitize_input($input['channel']   ?? '');
    $subject   = sanitize_input($input['subject']   ?? '');
    $body_html = $input['body_html'] ?? ''; // preserve HTML, sanitize below

    if ($name === '') {
        json_error('Field "name" is required', 422);
    }
    if ($channel === '') {
        json_error('Field "channel" is required', 422);
    }
    $valid_channels = ['email', 'sms', 'whatsapp', 'push'];
    if (!in_array($channel, $valid_channels, true)) {
        json_error('Field "channel" must be one of: ' . implode(', ', $valid_channels), 422);
    }
    if ($subject === '') {
        json_error('Field "subject" is required', 422);
    }
    if (trim($body_html) === '') {
        json_error('Field "body_html" is required', 422);
    }

    // Detect variables actually used in this template
    $used_vars = [];
    foreach (TEMPLATE_VARIABLES as $var) {
        if (str_contains($body_html, '{' . $var . '}') ||
            str_contains($subject,   '{' . $var . '}')) {
            $used_vars[] = $var;
        }
    }

    $data = [
        'name'       => $name,
        'channel'    => $channel,
        'subject'    => $subject,
        'body_html'  => $body_html,
        'body_text'  => sanitize_input($input['body_text'] ?? ''),
        'variables'  => json_encode($used_vars),
        'created_at' => date('Y-m-d H:i:s'),
    ];

    if ($data['body_text'] === '') {
        // Auto-generate plain text by stripping HTML tags if not provided
        $data['body_text'] = strip_tags(html_entity_decode($body_html));
    }

    $new_id = db_insert('email_templates', $data);
    audit_log('create', 'email_template', $new_id, ['name' => $name, 'channel' => $channel]);

    $created            = db_fetch_one("SELECT * FROM email_templates WHERE id = ?", [$new_id]);
    $created['variables'] = $used_vars; // return as array, not JSON string
    json_response($created, 201);
}

// ---------------------------------------------------------------------------
// PUT — update
// ---------------------------------------------------------------------------
if ($method === 'PUT') {
    if (!$id) {
        json_error('Query parameter "id" is required', 400);
    }

    $existing = db_fetch_one("SELECT id FROM email_templates WHERE id = ?", [$id]);
    if (!$existing) {
        json_error('Template not found', 404);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        json_error('Invalid JSON body', 400);
    }

    $data = [];

    if (array_key_exists('name', $input)) {
        $data['name'] = sanitize_input($input['name']);
        if ($data['name'] === '') {
            json_error('Field "name" cannot be empty', 422);
        }
    }

    if (array_key_exists('channel', $input)) {
        $data['channel'] = sanitize_input($input['channel']);
        $valid_channels  = ['email', 'sms', 'whatsapp', 'push'];
        if (!in_array($data['channel'], $valid_channels, true)) {
            json_error('Field "channel" must be one of: ' . implode(', ', $valid_channels), 422);
        }
    }

    if (array_key_exists('subject', $input)) {
        $data['subject'] = sanitize_input($input['subject']);
    }

    if (array_key_exists('body_html', $input)) {
        $data['body_html'] = $input['body_html']; // keep HTML intact

        // Re-detect used variables
        $used_vars = [];
        $subject_for_scan = $data['subject'] ?? ($existing['subject'] ?? '');
        foreach (TEMPLATE_VARIABLES as $var) {
            if (str_contains($data['body_html'], '{' . $var . '}') ||
                str_contains($subject_for_scan,  '{' . $var . '}')) {
                $used_vars[] = $var;
            }
        }
        $data['variables'] = json_encode($used_vars);

        // Regenerate plain text if not explicitly provided
        if (!array_key_exists('body_text', $input)) {
            $data['body_text'] = strip_tags(html_entity_decode($data['body_html']));
        }
    }

    if (array_key_exists('body_text', $input)) {
        $data['body_text'] = sanitize_input($input['body_text']);
    }

    if (empty($data)) {
        json_error('No valid fields provided for update', 422);
    }

    db_update('email_templates', $data, 'id = ?', [$id]);
    audit_log('update', 'email_template', $id, array_keys($data));

    $updated = db_fetch_one("SELECT * FROM email_templates WHERE id = ?", [$id]);
    if (isset($updated['variables']) && is_string($updated['variables'])) {
        $decoded = json_decode($updated['variables'], true);
        $updated['variables'] = is_array($decoded) ? $decoded : [];
    }

    json_response($updated);
}

// ---------------------------------------------------------------------------
// DELETE — only if not referenced in active automations
// ---------------------------------------------------------------------------
if ($method === 'DELETE') {
    if (!$id) {
        json_error('Query parameter "id" is required', 400);
    }

    $existing = db_fetch_one("SELECT id, name FROM email_templates WHERE id = ?", [$id]);
    if (!$existing) {
        json_error('Template not found', 404);
    }

    // Check active automations referencing this template
    $automation_check = db_fetch_one(
        "SELECT COUNT(*) AS cnt
         FROM   automations
         WHERE  template_id = ? AND status = 'active'",
        [$id]
    );

    if ($automation_check && (int) $automation_check['cnt'] > 0) {
        json_error(
            'Cannot delete template: it is used in ' . $automation_check['cnt'] . ' active automation(s). Deactivate or reassign them first.',
            409
        );
    }

    db_run("DELETE FROM email_templates WHERE id = ?", [$id]);
    audit_log('delete', 'email_template', $id, ['name' => $existing['name']]);

    json_response(['message' => 'Template deleted', 'id' => $id]);
}

// ---------------------------------------------------------------------------
// Fallback
// ---------------------------------------------------------------------------
json_error('Method not allowed', 405);
