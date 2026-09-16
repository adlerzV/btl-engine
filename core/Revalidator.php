<?php
defined('ABSPATH') || exit;

final class BTL_Revalidator
{
    private const GROUP = 'btl';
    private const CACHE_KEY = 'btl_revalidate_tags';
    private const LOCK_KEY = 'btl_revalidate_lock';
    private const RETRY_KEY = 'btl_revalidate_retries';
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY = 60;
    private const MAX_TAGS = 1000;

    private static bool $flushScheduledThisRequest = false;

    public static function boot(): void
    {
        add_action(
            'btl_revalidate_flush',
            [self::class, 'flush'],
            10
        );
    }

    public static function queue(
        array $tags
    ): void {
        if (!$tags) {
            return;
        }

        $current = get_transient(
            self::CACHE_KEY
        );

        if (!is_array($current)) {
            $current = [];
        }

        $merged = array_values(
            array_unique(
                array_merge(
                    $current,
                    $tags
                )
            )
        );

        set_transient(
            self::CACHE_KEY,
            $merged,
            600
        );

        if (self::$flushScheduledThisRequest) {
            return;
        }

        self::$flushScheduledThisRequest = true;

        self::schedule_flush(15);
    }

    private static function schedule_flush(
        int $delay,
        bool $force = false
    ): void {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }

        if (
            !$force &&
            function_exists('as_has_scheduled_action') &&
            as_has_scheduled_action(
                'btl_revalidate_flush',
                [],
                self::GROUP
            )
        ) {
            return;
        }

        as_schedule_single_action(
            time() + $delay,
            'btl_revalidate_flush',
            [],
            self::GROUP
        );
    }

    public static function flush(): void
    {
        if (
            get_transient(
                self::LOCK_KEY
            )
        ) {
            return;
        }

        set_transient(
            self::LOCK_KEY,
            1,
            30
        );

        try {
            $tags = get_transient(
                self::CACHE_KEY
            );

            if (
                !is_array($tags) ||
                empty($tags)
            ) {
                return;
            }

            delete_transient(
                self::CACHE_KEY
            );

            $payload = array_slice(
                array_values(
                    array_unique($tags)
                ),
                0,
                self::MAX_TAGS
            );

            if (self::send($payload)) {
                delete_transient(self::RETRY_KEY);
                return;
            }

            $retries = (int) get_transient(self::RETRY_KEY);

            if ($retries + 1 >= self::MAX_RETRIES) {
                delete_transient(self::RETRY_KEY);

                BTL_Helpers::logger(
                    'Revalidator: giving up after ' .
                    self::MAX_RETRIES .
                    ' attempts — dropped tags: ' .
                    wp_json_encode($payload)
                );

                return;
            }

            set_transient(
                self::RETRY_KEY,
                $retries + 1,
                600
            );

            self::queue($payload);
            self::schedule_flush(self::RETRY_DELAY, true);

        } finally {
            delete_transient(
                self::LOCK_KEY
            );
        }
    }

    private static function send(
        array $tags
    ): bool {
        if (!$tags) {
            return true;
        }

        $endpoint = defined('NEXTJS_API_URL')
            ? NEXTJS_API_URL
            : '';

        $secret = defined('NEXTJS_REVALIDATE_SECRET')
            ? NEXTJS_REVALIDATE_SECRET
            : '';

        if (!$endpoint || !$secret) {
            return true;
        }

        $response = wp_remote_post(
            $endpoint,
            [
                'timeout' => 12,
                'blocking' => true,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-revalidate-secret' => $secret,
                ],
                'body' => wp_json_encode(
                    [
                        'tag' => $tags,
                    ]
                ),
            ]
        );

        if (is_wp_error($response)) {
            BTL_Helpers::logger(
                'Revalidator: transport error — ' .
                $response->get_error_message()
            );

            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code < 200 || $code >= 300) {
            BTL_Helpers::logger(
                'Revalidator: HTTP ' . $code . ' — ' .
                wp_remote_retrieve_body($response)
            );

            return false;
        }

        return true;
    }
}

function btl_queue_revalidation(
    array $tags
): void {
    BTL_Revalidator::queue(
        $tags
    );
}