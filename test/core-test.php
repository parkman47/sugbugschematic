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

$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer redirected-token';
check(relay_header('Authorization') === 'Bearer redirected-token', 'redirected Apache Authorization header is accepted');
unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
$_SERVER['AUTHORIZATION'] = 'Bearer direct-token';
check(relay_header('Authorization') === 'Bearer direct-token', 'direct Authorization server variable is accepted');
unset($_SERVER['AUTHORIZATION']);

$report = relay_save_submission($config, 'browser-test', [
    'categories' => ['bug', 'suggestion'],
    'note' => 'Door does not open',
    'domHtml' => '<!doctype html><p>unsaved draft</p><input type="hidden" name="csrf" value="csrf-secret>still-secret"><textarea id="session_notes">session-secret</textarea><select name="payment_token"><option selected value="pay-secret">pay-secret</option></select>',
    'capture' => [
        'formState' => [
            ['selector' => '#draft', 'name' => 'draft', 'value' => 'unsaved draft'],
            ['selector' => '#csrf', 'name' => 'csrf', 'value' => 'csrf-secret'],
            ['selector' => '#session_id', 'name' => 'session_id', 'value' => 'session-secret'],
        ],
        'custom' => ['api_key' => 'api-secret', 'safeValue' => 'keep-me'],
    ],
]);
check(str_starts_with($report['id'], 'browser-test-'), 'report ID is project-scoped');
check(is_file(relay_report_directory($config, 'browser-test', $report['id']) . '/dom.html'), 'DOM is stored privately');

$listed = relay_list_reports($config);
check(count($listed) === 1 && $listed[0]['id'] === $report['id'], 'open report can be listed');
$bundle = relay_export_report($config, $report['id']);
check(str_contains((string) $bundle['domHtml'], 'unsaved draft'), 'report can be exported');
check(!str_contains((string) $bundle['domHtml'], 'csrf-secret') && !str_contains((string) $bundle['domHtml'], 'session-secret') && !str_contains((string) $bundle['domHtml'], 'pay-secret'), 'server scrubs sensitive DOM form values');
check($bundle['report']['capture']['formState'][0]['value'] === 'unsaved draft', 'server preserves safe form state');
check($bundle['report']['capture']['formState'][1]['value'] === '[REDACTED]' && $bundle['report']['capture']['formState'][2]['value'] === '[REDACTED]', 'server redacts sensitive formState records');
check($bundle['report']['capture']['custom']['api_key'] === '[REDACTED]' && $bundle['report']['capture']['custom']['safeValue'] === 'keep-me', 'server recursively redacts sensitive capture keys');

$legacy = relay_save_submission($config, 'browser-test', [
    'categories' => ['bug'], 'note' => 'Legacy report', 'capture' => [], 'domHtml' => '<p>legacy</p>',
]);
$legacyDirectory = relay_report_directory($config, 'browser-test', $legacy['id']);
$legacy['capture'] = ['formState' => [['name' => 'xsrf_nonce', 'value' => 'legacy-xsrf']], 'session' => ['id' => 'legacy-session']];
$legacy['assets']['screenshot'] = 'screenshot.png';
$legacy['assets']['screenshotMimeType'] = 'image/png';
relay_atomic_json($legacyDirectory . '/report.json', $legacy);
file_put_contents($legacyDirectory . '/dom.html', '<input id="csrf" value="legacy-dom"><textarea name="authorization">legacy-auth</textarea>');
file_put_contents($legacyDirectory . '/screenshot.png', 'legacy-pixels');
$scrubbed = relay_scrub_report($config, ['id' => $legacy['id']]);
$legacyBundle = relay_export_report($config, $legacy['id']);
check($scrubbed['screenshotDeleted'] === true && !isset($legacyBundle['report']['assets']['screenshot']), 'scrub deletes potentially sensitive screenshot by default');
check(!str_contains((string) $legacyBundle['domHtml'], 'legacy-dom') && !str_contains((string) $legacyBundle['domHtml'], 'legacy-auth'), 'scrub repairs legacy DOM');
check($legacyBundle['report']['capture']['formState'][0]['value'] === '[REDACTED]' && $legacyBundle['report']['capture']['session'] === '[REDACTED]', 'scrub repairs legacy metadata');

$resolved = relay_resolve_report($config, ['id' => $report['id'], 'resolution' => 'Fixed', 'retention' => 'metadata']);
check($resolved['status'] === 'resolved' && $resolved['assets'] === [], 'metadata retention resolves and purges heavy assets');
check(!is_file(relay_report_directory($config, 'browser-test', $report['id']) . '/dom.html'), 'DOM was purged');

$resolvedList = relay_list_reports($config, ['status' => 'resolved']);
check(count($resolvedList) === 1, 'resolved metadata remains reviewable');
relay_delete_tree($config['data_dir']);
fwrite(STDOUT, "All PHP core tests passed." . PHP_EOL);
