<?php
// CampusLink – Search and category browse
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/catalog.php';

$search       = trim($_GET['q'] ?? '');
$category     = trim($_GET['category'] ?? '');
$seller       = trim($_GET['seller'] ?? '');
$availability = $_GET['availability'] ?? '';
if (!in_array($availability, ['', 'in', 'out'], true)) {
    $availability = '';
}
$sort = $_GET['sort'] ?? 'newest';
if (!in_array($sort, ['newest', 'price_asc', 'price_desc'], true)) {
    $sort = 'newest';
}
$minPrice = catalog_money($_GET['min_price'] ?? null);
$maxPrice = catalog_money($_GET['max_price'] ?? null);
if ($minPrice !== null && $maxPrice !== null && (float)$minPrice > (float)$maxPrice) {
    [$minPrice, $maxPrice] = [$maxPrice, $minPrice];
}

$filters = [
    'q'            => $search,
    'category'     => $category,
    'seller'       => $seller,
    'availability' => $availability,
    'sort'         => $sort,
    'min_price'    => $minPrice,
    'max_price'    => $maxPrice,
];
$products      = catalog_products($conn, $filters);
$cats          = catalog_categories($conn);
$totalListings = (int)catalog_stats($conn)['listings'];

$baseUrl = APP_BASE_URL . '/public/browse.php';
$buildUrl = static function (array $overrides) use ($baseUrl, $search, $category, $seller, $availability, $sort, $minPrice, $maxPrice): string {
    $params = [
        'q'            => $search,
        'category'     => $category,
        'seller'       => $seller,
        'availability' => $availability,
        'sort'         => $sort,
        'min_price'    => $minPrice ?? '',
        'max_price'    => $maxPrice ?? '',
    ];
    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }
    $params = array_filter($params, static fn($value) => $value !== '' && $value !== null);
    if (($params['sort'] ?? '') === 'newest') {
        unset($params['sort']);
    }
    return $baseUrl . ($params ? '?' . http_build_query($params) : '');
};

