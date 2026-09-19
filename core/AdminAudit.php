<?php
defined('ABSPATH') || exit;

final class BTL_Admin_Audit
{
    private const READY_OPTION = 'btl_admin_audit_table_ready_v1';

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'btl_admin_audit';
    }

    public static function boot(): void
    {
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
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_user_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(80) NULL,
            entity_id BIGINT UNSIGNED NULL,
            result VARCHAR(32) NOT NULL DEFAULT 'success',
            metadata LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY admin_user_id (admin_user_id),
            KEY action (action),
            KEY entity_lookup (entity_type, entity_id),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function record(
        int $adminUserId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        string $result = 'success',
        array $metadata = []
    ): bool {
        global $wpdb;

        if ($adminUserId < 1 || trim($action) === '') {
            return false;
        }

        $encodedMetadata = wp_json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $inserted = $wpdb->insert(
            self::table(),
            [
                'admin_user_id' => $adminUserId,
                'action' => sanitize_key($action),
                'entity_type' => $entityType !== null ? sanitize_key($entityType) : null,
                'entity_id' => $entityId,
                'result' => sanitize_key($result) ?: 'success',
                'metadata' => $encodedMetadata !== false ? $encodedMetadata : null,
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        return $inserted !== false;
    }

    public static function recent(int $limit = 50): array
    {
        global $wpdb;

        $limit = min(max($limit, 1), 200);
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, admin_user_id, action, entity_type, entity_id, result, metadata, created_at
                 FROM " . self::table() . "
                 ORDER BY id DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }
}
