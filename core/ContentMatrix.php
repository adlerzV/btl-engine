<?php
defined('ABSPATH') || exit;

final class BTL_Content_Matrix
{
    private const META_KEY = '_content_matrix';
    private const NONCE_ACTION = 'btl_content_matrix_save';
    private const NONCE_FIELD = 'btl_content_matrix_nonce';

    public static function boot(): void
    {
        add_action('add_meta_boxes', [self::class, 'register_meta_box']);
        add_action('woocommerce_process_product_meta', [self::class, 'save'], 10, 1);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('admin_footer-post.php', [self::class, 'inline_admin_script']);
        add_action('admin_footer-post-new.php', [self::class, 'inline_admin_script']);
        add_action('graphql_register_types', [self::class, 'register_graphql'], 10);
    }

    public static function register_meta_box(): void
    {
        add_meta_box(
            'btl_content_matrix',
            'جدول محتویات (Content Matrix)',
            [self::class, 'render_meta_box'],
            'product',
            'normal',
            'default'
        );
    }

    public static function enqueue_assets(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;

        global $post;
        if (!$post || $post->post_type !== 'product') return;

        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');
    }

    public static function render_meta_box($post): void
    {
        $raw = get_post_meta($post->ID, self::META_KEY, true);
        $matrix = is_array($raw) ? $raw : [];

        $initial = [
            'columns' => array_values($matrix['columns'] ?? []),
            'items' => array_values($matrix['items'] ?? []),
            'image' => !empty($matrix['image']) ? (int)$matrix['image'] : null,
            'imageUrl' => !empty($matrix['image']) ? wp_get_attachment_url((int)$matrix['image']) : null,
        ];

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        ?>
        <style>
            #btl-content-matrix-app .btl-cm-section { margin-bottom: 20px; }
            #btl-content-matrix-app table.widefat td,
            #btl-content-matrix-app table.widefat th { vertical-align: middle; padding: 8px; }
            #btl-content-matrix-app .btl-cm-drag { cursor: move; text-align: center; color: #888; }
            #btl-content-matrix-app label { display: inline-flex; align-items: center; gap: 4px; min-width: 90px; }
        </style>
        <div id="btl-content-matrix-app" data-initial='<?php echo esc_attr(wp_json_encode($initial, JSON_UNESCAPED_UNICODE)); ?>'>
            <p class="description">این جدول کاملاً مستقل از ریجن، واریانت، قیمت و موجودی است. برای هر محصول حداکثر یک جدول محتویات وجود دارد.</p>

            <div class="btl-cm-section">
                <h4>ستون‌ها (نسخه‌ها)</h4>
                <table class="widefat btl-cm-columns-table">
                    <thead>
                        <tr>
                            <th style="width:24px;"></th>
                            <th>کلید داخلی (key)</th>
                            <th>عنوان نمایشی (label)</th>
                            <th style="width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody class="btl-cm-columns-body"></tbody>
                </table>
                <p><button type="button" class="button btl-cm-add-column">+ افزودن ستون</button></p>
            </div>

            <div class="btl-cm-section">
                <h4>ردیف‌ها (آیتم‌ها)</h4>
                <table class="widefat btl-cm-items-table">
                    <thead>
                        <tr>
                            <th style="width:24px;"></th>
                            <th style="width:180px;">نام آیتم</th>
                            <th class="btl-cm-items-head-cols"></th>
                            <th style="width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody class="btl-cm-items-body"></tbody>
                </table>
                <p><button type="button" class="button btl-cm-add-item">+ افزودن ردیف</button></p>
            </div>

            <div class="btl-cm-section">
                <h4>تصویر بخش (اختیاری، یک تصویر برای کل جدول)</h4>
                <div class="btl-cm-image-preview" style="margin-bottom:8px;"></div>
                <button type="button" class="button btl-cm-image-select">انتخاب تصویر</button>
                <button type="button" class="button btl-cm-image-remove">حذف تصویر</button>
            </div>

            <input type="hidden" name="_content_matrix_json" class="btl-cm-json-field" value="" />
        </div>
        <?php
    }

