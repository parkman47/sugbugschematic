<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/relay.php';

try {
    $config = relay_config();
    if (!is_dir($config['data_dir']) && !mkdir($config['data_dir'], 0700, true) && !is_dir($config['data_dir'])) {
        throw new RuntimeException('Storage is unavailable');
    }
    relay_json_response(200, ['ok' => true]);
} catch (Throwable $error) {
    error_log((string) $error);
    relay_json_response(503, ['ok' => false]);
}
