<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/relay.php';

try {
    $config = relay_config();
    relay_require_admin($config);
    $action = (string) ($_GET['action'] ?? '');
    $id = relay_safe_segment($_GET['id'] ?? '', 'report id');
    if ($action === 'export' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        relay_json_response(200, relay_export_report($config, $id));
    }
    if ($action === 'resolve' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        relay_json_response(200, relay_resolve_report($config, relay_read_json(1024 * 1024) + ['id' => $id]));
    }
    if ($action === 'scrub' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        relay_json_response(200, relay_scrub_report($config, relay_read_json(1024 * 1024) + ['id' => $id]));
    }
    throw new RelayHttpException(404, 'Unknown administrative action');
} catch (RelayHttpException $error) {
    relay_json_response($error->status, ['ok' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log((string) $error);
    relay_json_response(500, ['ok' => false, 'error' => 'Internal server error']);
}