    public static function inline_admin_script(): void
    {
        global $post;
        if (!$post || $post->post_type !== 'product') return;
        ?>
        <script>
        jQuery(function ($) {
            var root = document.getElementById('btl-content-matrix-app');
            if (!root) return;

            var state;
            try {
                state = JSON.parse(root.getAttribute('data-initial') || '{}');
            } catch (e) {
                state = {};
            }
            state.columns = Array.isArray(state.columns) ? state.columns : [];
            state.items = Array.isArray(state.items) ? state.items : [];
            state.image = state.image || null;
            state.imageUrl = state.imageUrl || null;

            var $columnsBody = $(root).find('.btl-cm-columns-body');
            var $itemsBody = $(root).find('.btl-cm-items-body');
            var $itemsHeadCols = $(root).find('.btl-cm-items-head-cols');
            var $jsonField = $(root).find('.btl-cm-json-field');
            var $imagePreview = $(root).find('.btl-cm-image-preview');

            function normalizeKey(str) {
                return (str || '').toString().toLowerCase().replace(/[^a-z0-9_\-]+/g, '-').replace(/^-+|-+$/g, '');
            }

            function escapeHtml(str) {
                return $('<div>').text(str == null ? '' : String(str)).html();
            }

            function syncJson() {
                $jsonField.val(JSON.stringify(state));
            }

            function renderImagePreview() {
                if (state.image) {
                    $imagePreview.html('<img src="' + escapeHtml(state.imageUrl || '') + '" style="max-width:160px;max-height:100px;display:block;border:1px solid #ccd0d4;" />');
                } else {
                    $imagePreview.empty();
                }
            }

            function readColumnsFromDom() {
                var cols = [];
                $columnsBody.find('tr').each(function () {
                    var key = normalizeKey($(this).find('.btl-cm-col-key').val());
                    var label = ($(this).find('.btl-cm-col-label').val() || '').trim();
                    if (key === '') return;
                    cols.push({ key: key, label: label || key });
                });
                return cols;
            }

            function readItemsFromDom() {
                var items = [];
                $itemsBody.find('tr').each(function () {
                    var name = ($(this).find('.btl-cm-item-name').val() || '').trim();
                    if (name === '') return;
                    var included = [];
                    $(this).find('.btl-cm-item-check:checked').each(function () {
                        included.push($(this).attr('data-key'));
                    });
                    items.push({ name: name, included_in: included });
                });
                return items;
            }

            function renderItemsHead() {
                $itemsHeadCols.empty();
                var $wrap = $('<div style="display:flex;gap:10px;flex-wrap:wrap;font-weight:600;"></div>');
                state.columns.forEach(function (col) {
                    $wrap.append('<span style="min-width:90px;display:inline-block;">' + escapeHtml(col.label || col.key) + '</span>');
                });
                $itemsHeadCols.append($wrap);
            }

            function renderItems() {
                $itemsBody.empty();
                state.items.forEach(function (item, idx) {
                    var $checks = $('<div style="display:flex;gap:10px;flex-wrap:wrap;"></div>');
                    state.columns.forEach(function (col) {
                        var checked = item.included_in && item.included_in.indexOf(col.key) !== -1;
                        var $label = $('<label></label>');
                        var $cb = $('<input type="checkbox" class="btl-cm-item-check" />').attr('data-key', col.key).prop('checked', !!checked);
                        $label.append($cb).append(document.createTextNode(col.label || col.key));
                        $checks.append($label);
                    });

                    var $row = $('<tr data-idx="' + idx + '"></tr>');
                    $row.append('<td class="btl-cm-drag">☰</td>');
                    $row.append($('<td></td>').append($('<input type="text" class="btl-cm-item-name" style="width:100%;" />').val(item.name)));
                    $row.append($('<td></td>').append($checks));
                    $row.append('<td><button type="button" class="button btl-cm-item-remove">×</button></td>');
                    $itemsBody.append($row);
                });
            }

            function renderColumns() {
                $columnsBody.empty();
                state.columns.forEach(function (col, idx) {
                    var $row = $('<tr data-idx="' + idx + '"></tr>');
                    $row.append('<td class="btl-cm-drag">☰</td>');
                    $row.append($('<td></td>').append($('<input type="text" class="btl-cm-col-key" dir="ltr" style="width:100%;" />').val(col.key)));
                    $row.append($('<td></td>').append($('<input type="text" class="btl-cm-col-label" style="width:100%;" />').val(col.label)));
                    $row.append('<td><button type="button" class="button btl-cm-col-remove">×</button></td>');
                    $columnsBody.append($row);
                });
                renderItemsHead();
                renderItems();
            }

            function commitColumns() {
                state.columns = readColumnsFromDom();
                renderColumns();
                syncJson();
            }

            function commitItems() {
                state.items = readItemsFromDom();
                syncJson();
            }

            $(root).on('click', '.btl-cm-add-column', function () {
                state.columns = readColumnsFromDom();
                state.columns.push({ key: '', label: '' });
                renderColumns();
                syncJson();
            });

            $(root).on('click', '.btl-cm-col-remove', function () {
                var idx = $(this).closest('tr').data('idx');
                state.columns = readColumnsFromDom();
                state.columns.splice(idx, 1);
                state.items = readItemsFromDom();
                renderColumns();
                syncJson();
            });

            $(root).on('focusin', '.btl-cm-col-key', function () {
                $(this).data('prev-key', normalizeKey($(this).val()));
            });

            $(root).on('change', '.btl-cm-col-key', function () {
                state.items = readItemsFromDom();

                var oldKey = $(this).data('prev-key') || '';
                var newKey = normalizeKey($(this).val());

                if (oldKey && newKey && oldKey !== newKey) {
                    state.items.forEach(function (item) {
                        if (!Array.isArray(item.included_in)) {
                            item.included_in = [];
                        }

                        if (item.included_in.indexOf(oldKey) !== -1) {
                            item.included_in = item.included_in.filter(function (key) {
                                return key !== oldKey;
                            });

                            if (item.included_in.indexOf(newKey) === -1) {
                                item.included_in.push(newKey);
                            }
                        }
                    });
                }

                $(this).data('prev-key', newKey);
                commitColumns();
            });

            $(root).on('change', '.btl-cm-col-label', function () {
                state.items = readItemsFromDom();
                commitColumns();
            });

            $(root).on('click', '.btl-cm-add-item', function () {
                state.items = readItemsFromDom();
                state.items.push({ name: '', included_in: [] });
                renderItems();
                syncJson();
            });

            $(root).on('click', '.btl-cm-item-remove', function () {
                var idx = $(this).closest('tr').data('idx');
                state.items = readItemsFromDom();
                state.items.splice(idx, 1);
                renderItems();
                syncJson();
            });

            $(root).on('input change', '.btl-cm-item-name, .btl-cm-item-check', function () {
                commitItems();
            });

            if ($.fn.sortable) {
                $columnsBody.sortable({
                    handle: '.btl-cm-drag',
                    axis: 'y',
                    update: function () {
                        state.items = readItemsFromDom();
                        commitColumns();
                    }
                });
                $itemsBody.sortable({
                    handle: '.btl-cm-drag',
                    axis: 'y',
                    update: function () {
                        commitItems();
                    }
                });
            }

            var mediaFrame;
            $(root).on('click', '.btl-cm-image-select', function (e) {
                e.preventDefault();
                if (mediaFrame) { mediaFrame.open(); return; }
                mediaFrame = wp.media({ title: 'انتخاب تصویر بخش', multiple: false });
                mediaFrame.on('select', function () {
                    var attachment = mediaFrame.state().get('selection').first().toJSON();
                    state.image = attachment.id;
                    state.imageUrl = attachment.url;
                    renderImagePreview();
                    syncJson();
                });
                mediaFrame.open();
            });

            $(root).on('click', '.btl-cm-image-remove', function () {
                state.image = null;
                state.imageUrl = null;
                renderImagePreview();
                syncJson();
            });

            renderColumns();
            renderImagePreview();
            syncJson();

            $('#post').on('submit', function () {
                state.columns = readColumnsFromDom();
                state.items = readItemsFromDom();
                syncJson();
            });
        });
        </script>
        <?php
    }

