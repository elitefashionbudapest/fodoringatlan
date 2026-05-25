<?php
/**
 * Fodor Review OS — Audit Logging
 *
 * Provides a single function, audit_log(), that records every
 * significant action to the audit_log table.
 *
 * Usage:
 *   audit_log('review.request.sent', 'review_requests', $requestId, ['channel' => 'email']);
 *   audit_log('agent.updated',       'agents',          $agentId,   $changedFields);
 *   audit_log('token.auth.failed',   'api_tokens',      0,          ['ip' => $ip]);
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';  // for get_client_ip()


/**
 * Insert one audit record into the audit_log table.
 *
 * @param string  $action       Dot-notation action identifier, e.g. 'review.request.sent'
 * @param string  $entity_type  The affected resource type, e.g. 'review_requests', 'agents'
 * @param int     $entity_id    Primary key of the affected row (0 if not applicable)
 * @param array   $payload      Optional context: changed fields, parameters, etc.
 */
function audit_log(
    string $action,
    string $entity_type,
    int    $entity_id,
    array  $payload = []
): void {
    try {
        $ip = get_client_ip();

        db_insert('audit_log', [
            'user_ip'     => $ip,
            'action'      => $action,
            'entity_type' => $entity_type,
            'entity_id'   => $entity_id,
            'payload'     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at'  => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    } catch (Throwable $e) {
        // Audit log failure must never crash the main request.
        // Log to file as a last resort.
        log_event('error', 'audit_log insert failed', [
            'action'      => $action,
            'entity_type' => $entity_type,
            'entity_id'   => $entity_id,
            'error'       => $e->getMessage(),
        ]);
    }
}
