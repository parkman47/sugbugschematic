<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/relay.php';

try {
    $config = relay_config();
    $projectId = relay_safe_segment($_GET['project'] ?? '', 'project');
    $project = relay_project($config, $projectId);
    $origin = relay_validate_origin($project);
    relay_cors($origin);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        relay_empty_response(204);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new RelayHttpException(405, 'Method not allowed');
    }
    relay_validate_submit_key($project);
    relay_rate_limit($config, $projectId, $project);
    $report = relay_save_submission($config, $projectId, relay_read_json($config['max_report_bytes']));
    relay_json_response(201, ['ok' => true, 'id' => $report['id']]);
} catch (RelayHttpException $error) {
    relay_json_response($error->status, ['ok' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log((string) $error);
    relay_json_response(500, ['ok' => false, 'error' => 'Internal server error']);
}
