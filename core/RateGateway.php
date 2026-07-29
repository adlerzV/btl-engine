<?php

defined('ABSPATH') || exit;

interface BTL_Rate_Gateway
{
    public function fetchRates(): array;
}

final class BTL_Navasan_Rate_Gateway implements BTL_Rate_Gateway
{
    private const BASE_URL = 'http://api.navasan.tech/latest/';

    private const ITEM_MAP = [
        'USD' => 'usd_sell',
        'EUR' => 'eur',
        'TRY' => 'try',
        'UAH' => 'uah',
    ];

    public function fetchRates(): array
    {
        if (!defined('NAVASAN_API_KEY') || NAVASAN_API_KEY === '') {
            BTL_Helpers::logger('RateSync: NAVASAN_API_KEY تعریف نشده.');
            return [];
        }

        $response = wp_remote_get(
            self::BASE_URL . '?api_key=' . rawurlencode(NAVASAN_API_KEY),
            ['timeout' => 12]
        );

        if (is_wp_error($response)) {
            BTL_Helpers::logger('RateSync: خطای اتصال navasan — ' . $response->get_error_message());
            return [];
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            BTL_Helpers::logger('RateSync: خطای HTTP navasan — ' . $status . ' — ' . wp_remote_retrieve_body($response));
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            BTL_Helpers::logger('RateSync: پاسخ navasan قابل parse نبود.');
            return [];
        }

        $rates = [];
        foreach (self::ITEM_MAP as $currency => $item) {
            $rawValue = $body[$item]['value'] ?? null;
            if ($rawValue === null) {
                continue;
            }

            $numeric = (float) str_replace([',', ' '], '', (string) $rawValue);
            if ($numeric > 0) {
                $rates[$currency] = $numeric;
            }
        }

        return $rates;
    }
}