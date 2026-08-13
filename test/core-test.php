<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/relay.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAILED: {$message}");
    }
    fwrite(STDOUT, "PASS: {$message}" . PHP_EOL);
}

$config = relay_config(__DIR__ . '/config.php');
relay_delete_tree($config['data_dir']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'relay-php-test';

$report = relay_save_submission($config, 'browser-test', [
    'categories' => ['bug', 'suggestion'],
    'note' => 'Door does not open',
    'domHtml' => '<!doctype html><p>unsaved draft</p>',
    'capture' => ['formState' => [['value' => 'unsaved draft']]],
]);
check(str_starts_with($report['id'], 'browser-test-'), 'report ID is project-scoped');
check(is_file(relay_report_directory($config, 'browser-test', $report['id']) . '/dom.html'), 'DOM is stored privately');

$listed = relay_list_reports($config);
check(count($listed) === 1 && $listed[0]['id'] === $report['id'], 'open report can be listed');
$bundle = relay_export_report($config, $report['id']);
check(str_contains((string) $bundle['domHtml'], 'unsaved draft'), 'report can be exported');

$resolved = relay_resolve_report($config, ['id' => $report['id'], 'resolution' => 'Fixed', 'retention' => 'metadata']);
check($resolved['status'] === 'resolved' && $resolved['assets'] === [], 'metadata retention resolves and purges heavy assets');
check(!is_file(relay_report_directory($config, 'browser-test', $report['id']) . '/dom.html'), 'DOM was purged');

$resolvedList = relay_list_reports($config, ['status' => 'resolved']);
check(count($resolvedList) === 1, 'resolved metadata remains reviewable');
relay_delete_tree($config['data_dir']);
fwrite(STDOUT, "All PHP core tests passed." . PHP_EOL);
