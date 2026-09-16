<?php
defined('ABSPATH') || exit;

final class BTL_Revalidator
{
    private const GROUP = 'btl';
    private const CACHE_KEY = 'btl_revalidate_tags';
    private const LOCK_KEY = 'btl_revalidate_lock';
    private const RETRY_KEY = 'btl_revalidate_retries';

    private const QUEUE_TTL = 3600;
    private const BATCH_SIZE = 1000;
    private const MAX_QUEUE_SIZE = 20000;
    private const MAX_RETRIES = 3;
    private const FLUSH_DELAY = 15;
    private const NEXT_BATCH_DELAY = 5;
    private const RETRY_DELAY = 60;

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
        if (!self::enqueue($tags)) {
            return;
        }

        if (self::$flushScheduledThisRequest) {
            return;
        }

        self::$flushScheduledThisRequest = true;

        self::schedule_flush(self::FLUSH_DELAY);
    }

    private static function enqueue(
        array $tags,
        bool $prepend = false
    ): bool {
        $tags = array_values(
            array_filter(
                array_map('strval', $tags),
                static function ($tag) {
                    return $tag !== '';
                }
            )
        );

        if (!$tags) {
            return false;
        }

        $current = get_transient(self::CACHE_KEY);

        if (!is_array($current)) {
            $current = [];
        }

        $merged = $prepend
            ? array_merge($tags, $current)
            : array_merge($current, $tags);

        $merged = array_values(array_unique($merged));

        if (count($merged) > self::MAX_QUEUE_SIZE) {
            BTL_Helpers::logger(
                'Revalidator: queue overflow (' . count($merged) .
                ') — collapsing to catalog-wide tags'
            );

            $merged = [
                'products',
                'home-featured',
                'home-latest',
                'banners',
                'header-data',
            ];
        }

        set_transient(
            self::CACHE_KEY,
            $merged,
            self::QUEUE_TTL
        );

        return true;
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
            60
        );

        $reschedule_in = 0;

        try {
            if (!self::is_configured()) {
                BTL_Helpers::logger(
                    'Revalidator: NEXTJS_API_URL یا NEXTJS_REVALIDATE_SECRET تعریف نشده — تگ‌ها در صف نگه داشته شدند.'
                );

                return;
            }

            $queued = get_transient(self::CACHE_KEY);

            if (!is_array($queued) || !$queued) {
                return;
            }

            $queued = array_values(array_unique($queued));

            $batch = array_slice($queued, 0, self::BATCH_SIZE);
            $remaining = array_slice($queued, self::BATCH_SIZE);

            if ($remaining) {
                set_transient(
                    self::CACHE_KEY,
                    $remaining,
                    self::QUEUE_TTL
                );
            } else {
                delete_transient(self::CACHE_KEY);
            }

            if (self::send($batch)) {
                delete_transient(self::RETRY_KEY);

                if ($remaining) {
                    $reschedule_in = self::NEXT_BATCH_DELAY;
                }

                return;
            }

            self::enqueue($batch, true);

            $retries = (int) get_transient(self::RETRY_KEY);

            if ($retries + 1 >= self::MAX_RETRIES) {
                delete_transient(self::RETRY_KEY);
                delete_transient(self::CACHE_KEY);

                BTL_Helpers::logger(
                    'Revalidator: dropped after ' . self::MAX_RETRIES .
                    ' failed attempts — ' . wp_json_encode($batch)
                );

                return;
            }

            set_transient(
                self::RETRY_KEY,
                $retries + 1,
                self::QUEUE_TTL
            );

            $reschedule_in = self::RETRY_DELAY;

        } finally {
            delete_transient(
                self::LOCK_KEY
            );

            if ($reschedule_in > 0) {
                self::schedule_flush($reschedule_in, true);
            }
        }
    }

    private static function is_configured(): bool
    {
        return defined('NEXTJS_API_URL')
            && NEXTJS_API_URL !== ''
            && defined('NEXTJS_REVALIDATE_SECRET')
            && NEXTJS_REVALIDATE_SECRET !== '';
    }

    private static function send(
        array $tags
    ): bool {
        if (!$tags) {
            return true;
        }

        if (!self::is_configured()) {
            return false;
        }

        $response = wp_remote_post(
            NEXTJS_API_URL,
            [
                'timeout' => 12,
                'blocking' => true,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-revalidate-secret' => NEXTJS_REVALIDATE_SECRET,
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