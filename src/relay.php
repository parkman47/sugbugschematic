<?php
declare(strict_types=1);

final class RelayHttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

function relay_config(?string $filename = null): array
{
    $filename ??= getenv('LIVE_REPORT_CONFIG') ?: dirname(__DIR__) . '/config/config.php';
    if (!is_file($filename)) {
        throw new RuntimeException("Missing relay configuration: {$filename}");
    }
    $config = require $filename;
    if (!is_array($config)) {
        throw new RuntimeException('Relay configuration must return an array');
    }
    $token = (string) ($config['admin_token'] ?? '');
    if (strlen($token) < 32) {
        throw new RuntimeException('admin_token must contain at least 32 characters');
    }
    $config['data_dir'] = relay_absolute_path((string) ($config['data_dir'] ?? ''));
    $config['max_report_bytes'] = (int) ($config['max_report_bytes'] ?? 15 * 1024 * 1024);
    $config['projects'] ??= [];
    return $config;
}

function relay_absolute_path(string $path): string
{
    if ($path === '') {
        throw new RuntimeException('data_dir is required');
    }
    if (preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path)) {
        return rtrim($path, "\\/");
    }
    return rtrim(dirname(__DIR__) . '/' . $path, "\\/");
}

function relay_safe_segment(mixed $value, string $label = 'identifier'): string
{
    $value = (string) $value;
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/', $value)) {
        throw new RelayHttpException(400, "Invalid {$label}");
    }
    return $value;
}

function relay_project(array $config, string $projectId): array
{
    if (!isset($config['projects'][$projectId]) || !is_array($config['projects'][$projectId])) {
        throw new RelayHttpException(404, 'Unknown project');
    }
    return $config['projects'][$projectId];
}

function relay_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return (string) ($_SERVER[$key] ?? '');
}

function relay_client_ip(): string
{
    $raw = relay_header('CF-Connecting-IP') ?: relay_header('X-Forwarded-For') ?: (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return trim(explode(',', $raw)[0]);
}

function relay_constant_time_matches(string $supplied, array $expected): bool
{
    foreach ($expected as $candidate) {
        if (hash_equals((string) $candidate, $supplied)) {
            return true;
        }
    }
    return false;
}

function relay_require_admin(array $config): void
{
    $header = relay_header('Authorization');
    $supplied = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
    if (!hash_equals((string) $config['admin_token'], $supplied)) {
        throw new RelayHttpException(401, 'Unauthorized');
    }
}

function relay_validate_origin(array $project): ?string
{
    $origin = relay_header('Origin');
    if ($origin === '' && !($project['allow_missing_origin'] ?? false)) {
        throw new RelayHttpException(403, 'Origin is required');
    }
    if ($origin !== '' && !in_array($origin, $project['allowed_origins'] ?? [], true)) {
        throw new RelayHttpException(403, 'Origin is not allowed');
    }
    return $origin !== '' ? $origin : null;
}

function relay_validate_submit_key(array $project): void
{
    if (!relay_constant_time_matches(relay_header('X-Report-Key'), $project['submit_keys'] ?? [])) {
        throw new RelayHttpException(401, 'Invalid report key');
    }
}

function relay_cors(?string $origin): void
{
    if ($origin === null) {
        return;
    }
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Report-Key');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
}

function relay_read_json(int $limit): array
{
    $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declared > $limit) {
        throw new RelayHttpException(413, 'Report is too large');
    }
    $stream = fopen('php://input', 'rb');
    if ($stream === false) {
        throw new RelayHttpException(400, 'Could not read request');
    }
    $raw = stream_get_contents($stream, $limit + 1);
    fclose($stream);
    if ($raw === false || strlen($raw) > $limit) {
        throw new RelayHttpException(413, 'Report is too large');
    }
    try {
        $decoded = json_decode($raw !== '' ? $raw : '{}', true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RelayHttpException(400, 'Invalid JSON');
    }
    if (!is_array($decoded)) {
        throw new RelayHttpException(400, 'JSON body must be an object');
    }
    return $decoded;
}

function relay_json_response(int $status, mixed $value): never
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}

function relay_empty_response(int $status): never
{
    http_response_code($status);
    header('Cache-Control: no-store');
    exit;
}

