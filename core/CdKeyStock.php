<?php
// core/CdKeyStock.php
defined('ABSPATH') || exit;

final class BTL_CdKey_Stock
{
    private const READY_OPTION = 'btl_cdkey_stock_table_ready';
    private const AUTO_ASSIGN_STATUSES = ['processing', 'completed'];
    private const BACKFILL_LIMIT = 200;

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'btl_cdkey_stock';
    }

    public static function boot(): void
    {
        add_action('init', [self::class, 'maybe_install'], 5);
        add_action('graphql_register_types', [self::class, 'register'], 20);
        add_action('woocommerce_order_status_changed', [self::class, 'maybe_assign_on_status_change'], 20, 4);
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
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ciphertext TEXT NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'available',
            order_id BIGINT UNSIGNED NULL,
            item_id BIGINT UNSIGNED NULL,
            added_by BIGINT UNSIGNED NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY product_variation_status (product_id, variation_id, status)
        ) {$charset} ENGINE=InnoDB;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function add(int $productId, int $variationId, string $plaintext, int $staffUserId): void
    {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'product_id' => $productId,
            'variation_id' => $variationId,
            'ciphertext' => BTL_Secure_Vault::encrypt($plaintext),
            'status' => 'available',
            'added_by' => $staffUserId ?: null,
        ]);
    }

    public static function bulkAdd(int $productId, int $variationId, array $plaintextKeys, int $staffUserId): int
    {
        $count = 0;
        foreach ($plaintextKeys as $key) {
            $key = trim((string)$key);
            if ($key === '') continue;
            self::add($productId, $variationId, $key, $staffUserId);
            $count++;
        }

        if ($count > 0) {
            self::backfillPendingOrders($productId, $variationId);
        }

        return $count;
    }

    public static function availableCount(int $productId, int $variationId): int
    {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE product_id=%d AND variation_id=%d AND status='available'",
            $productId, $variationId
        ));
    }

    public static function assignNext(int $productId, int $variationId, int $orderId, int $itemId): bool
    {
        global $wpdb;
        $table = self::table();

        $wpdb->query('START TRANSACTION');

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, ciphertext FROM {$table} WHERE product_id=%d AND variation_id=%d AND status='available' ORDER BY id ASC LIMIT 1 FOR UPDATE",
            $productId, $variationId
        ));

        if (!$row) {
            $wpdb->query('COMMIT');
            return false;
        }

        $updated = $wpdb->update($table, [
            'status' => 'used',
            'order_id' => $orderId,
            'item_id' => $itemId,
            'used_at' => current_time('mysql', true),
        ], ['id' => $row->id]);

        $wpdb->query('COMMIT');

        if (!$updated) {
            return false;
        }

        $plaintext = BTL_Secure_Vault::decrypt($row->ciphertext);
        if ($plaintext === null) {
            BTL_Helpers::logger("CdKeyStock: decrypt failed for stock row {$row->id}");
            return false;
        }

        BTL_Secure_Fields::store($orderId, $itemId, 'cdkey', $plaintext);

        return true;
    }

    public static function maybe_assign_on_status_change($orderId, $oldStatus, $newStatus, $order): void
    {
        if (!in_array($newStatus, self::AUTO_ASSIGN_STATUSES, true)) return;
        if (!$order instanceof WC_Order) return;

        foreach ($order->get_items() as $itemId => $item) {
            if (!$item instanceof WC_Order_Item_Product) continue;
            if ($item->get_meta('روش تحویل') !== 'code') continue;

            $needed = max(1, (int)$item->get_quantity());
            $already = BTL_Secure_Fields::countByOrderItem((int)$orderId, (int)$itemId, 'cdkey');
            $remaining = $needed - $already;

            if ($remaining <= 0) continue;

            $productId = $item->get_product_id();
            $variationId = $item->get_variation_id() ?: 0;

            $assignedThisRun = 0;
            for ($i = 0; $i < $remaining; $i++) {
                if (self::assignNext($productId, $variationId, (int)$orderId, (int)$itemId)) {
                    $assignedThisRun++;
                    continue;
                }

                $totalAssigned = $already + $assignedThisRun;

                BTL_Helpers::logger(
                    "CdKeyStock: insufficient stock for product {$productId} variation {$variationId} " .
                    "(order {$orderId}, item {$itemId}) — assigned {$totalAssigned} of {$needed} needed"
                );

                $order->add_order_note(sprintf(
                    '⚠️ موجودی کد سی‌دی‌کی کافی نیست — آیتم #%d: %d از %d کد تخصیص یافت. با افزودن موجودی جدید این سفارش خودکار تکمیل می‌شود.',
                    $itemId,
                    $totalAssigned,
                    $needed
                ));

                self::notify_staff_low_stock($orderId, $itemId, $item->get_name(), $totalAssigned, $needed);

                break;
            }
        }
    }

    public static function backfillPendingOrders(int $productId, int $variationId): void
    {
        global $wpdb;

        $itemIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT oi.order_item_id
             FROM {$wpdb->prefix}woocommerce_order_items oi
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid ON oim_pid.order_item_id = oi.order_item_id AND oim_pid.meta_key = '_product_id' AND oim_pid.meta_value = %d
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_vid ON oim_vid.order_item_id = oi.order_item_id AND oim_vid.meta_key = '_variation_id' AND oim_vid.meta_value = %d
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_delivery ON oim_delivery.order_item_id = oi.order_item_id AND oim_delivery.meta_key = 'روش تحویل' AND oim_delivery.meta_value = 'code'
             INNER JOIN {$wpdb->posts} p ON p.ID = oi.order_id
             WHERE p.post_status IN ('wc-processing', 'wc-completed')
             ORDER BY oi.order_item_id ASC
             LIMIT %d",
            $productId,
            $variationId,
            self::BACKFILL_LIMIT
        ));

        if (!$itemIds) {
            return;
        }

        foreach ($itemIds as $rawItemId) {
            $itemId = (int)$rawItemId;
            $orderItem = WC_Order_Factory::get_order_item($itemId);
            if (!$orderItem) continue;

            $orderId = (int)$orderItem->get_order_id();
            $needed = max(1, (int)$orderItem->get_quantity());
            $already = BTL_Secure_Fields::countByOrderItem($orderId, $itemId, 'cdkey');
            $remaining = $needed - $already;

            if ($remaining <= 0) continue;

            $filledNow = 0;
            for ($i = 0; $i < $remaining; $i++) {
                if (!self::assignNext($productId, $variationId, $orderId, $itemId)) {
                    break;
                }
                $filledNow++;
            }

            if ($filledNow === 0) {
                continue;
            }

            $nowAssigned = $already + $filledNow;
            $order = wc_get_order($orderId);

            if ($nowAssigned >= $needed) {
                if ($order) {
                    $order->add_order_note('✅ کد سی‌دی‌کی به‌صورت خودکار پس از افزودن موجودی جدید تخصیص یافت.');
                }

                $customerId = $order ? (int)$order->get_customer_id() : 0;
                if ($customerId) {
                    BTL_Notifications::push(
                        $customerId,
                        'کد سفارش شما آماده شد ✅',
                        'کد سی‌دی‌کی سفارش شما تخصیص یافت و اکنون در پیشخوان قابل مشاهده است.',
                        '/my-account/orders',
                        'order'
                    );
                }
            }
        }
    }

    private static function notify_staff_low_stock(int $orderId, int $itemId, string $productName, int $assigned, int $needed): void
    {
        $editUrl = admin_url('post.php?post=' . $orderId . '&action=edit');

        foreach (self::staffUserIds() as $staffId) {
            BTL_Notifications::push(
                (int)$staffId,
                'کمبود موجودی کد سی‌دی‌کی ⚠️',
                sprintf('سفارش #%d — «%s»: فقط %d از %d کد موجود بود.', $orderId, $productName, $assigned, $needed),
                $editUrl,
                'order'
            );
        }
    }

    private static function staffUserIds(): array
    {
        return get_users(['capability' => 'manage_woocommerce', 'fields' => 'ID']);
    }

    public static function register(): void
    {
        register_graphql_field('OptimizedVariationItem', 'codeStockCount', [
            'type' => 'Int',
            'resolve' => static function ($variation) {
                $variationId = (int)($variation['databaseId'] ?? 0);
                if (!$variationId) return 0;

                $product = wc_get_product($variationId);
                if (!$product) return 0;

                $productId = $product->is_type('variation') ? $product->get_parent_id() : $variationId;

                return BTL_CdKey_Stock::availableCount($productId, $variationId);
            },
        ]);

        register_graphql_field('LineItem', 'cdkeyReady', [
            'type' => 'Boolean',
            'description' => 'true فقط وقتی تمام کدهای مورد نیاز (به تعداد quantity) تخصیص یافته باشند.',
            'resolve' => static function ($item) {
                $itemId = (int)($item->databaseId ?? 0);
                if (!$itemId) return false;

                $orderItem = WC_Order_Factory::get_order_item($itemId);
                if (!$orderItem) return false;

                $orderId = (int)$orderItem->get_order_id();
                $needed = max(1, (int)$orderItem->get_quantity());

                return BTL_Secure_Fields::countByOrderItem($orderId, $itemId, 'cdkey') >= $needed;
            },
        ]);

        register_graphql_field('LineItem', 'cdkeyAssignedCount', [
            'type' => 'Int',
            'description' => 'تعداد کدهایی که تا این لحظه برای این آیتم تخصیص یافته‌اند (ممکن است کمتر از quantity باشد).',
            'resolve' => static function ($item) {
                $itemId = (int)($item->databaseId ?? 0);
                if (!$itemId) return 0;

                $orderItem = WC_Order_Factory::get_order_item($itemId);
                if (!$orderItem) return 0;

                $orderId = (int)$orderItem->get_order_id();

                return BTL_Secure_Fields::countByOrderItem($orderId, $itemId, 'cdkey');
            },
        ]);
    }
}