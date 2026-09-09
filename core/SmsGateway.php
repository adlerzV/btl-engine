<?php

defined('ABSPATH') || exit;

interface BTL_Sms_Gateway
{
    public function sendOtp(string $phone, string $code): bool;
}

final class BTL_NirSms_Gateway implements BTL_Sms_Gateway
{
    private const BASE_URL = 'https://edge.ippanel.com/v1/api/send';

    public function sendOtp(string $phone, string $code): bool
    {
        if (!defined('NIRSMS_API_KEY') || NIRSMS_API_KEY === '') {
            BTL_Helpers::logger('NirSMS: NIRSMS_API_KEY تعریف نشده.');
            return false;
        }
        if (!defined('NIRSMS_PATTERN_CODE') || NIRSMS_PATTERN_CODE === '') {
            BTL_Helpers::logger('NirSMS: NIRSMS_PATTERN_CODE تعریف نشده.');
            return false;
        }
        if (!defined('NIRSMS_FROM_NUMBER') || NIRSMS_FROM_NUMBER === '') {
            BTL_Helpers::logger('NirSMS: NIRSMS_FROM_NUMBER تعریف نشده.');
            return false;
        }

        $payload = [
            'sending_type' => 'pattern',
            'from_number'  => NIRSMS_FROM_NUMBER,
            'code'         => NIRSMS_PATTERN_CODE,
            'recipients'   => [$this->toInternational($phone)],
            'params'       => [
                'zrvwigtmnchrsco' => $code, 
            ],
        ];

        $response = wp_remote_post(self::BASE_URL, [
            'timeout' => 10,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => NIRSMS_API_KEY,
            ],
            'body'    => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        if (is_wp_error($response)) {
            BTL_Helpers::logger('NirSMS request error: ' . $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body        = wp_remote_retrieve_body($response);
        $data        = json_decode($body, true);

        if ($status_code !== 200) {
            BTL_Helpers::logger('NirSMS HTTP error: ' . $status_code . ' — ' . $body);
            return false;
        }

        if (!isset($data['meta']['status']) || $data['meta']['status'] !== true) {
            $msg = $data['meta']['message'] ?? 'ناشناخته';
            BTL_Helpers::logger('NirSMS API Error: ' . $msg);
            return false;
        }

        return true;
    }

    private function toInternational(string $phone): string
    {
        $clean = preg_replace('/\D/', '', $phone);

        if (str_starts_with($clean, '0')) {
            $clean = '98' . substr($clean, 1);
        }

        return '+' . $clean;
    }
}