function relay_atomic_json(string $filename, array $value): void
{
    $directory = dirname($filename);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException("Could not create {$directory}");
    }
    $temporary = $filename . '.' . bin2hex(random_bytes(5)) . '.tmp';
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($temporary, $json, LOCK_EX) === false) {
        throw new RuntimeException("Could not write {$temporary}");
    }
    @chmod($temporary, 0600);
    if (!rename($temporary, $filename)) {
        @unlink($temporary);
        throw new RuntimeException("Could not replace {$filename}");
    }
}

function relay_rate_limit(array $config, string $projectId, array $project): void
{
    $limit = max(1, (int) ($project['max_reports_per_hour_per_ip'] ?? 20));
    $directory = $config['data_dir'] . '/rate-limits';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create rate-limit directory');
    }
    $key = hash('sha256', $projectId . '|' . relay_client_ip());
    $filename = $directory . '/' . $key . '.json';
    $handle = fopen($filename, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        throw new RuntimeException('Could not lock rate-limit record');
    }
    $raw = stream_get_contents($handle);
    $record = $raw ? json_decode($raw, true) : null;
    $now = time();
    if (!is_array($record) || (int) ($record['reset_at'] ?? 0) <= $now) {
        $record = ['count' => 1, 'reset_at' => $now + 3600];
    } else {
        $record['count'] = (int) $record['count'] + 1;
    }
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($record, JSON_THROW_ON_ERROR));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    if ($record['count'] > $limit) {
        throw new RelayHttpException(429, 'Report rate limit exceeded');
    }
}

function relay_normalize_image(mixed $dataUrl): ?array
{
    if (!$dataUrl) {
        return null;
    }
    if (!preg_match('~^data:(image/(?:png|jpeg|webp));base64,([A-Za-z0-9+/=\r\n]+)$~', (string) $dataUrl, $matches)) {
        throw new RelayHttpException(400, 'Screenshot must be a PNG, JPEG, or WebP data URL');
    }
    $bytes = base64_decode($matches[2], true);
    if ($bytes === false) {
        throw new RelayHttpException(400, 'Invalid screenshot data');
    }
    if (strlen($bytes) > 6 * 1024 * 1024) {
        throw new RelayHttpException(413, 'Screenshot is too large');
    }
    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    return ['mime_type' => $matches[1], 'extension' => $extensions[$matches[1]], 'bytes' => $bytes];
}

function relay_report_directory(array $config, string $projectId, string $reportId): string
{
    return $config['data_dir'] . '/projects/' . relay_safe_segment($projectId, 'project') . '/reports/' . relay_safe_segment($reportId, 'report id');
}

function relay_save_submission(array $config, string $projectId, array $body): array
{
    $categories = array_values(array_unique(array_intersect((array) ($body['categories'] ?? []), ['bug', 'suggestion'])));
    if ($categories === []) {
        throw new RelayHttpException(400, 'Choose bug, suggestion, or both');
    }
    $note = trim(mb_substr((string) ($body['note'] ?? ''), 0, 10000));
    if ($note === '') {
        throw new RelayHttpException(400, 'A description is required');
    }
    $domHtml = is_string($body['domHtml'] ?? null) ? $body['domHtml'] : '';
    $capture = is_array($body['capture'] ?? null) ? $body['capture'] : [];
    $image = relay_normalize_image($body['screenshotDataUrl'] ?? null);
    $createdAt = gmdate('c');
    $id = $projectId . '-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
    $directory = relay_report_directory($config, $projectId, $id);
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create report directory');
    }
    $assets = [];
    if ($domHtml !== '') {
        file_put_contents($directory . '/dom.html', $domHtml, LOCK_EX);
        @chmod($directory . '/dom.html', 0600);
        $assets['dom'] = 'dom.html';
    }
    if ($image !== null) {
        $filename = 'screenshot.' . $image['extension'];
        file_put_contents($directory . '/' . $filename, $image['bytes'], LOCK_EX);
        @chmod($directory . '/' . $filename, 0600);
        $assets['screenshot'] = $filename;
        $assets['screenshotMimeType'] = $image['mime_type'];
    }
    $report = [
        'schemaVersion' => 1,
        'id' => $id,
        'project' => $projectId,
        'status' => 'open',
        'categories' => $categories,
        'note' => $note,
        'createdAt' => $createdAt,
        'updatedAt' => $createdAt,
        'capture' => $capture,
        'assets' => $assets,
        'source' => [
            'ipHash' => substr(hash('sha256', relay_client_ip()), 0, 16),
            'userAgent' => mb_substr(relay_header('User-Agent'), 0, 500),
        ],
    ];
    relay_atomic_json($directory . '/report.json', $report);
    return $report;
}

