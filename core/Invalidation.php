<?php
defined('ABSPATH') || exit;

final class BTL_Invalidation
{
    public const SCOPE_ALL = 'all';
    public const SCOPE_CONTENT = 'content';
    public const SCOPE_PRICING = 'pricing';

    private const LATEST_GRID_SIZE = 10;
    private const LATEST_CUTOFF_KEY = 'home_latest_cutoff';

    private static array $queued = [];
    private static array $deferred = [];
    private static bool $suspended = false;

    public static function boot(): void
    {
        add_action('woocommerce_update_product', [self::class, 'on_product_saved'], 100, 1);
        add_action('woocommerce_new_product', [self::class, 'on_product_saved'], 100, 1);
        add_action('woocommerce_update_product_variation', [self::class, 'on_variation_saved'], 100, 1);
        add_action('woocommerce_new_product_variation', [self::class, 'on_variation_saved'], 100, 1);

        add_action('transition_post_status', [self::class, 'on_post_status_change'], 20, 3);
        add_action('before_delete_post', [self::class, 'on_before_delete_post'], 10, 1);

        add_action('created_term', [self::class, 'on_term_changed'], 20, 3);
        add_action('edited_term', [self::class, 'on_term_changed'], 20, 3);
        add_action('delete_term', [self::class, 'on_term_deleted'], 20, 4);

        add_action('acf/save_post', [self::class, 'on_acf_save'], 25, 1);
    }


    public static function queueProduct(int $productId, string $scope = self::SCOPE_ALL): void
    {
        if ($productId <= 0) {
            return;
        }

        self::bustObjectCache($productId, $scope);

        $key = $productId . ':' . $scope;
        if (isset(self::$queued[$key])) {
            return;
        }
        self::$queued[$key] = true;

        if (self::$suspended) {
            self::$deferred[$productId] = true;
            return;
        }

        $tags = self::tagsForProduct($productId, $scope);

        if ($tags && function_exists('btl_queue_revalidation')) {
            btl_queue_revalidation($tags);
        }
    }

    public static function tagsForProduct(int $productId, string $scope = self::SCOPE_ALL): array
    {
        $post = get_post($productId);

        if (!$post || $post->post_type !== 'product' || $post->post_name === '') {
            return [];
        }

        $slug = $post->post_name;
        $tags = [];

        if ($scope !== self::SCOPE_PRICING) {
            $tags[] = "product-{$slug}";
        }

        if ($scope !== self::SCOPE_CONTENT) {
            $tags[] = "product-pricing-{$slug}";
        }

        foreach (self::productCategorySlugs($productId) as $catSlug) {
            $tags[] = BTL_Helpers::slugTag('category', $catSlug);
        }

        if (self::isFeatured($productId)) {
            $tags[] = 'home-featured';
        }

        if (self::isInLatestWindow($post)) {
            $tags[] = 'home-latest';
        }

        return array_values(array_unique($tags));
    }

    public static function queueTerm(int $termId, string $taxonomy): void
    {
        $term = get_term($termId, $taxonomy);

        if (!$term || is_wp_error($term)) {
            return;
        }

        $tags = self::tagsForTerm($term, $taxonomy);

        if ($tags && function_exists('btl_queue_revalidation')) {
            btl_queue_revalidation($tags);
        }
    }

    public static function suspend(): void
    {
        self::$suspended = true;
        self::$deferred = [];
    }

    public static function resume(): array
    {
        self::$suspended = false;
        $ids = array_keys(self::$deferred);
        self::$deferred = [];

        return array_map('intval', $ids);
    }


    public static function on_product_saved($product): void
    {
        if (!self::editorialContext()) {
            return;
        }

        $id = self::resolveProductId($product);

        if ($id) {
            self::queueProduct($id, self::SCOPE_ALL);
        }
    }

    public static function on_variation_saved($variation): void
    {
        if (!self::editorialContext()) {
            return;
        }

        $variationId = self::resolveProductId($variation);

        if (!$variationId) {
            return;
        }

        $parentId = (int) wp_get_post_parent_id($variationId);

        if ($parentId) {
            self::queueProduct($parentId, self::SCOPE_PRICING);
        }
    }

    public static function on_post_status_change($newStatus, $oldStatus, $post): void
    {
        if (!$post instanceof WP_Post || $post->post_type !== 'product') {
            return;
        }

        if ($newStatus === $oldStatus) {
            return;
        }

        if ($newStatus !== 'publish' && $oldStatus !== 'publish') {
            return;
        }

        BTL_Cache::delete(self::LATEST_CUTOFF_KEY);
        self::queueProduct((int) $post->ID, self::SCOPE_ALL);

        if (function_exists('btl_queue_revalidation')) {
            btl_queue_revalidation(['home-latest']);
        }
    }

