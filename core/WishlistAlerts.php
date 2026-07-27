<?php
defined('ABSPATH') || exit;

final class BTL_Wishlist_Alerts
{
    private const READY_OPTION = 'btl_wishlist_snapshots_table_ready';

    public static function table(): string { global $wpdb; return $wpdb->prefix . 'btl_wishlist_snapshots'; }

    public static function boot(): void
    {
        add_action('init', [self::class, 'maybe_install'], 5);
        add_action('btl_price_dropped', [self::class, 'notify_watchers'], 10, 3);
    }

    public static function maybe_install(): void
    {
        BTL_Helpers::ensureTable(self::READY_OPTION, [self::class, 'install']);
    }

    public static function install(): void
    {
        global $wpdb;
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            price DECIMAL(15,2) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY user_product (user_id, product_id),
            KEY product_id (product_id)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function snapshot_on_add(int $userId, int $productId): void
    {
        $product = wc_get_product($productId);
        if (!$product) return;

        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::table() . " (user_id, product_id, price) VALUES (%d, %d, %f)
             ON DUPLICATE KEY UPDATE price=VALUES(price), created_at=CURRENT_TIMESTAMP",
            $userId, $productId, (float)$product->get_price()
        ));
    }

    public static function clear_on_remove(int $userId, int $productId): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['user_id' => $userId, 'product_id' => $productId]);
    }

    public static function notify_watchers(int $variationOrProductId, float $oldPrice, float $newPrice): void
    {
        $product = wc_get_product($variationOrProductId);
        if (!$product) return;

        $parentId = $product->is_type('variation') ? $product->get_parent_id() : $variationOrProductId;

        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE product_id=%d AND price > %f",
            $parentId, $newPrice
        ));

        if (!$rows) return;

        $productObj = wc_get_product($parentId);
        $name = $productObj ? $productObj->get_name() : 'محصول';

        foreach ($rows as $row) {
            BTL_Notifications::push(
                (int)$row->user_id,
                'افت قیمت در لیست علاقه‌مندی‌ها 🔻',
                sprintf('قیمت «%s» کاهش یافت. همین حالا بررسی کنید.', $name),
                '/my-account/wishlist'
            );
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET price=%f WHERE product_id=%d AND price > %f",
            $newPrice, $parentId, $newPrice
        ));
    }
}