function relay_list_reports(array $config, array $filters = []): array
{
    $projectIds = isset($filters['project']) ? [relay_safe_segment($filters['project'], 'project')] : array_keys($config['projects']);
    $found = [];
    foreach ($projectIds as $projectId) {
        $root = $config['data_dir'] . '/projects/' . $projectId . '/reports';
        if (!is_dir($root)) {
            continue;
        }
        foreach (new DirectoryIterator($root) as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }
            $filename = $entry->getPathname() . '/report.json';
            if (!is_file($filename)) {
                continue;
            }
            $report = json_decode((string) file_get_contents($filename), true, 512, JSON_THROW_ON_ERROR);
            $status = $filters['status'] ?? 'open';
            if ($status !== 'all' && ($report['status'] ?? null) !== $status) {
                continue;
            }
            $found[] = $report;
        }
    }
    usort($found, static fn(array $a, array $b): int => strcmp((string) $b['createdAt'], (string) $a['createdAt']));
    return array_slice($found, 0, min(max((int) ($filters['limit'] ?? 50), 1), 200));
}

function relay_locate_report(array $config, string $reportId): array
{
    relay_safe_segment($reportId, 'report id');
    foreach (array_keys($config['projects']) as $projectId) {
        $directory = relay_report_directory($config, $projectId, $reportId);
        $filename = $directory . '/report.json';
        if (is_file($filename)) {
            return [json_decode((string) file_get_contents($filename), true, 512, JSON_THROW_ON_ERROR), $directory];
        }
    }
    throw new RelayHttpException(404, 'Report not found');
}

function relay_export_report(array $config, string $reportId): array
{
    [$report, $directory] = relay_locate_report($config, $reportId);
    $dom = isset($report['assets']['dom']) && is_file($directory . '/' . $report['assets']['dom'])
        ? file_get_contents($directory . '/' . $report['assets']['dom']) : null;
    $screenshot = null;
    if (isset($report['assets']['screenshot']) && is_file($directory . '/' . $report['assets']['screenshot'])) {
        $screenshot = [
            'filename' => $report['assets']['screenshot'],
            'mimeType' => $report['assets']['screenshotMimeType'] ?? 'image/png',
            'dataBase64' => base64_encode((string) file_get_contents($directory . '/' . $report['assets']['screenshot'])),
        ];
    }
    return ['report' => $report, 'domHtml' => $dom, 'screenshot' => $screenshot];
}

function relay_delete_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
        $entry->isDir() ? relay_delete_tree($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
}

function relay_resolve_report(array $config, array $arguments): array
{
    [$report, $directory] = relay_locate_report($config, (string) ($arguments['id'] ?? ''));
    $project = relay_project($config, $report['project']);
    $retention = (string) ($arguments['retention'] ?? 'policy');
    if ($retention === 'policy') {
        $retention = (string) ($project['retention_on_resolve'] ?? 'metadata');
    }
    if (!in_array($retention, ['keep', 'metadata', 'delete'], true)) {
        throw new RelayHttpException(400, 'Invalid retention mode');
    }
    if ($retention === 'delete') {
        if (($arguments['confirmId'] ?? null) !== $report['id']) {
            throw new RelayHttpException(400, 'confirmId must exactly match id for deletion');
        }
        relay_delete_tree($directory);
        return ['id' => $report['id'], 'status' => 'deleted', 'retention' => 'delete'];
    }
    if ($retention === 'metadata') {
        foreach (['dom', 'screenshot'] as $asset) {
            if (isset($report['assets'][$asset])) {
                @unlink($directory . '/' . $report['assets'][$asset]);
            }
        }
        $report['assets'] = [];
    }
    $report['status'] = 'resolved';
    $report['resolution'] = mb_substr((string) ($arguments['resolution'] ?? ''), 0, 10000);
    $report['retention'] = $retention;
    $report['updatedAt'] = gmdate('c');
    relay_atomic_json($directory . '/report.json', $report);
    return ['id' => $report['id'], 'status' => 'resolved', 'retention' => $retention, 'assets' => $report['assets']];
}
