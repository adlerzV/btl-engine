<?php
defined('ABSPATH') || exit;

use GraphQL\Error\UserError;

final class BTL_Sessions
{
    private const READY_OPTION = 'btl_sessions_table_ready';
    private const SESSION_INACTIVITY_DAYS = 30;

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'btl_sessions';
    }

    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 10);
        add_action('init', [self::class, 'maybe_install'], 5);
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
            session_id VARCHAR(64) NOT NULL,
            device_label VARCHAR(190) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            last_active DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY user_session (user_id, session_id),
            KEY user_id (user_id),
            KEY last_active (last_active)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function register(): void
    {
        register_graphql_object_type('UserSession', [
            'fields' => [
                'sessionId' => ['type' => 'String', 'resolve' => fn($s) => $s['session_id']],
                'deviceLabel' => ['type' => 'String', 'resolve' => fn($s) => $s['device_label']],
                'ipAddress' => ['type' => 'String', 'resolve' => fn($s) => $s['ip_address']],
                'lastActive' => ['type' => 'String', 'resolve' => fn($s) => $s['last_active']],
                'createdAt' => ['type' => 'String', 'resolve' => fn($s) => $s['created_at']],
            ],
        ]);

        register_graphql_field('User', 'sessions', [
            'type' => ['list_of' => 'UserSession'],
            'resolve' => static function ($user) {
                $currentUserId = get_current_user_id();
                if (!$currentUserId || $currentUserId !== (int)$user->databaseId) return [];
                return self::listSessions($currentUserId);
            },
        ]);

        register_graphql_field('User', 'activeSessionValid', [
            'type' => 'Boolean',
            'args' => ['sessionId' => ['type' => 'String']],
            'resolve' => static function ($user, $args) {
                $currentUserId = get_current_user_id();
                if (!$currentUserId || $currentUserId !== (int)$user->databaseId) return false;
                if (empty($args['sessionId'])) return true;
                return self::isValid($currentUserId, (string)$args['sessionId']);
            },
        ]);

        register_graphql_mutation('registerSession', [
            'inputFields' => [
                'sessionId' => ['type' => ['non_null' => 'String']],
                'deviceLabel' => ['type' => 'String'],
                'ipAddress' => ['type' => 'String'],
                'userAgent' => ['type' => 'String'],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'isStaff' => [
                    'type' => 'Boolean',
                    'resolve' => static function () {
                        $userId = get_current_user_id();
                        return $userId ? user_can($userId, 'manage_woocommerce') : false;
                    },
                ],
            ],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) throw new UserError('باید وارد شوید.');
                try {
                    self::upsert(get_current_user_id(), $input);
                    return ['success' => true];
                } catch (\Throwable $e) {
                    BTL_Helpers::logger('registerSession error: ' . $e->getMessage());
                    throw new UserError('خطا در ثبت نشست کاربری.');
                }
            },
        ]);

        register_graphql_mutation('touchSession', [
            'inputFields' => ['sessionId' => ['type' => ['non_null' => 'String']]],
            'outputFields' => ['success' => ['type' => 'Boolean']],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) throw new UserError('باید وارد شوید.');
                try {
                    self::touch(get_current_user_id(), (string)$input['sessionId']);
                    return ['success' => true];
                } catch (\Throwable $e) {
                    return ['success' => false];
                }
            },
        ]);

        register_graphql_mutation('revokeSession', [
            'inputFields' => ['sessionId' => ['type' => ['non_null' => 'String']]],
            'outputFields' => ['success' => ['type' => 'Boolean']],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) throw new UserError('باید وارد شوید.');
                try {
                    self::revoke(get_current_user_id(), (string)$input['sessionId']);
                    return ['success' => true];
                } catch (\Throwable $e) {
                    BTL_Helpers::logger('revokeSession error: ' . $e->getMessage());
                    throw new UserError('خطا در لغو نشست.');
                }
            },
        ]);
    }

    public static function upsert(int $userId, array $input): void
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $sessionId = sanitize_text_field((string)$input['sessionId']);
        $deviceLabel = isset($input['deviceLabel']) ? mb_substr(sanitize_text_field((string)$input['deviceLabel']), 0, 190) : null;
        $ipAddress = isset($input['ipAddress']) ? mb_substr(sanitize_text_field((string)$input['ipAddress']), 0, 45) : null;
        $userAgent = isset($input['userAgent']) ? mb_substr(sanitize_text_field((string)$input['userAgent']), 0, 255) : null;

        $table = self::table();
        $sql = "INSERT INTO {$table}
            (user_id, session_id, device_label, ip_address, user_agent, revoked, last_active, created_at)
            VALUES (%d, %s, %s, %s, %s, 0, %s, %s)
            ON DUPLICATE KEY UPDATE
                device_label = VALUES(device_label),
                ip_address = VALUES(ip_address),
                user_agent = VALUES(user_agent),
                revoked = 0,
                last_active = VALUES(last_active)";

        $wpdb->query($wpdb->prepare(
            $sql,
            $userId,
            $sessionId,
            $deviceLabel,
            $ipAddress,
            $userAgent,
            $now,
            $now
        ));
    }

    public static function touch(int $userId, string $sessionId): void
    {
        global $wpdb;
        $table = self::table();
        $now = current_time('mysql', true);

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} 
             SET last_active = %s 
             WHERE user_id = %d 
               AND session_id = %s 
               AND revoked = 0 
               AND last_active < DATE_SUB(%s, INTERVAL 5 MINUTE)",
            $now,
            $userId,
            $sessionId,
            $now
        ));
    }

    public static function revoke(int $userId, string $sessionId): void
    {
        global $wpdb;
        $wpdb->update(
            self::table(),
            ['revoked' => 1],
            ['user_id' => $userId, 'session_id' => $sessionId],
            ['%d'],
            ['%d', '%s']
        );
    }

    public static function revokeAll(int $userId): void
    {
        global $wpdb;
        $wpdb->update(
            self::table(),
            ['revoked' => 1],
            ['user_id' => $userId],
            ['%d'],
            ['%d']
        );
    }

    public static function revokeAllExcept(int $userId, ?string $exceptSessionId): void
    {
        if (!$exceptSessionId) {
            self::revokeAll($userId);
            return;
        }

        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET revoked=1 WHERE user_id=%d AND session_id != %s",
            $userId,
            $exceptSessionId
        ));
    }

    public static function isValid(int $userId, string $sessionId): bool
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT revoked FROM " . self::table() . " WHERE user_id=%d AND session_id=%s",
            $userId,
            $sessionId
        ));

        if ($wpdb->last_error) {
            BTL_Helpers::logger('Sessions::isValid DB error: ' . $wpdb->last_error);
            return true;
        }

        if (!$row) {
            return true;
        }

        return (int)$row->revoked === 0;
    }

    public static function listSessions(int $userId): array
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $days = self::SESSION_INACTIVITY_DAYS;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " 
             WHERE user_id = %d 
               AND revoked = 0 
               AND last_active >= DATE_SUB(%s, INTERVAL %d DAY) 
             ORDER BY last_active DESC",
            $userId,
            $now,
            $days
        ), ARRAY_A);
    }
}