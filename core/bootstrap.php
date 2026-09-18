<?php
defined('ABSPATH') || exit;

function btl_autoload_core_class(string $class): void
{
    static $map = null;

    if ($map === null) {
        $map = [
            'BTL_Helpers' => 'Helpers.php',
            'BTL_Cache' => 'Cache.php',
            'BTL_Invalidation' => 'Invalidation.php',
            'BTL_Price_Engine' => 'PriceEngine.php',
            'BTL_Rate_Gateway' => 'RateGateway.php',
            'BTL_Navasan_Rate_Gateway' => 'RateGateway.php',
            'BTL_Rate_Sync' => 'RateSync.php',
            'BTL_Region_Taxonomy' => 'RegionTaxonomy.php',
            'BTL_GraphQL' => 'GraphQL.php',
            'BTL_Content_Matrix' => 'ContentMatrix.php',
            'BTL_Scheduler' => 'Scheduler.php',
            'BTL_Revalidator' => 'Revalidator.php',
            'BTL_Migrations' => 'Migrations.php',
            'BTL_Admin' => 'Admin.php',
            'BTL_Admin_Permissions' => 'AdminPermissions.php',
            'BTL_Admin_Audit' => 'AdminAudit.php',
            'BTL_Secure_Vault' => 'SecureVault.php',
            'BTL_Secure_Fields' => 'SecureFields.php',
            'BTL_Order_Security_Hooks' => 'OrderSecurityHooks.php',
            'BTL_Order_Fulfillment' => 'OrderFulfillment.php',
            'BTL_Notifications' => 'Notifications.php',
            'BTL_Sessions' => 'Sessions.php',
            'BTL_Ticket_Replies' => 'TicketReplies.php',
            'BTL_Ticket_Admin' => 'TicketAdmin.php',
            'BTL_Wishlist_Alerts' => 'WishlistAlerts.php',
            'BTL_Customer_Tickets' => 'CustomerTickets.php',
            'BTL_Customer_Reviews' => 'CustomerReviews.php',
            'BTL_Review_Moderation' => 'ReviewModeration.php',
            'BTL_Avatar_Guard' => 'AvatarGuard.php',
            'BTL_CdKey_Stock' => 'CdKeyStock.php',
            'BTL_CdKey_Admin' => 'CdKeyAdmin.php',
            'BTL_Blog_Follow' => 'BlogFollow.php',
            'BTL_Post_Ratings' => 'PostRatings.php',
            'BTL_Blog_Comments' => 'BlogComments.php',
            'BTL_Customer_Orders' => 'CustomerOrders.php',
            'BTL_Otp' => 'Otp.php',
            'BTL_NirSms_Gateway' => 'SmsGateway.php',
            'BTL_Sms_Gateway' => 'SmsGateway.php',
            'BTL_Email_Gateway' => 'EmailGateway.php',
            'BTL_Admin_Totp' => 'AdminTotp.php',
            'BTL_Admin_Sms_Auth' => 'AdminSmsAuth.php',
            'BTL_Login_Throttle' => 'LoginThrottle.php',
            'BTL_Phone_Auth' => 'PhoneAuth.php',
            'BTL_Admin_Login' => 'AdminLogin.php',
            'BTL_Credentials_Auth' => 'CredentialsAuth.php',
            'BTL_Password_Reset' => 'PasswordReset.php',
        ];
    }

    if (!isset($map[$class])) {
        return;
    }

    $file = __DIR__ . '/' . $map[$class];

    if (is_file($file)) {
        require_once $file;
    }
}

spl_autoload_register('btl_autoload_core_class');

BTL_Region_Taxonomy::boot();
BTL_Migrations::boot();
BTL_Revalidator::boot();
BTL_Invalidation::boot();
BTL_Price_Engine::boot();
BTL_Rate_Sync::boot();
BTL_GraphQL::boot();
BTL_Content_Matrix::boot();
BTL_Scheduler::boot();
BTL_Secure_Fields::boot();
BTL_Order_Security_Hooks::boot();
BTL_Order_Fulfillment::boot();
BTL_Notifications::boot();
BTL_Admin_Permissions::boot();
BTL_Admin_Audit::boot();
BTL_Sessions::boot();
BTL_Ticket_Replies::boot();
BTL_Wishlist_Alerts::boot();
BTL_Customer_Reviews::boot();
BTL_Review_Moderation::boot();
BTL_Avatar_Guard::boot();
BTL_CdKey_Stock::boot();
BTL_Customer_Orders::boot();
BTL_Blog_Follow::boot();
BTL_Post_Ratings::boot();
BTL_Otp::boot();
BTL_Admin_Totp::boot();
BTL_Admin_Sms_Auth::boot();
BTL_Login_Throttle::boot();
BTL_Phone_Auth::boot();
BTL_Admin_Login::boot();
BTL_Credentials_Auth::boot();
BTL_Password_Reset::boot();

if (is_admin()) {
    BTL_Admin::boot();
    BTL_CdKey_Admin::boot();
    BTL_Ticket_Admin::boot();
}

add_action(
    'graphql_register_types',
    static function (): void {
        BTL_Customer_Tickets::register();
    },
    9
);

add_action(
    'graphql_register_types',
    static function (): void {
        BTL_Blog_Comments::register();
    },
    10
);

