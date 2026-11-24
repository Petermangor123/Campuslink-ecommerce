<?php
// Shared storefront queries. Prices, stock, names and counts always come from the database.

function catalog_columns(): string {
    return 'p.id, p.seller_id, p.title, p.price, p.image, p.stock, p.created_at, c.name AS category, u.name AS seller';
}

function catalog_bind(mysqli_stmt $stmt, string $types, array $values): void {
    if ($types === '') {
        return;
    }
    $refs = [];
    foreach (array_values($values) as $i => $value) {
        $refs[$i] = $value;
    }
    $params = [$types];
    foreach ($refs as $i => &$ref) {
        $params[] = &$ref;
    }
    unset($ref);
    $stmt->bind_param(...$params);
}

/**
 * @param array{q?:string,category?:string,seller?:string,availability?:string,sort?:string,min_price?:float|string|null,max_price?:float|string|null,has_image?:bool,exclude?:int[],limit?:int} $opt
 */
function catalog_products(mysqli $conn, array $opt = []): array {
    $search = trim((string)($opt['q'] ?? ''));
    $category = trim((string)($opt['category'] ?? ''));
    $seller = trim((string)($opt['seller'] ?? ''));
    $availability = (string)($opt['availability'] ?? '');
    if (!in_array($availability, ['', 'in', 'out'], true)) {
        $availability = '';
    }
    $sort = (string)($opt['sort'] ?? 'newest');
    if (!in_array($sort, ['newest', 'price_asc', 'price_desc'], true)) {
        $sort = 'newest';
    }
    $limit = isset($opt['limit']) ? max(1, min(48, (int)$opt['limit'])) : 0;
    $exclude = array_values(array_filter(array_map('intval', $opt['exclude'] ?? []), static fn(int $id): bool => $id > 0));

    $where = ['p.is_active = 1'];
    $types = '';
    $params = [];

    if ($search !== '') {
        $where[] = '(p.title LIKE ? OR IFNULL(p.description, \'\') LIKE ? OR IFNULL(c.name, \'\') LIKE ? OR IFNULL(u.name, \'\') LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }
    if ($category !== '') {
        $where[] = 'c.name = ?';
        $types .= 's';
        $params[] = $category;
    }
    if ($seller !== '') {
        $where[] = 'u.name = ?';
        $types .= 's';
        $params[] = $seller;
    }
    if ($availability === 'in') {
        $where[] = 'p.stock > 0';
    } elseif ($availability === 'out') {
        $where[] = 'p.stock < 1';
    }
    if (!empty($opt['has_image'])) {
        $where[] = "p.image IS NOT NULL AND p.image <> ''";
    }
    if ($exclude) {
        $where[] = 'p.id NOT IN (' . implode(',', array_fill(0, count($exclude), '?')) . ')';
        $types .= str_repeat('i', count($exclude));
        foreach ($exclude as $id) {
            $params[] = $id;
        }
    }
    if (isset($opt['min_price']) && $opt['min_price'] !== '' && $opt['min_price'] !== null) {
        $where[] = 'p.price >= ?';
        $types .= 'd';
        $params[] = (float)$opt['min_price'];
    }
    if (isset($opt['max_price']) && $opt['max_price'] !== '' && $opt['max_price'] !== null) {
        $where[] = 'p.price <= ?';
        $types .= 'd';
        $params[] = (float)$opt['max_price'];
    }

    $order = match ($sort) {
        'price_asc'  => 'p.price ASC, p.id DESC',
        'price_desc' => 'p.price DESC, p.id DESC',
        default      => 'p.created_at DESC, p.id DESC',
    };

    $sql = 'SELECT ' . catalog_columns() . '
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN users u ON u.id = p.seller_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY ' . $order
        . ($limit > 0 ? ' LIMIT ' . $limit : '');

    $stmt = $conn->prepare($sql);
    catalog_bind($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function catalog_categories(mysqli $conn): array {
    $sql = 'SELECT c.id, c.name, COUNT(p.id) AS listings
            FROM categories c
            LEFT JOIN products p ON p.category_id = c.id AND p.is_active = 1
            GROUP BY c.id, c.name
            ORDER BY c.name';
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function catalog_stats(mysqli $conn): array {
    $row = $conn->query(
        'SELECT
            COUNT(*) AS listings,
            SUM(stock > 0) AS in_stock,
            COUNT(DISTINCT seller_id) AS sellers
         FROM products
         WHERE is_active = 1'
    )->fetch_assoc();
    $buyers = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'buyer' AND is_active = 1")->fetch_assoc()['c'];
    return [
        'listings' => (int)($row['listings'] ?? 0),
        'in_stock' => (int)($row['in_stock'] ?? 0),
        'sellers'  => (int)($row['sellers'] ?? 0),
        'buyers'   => $buyers,
    ];
}

function catalog_popular(mysqli $conn, int $limit = 8): array {
    $limit = max(1, min(12, $limit));
    $sql = 'SELECT ' . catalog_columns() . ', SUM(oi.quantity) AS units_sold
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            INNER JOIN products p ON p.id = oi.product_id
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN users u ON u.id = p.seller_id
            WHERE p.is_active = 1
              AND (o.payment_status = \'paid\' OR o.status IN (\'paid\', \'shipped\', \'delivered\'))
            GROUP BY p.id, p.seller_id, p.title, p.price, p.image, p.stock, p.created_at, c.name, u.name
            ORDER BY units_sold DESC, p.id DESC
            LIMIT ' . $limit;
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function catalog_sellers(mysqli $conn, int $limit = 8): array {
    $limit = max(1, min(24, $limit));
    $sql = 'SELECT u.id, u.name, COUNT(p.id) AS listings
            FROM users u
            INNER JOIN products p ON p.seller_id = u.id AND p.is_active = 1
            WHERE u.is_active = 1
            GROUP BY u.id, u.name
            HAVING listings > 0
            ORDER BY listings DESC, u.name ASC
            LIMIT ' . $limit;
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function catalog_money(?string $raw): ?string {
    $raw = trim((string)$raw);
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    $amount = (float)$raw;
    if ($amount < 0 || $amount > 99999999) {
        return null;
    }
    return number_format($amount, 2, '.', '');
}
