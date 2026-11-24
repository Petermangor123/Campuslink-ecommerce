<?php
/** CLI-only payment maintenance for CampusLink. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (in_array('--help', $argv, true)) {
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php setup_admin.php --migrate\n");
    fwrite(STDOUT, "  php setup_admin.php --backfill-captures [--limit=1..500] [--apply]\n");
    fwrite(STDOUT, "  php setup_admin.php --expire-orders [--hours=1..8760] [--limit=1..500] [--apply]\n");
    fwrite(STDOUT, "Create an administrator with: php scripts/create_admin.php <email> [name]\n");
    exit(0);
}

if (!array_intersect(['--migrate', '--backfill-captures', '--expire-orders'], $argv)) {
    fwrite(STDERR, "Use --help to list the available maintenance commands.\n");
    exit(1);
}

if (array_intersect(['--migrate', '--backfill-captures', '--expire-orders'], $argv)) {
    require_once __DIR__ . '/config/db.php';
    $conn->set_charset('utf8mb4');
    $migrationFile = __DIR__ . '/database/migrations/011_payment_operations.sql';
    try {
        $migrationSql = file_get_contents($migrationFile);
        if ($migrationSql === false || !$conn->multi_query($migrationSql)) {
            throw new RuntimeException($conn->error ?: 'Migration could not start.');
        }
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
            if (!$conn->more_results()) {
                break;
            }
            if (!$conn->next_result()) {
                throw new RuntimeException($conn->error ?: 'Migration statement failed.');
            }
        } while (true);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
        exit(1);
    }

    if (in_array('--migrate', $argv, true)) {
        echo "Payment operations migration applied.\n";
        exit(0);
    }

    require_once __DIR__ . '/includes/order_service.php';

    if (in_array('--backfill-captures', $argv, true)) {
        $limit = 100;
        foreach ($argv as $argument) {
            if (preg_match('/^--limit=(\d+)$/', $argument, $matches)) {
                $limit = (int)$matches[1];
            }
        }
        if ($limit < 1 || $limit > 500) {
            fwrite(STDERR, "Use --limit=1..500.\n");
            exit(1);
        }
        $apply = in_array('--apply', $argv, true);
        $stmt = $conn->prepare(
            "SELECT p.id, p.order_id, p.provider, o.reference_code
             FROM payments p
             INNER JOIN orders o ON o.id=p.order_id
             LEFT JOIN financial_batches fb ON fb.event_key=CONCAT('capture:',p.id)
             WHERE p.status='paid' AND o.payment_status='paid' AND fb.id IS NULL
             ORDER BY p.id ASC LIMIT ?"
        );
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $posted = 0;
        $failed = 0;
        foreach ($payments as $payment) {
            $label = (string)$payment['reference_code'] . ' (payment #' . (int)$payment['id'] . ')';
            if (!$apply) {
                echo 'would post: ' . $label . "\n";
                continue;
            }
            $conn->begin_transaction();
            try {
                post_payment_capture($conn, (int)$payment['order_id'], null, (string)$payment['provider']);
                $conn->commit();
                $posted++;
                echo 'posted: ' . $label . "\n";
            } catch (Throwable $e) {
                $conn->rollback();
                $failed++;
                echo 'failed: ' . $label . ' - ' . $e->getMessage() . "\n";
            }
        }
        echo $apply ? "Done: {$posted} posted, {$failed} failed.\n" : "Dry run only; use --apply to post these captures.\n";
        exit($failed === 0 ? 0 : 1);
    }

    if (in_array('--expire-orders', $argv, true)) {
        $hours = 24;
        $limit = 100;
        foreach ($argv as $argument) {
            if (preg_match('/^--hours=(\d+)$/', $argument, $matches)) {
                $hours = (int)$matches[1];
            }
            if (preg_match('/^--limit=(\d+)$/', $argument, $matches)) {
                $limit = (int)$matches[1];
            }
        }
        if ($hours < 1 || $hours > 8760 || $limit < 1 || $limit > 500) {
            fwrite(STDERR, "Use --hours=1..8760 and --limit=1..500.\n");
            exit(1);
        }
        try {
            $results = expire_abandoned_orders($conn, $hours, $limit, !in_array('--apply', $argv, true));
            foreach ($results as $result) {
                echo ($result['reference'] ?? ('#' . $result['order_id'])) . ': ' . $result['action'];
                if (!empty($result['reason'])) {
                    echo ' - ' . $result['reason'];
                }
                echo "\n";
            }
            echo 'DONE: ' . count($results) . " order(s) examined\n";
            exit(0);
        } catch (Throwable $e) {
            fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
            exit(1);
        }
    }
}