    public static function save(int $product_id): void
    {
        if (!isset($_POST[self::NONCE_FIELD]) || !wp_verify_nonce(wp_unslash($_POST[self::NONCE_FIELD]), self::NONCE_ACTION)) {
            return;
        }
        if (!current_user_can('edit_product', $product_id)) {
            return;
        }

        $raw = isset($_POST['_content_matrix_json']) ? wp_unslash($_POST['_content_matrix_json']) : '';
        $decoded = $raw !== '' ? json_decode($raw, true) : null;

        if (!is_array($decoded)) {
            delete_post_meta($product_id, self::META_KEY);
            self::invalidate($product_id);
            return;
        }

        $columns = [];
        $seenKeys = [];
        foreach ((array)($decoded['columns'] ?? []) as $col) {
            $key = isset($col['key']) ? self::normalizeKey((string)$col['key']) : '';
            if ($key === '' || isset($seenKeys[$key])) continue;
            $label = isset($col['label']) ? sanitize_text_field($col['label']) : '';
            $columns[] = ['key' => $key, 'label' => $label !== '' ? $label : $key];
            $seenKeys[$key] = true;
        }

        $validKeys = array_column($columns, 'key');

        $items = [];
        foreach ((array)($decoded['items'] ?? []) as $item) {
            $name = isset($item['name']) ? sanitize_text_field($item['name']) : '';
            if ($name === '') continue;
            $included = array_values(array_intersect((array)($item['included_in'] ?? []), $validKeys));
            $items[] = ['name' => $name, 'included_in' => $included];
        }

        $imageId = 0;
        if (!empty($decoded['image']) && is_numeric($decoded['image'])) {
            $candidate = (int)$decoded['image'];
            if (wp_attachment_is_image($candidate)) {
                $imageId = $candidate;
            }
        }

        if (empty($columns) || empty($items)) {
            delete_post_meta($product_id, self::META_KEY);
        } else {
            update_post_meta($product_id, self::META_KEY, [
                'columns' => $columns,
                'items' => $items,
                'image' => $imageId ?: null,
            ]);
        }

        self::invalidate($product_id);
    }

