<?php
// CampusLink – shared UI helpers (icons, badges, states, dashboard shell)
// Presentation only. No business logic lives here.

if (!function_exists('ui_icon')) {

    /** Inline SVG icon set (stroke-based, 24x24 grid). */
    function ui_icon_path(string $name): string {
        static $icons = [
            'search'        => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
            'cart'          => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
            'bag'           => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
            'user'          => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'users'         => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'menu'          => '<path d="M3 6h18M3 12h18M3 18h18"/>',
            'x'             => '<path d="M18 6 6 18M6 6l12 12"/>',
            'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
            'chevron-down'  => '<path d="m6 9 6 6 6-6"/>',
            'arrow-right'   => '<path d="M5 12h14M13 5l7 7-7 7"/>',
            'arrow-left'    => '<path d="M19 12H5M11 19l-7-7 7-7"/>',
            'plus'          => '<path d="M12 5v14M5 12h14"/>',
            'pencil'        => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
            'trash'         => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M10 11v6M14 11v6"/>',
            'package'       => '<path d="M16.5 9.4 7.55 4.24"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
            'dashboard'     => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
            'grid'          => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
            'list'          => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
            'tag'           => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r="1.2"/>',
            'orders'        => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6M9 16h4"/>',
            'settings'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
            'heart'         => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
            'check'         => '<path d="M20 6 9 17l-5-5"/>',
            'check-circle'  => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>',
            'alert'         => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
            'info'          => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
            'x-circle'      => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/>',
            'truck'         => '<path d="M14 18V6a1 1 0 0 0-1-1H2a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h1"/><path d="M14 9h4l3 3v5a1 1 0 0 1-1 1h-1"/><circle cx="7.5" cy="18.5" r="2"/><circle cx="17.5" cy="18.5" r="2"/><path d="M9.5 18.5h6"/>',
            'card'          => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
            'map-pin'       => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
            'eye'           => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
            'eye-off'       => '<path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c6.5 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.53 13.53 0 0 0 2 12s3.5 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><path d="m2 2 20 20"/>',
            'logout'        => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
            'login'         => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
            'star'          => '<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/>',
            'book'          => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
            'monitor'       => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
            'headphones'    => '<path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/>',
            'shirt'         => '<path d="M20.38 3.46 16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.57a1 1 0 0 0 .99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.57a2 2 0 0 0-1.34-2.23z"/>',
            'sofa'          => '<path d="M20 9V7a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v2"/><path d="M2 11v5a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-5a2 2 0 0 0-4 0v2H6v-2a2 2 0 0 0-4 0z"/><path d="M6 18v2M18 18v2"/>',
            'pen'           => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
            'filter'        => '<path d="M22 3H2l8 9.46V19l4 2v-8.54Z"/>',
            'sliders'       => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
            'image'         => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
            'upload'        => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
            'dollar'        => '<path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
            'trending'      => '<path d="m22 7-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/>',
            'store'         => '<path d="M3.5 9h17l-1.4 10.2a2 2 0 0 1-2 1.8H6.9a2 2 0 0 1-2-1.8Z"/><path d="M8.5 9V6a3.5 3.5 0 0 1 7 0v3"/>',
            'shield'        => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'key'           => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3"/>',
            'mail'          => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
            'phone'         => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
            'calendar'      => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
            'clock'         => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
            'lock'          => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'more'          => '<circle cx="12" cy="12" r="1.2"/><circle cx="19" cy="12" r="1.2"/><circle cx="5" cy="12" r="1.2"/>',
            'refresh'       => '<path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/>',
            'inbox'         => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
            'zap'           => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
            'home'          => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
            'compass'       => '<circle cx="12" cy="12" r="10"/><path d="m16.24 7.76-2.12 6.36-6.36 2.12 2.12-6.36z"/>',
            'external'      => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
            'sun'           => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
            'save'          => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
            'minus'         => '<path d="M5 12h14"/>',
        ];
        return $icons[$name] ?? $icons['package'];
    }

    /** Render an inline SVG icon. */
    function ui_icon(string $name, string $class = 'icn', string $extra = ''): string {
        return '<svg class="' . htmlspecialchars($class) . '" ' . $extra
            . ' xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . ui_icon_path($name) . '</svg>';
    }

    /** Render the approved CampusLink logo lockup for light or dark surfaces. */
    function ui_brand_logo(bool $light = false, string $class = 'brand-logo'): string {
        $file = $light ? 'logo-light.svg' : 'logo.svg';
        $path = dirname(__DIR__) . '/assets/img/' . $file;
        $ver  = is_file($path) ? (string)filemtime($path) : '1';
        $src  = APP_BASE_URL . '/assets/img/' . $file . '?v=' . rawurlencode($ver);
        return '<img class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"'
            . ' src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"'
            . ' width="185" height="48" alt="" aria-hidden="true">';
    }

    /** Icon name for a product category. */
    function ui_category_icon(string $name): string {
        $map = [
            'textbooks'   => 'book',
            'books'       => 'book',
            'electronics' => 'headphones',
            'clothing'    => 'shirt',
            'furniture'   => 'sofa',
            'stationery'  => 'pen',
            'sports'      => 'zap',
            'other'       => 'package',
        ];
        return $map[strtolower(trim($name))] ?? 'package';
    }

    /** Slug used for local category photography filenames. */
    function ui_category_slug(string $name): string {
        $slug = strtolower(trim($name));
        $aliases = ['books' => 'textbooks'];
        return $aliases[$slug] ?? preg_replace('/[^a-z0-9]+/', '-', $slug);
    }

    /**
     * Relative path (under the app root) to a category photo.
     * Prefers WebP when present; falls back to JPEG, then Other.
     */
    function ui_category_photo(?string $name): string {
        $slug = ui_category_slug((string)$name);
        if ($slug === '') {
            $slug = 'other';
        }
        $base = dirname(__DIR__) . '/assets/img/categories/';
        foreach ([$slug, 'other'] as $try) {
            if (is_file($base . 'cat-' . $try . '.webp')) {
                return 'assets/img/categories/cat-' . $try . '.webp';
            }
            if (is_file($base . 'cat-' . $try . '.jpg')) {
                return 'assets/img/categories/cat-' . $try . '.jpg';
            }
        }
        return 'assets/img/categories/cat-other.jpg';
    }

    /** <picture> markup for a category photo with WebP + JPEG sources. */
    function ui_category_picture(string $name, string $class = '', string $alt = ''): string {
        $slug = ui_category_slug($name);
        if ($slug === '') {
            $slug = 'other';
        }
        $dir = dirname(__DIR__) . '/assets/img/categories/';
        $webpRel = 'assets/img/categories/cat-' . $slug . '.webp';
        $jpgRel  = 'assets/img/categories/cat-' . $slug . '.jpg';
        if (!is_file($dir . 'cat-' . $slug . '.jpg') && !is_file($dir . 'cat-' . $slug . '.webp')) {
            $webpRel = 'assets/img/categories/cat-other.webp';
            $jpgRel  = 'assets/img/categories/cat-other.jpg';
        }
        $cls = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '';
        $html = '<picture' . $cls . '>';
        if (is_file(dirname(__DIR__) . '/' . $webpRel)) {
            $html .= '<source type="image/webp" srcset="' . APP_BASE_URL . '/' . htmlspecialchars($webpRel, ENT_QUOTES, 'UTF-8') . '">';
        }
        $html .= '<img src="' . APP_BASE_URL . '/' . htmlspecialchars($jpgRel, ENT_QUOTES, 'UTF-8') . '"'
            . ' alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '"'
            . ' width="640" height="480" loading="lazy" decoding="async">';
        $html .= '</picture>';
        return $html;
    }

    /**
     * Display label for payment_status DB values (does not change stored enums).
     * pending | paid→Successful | failed | cancelled | refunded
     */
    function ui_payment_label(string $status): string {
        $map = [
            'pending'   => 'Pending',
            'paid'      => 'Successful',
            'failed'    => 'Failed',
            'cancelled' => 'Cancelled',
            'refunded'  => 'Refunded',
            'partial' => 'Partial refund',
            'full' => 'Fully refunded',
            'needs_attention' => 'Needs attention',
            'processing' => 'Processing',
            'completed' => 'Completed',
            'void' => 'Void',
            'open' => 'Open',
            'under_review' => 'Under review',
            'resolved' => 'Resolved',
            'rejected' => 'Rejected',
        ];
        $s = strtolower(trim($status));
        return $map[$s] ?? ucfirst(str_replace('_', ' ', $s));
    }

    /** Status pill. Status may be a role, payment state or fulfilment state. */
    function ui_badge(string $status, ?string $label = null, bool $dot = true): string {
        $s = strtolower(trim($status));
        $map = [
            'buyer' => 'buyer', 'seller' => 'seller', 'admin' => 'admin', 'vendor' => 'seller',
            'pending' => 'pending', 'approved' => 'paid', 'rejected' => 'failed', 'suspended' => 'cancelled',
            'paid' => 'paid', 'failed' => 'failed',
            'shipped' => 'shipped', 'delivered' => 'delivered', 'cancelled' => 'cancelled',
            'warning' => 'warning', 'neutral' => 'neutral',
            'awaiting_payment' => 'pending', 'awaiting_acceptance' => 'warning',
            'accepted' => 'paid', 'processing' => 'paid', 'ready_for_pickup' => 'shipped',
            'rejected' => 'failed', 'refunded' => 'cancelled',
            'partial' => 'warning', 'full' => 'cancelled', 'needs_attention' => 'warning',
            'processing' => 'pending', 'completed' => 'paid', 'void' => 'neutral',
            'open' => 'warning', 'under_review' => 'pending', 'resolved' => 'paid',
            'successful' => 'paid', 'dispatched' => 'shipped',
        ];
        $tone = $map[$s] ?? 'neutral';
        $cls = 'badge badge-' . $tone;
        if (!$dot) $cls .= ' badge-plain';
        if ($label === null) {
            if (isset(['pending'=>1,'paid'=>1,'failed'=>1,'cancelled'=>1,'refunded'=>1][$s])) {
                $label = ui_payment_label($s);
            } elseif (function_exists('fulfilment_label') && (str_contains($s, '_') || in_array($s, ['shipped','delivered','processing','accepted','rejected'], true))) {
                $label = fulfilment_label($s);
            } elseif ($s === 'seller' || $s === 'vendor') {
                $label = 'Vendor';
            } elseif ($s === 'buyer') {
                $label = 'Customer';
            } else {
                $label = ucfirst(str_replace('_', ' ', $s));
            }
        }
        return '<span class="' . $cls . '">' . htmlspecialchars($label) . '</span>';
    }

    /** Friendly label for an order / fulfilment / payment status value. */
    function ui_status_label(string $status): string {
        $s = strtolower(trim($status));
        if (isset(['pending'=>1,'paid'=>1,'failed'=>1,'cancelled'=>1,'refunded'=>1][$s])) {
            return ui_payment_label($s);
        }
        if (function_exists('fulfilment_label')) {
            return fulfilment_label($s);
        }
        return ucfirst(str_replace('_', ' ', $s));
    }

    /** Compact empty / error / loading state. */
    function ui_empty(string $icon, string $title, string $text = '', string $actionsHtml = '', bool $compact = false): string {
        return '<div class="empty-state' . ($compact ? ' compact' : '') . '">'
            . '<div class="ei">' . ui_icon($icon, 'icn-lg', 'width="28" height="28"') . '</div>'
            . '<h3>' . htmlspecialchars($title) . '</h3>'
            . ($text !== '' ? '<p>' . htmlspecialchars($text) . '</p>' : '')
            . ($actionsHtml !== '' ? '<div class="actions">' . $actionsHtml . '</div>' : '')
            . '</div>';
    }

    /** Breadcrumb trail. $items = [[label, href|null], ...] */
    function ui_breadcrumbs(array $items): string {
        $last = count($items) - 1;
        $out = '<nav class="breadcrumbs" aria-label="Breadcrumb">';
        foreach (array_values($items) as $i => $item) {
            $label = htmlspecialchars($item[0]);
            if ($i > 0) $out .= '<span class="sep" aria-hidden="true">/</span>';
            if (!empty($item[1]) && $i !== $last) {
                $out .= '<a href="' . htmlspecialchars($item[1]) . '">' . $label . '</a>';
            } else {
                $out .= '<span class="cur" aria-current="page">' . $label . '</span>';
            }
        }
        return $out . '</nav>';
    }

    /** Status timeline. $steps = [['label'=>, 'time'=>, 'state'=>'done|current|']] */
    function ui_timeline(array $steps): string {
        $out = '<ol class="timeline">';
        foreach ($steps as $step) {
            $state = $step['state'] ?? '';
            $cls = 'tl-step' . ($state ? ' is-' . $state : '');
            $out .= '<li class="' . $cls . '"><span class="tl-dot">'
                . ($state === 'done' ? ui_icon('check', 'icn-sm') : '')
                . '</span><span><span class="tl-label">' . htmlspecialchars($step['label']) . '</span>'
                . (!empty($step['time']) ? '<span class="tl-time">' . htmlspecialchars($step['time']) . '</span>' : '')
                . '</span></li>';
        }
        return $out . '</ol>';
    }

    /** Metric card. */
    function ui_stat(string $value, string $label, string $icon, string $variant = '', string $foot = '', string $valueClass = ''): string {
        return '<div class="stat-card">'
            . '<div class="stat-top"><div class="stat-icon ' . htmlspecialchars($variant) . '">'
            . ui_icon($icon, 'icn', 'width="20" height="20"') . '</div></div>'
            . '<div class="stat-value ' . htmlspecialchars($valueClass) . '">' . $value . '</div>'
            . '<div class="stat-label">' . htmlspecialchars($label) . '</div>'
            . ($foot !== '' ? '<div class="stat-foot">' . htmlspecialchars($foot) . '</div>' : '')
            . '</div>';
    }

    /**
     * Compact dashboard shortcut tile.
     * @param array{href:string,icon:string,label:string,hint?:string} $item
     */
    function ui_shortcut(array $item): string {
        $href = htmlspecialchars((string)($item['href'] ?? '#'));
        $label = htmlspecialchars((string)($item['label'] ?? ''));
        $hint = trim((string)($item['hint'] ?? ''));
        $icon = (string)($item['icon'] ?? 'arrow-right');
        return '<a class="dash-shortcut" href="' . $href . '">'
            . '<span class="ds-ico">' . ui_icon($icon, 'icn') . '</span>'
            . '<span class="ds-copy"><span class="ds-label">' . $label . '</span>'
            . ($hint !== '' ? '<span class="ds-hint">' . htmlspecialchars($hint) . '</span>' : '')
            . '</span>'
            . ui_icon('arrow-right', 'icn-sm ds-arrow')
            . '</a>';
    }

    /** Soft alert strip for dashboards (only when count > 0 or forced). */
    function ui_alert_chip(string $href, string $label, int $count, string $tone = 'warn'): string {
        if ($count < 1) {
            return '';
        }
        $tone = in_array($tone, ['warn', 'danger', 'info', 'ok'], true) ? $tone : 'warn';
        return '<a class="dash-alert dash-alert-' . $tone . '" href="' . htmlspecialchars($href) . '">'
            . '<span class="da-count">' . (int)$count . '</span>'
            . '<span class="da-label">' . htmlspecialchars($label) . '</span>'
            . ui_icon('arrow-right', 'icn-sm')
            . '</a>';
    }

    /**
     * Return a safe relative product image path, or null if missing/corrupt.
     * Rejects empty values and the legacy bind_param sentinel "0".
     */
    function ui_product_image_path(?string $image): ?string {
        $img = trim((string)$image);
        if ($img === '' || $img === '0') {
            return null;
        }
        if (!preg_match('#^assets/uploads/[A-Za-z0-9._-]+$#', $img)) {
            return null;
        }
        return $img;
    }

    /** Shop thumbnail: seller photo when present, otherwise category fallback. */
    function ui_thumb(?string $image, string $alt = '', string $category = ''): string {
        $safe = ui_product_image_path($image);
        if ($safe !== null) {
            return '<img class="thumb" loading="lazy" decoding="async" alt="' . htmlspecialchars($alt)
                . '" src="' . APP_BASE_URL . '/' . htmlspecialchars($safe) . '">';
        }
        $photo = ui_category_photo($category);
        return '<span class="thumb thumb-fallback" role="img" aria-label="' . htmlspecialchars($alt !== '' ? $alt : 'No photo yet') . '">'
            . '<img src="' . APP_BASE_URL . '/' . htmlspecialchars($photo) . '" alt="" loading="lazy" decoding="async" width="96" height="96">'
            . '<span class="thumb-fallback-label">No photo</span></span>';
    }

    /**
     * Product listing card. Expects: id, title, price, image, stock and
     * optionally category + seller. The whole card links to the product page.
     * Seller-uploaded images are never replaced; missing photos use a category fallback.
     */
    function ui_product_card(array $p): string {
        $id    = (int)($p['id'] ?? 0);
        $href  = APP_BASE_URL . '/public/product.php?id=' . $id;
        $title = (string)($p['title'] ?? '');
        $stock = array_key_exists('stock', $p) ? (int)$p['stock'] : 0;
        $img   = ui_product_image_path(isset($p['image']) ? (string)$p['image'] : null);
        $category = trim((string)($p['category'] ?? ''));
        $link  = htmlspecialchars($href);

        $out  = '<article class="product-card">';
        $out .= '<a class="pc-media" href="' . $link . '">';
        if ($img !== null) {
            $out .= '<img class="pc-img" loading="lazy" decoding="async" src="' . APP_BASE_URL . '/'
                . htmlspecialchars($img) . '" alt="' . htmlspecialchars($title) . '" width="640" height="640">';
        } else {
            $fallback = ui_category_photo($category);
            $out .= '<span class="pc-placeholder pc-fallback">'
                . '<img class="pc-fallback-img" src="' . APP_BASE_URL . '/' . htmlspecialchars($fallback) . '"'
                . ' alt="" loading="lazy" decoding="async" width="640" height="640">'
                . '<span class="pc-fallback-label">No photo yet</span></span>';
        }
        if ($stock < 1) {
            $out .= '<span class="pc-flag">' . ui_badge('cancelled', 'Sold out') . '</span>';
        }
        $out .= '</a>';

        $out .= '<div class="pc-body">';
        if ($category !== '') {
            $out .= '<span class="pc-cat">' . ui_icon(ui_category_icon($category), 'icn-xs') . ' '
                . htmlspecialchars($category) . '</span>';
        }
        $out .= '<h3 class="pc-title"><a href="' . $link . '">' . htmlspecialchars($title) . '</a></h3>';
        $out .= '<div class="pc-price">' . format_currency($p['price'] ?? 0) . '</div>';
        $out .= '<div class="pc-stock' . ($stock < 1 ? ' is-out' : '') . '">'
            . ($stock < 1 ? 'Out of stock' : ((int)$stock === 1 ? '1 available' : (int)$stock . ' available'))
            . '</div>';
        if (!empty($p['seller'])) {
            $sellerHref = APP_BASE_URL . '/public/browse.php?seller=' . rawurlencode((string)$p['seller']);
            $out .= '<a class="pc-seller" href="' . htmlspecialchars($sellerHref) . '">'
                . ui_icon('store', 'icn-xs') . ' ' . htmlspecialchars((string)$p['seller']) . '</a>';
        }
        if (isset($p['units_sold']) && (int)$p['units_sold'] > 0) {
            $sold = (int)$p['units_sold'];
            $out .= '<div class="pc-sold">' . $sold . ($sold === 1 ? ' sold' : ' sold') . '</div>';
        }
        $out .= '</div>';

        $out .= '<div class="pc-foot"><a class="btn btn-primary btn-sm btn-full" href="' . $link . '">'
            . 'View item' . ui_icon('arrow-right', 'icn-xs') . '</a></div>';
        return $out . '</article>';
    }

    /** True when the current request is inside the seller or admin workspace. */
    function ui_is_dashboard(): bool {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        return (bool)preg_match('#/(seller|admin)/#', $script);
    }

    function ui_dashboard_area(): string {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        if (str_contains($script, '/seller/')) return 'seller';
        if (str_contains($script, '/admin/')) return 'admin';
        return '';
    }

    /**
     * Sidebar navigation for admin / vendor workspaces.
     * Item: [label, href, icon, matchPath, ?alsoMatchPaths[]]
     * Group: ['Group', label]
     */
    function ui_dash_nav(string $area): array {
        if ($area === 'admin') {
            return [
                ['Group', 'Main menu'],
                ['Dashboard',          APP_BASE_URL . '/admin/dashboard.php', 'dashboard', '/admin/dashboard.php'],
                ['Orders',             APP_BASE_URL . '/admin/orders.php',    'orders',    '/admin/orders.php', ['/admin/order_view.php']],
                ['Payments',           APP_BASE_URL . '/admin/payments.php',  'card',      '/admin/payments.php'],
                ['Refunds',            APP_BASE_URL . '/admin/refunds.php',   'refresh',   '/admin/refunds.php'],
                ['Disputes',           APP_BASE_URL . '/admin/disputes.php',  'shield',    '/admin/disputes.php'],
                ['Settlements',        APP_BASE_URL . '/admin/settlements.php', 'dollar',  '/admin/settlements.php'],
                ['Commission',         APP_BASE_URL . '/admin/commission.php', 'sliders', '/admin/commission.php'],
                ['Financial Ledger',  APP_BASE_URL . '/admin/ledger.php',      'book',    '/admin/ledger.php'],
                ['Products',           APP_BASE_URL . '/admin/products.php',  'package',   '/admin/products.php', ['/admin/add_product.php', '/admin/edit_product.php']],
                ['Vendor Management',  APP_BASE_URL . '/admin/vendors.php',   'store',     '/admin/vendors.php', ['/admin/vendor_view.php', '/admin/sellers.php']],
                ['Users',              APP_BASE_URL . '/admin/users.php',     'users',     '/admin/users.php', ['/admin/user_view.php']],
                ['Analytics & Reports',APP_BASE_URL . '/admin/reports.php',   'trending',  '/admin/reports.php'],
                ['Group', 'Marketplace'],
                ['View Marketplace',   APP_BASE_URL . '/public/browse.php',   'compass',   '/public/browse.php'],
                ['Group', 'Account'],
                ['My Profile',         APP_BASE_URL . '/admin/profile.php',   'user',      '/admin/profile.php'],
                ['Sign Out',           APP_BASE_URL . '/auth/logout.php',     'logout',    '/auth/logout.php'],
            ];
        }
        return [
            ['Group', 'Main menu'],
            ['Dashboard',        APP_BASE_URL . '/seller/dashboard.php',   'dashboard', '/seller/dashboard.php'],
            ['My Products',      APP_BASE_URL . '/seller/my_products.php', 'package',   '/seller/my_products.php', ['/seller/edit_product.php']],
            ['Add Product',      APP_BASE_URL . '/seller/add_product.php', 'plus',      '/seller/add_product.php'],
            ['Orders',           APP_BASE_URL . '/seller/orders.php',      'orders',    '/seller/orders.php', ['/seller/order_view.php']],
            ['Sales & Earnings', APP_BASE_URL . '/seller/sales.php',       'dollar',    '/seller/sales.php'],
            ['Store Profile',    APP_BASE_URL . '/seller/profile.php',     'store',     '/seller/profile.php'],
            ['Group', 'Account'],
            ['My Profile',       APP_BASE_URL . '/seller/profile.php',     'user',      '/seller/profile.php'],
            ['View Marketplace', APP_BASE_URL . '/public/browse.php',      'compass',   '/public/browse.php'],
            ['Sign Out',         APP_BASE_URL . '/auth/logout.php',        'logout',    '/auth/logout.php'],
        ];
    }

    /** Print the flash message as an accessible toast. */
    function ui_flash(): void {
        if (!function_exists('get_flash')) return;
        $flash = get_flash();
        if (!$flash) return;
        $type = in_array($flash['type'], ['success', 'error', 'info'], true) ? $flash['type'] : 'info';
        $icon = ['success' => 'check-circle', 'error' => 'x-circle', 'info' => 'info'][$type];
        echo '<div class="toast-stack" id="toastStack" aria-live="polite">';
        echo '<div class="toast toast-' . $type . '" role="status">' . ui_icon($icon)
            . '<span>' . htmlspecialchars($flash['message']) . '</span>'
            . '<button type="button" class="toast-close" aria-label="Dismiss notification">&times;</button></div>';
        echo '</div>';
    }
}
