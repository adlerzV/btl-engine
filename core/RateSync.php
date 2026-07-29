<?php

defined('ABSPATH') || exit;

final class BTL_Rate_Sync
{
    private const GROUP = 'btl';
    private const HOOK = 'btl_sync_exchange_rates';
    private const OPTION_KEY = 'site-settings';
    private const LAST_SYNC_OPTION = 'btl_last_rate_sync';
    private const DEFAULT_INTERVAL_HOURS = 6;
    private const MAX_DEVIATION_RATIO = 0.2;

    private const CURRENCY_TO_FIELD = [
        'USD' => 'usd_to_toman_rate',
        'EUR' => 'eur_to_toman_rate',
        'TRY' => 'try_to_toman_rate',
        'UAH' => 'uah_to_toman_rate',
    ];

    public static function boot(): void
    {
        add_action('init', [self::class, 'maybe_schedule'], 5);
        add_action(self::HOOK, [self::class, 'run']);
        add_action('updated_option_' . self::OPTION_KEY, [self::class, 'maybe_reschedule'], 10, 3);
    }

    public static function maybe_schedule(): void
    {
        if (!function_exists('as_has_scheduled_action')) {
            return;
        }

        if (as_has_scheduled_action(self::HOOK, [], self::GROUP)) {
            return;
        }

        self::schedule(self::interval_hours());
    }

    public static function maybe_reschedule($old_value, $new_value, $option): void
    {
        $oldHours = self::extract_interval(is_array($old_value) ? $old_value : []);
        $newHours = self::extract_interval(is_array($new_value) ? $new_value : []);

        if ($oldHours === $newHours) {
            return;
        }

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK, [], self::GROUP);
        }

        self::schedule($newHours);
    }

    private static function schedule(int $hours): void
    {
        if (!function_exists('as_schedule_recurring_action')) {
            return;
        }

        as_schedule_recurring_action(
            time() + 60,
            $hours * HOUR_IN_SECONDS,
            self::HOOK,
            [],
            self::GROUP
        );
    }

    private static function interval_hours(): int
    {
        return self::extract_interval(get_option(self::OPTION_KEY, []));
    }

    private static function extract_interval(array $settings): int
    {
        $hours = isset($settings['rate_sync_interval_hours']) ? (int) $settings['rate_sync_interval_hours'] : 0;
        return $hours > 0 ? $hours : self::DEFAULT_INTERVAL_HOURS;
    }

    public static function run(): void
    {
        $gateway = new BTL_Navasan_Rate_Gateway();
        $rates = $gateway->fetchRates();

        if (empty($rates)) {
            BTL_Helpers::logger('RateSync: هیچ نرخی دریافت نشد — این دور رد شد.');
            return;
        }

        $settings = get_option(self::OPTION_KEY, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $changed = false;

        foreach (self::CURRENCY_TO_FIELD as $currency => $fieldSlug) {
            if (!isset($rates[$currency])) {
                continue;
            }

            $newValue = $rates[$currency];
            $oldValue = BTL_Helpers::money($settings[$fieldSlug] ?? 0);

            if ($oldValue > 0) {
                $deviation = abs($newValue - $oldValue) / $oldValue;
                if ($deviation > self::MAX_DEVIATION_RATIO) {
                    BTL_Helpers::logger(sprintf(
                        'RateSync: نرخ %s از %s به %s تغییر کرد (بیش از حد مجاز) — نادیده گرفته شد.',
                        $currency, $oldValue, $newValue
                    ));
                    continue;
                }
            }

            $newValueString = (string) round($newValue);
            if (($settings[$fieldSlug] ?? '') !== $newValueString) {
                $settings[$fieldSlug] = $newValueString;
                $changed = true;
            }
        }

        update_option(self::LAST_SYNC_OPTION, current_time('mysql', true), false);

        if (!$changed) {
            return;
        }

        update_option(self::OPTION_KEY, $settings);
        wp_cache_delete('rates', 'btl');

        if (class_exists('BTL_Scheduler')) {
            BTL_Scheduler::schedule();
        }

        BTL_Helpers::logger('RateSync: نرخ ارزها بروزرسانی شد — ' . wp_json_encode($rates));
    }
}