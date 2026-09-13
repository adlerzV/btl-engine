<?php

defined('ABSPATH') || exit;

use GraphQL\Error\UserError;

final class BTL_Credentials_Auth
{
    private const MANUAL_PASSWORD_META = 'btl_has_manual_password';
    private const DUMMY_HASH = '$P$Bnothinghere.nothinghere.nothing0';

    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 10);
    }

    public static function register(): void
    {
        register_graphql_field('User', 'hasManualPassword', [
            'type' => 'Boolean',
            'resolve' => static function ($user) {
                $currentUserId = get_current_user_id();
                if (!$currentUserId) {
                    return null;
                }

                if ($currentUserId !== (int) $user->databaseId && !current_user_can('manage_options')) {
                    return null;
                }

                return (bool) get_user_meta((int) $user->databaseId, self::MANUAL_PASSWORD_META, true);
            },
        ]);

        register_graphql_mutation('loginWithPassword', [
            'inputFields' => [
                'identifier' => ['type' => ['non_null' => 'String']],
                'password' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'authToken' => ['type' => 'String'],
                'refreshToken' => ['type' => 'String'],
                'requiresAdminTotp' => ['type' => 'Boolean'],
                'requiresAdminTotpSetup' => ['type' => 'Boolean'],
                'pendingTicket' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function ($input) {
                return self::safeExecute(function () use ($input) {
                    $rawIdentifier = trim((string) $input['identifier']);
                    $password = (string) $input['password'];

                    if ($rawIdentifier === '') {
                        throw new UserError('شماره موبایل یا ایمیل را وارد کنید.');
                    }

                    if ($password === '') {
                        throw new UserError('رمز عبور را وارد کنید.');
                    }

                    $throttleKey = mb_strtolower($rawIdentifier);
                    $ip = BTL_Helpers::clientIp();
                    BTL_Login_Throttle::assertAllowed($throttleKey, $ip);

                    $user = self::findUserByIdentifier($rawIdentifier);
                    $isValid = false;

                    if ($user instanceof WP_User) {
                        $isValid = wp_check_password($password, $user->user_pass, $user->ID);
                    } else {
                        wp_check_password($password, self::DUMMY_HASH, 0);
                    }

                    if (!$user || !$isValid) {
                        BTL_Login_Throttle::recordAttempt($throttleKey, $ip);
                        throw new UserError('شماره موبایل/ایمیل یا رمز عبور اشتباه است.');
                    }

                    BTL_Login_Throttle::clearAttempts($throttleKey, $ip);

                    if (user_can($user->ID, 'manage_woocommerce')) {
                        $ticket = BTL_Admin_Totp::issuePendingTicket($user->ID);

                        $isConfigured = BTL_Admin_Totp::isConfigured($user->ID);
                        return [
                            'authToken' => null,
                            'refreshToken' => null,
                            'requiresAdminTotp' => $isConfigured,
                            'requiresAdminTotpSetup' => !$isConfigured,
                            'pendingTicket' => $ticket,
                        ];
                    }

                    $tokens = BTL_Phone_Auth::issueTokens($user);

                    return [
                        'authToken' => $tokens['authToken'] ?? null,
                        'refreshToken' => $tokens['refreshToken'] ?? null,
                        'requiresAdminTotp' => false,
                        'requiresAdminTotpSetup' => false,
                        'pendingTicket' => null,
                    ];
                });
            },
        ]);

        register_graphql_mutation('setPassword', [
            'inputFields' => [
                'currentPassword' => ['type' => 'String'],
                'newPassword' => ['type' => ['non_null' => 'String']],
                'sessionId' => ['type' => 'String'],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
            ],
            'mutateAndGetPayload' => function ($input) {
                return self::safeExecute(function () use ($input) {
                    if (!is_user_logged_in()) {
                        throw new UserError('باید وارد حساب کاربری شوید.');
                    }

                    $userId = get_current_user_id();
                    $user = get_userdata($userId);
                    if (!$user) {
                        throw new UserError('کاربر یافت نشد.');
                    }

                    $hasManual = (bool) get_user_meta($userId, self::MANUAL_PASSWORD_META, true);

                    if ($hasManual) {
                        $current = (string) ($input['currentPassword'] ?? '');
                        if ($current === '' || !wp_check_password($current, $user->user_pass, $userId)) {
                            throw new UserError('رمز عبور فعلی صحیح نیست.');
                        }
                    }

                    $newPassword = (string) $input['newPassword'];
                    self::validatePasswordStrength($newPassword);

                    wp_set_password($newPassword, $userId);
                    update_user_meta($userId, self::MANUAL_PASSWORD_META, 1);

                    if (function_exists('wp_set_auth_cookie')) {
                        wp_set_auth_cookie($userId, true);
                    }

                    BTL_Sessions::revokeAllExcept($userId, $input['sessionId'] ?? null);

                    BTL_Notifications::push(
                        $userId,
                        'رمز عبور حساب شما تغییر کرد 🔐',
                        'اگر این تغییر توسط شما انجام نشده، فوراً از طریق تیکت پشتیبانی با ما در ارتباط باشید.',
                        '/my-account/settings',
                        'account'
                    );

                    return ['success' => true];
                });
            },
        ]);
    }

    public static function findUserByIdentifier(string $identifier): ?WP_User
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (is_email($identifier)) {
            $user = get_user_by('email', $identifier);
            return $user instanceof WP_User ? $user : null;
        }

        $phone = BTL_Phone_Auth::normalizePhone($identifier);
        if (!$phone) {
            return null;
        }

        $userId = BTL_Phone_Auth::findUserByPhone($phone);
        return $userId ? (get_userdata($userId) ?: null) : null;
    }

    public static function validatePasswordStrength(string $password): void
    {
        if (mb_strlen($password) < 8) {
            throw new UserError('رمز عبور باید حداقل ۸ کاراکتر باشد.');
        }
        if (mb_strlen($password) > 100) {
            throw new UserError('رمز عبور بیش از حد طولانی است.');
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            throw new UserError('رمز عبور باید ترکیبی از حروف انگلیسی و عدد باشد.');
        }
    }

    private static function safeExecute(callable $fn)
    {
        try {
            return $fn();
        } catch (UserError $e) {
            throw $e;
        } catch (Throwable $e) {
            BTL_Helpers::logger(
                'CredentialsAuth fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
            );
            throw new UserError('خطای داخلی سرور رخ داد، لطفاً با پشتیبانی تماس بگیرید.');
        }
    }
}