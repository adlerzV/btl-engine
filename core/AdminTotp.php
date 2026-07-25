<?php

defined('ABSPATH') || exit;

final class BTL_Admin_Totp
{
    private const SECRET_META = 'btl_admin_totp_secret';
    private const RECOVERY_META = 'btl_admin_totp_recovery';
    private const TICKET_PREFIX = 'btl_totp_ticket_';
    private const SETUP_PREFIX = 'btl_totp_setup_';
    private const TICKET_TTL = 300;
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const WINDOW = 1;

    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 10);
    }

    public static function isConfigured(int $userId): bool
    {
        return (bool) get_user_meta($userId, self::SECRET_META, true);
    }

    public static function issuePendingTicket(int $userId): string
    {
        $ticket = wp_generate_password(40, false);
        set_transient(self::TICKET_PREFIX . $ticket, $userId, self::TICKET_TTL);
        return $ticket;
    }

    private static function resolveTicket(string $ticket): int
    {
        $userId = (int) get_transient(self::TICKET_PREFIX . $ticket);
        if (!$userId) {
            throw new GraphQL\Error\UserError('نشست تأیید دومرحله‌ای منقضی شده. دوباره وارد شوید.');
        }
        return $userId;
    }

    public static function register(): void
    {
        register_graphql_mutation('requestAdminTotpSetup', [
            'inputFields' => ['pendingTicket' => ['type' => ['non_null' => 'String']]],
            'outputFields' => [
                'secret' => ['type' => 'String'],
                'otpauthUrl' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function ($input) {
                $userId = self::resolveTicket($input['pendingTicket']);
                if (self::isConfigured($userId)) {
                    throw new GraphQL\Error\UserError('تأیید دومرحله‌ای قبلاً فعال شده است.');
                }

                $secret = self::generateSecret();
                set_transient(self::SETUP_PREFIX . $input['pendingTicket'], $secret, self::TICKET_TTL);

                $user = get_userdata($userId);
                $label = rawurlencode('Arena2Battle:' . $user->user_login);
                $otpauthUrl = "otpauth://totp/{$label}?secret={$secret}&issuer=Arena2Battle&digits=" . self::DIGITS . "&period=" . self::PERIOD;

                return ['secret' => $secret, 'otpauthUrl' => $otpauthUrl];
            },
        ]);

        register_graphql_mutation('confirmAdminTotpSetup', [
            'inputFields' => [
                'pendingTicket' => ['type' => ['non_null' => 'String']],
                'code' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'authToken' => ['type' => 'String'],
                'refreshToken' => ['type' => 'String'],
                'recoveryCodes' => ['type' => ['list_of' => 'String']],
            ],
            'mutateAndGetPayload' => function ($input) {
                $userId = self::resolveTicket($input['pendingTicket']);
                $secret = get_transient(self::SETUP_PREFIX . $input['pendingTicket']);

                if (!$secret) {
                    throw new GraphQL\Error\UserError('نشست تنظیم منقضی شده. دوباره تلاش کنید.');
                }

                if (!self::verifyCode($secret, sanitize_text_field($input['code']))) {
                    throw new GraphQL\Error\UserError('کد وارد شده صحیح نیست.');
                }

                $recoveryCodes = self::generateRecoveryCodes();
                $hashed = array_map(
                    static fn($c) => ['hash' => password_hash($c, PASSWORD_BCRYPT), 'used' => false],
                    $recoveryCodes
                );

                update_user_meta($userId, self::SECRET_META, BTL_Secure_Vault::encrypt($secret));
                update_user_meta($userId, self::RECOVERY_META, $hashed);

                delete_transient(self::SETUP_PREFIX . $input['pendingTicket']);
                delete_transient(self::TICKET_PREFIX . $input['pendingTicket']);

                $tokens = BTL_Phone_Auth::issueTokens(get_userdata($userId));

                return [
                    'authToken' => $tokens['authToken'],
                    'refreshToken' => $tokens['refreshToken'],
                    'recoveryCodes' => $recoveryCodes,
                ];
            },
        ]);

        register_graphql_mutation('verifyAdminTotp', [
            'inputFields' => [
                'pendingTicket' => ['type' => ['non_null' => 'String']],
                'code' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'authToken' => ['type' => 'String'],
                'refreshToken' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function ($input) {
                $userId = self::resolveTicket($input['pendingTicket']);
                $code = sanitize_text_field($input['code']);

                $encryptedSecret = get_user_meta($userId, self::SECRET_META, true);
                $secret = $encryptedSecret ? BTL_Secure_Vault::decrypt($encryptedSecret) : null;

                $verified = $secret && self::verifyCode($secret, $code);
                if (!$verified) {
                    $verified = self::tryRecoveryCode($userId, $code);
                }

                if (!$verified) {
                    throw new GraphQL\Error\UserError('کد وارد شده صحیح نیست.');
                }

                delete_transient(self::TICKET_PREFIX . $input['pendingTicket']);

                $tokens = BTL_Phone_Auth::issueTokens(get_userdata($userId));

                return ['authToken' => $tokens['authToken'], 'refreshToken' => $tokens['refreshToken']];
            },
        ]);
    }

    private static function tryRecoveryCode(int $userId, string $code): bool
    {
        $codes = get_user_meta($userId, self::RECOVERY_META, true);
        if (!is_array($codes)) return false;

        foreach ($codes as $i => $entry) {
            if (!$entry['used'] && password_verify($code, $entry['hash'])) {
                $codes[$i]['used'] = true;
                update_user_meta($userId, self::RECOVERY_META, $codes);
                return true;
            }
        }
        return false;
    }

    private static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        }
        return $codes;
    }

    private static function generateSecret(int $length = 32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }
        return $secret;
    }

    private static function verifyCode(string $secret, string $code): bool
    {
        if (!preg_match('/^\d{6}$/', $code)) return false;

        $timeSlice = (int) floor(time() / self::PERIOD);
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            if (self::generateCode($secret, $timeSlice + $i) === $code) {
                return true;
            }
        }
        return false;
    }

    private static function generateCode(string $secret, int $timeSlice): string
    {
        $key = self::base32Decode($secret);
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $time, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncated =
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($truncated % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $input): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input = strtoupper($input);
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        for ($i = 0; $i < strlen($input); $i++) {
            $val = strpos($alphabet, $input[$i]);
            if ($val === false) continue;
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        return $output;
    }
}