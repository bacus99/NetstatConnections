<?php
/**
 * This endpoint has been removed in v1.4.4.
 *
 * Network connections are now collected via the standard GLPI Agent inventory
 * mechanism: install Connections.pm into the agent's Inventory/Generic/ folder
 * and restart the service.  No custom push URL or token is required.
 */
http_response_code(410);
header('Content-Type: application/json');
echo json_encode([
    'error'   => 'gone',
    'message' => 'This push endpoint was removed in v1.4.4. '
               . 'Use the GLPI Agent Connections.pm inventory module instead.',
]);