    public static function on_before_delete_post($postId): void
    {
        $post = get_post((int) $postId);

        if (!$post || $post->post_type !== 'product') {
            return;
        }

        BTL_Cache::delete(self::LATEST_CUTOFF_KEY);
        self::queueProduct((int) $post->ID, self::SCOPE_ALL);
    }

    public static function on_term_changed($termId, $ttId, $taxonomy): void
    {
        self::queueTerm((int) $termId, (string) $taxonomy);
    }

    public static function on_term_deleted($term, $ttId, $taxonomy, $deletedTerm): void
    {
        if (!$deletedTerm instanceof WP_Term) {
            return;
        }

        $tags = self::tagsForTerm($deletedTerm, (string) $taxonomy);

        if ($tags && function_exists('btl_queue_revalidation')) {
            btl_queue_revalidation($tags);
        }
    }

    public static function on_acf_save($postId): void
    {
        if (is_numeric($postId)) {
            if (get_post_type((int) $postId) === 'product') {
                self::queueProduct((int) $postId, self::SCOPE_ALL);
            }

            return;
        }

        $raw = (string) $postId;

        if (preg_match('/^term_(\d+)$/', $raw, $m)) {
            $term = get_term((int) $m[1]);

            if ($term && !is_wp_error($term)) {
                self::queueTerm((int) $m[1], $term->taxonomy);
            }

            return;
        }

        if (preg_match('/^([a-z0-9_\-]+)_(\d+)$/i', $raw, $m) && taxonomy_exists($m[1])) {
            self::queueTerm((int) $m[2], $m[1]);
        }
    }

    private static function tagsForTerm(WP_Term $term, string $taxonomy): array
    {
        $tags = [];

        switch ($taxonomy) {
            case 'product_cat':
                $tags[] = 'header-data';
                $tags[] = 'banners';
                $tags[] = BTL_Helpers::slugTag('banners', $term->slug);
                $tags[] = BTL_Helpers::slugTag('category', $term->slug);

                foreach (get_ancestors($term->term_id, 'product_cat', 'taxonomy') as $ancestorId) {
                    $ancestor = get_term((int) $ancestorId, 'product_cat');

                    if ($ancestor && !is_wp_error($ancestor)) {
                        $tags[] = BTL_Helpers::slugTag('category', $ancestor->slug);
                    }
                }
                break;

            case 'category':
                $tags[] = 'header-data';
                $tags[] = "blog-category-{$term->slug}";
                break;

            case 'pa_region_shop':
                $tags[] = 'regions';
                break;

            default:
                return [];
        }

        return array_values(array_unique($tags));
    }

    private static function productCategorySlugs(int $productId): array
    {
        $terms = wp_get_post_terms($productId, 'product_cat', ['fields' => 'all']);

        if (is_wp_error($terms) || !$terms) {
            return [];
        }

        $slugs = [];

        foreach ($terms as $term) {
            $slugs[$term->slug] = true;

            foreach (get_ancestors($term->term_id, 'product_cat', 'taxonomy') as $ancestorId) {
                $ancestor = get_term((int) $ancestorId, 'product_cat');

                if ($ancestor && !is_wp_error($ancestor)) {
                    $slugs[$ancestor->slug] = true;
                }
            }
        }

        return array_keys($slugs);
    }

    private static function isFeatured(int $productId): bool
    {
        if (!function_exists('wc_get_product')) {
            return false;
        }

        $product = wc_get_product($productId);

        return $product ? (bool) $product->is_featured() : false;
    }

    private static function isInLatestWindow(WP_Post $post): bool
    {
        $cutoff = BTL_Cache::remember(self::LATEST_CUTOFF_KEY, static function () {
            global $wpdb;

            $date = $wpdb->get_var($wpdb->prepare(
                "SELECT post_date_gmt FROM {$wpdb->posts}
                 WHERE post_type = %s AND post_status = %s
                 ORDER BY post_date_gmt DESC
                 LIMIT 1 OFFSET %d",
                'product',
                'publish',
                self::LATEST_GRID_SIZE - 1
            ));

            return $date ?: '';
        }, 'btl', 300);

        if ($cutoff === '') {
            return true;
        }

        return $post->post_date_gmt >= $cutoff;
    }

    private static function bustObjectCache(int $productId, string $scope): void
    {
        if ($scope !== self::SCOPE_CONTENT) {
            wp_cache_delete("variations_{$productId}", 'btl');
        }

        if ($scope !== self::SCOPE_PRICING) {
            BTL_Cache::delete("short_notify_{$productId}");
            BTL_Cache::delete("secondary_gallery_{$productId}");
            BTL_Cache::delete("content_matrix_{$productId}");
        }
    }

    private static function resolveProductId($product): int
    {
        if (is_numeric($product)) {
            return (int) $product;
        }

        if ($product instanceof WC_Product) {
            return (int) $product->get_id();
        }

        return 0;
    }

    private static function editorialContext(): bool
    {
        if (is_admin()) {
            return true;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        return function_exists('wp_doing_cron') && wp_doing_cron();
    }
}