<?php
defined('ABSPATH') || exit;

final class BTL_Admin_Customers
{
    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 12);
    }

    public static function register(): void
    {
        if (!btl_is_admin_graphql_request()) return;
        register_graphql_object_type('BtlAdminCustomer', [
            'fields' => [
                'databaseId' => ['type' => 'Int'],
                'name' => ['type' => 'String'],
                'email' => ['type' => 'String'],
                'registeredAt' => ['type' => 'String'],
                'isStaff' => ['type' => 'Boolean'],
                'ordersCount' => ['type' => 'Int'],
                'ticketsCount' => ['type' => 'Int'],
                'reviewsCount' => ['type' => 'Int'],
            ],
        ]);
        register_graphql_object_type('BtlAdminCustomerConnection', [
            'fields' => [
                'nodes' => ['type' => ['list_of' => 'BtlAdminCustomer']],
                'pageInfo' => ['type' => 'BtlCursorPageInfo'],
            ],
        ]);
        register_graphql_object_type('BtlAdminCustomerDetail', [
            'fields' => [
                'customer' => ['type' => 'BtlAdminCustomer'],
                'orders' => ['type' => ['list_of' => 'BtlAdminOrder']],
                'tickets' => ['type' => ['list_of' => 'BtlAdminTicket']],
                'reviews' => ['type' => ['list_of' => 'MyReviewItem']],
            ],
        ]);

        register_graphql_field('RootQuery', 'adminCustomers', [
            'type' => 'BtlAdminCustomerConnection',
            'args' => [
                'first' => ['type' => 'Int'],
                'after' => ['type' => 'String'],
                'search' => ['type' => 'String'],
            ],
            'resolve' => static function ($root, array $args): array {
                self::assertPermission('users.read');
                $first = min(max((int)($args['first'] ?? 20), 1), 50);
                $offset = BTL_Customer_Tickets::decodeCursor($args['after'] ?? null);
                $search = sanitize_text_field((string)($args['search'] ?? ''));
                $query = new WP_User_Query([
                    'number' => $first + 1,
                    'offset' => $offset,
                    'orderby' => 'registered',
                    'order' => 'DESC',
                    'search' => $search !== '' ? '*' . $search . '*' : '',
                    'search_columns' => ['user_login', 'user_email', 'display_name'],
                ]);
                $users = $query->get_results();
                $hasNext = count($users) > $first;
                if ($hasNext) $users = array_slice($users, 0, $first);
                return [
                    'nodes' => self::customerPayloads($users),
                    'pageInfo' => [
                        'hasNextPage' => $hasNext,
                        'endCursor' => BTL_Customer_Tickets::encodeCursor($offset + count($users)),
                    ],
                ];
            },
        ]);

        register_graphql_field('RootQuery', 'adminCustomer', [
            'type' => 'BtlAdminCustomerDetail',
            'args' => ['id' => ['type' => ['non_null' => 'Int']]],
            'resolve' => static function ($root, array $args): ?array {
                self::assertPermission('users.read');
                $user = get_userdata((int)$args['id']);
                if (!$user) return null;

                $orders = wc_get_orders(['customer_id' => (int)$user->ID, 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects']);
                $tickets = (new WP_Query([
                    'post_type' => 'support_ticket',
                    'post_status' => 'publish',
                    'posts_per_page' => 10,
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'meta_query' => [[ 'key' => 'customer_id', 'value' => (int)$user->ID, 'compare' => '=' ]],
                ]))->posts;
                $reviews = get_comments([
                    'user_id' => (int)$user->ID,
                    'type' => 'review',
                    'status' => 'all',
                    'parent' => 0,
                    'number' => 10,
                    'orderby' => 'comment_date',
                    'order' => 'DESC',
                ]);

                $orderIds = array_map(static fn(WC_Order $order): int => (int)$order->get_id(), $orders);
                $cdkeyCountsByOrder = BTL_Secure_Fields::countsByOrders($orderIds, 'cdkey');

                return [
                    'customer' => self::customerPayloads([$user])[0],
                    'orders' => array_map(static function (WC_Order $order) use ($cdkeyCountsByOrder): array {
                        return BTL_Admin_Orders::payloadForExternal($order, $cdkeyCountsByOrder[(int)$order->get_id()] ?? []);
                    }, $orders),
                    'tickets' => array_map(static fn($post) => BTL_Admin_Tickets::payloadForExternal($post), $tickets),
                    'reviews' => array_map([self::class, 'reviewPayload'], $reviews ?: []),
                ];
            },
        ]);
    }

    public static function payloadForExternal(WP_User $user): array
    {
        return self::customerPayloads([$user])[0];
    }

    /** @param WP_User[] $users */
    private static function customerPayloads(array $users): array
    {
        $ids = array_values(array_filter(array_map(static fn(WP_User $user): int => (int)$user->ID, $users)));
        if (!$ids) return [];
        $orderCounts = self::countOrders($ids);
        $ticketCounts = self::countTickets($ids);
        $reviewCounts = self::countReviews($ids);
        return array_map(static function (WP_User $user) use ($orderCounts, $ticketCounts, $reviewCounts): array {
            $id = (int)$user->ID;
            return [
                'databaseId' => $id,
                'name' => (string)$user->display_name,
                'email' => (string)$user->user_email,
                'registeredAt' => gmdate(DATE_ATOM, strtotime((string)$user->user_registered)),
                'isStaff' => user_can($id, 'manage_woocommerce'),
                'ordersCount' => (int)($orderCounts[$id] ?? 0),
                'ticketsCount' => (int)($ticketCounts[$id] ?? 0),
                'reviewsCount' => (int)($reviewCounts[$id] ?? 0),
            ];
        }, $users);
    }

    /** @param int[] $ids */
    private static function countOrders(array $ids): array
    {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $table = $wpdb->prefix . 'wc_orders';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if ($exists) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT customer_id, COUNT(*) AS total FROM {$table} WHERE type=%s AND customer_id IN ({$placeholders}) GROUP BY customer_id", ...array_merge(['shop_order'], $ids)), ARRAY_A) ?: [];
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT pm.meta_value AS customer_id, COUNT(DISTINCT pm.post_id) AS total FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type=%s AND pm.meta_key=%s AND CAST(pm.meta_value AS UNSIGNED) IN ({$placeholders}) GROUP BY pm.meta_value", ...array_merge(['shop_order', '_customer_user'], $ids)), ARRAY_A) ?: [];
        }
        $map = [];
        foreach ($rows as $row) $map[(int)$row['customer_id']] = (int)$row['total'];
        return $map;
    }

    /** @param int[] $ids */
    private static function countTickets(array $ids): array
    {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT CAST(pm.meta_value AS UNSIGNED) AS customer_id, COUNT(DISTINCT pm.post_id) AS total FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type=%s AND p.post_status=%s AND pm.meta_key=%s AND CAST(pm.meta_value AS UNSIGNED) IN ({$placeholders}) GROUP BY pm.meta_value", ...array_merge(['support_ticket', 'publish', 'customer_id'], $ids)), ARRAY_A) ?: [];
        $map = [];
        foreach ($rows as $row) $map[(int)$row['customer_id']] = (int)$row['total'];
        return $map;
    }

    /** @param int[] $ids */
    private static function countReviews(array $ids): array
    {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT user_id, COUNT(*) AS total FROM {$wpdb->comments} WHERE comment_type=%s AND comment_parent=0 AND user_id IN ({$placeholders}) GROUP BY user_id", ...array_merge(['review'], $ids)), ARRAY_A) ?: [];
        $map = [];
        foreach ($rows as $row) $map[(int)$row['user_id']] = (int)$row['total'];
        return $map;
    }

    private static function reviewPayload(WP_Comment $comment): array
    {
        $product = wc_get_product((int)$comment->comment_post_ID);
        return [
            'databaseId' => (int)$comment->comment_ID,
            'content' => (string)$comment->comment_content,
            'rating' => (int)get_comment_meta($comment->comment_ID, 'rating', true),
            'date' => (string)$comment->comment_date_gmt,
            'approved' => (string)$comment->comment_approved === '1',
            'productId' => (int)$comment->comment_post_ID,
            'productName' => $product ? $product->get_name() : null,
            'productSlug' => $product ? $product->get_slug() : null,
            'replies' => [],
        ];
    }

    private static function assertPermission(string $permission): void
    {
        if (!BTL_Admin_Permissions::can(get_current_user_id(), $permission)) throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
    }
}
