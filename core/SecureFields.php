<?php
defined('ABSPATH') || exit;

final class BTL_Secure_Fields
{
    private const CDKEY_TYPE = 'cdkey';
    private const CREDENTIAL_TYPES = ['email', 'password', 'battletag'];
    private const READY_OPTION = 'btl_secure_fields_table_ready_v2';
    private const ACTIVE_STATUS = 'active';

    public static function boot(): void { add_action('init', [self::class, 'maybe_install'], 5); }
    public static function maybe_install(): void { BTL_Helpers::ensureTable(self::READY_OPTION, [self::class, 'install']); }
    public static function table(): string { global $wpdb; return $wpdb->prefix . 'btl_secure_fields'; }

    public static function install(): void
    {
        global $wpdb;
        $sql = "CREATE TABLE " . self::table() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            field_type VARCHAR(20) NOT NULL,
            source_key VARCHAR(64) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            value_fingerprint VARBINARY(32) NULL,
            ciphertext TEXT NOT NULL,
            revealed_at DATETIME NULL,
            revealed_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY item_id (item_id),
            UNIQUE KEY source_identity (order_id, item_id, field_type, source_key),
            UNIQUE KEY cdkey_value (value_fingerprint)
        ) " . $wpdb->get_charset_collate() . ' ENGINE=InnoDB;';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function store(int $orderId, int $itemId, string $fieldType, string $plaintext, string $sourceKey = ''): bool
    {
        if (!in_array($fieldType, array_merge([self::CDKEY_TYPE], self::CREDENTIAL_TYPES), true) || $orderId < 1 || $itemId < 1 || $plaintext === '') return false;
        global $wpdb;
        $sourceKey = $sourceKey !== '' ? substr($sourceKey, 0, 64) : ($fieldType === self::CDKEY_TYPE ? hash('sha256', $plaintext) : $fieldType);

        try {
            $ciphertext = BTL_Secure_Vault::encrypt($plaintext);
            $fingerprint = $fieldType === self::CDKEY_TYPE ? BTL_Secure_Vault::fingerprint($plaintext) : null;
        } catch (Throwable $e) {
            BTL_Helpers::logger("SecureFields: encryption failed for order {$orderId}, item {$itemId}");
            return false;
        }

        if ($fieldType === self::CDKEY_TYPE) {
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO " . self::table() . " (order_id,item_id,field_type,source_key,status,value_fingerprint,ciphertext,created_at)
                 VALUES (%d,%d,%s,%s,%s,%s,%s,%s)",
                $orderId, $itemId, $fieldType, $sourceKey, self::ACTIVE_STATUS, $fingerprint, $ciphertext, current_time('mysql', true)
            ));
            if ($inserted === false) return false;
            if ((int)$inserted === 1) return true;

            return (bool)$wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM " . self::table() . "
                 WHERE order_id=%d AND item_id=%d AND field_type=%s AND source_key=%s AND value_fingerprint=%s AND status=%s LIMIT 1",
                $orderId, $itemId, $fieldType, $sourceKey, $fingerprint, self::ACTIVE_STATUS
            ));
        }

        $sql = $wpdb->prepare(
            "INSERT INTO " . self::table() . " (order_id,item_id,field_type,source_key,status,ciphertext,created_at)
             VALUES (%d,%d,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE ciphertext=VALUES(ciphertext), status=VALUES(status)",
            $orderId, $itemId, $fieldType, $sourceKey, self::ACTIVE_STATUS, $ciphertext, current_time('mysql', true)
        );
        return $wpdb->query($sql) !== false;
    }

    public static function exists(int $orderId, int $itemId, string $fieldType): bool
    {
        return self::countByOrderItem($orderId, $itemId, $fieldType) > 0;
    }

    public static function existsSource(int $orderId, int $itemId, string $fieldType, string $sourceKey): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM " . self::table() . " WHERE order_id=%d AND item_id=%d AND field_type=%s AND source_key=%s AND status=%s LIMIT 1",
            $orderId, $itemId, $fieldType, $sourceKey, self::ACTIVE_STATUS
        ));
    }

    public static function countByOrderItem(int $orderId, int $itemId, string $fieldType): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE order_id=%d AND item_id=%d AND field_type=%s AND status=%s",
            $orderId, $itemId, $fieldType, self::ACTIVE_STATUS
        ));
    }

    public static function revealAllForCustomerCdKey(int $orderId, int $itemId, int $userId): array
    {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE order_id=%d AND item_id=%d AND field_type=%s AND status=%s ORDER BY id ASC",
            $orderId, $itemId, self::CDKEY_TYPE, self::ACTIVE_STATUS
        ));
        $values = [];
        foreach ($rows ?: [] as $row) {
            $plain = BTL_Secure_Vault::decrypt((string) $row->ciphertext);
            if ($plain === null) { BTL_Helpers::logger("SecureFields: decrypt failed for row {$row->id}"); continue; }
            $values[] = $plain;
            if ($row->revealed_at === null) $wpdb->update($table, ['revealed_at' => current_time('mysql', true), 'revealed_by' => $userId], ['id' => $row->id]);
        }
        return $values;
    }

    public static function revealForStaff(int $orderId, int $itemId, string $fieldType, int $staffUserId): ?string
    {
        if (!in_array($fieldType, self::CREDENTIAL_TYPES, true)) return null;
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE order_id=%d AND item_id=%d AND field_type=%s AND status=%s ORDER BY id DESC LIMIT 1",
            $orderId, $itemId, $fieldType, self::ACTIVE_STATUS
        ));
        if (!$row) return null;
        $plain = BTL_Secure_Vault::decrypt((string) $row->ciphertext);
        if ($plain === null) return null;
        $wpdb->update($table, ['revealed_at' => current_time('mysql', true), 'revealed_by' => $staffUserId], ['id' => $row->id]);
        return $plain;
    }

    public static function deleteByOrder(int $orderId): int
    {
        global $wpdb;
        return (int) $wpdb->delete(self::table(), ['order_id' => $orderId], ['%d']);
    }

    public static function wipeCredentialsByOrder(int $orderId): int
    {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count(self::CREDENTIAL_TYPES), '%s'));
        return (int) $wpdb->query($wpdb->prepare("DELETE FROM " . self::table() . " WHERE order_id=%d AND field_type IN ({$placeholders})", array_merge([$orderId], self::CREDENTIAL_TYPES)));
    }
}