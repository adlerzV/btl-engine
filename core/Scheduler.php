<?php

defined('ABSPATH') || exit;

final class BTL_Scheduler
{
    private const GROUP = 'btl';
    private const LOCK_KEY = 'btl_batch_lock';
    private const BATCH_SIZE = 100;
    private const MAX_ITERATIONS = 500;
    private const CHANGED_COUNTER_KEY = 'btl_batch_changed_count';
    private const MAX_GRANULAR_PRODUCTS_PER_CHUNK = 40;

    public static function boot(): void
    {
        add_action(
            'acf/save_post',
            [self::class, 'trigger_mass_update'],
            20
        );

        add_action(
            'updated_option_site-settings',
            [self::class, 'trigger_mass_update'],
            10
        );

        add_action(
            'jet-engine/options-pages/updated',
            [self::class, 'trigger_mass_update'],
            10
        );

        add_action(
            'btl_batch_job',
            [self::class, 'batch_job'],
            10,
            2
        );

        add_action(
            'btl_product_chunk_job',
            [self::class, 'process_chunk'],
            10,
            1
        );

        add_action(
            'btl_cleanup_job',
            [self::class, 'cleanup'],
            10
        );
    }

    public static function trigger_mass_update(
        $post_id = null
    ): void {
        if (
            $post_id === null ||
            in_array(
                $post_id,
                ['options', 'site-settings'],
                true
            )
        ) {
            self::schedule();
            return;
        }

        if (
            is_numeric($post_id) &&
            get_post_type(
                (int)$post_id
            ) === 'product'
        ) {
            BTL_Price_Engine::calculate(
                (int)$post_id,
                true
            );
        }
    }

    public static function schedule(): void
    {
        if (
            get_transient(
                self::LOCK_KEY
            )
        ) {
            return;
        }

        if (
            function_exists(
                'as_has_scheduled_action'
            ) &&
            as_has_scheduled_action(
                'btl_batch_job',
                ['offset' => 0, 'iteration' => 0],
                self::GROUP
            )
        ) {
            return;
        }

        set_transient(
            self::LOCK_KEY,
            1,
            1800
        );

        as_schedule_single_action(
            time(),
            'btl_batch_job',
            [
                'offset' => 0,
                'iteration' => 0
            ],
            self::GROUP
        );
    }

    public static function batch_job(
        int $offset = 0,
        int $iteration = 0
    ): void {
        if (
            $iteration >
            self::MAX_ITERATIONS
        ) {
            self::cleanup();
            return;
        }

        $products =
            get_posts([
                'post_type' => 'product',
                'post_status' => 'publish',
                'fields' => 'ids',
                'posts_per_page' =>
                    self::BATCH_SIZE,
                'offset' => $offset,
                'orderby' => 'ID',
                'order' => 'ASC'
            ]);

        if (!$products) {
            as_schedule_single_action(
                time() + 10,
                'btl_cleanup_job',
                [],
                self::GROUP
            );
            return;
        }

        as_schedule_single_action(
            time(),
            'btl_product_chunk_job',
            [
                'products' =>
                    $products
            ],
            self::GROUP
        );

        as_schedule_single_action(
            time() + 5,
            'btl_batch_job',
            [
                'offset' =>
                    $offset +
                    self::BATCH_SIZE,

                'iteration' =>
                    $iteration + 1
            ],
            self::GROUP
        );
    }

    public static function process_chunk(
        array $products
    ): void {
        if (!$products) {
            return;
        }

        $useInvalidation = class_exists('BTL_Invalidation');

        if ($useInvalidation) {
            BTL_Invalidation::suspend();
        }

        $changed_ids = [];

        foreach (
            $products as $product_id
        ) {
            try {
                if (
                    BTL_Price_Engine::calculate(
                        (int)$product_id,
                        false
                    )
                ) {
                    $changed_ids[] = (int)$product_id;
                }
            } catch (
                Throwable $e
            ) {
                error_log(
                    sprintf(
                        '[BTL] Product %d failed: %s',
                        $product_id,
                        $e->getMessage()
                    )
                );
            }
        }

        if ($useInvalidation) {
            BTL_Invalidation::resume();
        }

        if (!$changed_ids) {
            return;
        }

        self::bump_changed_counter(
            count($changed_ids)
        );

        if (!$useInvalidation) {
            return;
        }

        if (
            count($changed_ids) >
            self::MAX_GRANULAR_PRODUCTS_PER_CHUNK
        ) {
            return;
        }

        $tags = [];

        foreach ($changed_ids as $changed_id) {
            foreach (
                BTL_Invalidation::tagsForProduct(
                    $changed_id,
                    BTL_Invalidation::SCOPE_PRICING
                ) as $tag
            ) {
                $tags[$tag] = true;
            }
        }

        if (
            $tags &&
            function_exists(
                'btl_queue_revalidation'
            )
        ) {
            btl_queue_revalidation(
                array_keys($tags)
            );
        }
    }

    private static function bump_changed_counter(
        int $count
    ): void {
        $current = (int) get_transient(
            self::CHANGED_COUNTER_KEY
        );

        set_transient(
            self::CHANGED_COUNTER_KEY,
            $current + $count,
            HOUR_IN_SECONDS
        );
    }

    public static function cleanup(): void
    {
        delete_transient(
            self::LOCK_KEY
        );

        $changed = (int) get_transient(
            self::CHANGED_COUNTER_KEY
        );

        delete_transient(
            self::CHANGED_COUNTER_KEY
        );

        if ($changed <= 0) {
            return;
        }

        if (
            $changed >=
            self::MAX_GRANULAR_PRODUCTS_PER_CHUNK &&
            function_exists(
                'btl_queue_revalidation'
            )
        ) {
            btl_queue_revalidation([
                'products',
                'home-featured',
                'home-latest'
            ]);
        }
    }
}