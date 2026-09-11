<?php
defined('ABSPATH') || exit;

final class BTL_Region_Taxonomy
{
    private const TAXONOMY = 'pa_region_shop';

    public static function boot(): void
    {
        add_filter('woocommerce_taxonomy_args_' . self::TAXONOMY, [self::class, 'expose_to_graphql']);
        add_filter('register_taxonomy_args', [self::class, 'expose_existing_taxonomy'], 10, 2);
    }

    public static function expose_to_graphql(array $args): array
    {
        $args['show_in_graphql'] = true;
        $args['graphql_single_name'] = 'PaRegionShop';
        $args['graphql_plural_name'] = 'AllPaRegionShop';
        return $args;
    }

    public static function expose_existing_taxonomy(array $args, string $taxonomy): array
    {
        if ($taxonomy !== self::TAXONOMY) {
            return $args;
        }

        $args['show_in_graphql'] = true;
        $args['graphql_single_name'] = 'PaRegionShop';
        $args['graphql_plural_name'] = 'AllPaRegionShop';

        return $args;
    }
}