$sortLabels = ['newest' => 'Newest', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low'];
$availLabels = ['in' => 'In stock', 'out' => 'Out of stock'];
$chips = [];
if ($search !== '') {
    $chips[] = ['Search: “' . $search . '”', $buildUrl(['q' => ''])];
}
if ($category !== '') {
    $chips[] = ['Category: ' . $category, $buildUrl(['category' => ''])];
}
if ($seller !== '') {
    $chips[] = ['Vendor: ' . $seller, $buildUrl(['seller' => ''])];
}
if ($availability !== '') {
    $chips[] = [$availLabels[$availability], $buildUrl(['availability' => ''])];
}
if ($minPrice !== null) {
    $chips[] = ['Min ' . format_currency($minPrice), $buildUrl(['min_price' => ''])];
}
if ($maxPrice !== null) {
    $chips[] = ['Max ' . format_currency($maxPrice), $buildUrl(['max_price' => ''])];
}
if ($sort !== 'newest') {
    $chips[] = ['Sort: ' . $sortLabels[$sort], $buildUrl(['sort' => 'newest'])];
}

$heading = 'All products';
if ($search !== '') {
    $heading = 'Results for “' . $search . '”';
} elseif ($seller !== '') {
    $heading = 'Products from ' . $seller;
} elseif ($category !== '') {
    $heading = $category;
}
$resultCount = count($products);
$pageTitle = $heading;
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<nav class="breadcrumbs" aria-label="Breadcrumb">
  <a href="<?= APP_BASE_URL ?>/">Home</a>
  <span class="sep" aria-hidden="true">/</span>
  <span class="cur" aria-current="page">Shop</span>
</nav>

<div class="page-bar">
  <div>
    <h1 class="page-title"><?= e($heading) ?></h1>
    <p class="page-sub">Find campus products by category, price, stock and vendor. Use search to narrow results.</p>
  </div>
</div>

<form method="GET" action="<?= $baseUrl ?>" class="filter-bar shop-filters" data-autosubmit>
  <?php if ($search !== ''): ?>
    <input type="hidden" name="q" value="<?= e($search) ?>">
  <?php endif; ?>

  <?php if ($category !== ''): ?>
    <input type="hidden" name="category" value="<?= e($category) ?>">
  <?php else: ?>
    <div class="search-field">
      <label for="cat">Category</label>
      <select id="cat" name="category">
        <option value="">All categories</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= e($c['name']) ?>">
            <?= e($c['name']) ?> (<?= (int)$c['listings'] ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>

  <div class="search-field">
    <label for="min_price">Min price</label>
    <input type="number" id="min_price" name="min_price" min="0" step="0.01" inputmode="decimal" value="<?= e($minPrice ?? '') ?>">
  </div>
  <div class="search-field">
    <label for="max_price">Max price</label>
    <input type="number" id="max_price" name="max_price" min="0" step="0.01" inputmode="decimal" value="<?= e($maxPrice ?? '') ?>">
  </div>
  <div class="search-field">
    <label for="availability">Availability</label>
    <select id="availability" name="availability">
      <option value="" <?= $availability === '' ? 'selected' : '' ?>>Any stock</option>
      <option value="in" <?= $availability === 'in' ? 'selected' : '' ?>>In stock</option>
      <option value="out" <?= $availability === 'out' ? 'selected' : '' ?>>Out of stock</option>
    </select>
  </div>
  <div class="search-field">
    <label for="sort">Sort</label>
    <select id="sort" name="sort">
      <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
      <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: low to high</option>
      <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: high to low</option>
    </select>
  </div>
  <div class="search-actions">
    <button type="submit" class="btn btn-primary"><?= ui_icon('sliders', 'icn-sm') ?> Apply</button>
  </div>

  <details class="filter-more"<?= $seller !== '' ? ' open' : '' ?>>
    <summary>
      <?= ui_icon('filter', 'icn-sm') ?>
      <span>More filters</span>
      <?php if ($seller !== ''): ?><span class="filter-more-badge">1</span><?php endif; ?>
    </summary>
    <div class="filter-more-body">
      <div class="search-field">
        <label for="seller">Vendor</label>
        <input type="search" id="seller" name="seller" placeholder="Vendor name" value="<?= e($seller) ?>" autocomplete="off">
      </div>
      <button type="submit" class="btn btn-secondary btn-sm">Apply vendor</button>
    </div>
  </details>
</form>

<div class="filter-summary">
  <span>Showing <strong class="num"><?= number_format($resultCount) ?></strong>
    <?= $resultCount === 1 ? 'product' : 'products' ?><?= $totalListings !== $resultCount ? ' of ' . number_format($totalListings) : '' ?></span>
  <?php foreach ($chips as [$label, $href]): ?>
    <span class="chip"><?= e($label) ?>
      <a class="x" href="<?= e($href) ?>" aria-label="Remove filter: <?= e($label) ?>">&times;</a>
    </span>
  <?php endforeach; ?>
  <?php if ($chips): ?>
    <a class="chip-clear" href="<?= $baseUrl ?>">Clear all</a>
  <?php endif; ?>
</div>

<?php if (empty($products)): ?>
  <?php if ($totalListings === 0): ?>
    <?= ui_empty(
          'store',
          'No products yet',
          'Nothing has been listed on CampusLink so far.',
          (is_logged_in() && current_user_role() === 'seller')
            ? '<a class="btn btn-primary" href="' . APP_BASE_URL . '/seller/add_product.php">Add Product</a>'
            : '<a class="btn btn-primary" href="' . APP_BASE_URL . '/auth/register.php?role=seller">Become a Vendor</a>'
        ) ?>
  <?php else: ?>
    <?= ui_empty(
          'search',
          'No products match',
          'Try another category, vendor, price range or stock filter.',
          '<a class="btn btn-secondary" href="' . $baseUrl . '">Clear all</a>'
        ) ?>
  <?php endif; ?>
<?php else: ?>
  <div class="product-grid">
    <?php foreach ($products as $p) echo ui_product_card($p); ?>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
