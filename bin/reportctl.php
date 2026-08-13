<?php
declare(strict_types=1);

function usage(int $status = 0): never
{
    fwrite($status === 0 ? STDOUT : STDERR, <<<TEXT
Usage:
  php bin/reportctl.php pull <report-id> --url <relay-public-url> [--token <admin-token>]
      [--out <directory>] [--resolve "resolution note"] [--retention keep|metadata|delete]

The token may also be supplied as LIVE_REPORT_ADMIN_TOKEN.
The default local archive directory is ./.bug-reports.

TEXT);
    exit($status);
}

function request_json(string $url, string $token, string $method = 'GET', ?array $body = null): array
{
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Could not initialize cURL');
    }
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($raw === false) {
        throw new RuntimeException($error ?: 'Request failed');
    }
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException((string) ($decoded['error'] ?? "HTTP {$status}"));
    }
    return $decoded;
}

$arguments = $argv;
array_shift($arguments);
if (($arguments[0] ?? null) !== 'pull' || !isset($arguments[1])) {
    usage(1);
}
$options = ['command' => $arguments[0], 'id' => $arguments[1], 'out' => '.bug-reports'];
for ($index = 2; $index < count($arguments); $index += 2) {
    $flag = $arguments[$index] ?? '';
    $value = $arguments[$index + 1] ?? null;
    if (!str_starts_with($flag, '--') || $value === null) {
        usage(1);
    }
    $options[substr($flag, 2)] = $value;
}
$token = (string) ($options['token'] ?? getenv('LIVE_REPORT_ADMIN_TOKEN') ?: '');
if (($options['url'] ?? '') === '' || $token === '') {
    usage(1);
}
$base = rtrim($options['url'], '/');
$id = rawurlencode($options['id']);
$bundle = request_json("{$base}/admin.php?action=export&id={$id}", $token);
$destination = rtrim($options['out'], "\\/") . DIRECTORY_SEPARATOR . $options['id'];
if (!is_dir($destination) && !mkdir($destination, 0700, true) && !is_dir($destination)) {
    throw new RuntimeException("Could not create {$destination}");
}
file_put_contents($destination . '/report.json', json_encode($bundle['report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
if (($bundle['domHtml'] ?? null) !== null) {
    file_put_contents($destination . '/dom.html', $bundle['domHtml']);
}
if (isset($bundle['screenshot'])) {
    file_put_contents($destination . '/' . $bundle['screenshot']['filename'], base64_decode($bundle['screenshot']['dataBase64'], true));
}
fwrite(STDOUT, "Archived {$options['id']} to {$destination}" . PHP_EOL);

if (isset($options['resolve'])) {
    $retention = $options['retention'] ?? 'metadata';
    $body = ['resolution' => $options['resolve'], 'retention' => $retention];
    if ($retention === 'delete') {
        $body['confirmId'] = $options['id'];
    }
    $result = request_json("{$base}/admin.php?action=resolve&id={$id}", $token, 'POST', $body);
    fwrite(STDOUT, "Remote report is now {$result['status']} ({$result['retention']})" . PHP_EOL);
}
