<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/order_service.php';

$options = getopt('', ['hours:', 'limit:', 'apply']);
$hours = (int)($options['hours'] ?? 24);
$limit = (int)($options['limit'] ?? 100);
$apply = array_key_exists('apply', $options);
try {
    $results = expire_abandoned_orders($conn, $hours, $limit, !$apply);
    foreach ($results as $result) {
        echo ($result['reference'] ?? ('#' . $result['order_id'])) . ': ' . $result['action'];
        if (!empty($result['reason'])) {
            echo ' — ' . $result['reason'];
        }
        echo "\n";
    }
    echo 'DONE: ' . count($results) . " order(s) examined\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
