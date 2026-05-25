-- ============================================================
-- Fodor Review OS — SQLite Schema + Seed Data
-- Encoding: UTF-8
-- ============================================================

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- ------------------------------------------------------------
-- 1. OFFICES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS offices (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT    NOT NULL,
    address           TEXT    NOT NULL,
    google_place_id   TEXT,
    google_verified   INTEGER NOT NULL DEFAULT 0,  -- BOOLEAN 0/1
    main_agent_id     INTEGER,                      -- FK → agents.id (set after insert)
    avg_rating        REAL    NOT NULL DEFAULT 0.0,
    review_count      INTEGER NOT NULL DEFAULT 0,
    created_at        TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- ------------------------------------------------------------
-- 2. AGENTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agents (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    office_id         INTEGER NOT NULL REFERENCES offices(id) ON DELETE CASCADE,
    name              TEXT    NOT NULL,
    role              TEXT    NOT NULL CHECK(role IN ('senior','ügynök','junior','vezető')),
    phone             TEXT,
    email             TEXT,
    review_link       TEXT,
    signature         TEXT,
    personalized_msg  TEXT,
    status            TEXT    NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive','suspended')),
    created_at        TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- ------------------------------------------------------------
-- 3. CONTACTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contacts (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT    NOT NULL,
    email             TEXT,
    phone             TEXT,
    agent_id          INTEGER REFERENCES agents(id) ON DELETE SET NULL,
    office_id         INTEGER REFERENCES offices(id) ON DELETE SET NULL,
    transaction_type  TEXT    CHECK(transaction_type IN ('vétel','eladás','bérlet','albérlet','egyéb')),
    transaction_date  TEXT,
    notes             TEXT,
    created_at        TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- ------------------------------------------------------------
-- 4. REVIEW REQUESTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS review_requests (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_id        INTEGER REFERENCES contacts(id) ON DELETE SET NULL,
    agent_id          INTEGER REFERENCES agents(id) ON DELETE SET NULL,
    template_id       INTEGER REFERENCES email_templates(id) ON DELETE SET NULL,
    automation_id     INTEGER REFERENCES automations(id) ON DELETE SET NULL,
    channel           TEXT    NOT NULL CHECK(channel IN ('email','sms','mindkettő')),
    sent_at           TEXT,
    opened_at         TEXT,
    nps_score         INTEGER CHECK(nps_score BETWEEN 0 AND 10),
    star_rating       INTEGER CHECK(star_rating BETWEEN 1 AND 5),
    published_at      TEXT,
    nps_token         TEXT    UNIQUE,                  -- random hex for tracking URL /nps.php?t=TOKEN
    nps_at            TEXT,                            -- when NPS was submitted
    clicked_at        TEXT,                            -- when client clicked the Google review link
    state             TEXT    NOT NULL DEFAULT 'pending'
                              CHECK(state IN ('pending','sent','opened','nps_done',
                                             'nps_done_positive','nps_done_negative',
                                             'waiting','published','internal','disappeared','bounced','blocked')),
    created_at        TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- ------------------------------------------------------------
-- 5. GOOGLE REVIEWS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS google_reviews (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    office_id         INTEGER NOT NULL REFERENCES offices(id) ON DELETE CASCADE,
    author            TEXT    NOT NULL,
    star_rating       INTEGER NOT NULL CHECK(star_rating BETWEEN 1 AND 5),
    review_text       TEXT,
    published_at      TEXT,
    reply_text        TEXT,
    reply_at          TEXT,
    synced_at         TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- ------------------------------------------------------------
-- 6. AUTOMATIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS automations (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT    NOT NULL,
    trigger_type      TEXT    NOT NULL CHECK(trigger_type IN ('adásvétel','bérleti_aláírás','megtekintés','ünnep','inaktív','egyéb')),
    trigger_config    TEXT,                          -- JSON config
    delay_hours       INTEGER NOT NULL DEFAULT 24,
    nps_threshold     INTEGER NOT NULL DEFAULT 7,    -- NPS ≥ this → send Google request
    channel           TEXT    NOT NULL CHECK(channel IN ('email','sms','mindkettő')),
    template_id       INTEGER REFERENCES email_templates(id) ON DELETE SET NULL,
    neg_template_id   INTEGER REFERENCES email_templates(id) ON DELETE SET NULL,
    active            INTEGER NOT NULL DEFAULT 1,    -- BOOLEAN
    runs              INTEGER NOT NULL DEFAULT 0,
    conv_count        INTEGER NOT NULL DEFAULT 0,
    created_at        TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- ------------------------------------------------------------
-- 7. AUTOMATION LOGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS automation_logs (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    automation_id     INTEGER NOT NULL REFERENCES automations(id) ON DELETE CASCADE,
    contact_id        INTEGER REFERENCES contacts(id) ON DELETE SET NULL,
    queue_id          INTEGER,
    started_at        TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    current_step      TEXT    NOT NULL DEFAULT 'init',
    state             TEXT    NOT NULL DEFAULT 'running'
                              CHECK(state IN (
                                  'running','completed','failed','cancelled',
                                  'waiting_nps','negative_path','converted','skipped'
                              )),
    notes             TEXT,
    created_at        TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- ------------------------------------------------------------
-- 8. EMAIL TEMPLATES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_templates (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT    NOT NULL UNIQUE,
    channel           TEXT    NOT NULL CHECK(channel IN ('email','sms','mindkettő')),
    subject           TEXT,
    body_html         TEXT,
    body_text         TEXT,
    variables         TEXT,                          -- JSON array of variable names
    created_at        TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- ------------------------------------------------------------
-- 9. SEND QUEUE
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS send_queue (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id        INTEGER REFERENCES review_requests(id) ON DELETE CASCADE,
    channel           TEXT    NOT NULL CHECK(channel IN ('email','sms')),
    to_address        TEXT    NOT NULL DEFAULT '',
    to_name           TEXT,
    subject           TEXT,
    body_html         TEXT,
    body_text         TEXT,
    message_id        TEXT,
    scheduled_at      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    sent_at           TEXT,
    status            TEXT    NOT NULL DEFAULT 'queued'
                              CHECK(status IN ('queued','sent','failed','cancelled')),
    error_msg         TEXT
);

-- ------------------------------------------------------------
-- 10. FOLLOW-UPS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS follow_ups (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id        INTEGER NOT NULL REFERENCES review_requests(id) ON DELETE CASCADE,
    due_at            TEXT    NOT NULL,
    type              TEXT    NOT NULL CHECK(type IN ('reminder','escalation','manual')),
    resolved_at       TEXT,
    resolved_by       TEXT
);

-- ------------------------------------------------------------
-- 11. API TOKENS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_tokens (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    token_hash        TEXT    NOT NULL UNIQUE,
    name              TEXT    NOT NULL,
    created_at        TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    last_used_at      TEXT
);

-- ------------------------------------------------------------
-- 12. RATE LIMITS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rate_limits (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_hash           TEXT    NOT NULL,
    endpoint          TEXT    NOT NULL,
    requests          INTEGER NOT NULL DEFAULT 1,
    window_start      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    UNIQUE(ip_hash, endpoint)
);

-- ------------------------------------------------------------
-- 13. AUDIT LOG
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    user_ip           TEXT,
    action            TEXT    NOT NULL,
    entity_type       TEXT    NOT NULL,
    entity_id         INTEGER,
    payload           TEXT,                          -- JSON
    created_at        TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- ------------------------------------------------------------
-- 14. USERS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT    NOT NULL,
    email           TEXT    NOT NULL UNIQUE,
    password_hash   TEXT    NOT NULL,
    role            TEXT    NOT NULL DEFAULT 'agent' CHECK(role IN ('admin','agent','viewer')),
    office_id       INTEGER REFERENCES offices(id) ON DELETE SET NULL,
    active          INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- ------------------------------------------------------------
-- 15. SESSIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash      TEXT    NOT NULL UNIQUE,
    expires_at      TEXT    NOT NULL,
    created_at      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- ------------------------------------------------------------
-- 16. APP SETTINGS (runtime overrides, key-value)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_settings (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    key        TEXT    NOT NULL UNIQUE,
    value      TEXT,
    updated_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- ============================================================
-- INDEXES
-- ============================================================
CREATE INDEX IF NOT EXISTS idx_agents_office         ON agents(office_id);
CREATE INDEX IF NOT EXISTS idx_contacts_agent        ON contacts(agent_id);
CREATE INDEX IF NOT EXISTS idx_contacts_office       ON contacts(office_id);
CREATE INDEX IF NOT EXISTS idx_review_req_contact    ON review_requests(contact_id);
CREATE INDEX IF NOT EXISTS idx_review_req_agent      ON review_requests(agent_id);
CREATE INDEX IF NOT EXISTS idx_review_req_state      ON review_requests(state);
CREATE INDEX IF NOT EXISTS idx_google_reviews_office ON google_reviews(office_id);
CREATE INDEX IF NOT EXISTS idx_send_queue_status     ON send_queue(status, scheduled_at);
CREATE INDEX IF NOT EXISTS idx_audit_log_entity      ON audit_log(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_rate_limits_ip        ON rate_limits(ip_hash, endpoint);
CREATE INDEX IF NOT EXISTS idx_auto_logs_automation  ON automation_logs(automation_id);


-- ============================================================
-- SEED DATA
-- ============================================================

-- ------------------------------------------------------------
-- OFFICES (1 db — Fodor Ingatlanközvetítő Kft.)
-- ------------------------------------------------------------
INSERT INTO offices (id, name, address, google_place_id, google_verified, avg_rating, review_count, created_at) VALUES
(1, 'Fodor Ingatlanközvetítő Kft.', '1188 Budapest, Lea utca 30/a', '', 0, 0.0, 0, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'));

-- ------------------------------------------------------------
-- AGENTS (3 db — valós ügynökök)
-- ------------------------------------------------------------
INSERT INTO agents (id, office_id, name, role, phone, email, review_link, signature, personalized_msg, status, created_at) VALUES
(1, 1, 'Fodor Zsolt',    'vezető', '+36 20 355 6000', 'info@fodoringatlan.hu',
 'https://g.page/r/Cdz0GBei70VkEBM/review',
 'Fodor Zsolt | Ingatlanközvetítő és irodavezető | Fodor Ingatlanközvetítő Kft. | +36 20 355 6000 | info@fodoringatlan.hu',
 'Örömmel segítek ingatlanügyeiben — a hirdetéstől a kulcsátadásig.',
 'active', strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),

(2, 1, 'Fodor Norbert',  'ügynök', '+36 20 943 0187', 'fodornorbert@fodoringatlan.hu',
 'https://g.page/r/Cdz0GBei70VkEBM/review',
 'Fodor Norbert | Ingatlanközvetítő | Fodor Ingatlanközvetítő Kft. | +36 20 943 0187 | fodornorbert@fodoringatlan.hu',
 'Segítek megtalálni az Ön számára legjobb ingatlant.',
 'active', strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),

(3, 1, 'Bajzák Gergely', 'ügynök', '+36 30 308 7480', 'bajzakgergely@fodoringatlan.hu',
 'https://g.page/r/Cdz0GBei70VkEBM/review',
 'Bajzák Gergely | Ingatlanközvetítő | Fodor Ingatlanközvetítő Kft. | +36 30 308 7480 | bajzakgergely@fodoringatlan.hu',
 'Professzionális ingatlanközvetítés Budapesten és környékén.',
 'active', strftime('%Y-%m-%dT%H:%M:%SZ', 'now'));

UPDATE offices SET main_agent_id = 1 WHERE id = 1;

-- ------------------------------------------------------------
-- EMAIL TEMPLATES (7 db)
-- ------------------------------------------------------------
INSERT INTO email_templates (id, name, channel, subject, body_html, body_text, variables, created_at) VALUES

(1, 'sikeres_ugyletat_utan', 'email',
 'Köszönjük a bizalmát, {{nev}}!',
 '<p>Kedves <strong>{{nev}}</strong>!</p>
<p>Köszönjük a bizalmát és a közös munkát. Öröm számunkra, hogy segíthettünk Önnek az ingatlanügylet sikeres lezárásában.</p>
<p>A Fodor Ingatlan Közvetítő Kft.-nél arra törekszünk, hogy ügyfeleink ne csupán eredményes, hanem valóban nyugodt és pozitív élményként éljék meg az ingatlanközvetítés folyamatát.</p>
<p>Amennyiben elégedett volt szolgáltatásunkkal, nagyra értékelnénk, ha megosztaná tapasztalatait egy Google értékelés formájában. Néhány kedves mondat sokat segít azoknak is, akik jelenleg keresik a számukra megfelelő ingatlanirodát.</p>
<p>👉 Google értékeléshez erre a linkre kattintva jut el:<br>
<a href="{{review_link}}" style="display:inline-block;background:#1F2D3D;color:#F5F0E6;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;margin-top:8px;">⭐ Google értékelés írása</a></p>
<p>Köszönjük megtisztelő bizalmát!</p>
<p style="margin-top:24px;font-size:13px;color:#6E7A88;border-top:1px solid #eee;padding-top:16px;">{{ugynok_alairas}}</p>',
 'Kedves {{nev}}!

Köszönjük a bizalmát és a közös munkát. Öröm számunkra, hogy segíthettünk Önnek az ingatlanügylet sikeres lezárásában.

A Fodor Ingatlan Közvetítő Kft.-nél arra törekszünk, hogy ügyfeleink ne csupán eredményes, hanem valóban nyugodt és pozitív élményként éljék meg az ingatlanközvetítés folyamatát.

Amennyiben elégedett volt szolgáltatásunkkal, nagyra értékelnénk, ha megosztaná tapasztalatait egy Google értékelés formájában. Néhány kedves mondat sokat segít azoknak is, akik jelenleg keresik a számukra megfelelő ingatlanirodát.

Google értékelés: {{review_link}}

Köszönjük megtisztelő bizalmát!

{{ugynok_alairas}}',
 '["nev","ugynok_alairas","review_link"]',
 strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),

(2, 'berleti_alairas', 'sms',
 NULL,
 NULL,
 'Kedves {{nev}}! Gratulálunk az aláírt bérleti szerződéshez! Ha elégedett volt, kérjük értékelje {{ugynok_nev}} munkáját: {{review_link}} – Fodor Ingatlanközvetítő Kft.',
 '["nev","ugynok_nev","review_link"]',
 strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),

(3, 'megtekintes_3nap', 'email',
 'Hogy tetszett az ingatlan, {{nev}}?',
 '<p>Kedves <strong>{{nev}}</strong>!</p>
<p>3 napja nézte meg a(z) <strong>{{ingatlan_cim}}</strong> ingatlant <strong>{{ugynok_nev}}</strong> kollégánkkal.</p>
<p>Szeretnénk tudni, hogyan érezte magát a megtekintés során, és miben tudunk még segíteni.</p>
<p>Ha kérdése van, vagy szeretne más ingatlanokat is megnézni, keressen minket bizalommal!</p>
<p style="margin-top:24px;font-size:13px;color:#6E7A88;border-top:1px solid #eee;padding-top:16px;">{{ugynok_alairas}}</p>',
 'Kedves {{nev}}! 3 napja nézte meg a(z) {{ingatlan_cim}} ingatlant. Tudunk még segíteni? – {{ugynok_nev}}',
 '["nev","ugynok_nev","ugynok_alairas","ingatlan_cim"]',
 strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),

(5, 'unnep_karacsony', 'email',
 'Kellemes karácsonyi ünnepeket kíván a Fodor Ingatlan!',
 '<p>Kedves <strong>{{nev}}</strong>!</p>
<p>A Fodor Ingatlanközvetítő Kft. csapata kellemes karácsonyi ünnepeket és boldog új évet kíván Önnek és szeretteinek!</p>
<p>Ha az ünnepi időszakban ingatlan témában kérdése merülne fel, örömmel állunk rendelkezésére január 2-től.</p>
<p>Köszönjük az eddigi bizalmát!<br>Fodor Zsolt, Fodor Norbert, Bajzák Gergely<br>Fodor Ingatlanközvetítő Kft.</p>',
 'Kellemes karácsonyi ünnepeket kíván Önnek a Fodor Ingatlanközvetítő Kft. csapata! Boldog új évet!',
 '["nev"]',
 strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),

(6, 'inaktiv_6ho', 'email',
 '{{nev}}, még mindig segíthetünk ingatlan ügyében?',
 '<p>Kedves <strong>{{nev}}</strong>!</p>
<p>Fél éve kereste fel irodánkat, de azóta nem hallottunk Önről.</p>
<p>Ha még keresi álomotthonát, vagy ingatlanértékesítési terve van, szívesen vesszük fel újra a kapcsolatot.</p>
<p>Kattintson ide az aktuális kínálatunkért: <a href="https://fodoringatlan.hu">Friss ajánlatok</a></p>
<p style="margin-top:24px;font-size:13px;color:#6E7A88;border-top:1px solid #eee;padding-top:16px;">{{ugynok_alairas}}</p>',
 'Kedves {{nev}}! Még segíthetünk ingatlan ügyében? Friss ajánlataink: https://fodoringatlan.hu – {{ugynok_nev}}',
 '["nev","ugynok_nev","ugynok_alairas"]',
 strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),

(7, 'koszonetes', 'email',
 'Köszönjük az értékelését, {{nev}}!', '<p>Kedves <strong>{{nev}}</strong>!</p>
<p>Nagyon köszönjük, hogy időt szánt értékelése megírására!</p>
<p>Véleménye sokat jelent nekünk és segíti jövőbeli ügyfeleinket a tájékozódásban.</p>
<p>Ha a jövőben bármilyen ingatlan ügyben számíthat ránk, örömmel állunk rendelkezésére.</p>
<p style="margin-top:24px;font-size:13px;color:#6E7A88;border-top:1px solid #eee;padding-top:16px;">{{ugynok_alairas}}</p>',
 'Kedves {{nev}}! Köszönjük értékelését! Ha ismét szükséges, szívesen segítünk. – {{ugynok_nev}}',
 '["nev","ugynok_nev","ugynok_alairas"]',
 strftime('%Y-%m-%dT%H:%M:%SZ', 'now'));

-- ------------------------------------------------------------
-- AUTOMATIONS (1 db)
-- ------------------------------------------------------------
INSERT INTO automations (id, name, trigger_type, trigger_config, delay_hours, nps_threshold, channel, template_id, active, runs, conv_count, created_at) VALUES
(1, 'Új partner felvételekor · értékelés-kérő email', 'adásvétel', NULL, 0, 0, 'email', 1, 1, 0, 0, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'));

-- ------------------------------------------------------------
-- DEFAULT API TOKEN  (hash of 'fodor-admin-token-2026')
-- Computed via PHP: hash('sha256', 'fodor-admin-token-2026')
-- IMPORTANT: Change this token in production — generate a new one with:
--   php -r "echo bin2hex(random_bytes(32));"
--   Then store hash('sha256', '<new_token>') here.
-- ------------------------------------------------------------
INSERT INTO api_tokens (id, token_hash, name, created_at) VALUES
(1, '737c8e82b80d641f1b0be2175c54ddfe5a884ac69318df9deb1d192dc44cbb09', 'Fodor Admin Token 2026', '2026-01-01T00:00:00Z');

-- NOTE: Admin user is created via /api/migrate_users.php?key=fodor-migrate-2026
-- bcrypt hash is generated at runtime — cannot be seeded statically.