    private static function normalizeKey(string $raw): string
    {
        $key = strtolower(trim($raw));
        $key = preg_replace('/[^a-z0-9_\-]+/', '-', $key);
        return trim($key, '-');
    }

    private static function invalidate(int $product_id): void
    {
        BTL_Cache::delete("content_matrix_{$product_id}");

        $product = wc_get_product($product_id);
        if ($product && function_exists('btl_queue_revalidation')) {
            btl_queue_revalidation(["product-{$product->get_slug()}"]);
        }
    }

    public static function register_graphql(): void
    {
        register_graphql_object_type('ContentMatrixColumn', [
            'fields' => [
                'key' => ['type' => ['non_null' => 'String']],
                'label' => ['type' => ['non_null' => 'String']],
            ],
        ]);

        register_graphql_object_type('ContentMatrixItem', [
            'fields' => [
                'name' => ['type' => ['non_null' => 'String']],
                'includedIn' => [
                    'type' => ['non_null' => ['list_of' => ['non_null' => 'String']]],
                    'resolve' => static fn($item) => $item['included_in'] ?? [],
                ],
            ],
        ]);

        register_graphql_object_type('ContentMatrix', [
            'fields' => [
                'columns' => ['type' => ['non_null' => ['list_of' => ['non_null' => 'ContentMatrixColumn']]]],
                'items' => ['type' => ['non_null' => ['list_of' => ['non_null' => 'ContentMatrixItem']]]],
                'image' => ['type' => 'String'],
            ],
        ]);

        register_graphql_field('Product', 'contentMatrix', [
            'type' => 'ContentMatrix',
            'resolve' => static function ($product) {
                $id = (int)($product->databaseId ?? 0);
                if (!$id) return null;

                return BTL_Cache::remember("content_matrix_{$id}", static function () use ($id) {
                    $raw = get_post_meta($id, BTL_Content_Matrix::metaKey(), true);
                    if (!is_array($raw) || empty($raw['columns']) || empty($raw['items'])) {
                        return null;
                    }

                    $imageUrl = null;
                    if (!empty($raw['image'])) {
                        $url = wp_get_attachment_url((int)$raw['image']);
                        $imageUrl = $url ?: null;
                    }

                    return [
                        'columns' => array_values($raw['columns']),
                        'items' => array_values($raw['items']),
                        'image' => $imageUrl,
                    ];
                }, 'btl', DAY_IN_SECONDS);
            },
        ]);
    }

    public static function metaKey(): string
    {
        return self::META_KEY;
    }
}