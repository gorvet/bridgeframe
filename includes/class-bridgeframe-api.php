<?php
defined('ABSPATH') || exit;

class Bridgeframe_API {

    const CACHE_TTL = 300;
    const MAX_LIST_LIMIT = 100;
    const MAX_TERMS_LIMIT = 100;

    public static function register_routes() {
        register_rest_route('bridgeframe/v1', '/html', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_html'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('bridgeframe/v1', '/list', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_list'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('bridgeframe/v1', '/terms', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_terms_list'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('bridgeframe/v1', '/menu', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_menu'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('bridgeframe/v1', '/schema', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_schema'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('bridgeframe/v1', '/comments', [
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'get_comments'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'create_comment'],
                'permission_callback' => '__return_true',
            ],
        ]);

        register_rest_route('bridgeframe/v1', '/comments/(?P<id>\d+)/moderate', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'moderate_comment'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function send_cors_headers($served, $result, $request, $server) {
        if (!$request instanceof WP_REST_Request || !preg_match('#^/bridgeframe/v[12]/#', $request->get_route())) {
            return $served;
        }

        $mode = Bridgeframe_Utils::get_cors_mode();
        if (strpos($request->get_route(), '/bridgeframe/v2/') === 0) {
            foreach (['Access-Control-Allow-Origin', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Headers', 'Access-Control-Allow-Credentials', 'Access-Control-Max-Age'] as $cors_header) {
                header_remove($cors_header);
            }
        }
        if ($mode === 'disabled') {
            return $served;
        }

        $origin = isset($_SERVER['HTTP_ORIGIN'])
            ? Bridgeframe_Utils::normalize_origin(wp_unslash($_SERVER['HTTP_ORIGIN']))
            : '';

        if ($origin === '') {
            return $served;
        }

        if ($mode === 'restricted' && !in_array($origin, Bridgeframe_Utils::get_allowed_origins(), true)) {
            return $served;
        }

        header_remove('Access-Control-Allow-Origin');
        header_remove('Access-Control-Allow-Methods');
        header_remove('Access-Control-Allow-Headers');
        header_remove('Access-Control-Max-Age');

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-BridgeFrame-Contract');
        header('Access-Control-Max-Age: 600');
        header('Vary: Origin', false);

        return $served;
    }

    public static function clear_cache() {
        global $wpdb;

        $transient_pattern = $wpdb->esc_like('_transient_bridgeframe_') . '%';
        $timeout_pattern = $wpdb->esc_like('_transient_timeout_bridgeframe_') . '%';

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $transient_pattern,
                $timeout_pattern
            )
        );
    }

    private static function validate_token($request) {
        if (strpos($request->get_route(), '/bridgeframe/v2/') === 0) {
            $permission = Bridgeframe_V2::authorize($request);
            if (is_wp_error($permission)) {
                return new WP_REST_Response([
                    'code' => $permission->get_error_code(),
                    'error' => $permission->get_error_message(),
                ], (int) $permission->get_error_data()['status']);
            }
            return true;
        }
        $token = sanitize_text_field(self::get_scalar_param($request, 'token'));

        if ($token === '') {
            $token = self::get_bearer_token($request);
        }

        if (!Bridgeframe_Auth::is_token_valid($token)) {
            return new WP_REST_Response(['error' => 'Token inválido o ausente'], 403);
        }

        return $token;
    }

    private static function get_bearer_token($request) {
        $authorization = '';

        if (method_exists($request, 'get_header')) {
            $authorization = (string) $request->get_header('authorization');
        }

        if ($authorization === '') {
            foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $server_key) {
                if (!empty($_SERVER[$server_key])) {
                    $authorization = sanitize_text_field(wp_unslash($_SERVER[$server_key]));
                    break;
                }
            }
        }

        if (preg_match('/Bearer\s+(.+)$/i', $authorization, $matches)) {
            return sanitize_text_field($matches[1]);
        }

        return '';
    }

    private static function get_scalar_param($request, $name, $default = '') {
        $value = $request->get_param($name);

        if ($value === null || is_array($value) || is_object($value)) {
            return $default;
        }

        return (string) wp_unslash($value);
    }

    private static function get_array_param($request, $name) {
        $value = $request->get_param($name);

        if (is_string($value)) {
            $decoded = json_decode(wp_unslash($value), true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? wp_unslash($value) : [];
    }

    private static function get_bool_param($request, $name, $default = false) {
        $raw = self::get_scalar_param($request, $name, null);

        if ($raw === null) {
            return $default;
        }

        $value = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $value === null ? $default : $value;
    }

    private static function sanitize_limit($value, $default, $max, $allow_zero = false) {
        if ($value === null || $value === '') {
            $limit = $default;
        } else {
            $limit = intval($value);
        }

        $minimum = $allow_zero ? 0 : 1;
        $limit = max($minimum, $limit);

        if ($max > 0 && $limit > 0) {
            $limit = min($limit, $max);
        }

        return $limit;
    }

    private static function parse_csv($value, $sanitize_callback = 'sanitize_text_field') {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $items = array_map('trim', explode(',', $value));
        $items = array_filter($items, function ($item) {
            return $item !== '';
        });

        return array_values(array_map($sanitize_callback, $items));
    }

    private static function parse_int_csv($value) {
        $items = self::parse_csv($value, 'absint');
        return array_values(array_filter($items));
    }

    private static function parse_fields($fields_param, $allowed_fields) {
        if (!is_string($fields_param) || trim($fields_param) === '') {
            return [];
        }

        $fields = array_map('trim', explode(',', $fields_param));
        $fields = array_map('sanitize_key', $fields);
        $fields = array_filter($fields);

        return array_values(array_intersect($fields, $allowed_fields));
    }

    private static function should_include_field($fields, $field) {
        return empty($fields) || in_array($field, $fields, true);
    }

    private static function cache_key($scope, $parts) {
        return 'bridgeframe_' . md5($scope . ':' . wp_json_encode($parts));
    }

    private static function get_cached($cache_key) {
        if (!Bridgeframe_Utils::is_cache_enabled()) {
            return false;
        }

        return get_transient($cache_key);
    }

    private static function set_cached($cache_key, $response) {
        if (!Bridgeframe_Utils::is_cache_enabled()) {
            return;
        }

        set_transient($cache_key, $response, Bridgeframe_Utils::get_cache_ttl());
    }

    private static function restore_locale_if_needed($switched_locale) {
        if ($switched_locale && function_exists('restore_previous_locale')) {
            restore_previous_locale();
        }
    }

    private static function allowed_content_fields() {
        return ['id', 'title', 'slug', 'type', 'status', 'date', 'modified', 'link', 'excerpt', 'html', 'image', 'meta', 'acf', 'taxonomies', 'private'];
    }

    private static function build_tax_query($request, $taxonomy, $term) {
        $queries = [];
        $terms_param = self::get_scalar_param($request, 'terms');

        if ($taxonomy && ($term || $terms_param)) {
            $field = sanitize_key(self::get_scalar_param($request, 'term_field', 'slug'));
            $field = in_array($field, ['slug', 'term_id', 'id', 'name'], true) ? $field : 'slug';
            $operator = strtoupper(sanitize_text_field(self::get_scalar_param($request, 'tax_operator', 'IN')));
            $operator = in_array($operator, ['IN', 'NOT IN', 'AND'], true) ? $operator : 'IN';
            $terms = $terms_param ? self::parse_csv($terms_param, 'sanitize_text_field') : [sanitize_text_field($term)];

            if ($field === 'term_id' || $field === 'id') {
                $field = 'term_id';
                $terms = array_values(array_filter(array_map('absint', $terms)));
            }

            if (!empty($terms)) {
                $queries[] = [
                    'taxonomy' => $taxonomy,
                    'field'    => $field,
                    'terms'    => $terms,
                    'operator' => $operator,
                ];
            }
        }

        foreach (self::get_array_param($request, 'tax_query') as $item) {
            if (!is_array($item)) {
                continue;
            }

            $item_taxonomy = sanitize_key($item['taxonomy'] ?? '');
            if (!$item_taxonomy || !taxonomy_exists($item_taxonomy)) {
                continue;
            }

            $item_field = sanitize_key($item['field'] ?? 'slug');
            $item_field = in_array($item_field, ['slug', 'term_id', 'id', 'name'], true) ? $item_field : 'slug';
            $item_operator = strtoupper(sanitize_text_field($item['operator'] ?? 'IN'));
            $item_operator = in_array($item_operator, ['IN', 'NOT IN', 'AND'], true) ? $item_operator : 'IN';
            $item_terms = $item['terms'] ?? [];
            $item_terms = is_array($item_terms) ? $item_terms : explode(',', (string) $item_terms);

            if ($item_field === 'term_id' || $item_field === 'id') {
                $item_field = 'term_id';
                $item_terms = array_values(array_filter(array_map('absint', $item_terms)));
            } else {
                $item_terms = array_values(array_filter(array_map('sanitize_text_field', $item_terms)));
            }

            if (!empty($item_terms)) {
                $queries[] = [
                    'taxonomy' => $item_taxonomy,
                    'field'    => $item_field,
                    'terms'    => $item_terms,
                    'operator' => $item_operator,
                ];
            }
        }

        if (count($queries) > 1) {
            $relation = strtoupper(sanitize_key(self::get_scalar_param($request, 'tax_relation', 'AND')));
            $queries = array_merge(['relation' => in_array($relation, ['AND', 'OR'], true) ? $relation : 'AND'], $queries);
        }

        return $queries;
    }

    private static function build_date_query($request) {
        $date_query = [];
        $after = sanitize_text_field(self::get_scalar_param($request, 'date_after'));
        $before = sanitize_text_field(self::get_scalar_param($request, 'date_before'));
        $modified_after = sanitize_text_field(self::get_scalar_param($request, 'modified_after'));
        $modified_before = sanitize_text_field(self::get_scalar_param($request, 'modified_before'));

        if ($after || $before) {
            $published = ['inclusive' => true];
            if ($after) $published['after'] = $after;
            if ($before) $published['before'] = $before;
            $date_query[] = $published;
        }

        if ($modified_after || $modified_before) {
            $modified = ['column' => 'post_modified_gmt', 'inclusive' => true];
            if ($modified_after) $modified['after'] = $modified_after;
            if ($modified_before) $modified['before'] = $modified_before;
            $date_query[] = $modified;
        }

        return $date_query;
    }

    private static function build_meta_query($request) {
        $queries = [];
        $meta_key = sanitize_key(self::get_scalar_param($request, 'meta_key'));

        if ($meta_key !== '') {
            $queries[] = self::format_meta_clause([
                'key'     => $meta_key,
                'value'   => self::get_scalar_param($request, 'meta_value'),
                'compare' => self::get_scalar_param($request, 'meta_compare', '='),
                'type'    => self::get_scalar_param($request, 'meta_type', 'CHAR'),
            ]);
        }

        foreach (self::get_array_param($request, 'meta_query') as $item) {
            if (!is_array($item)) {
                continue;
            }

            $clause = self::format_meta_clause($item);
            if (!empty($clause['key'])) {
                $queries[] = $clause;
            }
        }

        if (count($queries) > 1) {
            $relation = strtoupper(sanitize_key(self::get_scalar_param($request, 'meta_relation', 'AND')));
            $queries = array_merge(['relation' => in_array($relation, ['AND', 'OR'], true) ? $relation : 'AND'], $queries);
        }

        return $queries;
    }

    private static function format_meta_clause($item) {
        $allowed_compare = ['=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'EXISTS', 'NOT EXISTS'];
        $allowed_type = ['NUMERIC', 'BINARY', 'CHAR', 'DATE', 'DATETIME', 'DECIMAL', 'SIGNED', 'TIME', 'UNSIGNED'];
        $compare = strtoupper(sanitize_text_field($item['compare'] ?? '='));
        $type = strtoupper(sanitize_key($item['type'] ?? 'CHAR'));

        if (!in_array($compare, $allowed_compare, true)) {
            $compare = '=';
        }

        if (!in_array($type, $allowed_type, true)) {
            $type = 'CHAR';
        }

        $clause = [
            'key'     => sanitize_key($item['key'] ?? ''),
            'compare' => $compare,
            'type'    => $type,
        ];

        if (!in_array($compare, ['EXISTS', 'NOT EXISTS'], true)) {
            $value = $item['value'] ?? '';
            $clause['value'] = is_array($value)
                ? array_map('sanitize_text_field', wp_unslash($value))
                : sanitize_text_field(wp_unslash((string) $value));
        }

        return $clause;
    }

    private static function format_post($post, $fields, $format = 'html', $acf_fields = []) {
        $data = [];

        if (self::should_include_field($fields, 'id')) $data['id'] = $post->ID;
        if (self::should_include_field($fields, 'title')) $data['title'] = $post->post_title;
        if (self::should_include_field($fields, 'slug')) $data['slug'] = $post->post_name;
        if (self::should_include_field($fields, 'type')) $data['type'] = $post->post_type;
        if (self::should_include_field($fields, 'status')) $data['post_status'] = $post->post_status;
        if (self::should_include_field($fields, 'date')) $data['date'] = get_the_date(DATE_ATOM, $post);
        if (self::should_include_field($fields, 'modified')) $data['modified'] = get_post_modified_time(DATE_ATOM, false, $post, true);
        if (self::should_include_field($fields, 'link')) $data['link'] = get_permalink($post);
        if (self::should_include_field($fields, 'excerpt')) $data['excerpt'] = get_the_excerpt($post);

        if (self::should_include_field($fields, 'html')) {
            $data['html'] = $format === 'json'
                ? $post->post_content
                : apply_filters('the_content', $post->post_content);
        }

        if (self::should_include_field($fields, 'image')) {
            $data['image'] = Bridgeframe_Content::get_featured_or_first_image($post->ID);
        }

        if (self::should_include_field($fields, 'meta')) {
            $data['meta'] = Bridgeframe_Content::get_post_meta_data($post->ID);
        }

        if (self::should_include_field($fields, 'acf')) {
            $acf = Bridgeframe_Content::get_acf_data($post->ID, $acf_fields);
            if ($acf) {
                $data['acf'] = $acf;
            }
        }

        if (self::should_include_field($fields, 'taxonomies')) {
            $data['taxonomies'] = Bridgeframe_Content::get_post_taxonomies($post->ID, $post->post_type);
        }

        if (self::should_include_field($fields, 'private')) {
            $data['private'] = ($post->post_status === 'private');
        }

        return $data;
    }

    private static function resolve_menu($request) {
        $location = sanitize_key(self::get_scalar_param($request, 'location'));
        $slug = sanitize_title(self::get_scalar_param($request, 'slug'));
        $menu_id = absint(self::get_scalar_param($request, 'id'));
        $menu = null;

        if ($location !== '') {
            $locations = get_nav_menu_locations();

            if (empty($locations[$location])) {
                return new WP_Error('bridgeframe_menu_not_found', 'Ubicación de menú no encontrada.');
            }

            $menu = wp_get_nav_menu_object($locations[$location]);
        } elseif ($menu_id) {
            $menu = wp_get_nav_menu_object($menu_id);
        } elseif ($slug !== '') {
            $menu = wp_get_nav_menu_object($slug);
        }

        if (!$menu || is_wp_error($menu)) {
            return new WP_Error('bridgeframe_menu_not_found', 'Menú no encontrado.');
        }

        return $menu;
    }

    private static function format_menu_item($item) {
        return [
            'id'          => (int) $item->ID,
            'title'       => $item->title,
            'url'         => $item->url,
            'target'      => $item->target,
            'description' => $item->description,
            'attr_title'  => $item->attr_title,
            'parent'      => (int) $item->menu_item_parent,
            'order'       => (int) $item->menu_order,
            'object_id'   => (int) $item->object_id,
            'object'      => $item->object,
            'type'        => $item->type,
            'classes'     => array_values(array_filter((array) $item->classes)),
        ];
    }

    private static function build_menu_tree($items, $parent_id = 0) {
        $branch = [];

        foreach ($items as $item) {
            if ((int) $item['parent'] !== (int) $parent_id) {
                continue;
            }

            $children = self::build_menu_tree($items, (int) $item['id']);
            if (!empty($children)) {
                $item['children'] = $children;
            }

            $branch[] = $item;
        }

        return $branch;
    }

    private static function get_comment_post($request, $require_comments_open = true) {
        $post_id = absint(self::get_scalar_param($request, 'post_id'));

        if (!$post_id) {
            $post_id = absint(self::get_scalar_param($request, 'id'));
        }

        $slug = sanitize_title(self::get_scalar_param($request, 'slug'));
        $type = sanitize_key(self::get_scalar_param($request, 'type', 'post'));

        if ($post_id) {
            $post = get_post($post_id);
        } elseif ($slug !== '') {
            if (!$type || !post_type_exists($type)) {
                return new WP_Error('bridgeframe_invalid_post_type', 'Tipo de contenido inválido.');
            }

            $posts = get_posts([
                'name'              => $slug,
                'post_type'         => $type,
                'post_status'       => 'publish',
                'posts_per_page'    => 1,
                'no_found_rows'     => true,
                'suppress_filters'  => false,
            ]);
            $post = $posts[0] ?? null;
        } else {
            return new WP_Error('bridgeframe_missing_post', 'Indica post_id o slug.');
        }

        if (!$post || $post->post_status !== 'publish') {
            return new WP_Error('bridgeframe_post_not_found', 'Contenido no encontrado.');
        }

        if ($require_comments_open && !comments_open($post->ID)) {
            return new WP_Error('bridgeframe_comments_closed', 'Los comentarios están cerrados para este contenido.');
        }

        return $post;
    }

    private static function normalize_comment_status($status) {
        $status = sanitize_key($status);
        $map = [
            'pending'   => 'hold',
            'moderated' => 'hold',
            'unapproved'=> 'hold',
            'hold'      => 'hold',
            'approved'  => 'approve',
            'approve'   => 'approve',
            'spam'      => 'spam',
            'trash'     => 'trash',
            'all'       => 'all',
        ];

        return $map[$status] ?? 'hold';
    }

    private static function get_comment_status_label($comment_approved) {
        $comment_approved = (string) $comment_approved;

        if ($comment_approved === '1') {
            return 'approved';
        }

        if ($comment_approved === 'spam') {
            return 'spam';
        }

        if ($comment_approved === 'trash') {
            return 'trash';
        }

        return 'pending';
    }

    private static function get_comments_counts($post_id = 0) {
        $counts = wp_count_comments($post_id);

        return [
            'all'      => isset($counts->total_comments) ? (int) $counts->total_comments : 0,
            'approved' => isset($counts->approved) ? (int) $counts->approved : 0,
            'pending'  => isset($counts->moderated) ? (int) $counts->moderated : 0,
            'spam'     => isset($counts->spam) ? (int) $counts->spam : 0,
            'trash'    => isset($counts->trash) ? (int) $counts->trash : 0,
        ];
    }

    private static function format_comment($comment) {
        $post = get_post($comment->comment_post_ID);
        $status = self::get_comment_status_label($comment->comment_approved);

        return [
            'id'           => (int) $comment->comment_ID,
            'post_id'      => (int) $comment->comment_post_ID,
            'post_title'   => $post ? get_the_title($post) : '',
            'post_type'    => $post ? $post->post_type : '',
            'post_slug'    => $post ? $post->post_name : '',
            'parent'       => (int) $comment->comment_parent,
            'author_name'  => $comment->comment_author,
            'author_email' => $comment->comment_author_email,
            'author_url'   => $comment->comment_author_url,
            'author_ip'    => $comment->comment_author_IP,
            'content'      => $comment->comment_content,
            'date'         => mysql_to_rfc3339($comment->comment_date_gmt ?: $comment->comment_date),
            'status'       => $status,
            'approved'     => $status === 'approved',
            'moderation'   => $status !== 'approved',
            'edit_url'     => admin_url('comment.php?action=editcomment&c=' . absint($comment->comment_ID)),
            'comments_url' => admin_url('edit-comments.php'),
        ];
    }

    private static function get_request_ip($request) {
        $ip = sanitize_text_field(self::get_scalar_param($request, 'author_ip'));

        if ($ip === '') {
            $ip = sanitize_text_field(self::get_scalar_param($request, 'visitor_ip'));
        }

        if ($ip === '' && !empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    private static function get_request_user_agent($request) {
        $user_agent = sanitize_text_field(self::get_scalar_param($request, 'user_agent'));

        if ($user_agent === '' && !empty($_SERVER['HTTP_USER_AGENT'])) {
            $user_agent = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']));
        }

        return substr($user_agent, 0, 254);
    }

    public static function get_html($request) {
        $token = self::validate_token($request);
        if ($token instanceof WP_REST_Response) {
            return $token;
        }

        $slug = sanitize_title(self::get_scalar_param($request, 'slug'));
        $id = absint(self::get_scalar_param($request, 'id'));
        $type = sanitize_key(self::get_scalar_param($request, 'type', 'post'));
        $lang = sanitize_text_field(self::get_scalar_param($request, 'lang'));
        $format = sanitize_key(self::get_scalar_param($request, 'format', 'html'));
        $private = self::get_bool_param($request, 'private', false);
        $fields_param = sanitize_text_field(self::get_scalar_param($request, 'fields'));
        $fields = self::parse_fields($fields_param, self::allowed_content_fields());
        $acf_fields = self::parse_csv(self::get_scalar_param($request, 'acf_fields'), 'sanitize_key');

        if (!$id && $slug === '') {
            return new WP_REST_Response(['error' => 'Indica id o slug.'], 400);
        }

        if (!$type || !post_type_exists($type)) {
            return new WP_REST_Response(['code' => 'invalid_content_type', 'error' => 'Tipo de contenido inválido'], 400);
        }

        if (!in_array($format, ['html', 'json'], true)) {
            $format = 'html';
        }

        $status = ['publish'];
        if ($private) {
            $status[] = 'private';
        }

        $cache_key = self::cache_key('html', [
            'id'      => $id,
            'slug'    => $slug,
            'type'    => $type,
            'format'  => $format,
            'lang'    => $lang,
            'private' => $private,
            'fields'  => $fields,
            'acf_fields' => $acf_fields,
        ]);

        $cached = self::get_cached($cache_key);
        if ($cached !== false) {
            return new WP_REST_Response($cached, 200);
        }

        $switched_locale = false;
        if ($lang && function_exists('switch_to_locale')) {
            $switched_locale = switch_to_locale($lang);
        }

        if ($id) {
            $post = get_post($id);
        } else {
            $posts = get_posts([
                'name'           => $slug,
                'post_type'      => $type,
                'post_status'    => $status,
                'posts_per_page' => 1,
                'no_found_rows'  => true,
                'suppress_filters' => false,
            ]);
            $post = $posts[0] ?? null;
        }

        if (!$post || $post->post_type !== $type || !in_array($post->post_status, $status, true)) {
            self::restore_locale_if_needed($switched_locale);

            if ($post && $post->post_status === 'private') {
                return new WP_REST_Response(['error' => 'Contenido privado', 'private' => true], 403);
            }

            return new WP_REST_Response(['error' => 'Contenido no encontrado'], 404);
        }

        $response = array_merge(['status' => 'success'], self::format_post($post, $fields, $format, $acf_fields));

        self::set_cached($cache_key, $response);
        self::restore_locale_if_needed($switched_locale);

        return new WP_REST_Response($response, 200);
    }

    public static function create_comment($request) {
        $token = self::validate_token($request);
        if ($token instanceof WP_REST_Response) {
            return $token;
        }

        $post = self::get_comment_post($request);
        if (is_wp_error($post)) {
            $status = $post->get_error_code() === 'bridgeframe_comments_closed' ? 403 : 400;

            if ($post->get_error_code() === 'bridgeframe_post_not_found') {
                $status = 404;
            }

            return new WP_REST_Response(['error' => $post->get_error_message()], $status);
        }

        $author_name = sanitize_text_field(self::get_scalar_param($request, 'author_name'));
        $author_email = sanitize_email(self::get_scalar_param($request, 'author_email'));
        $author_url = esc_url_raw(self::get_scalar_param($request, 'author_url'));
        $content = trim(wp_kses_post(self::get_scalar_param($request, 'content')));
        $parent = absint(self::get_scalar_param($request, 'parent'));
        $user_id = absint(self::get_scalar_param($request, 'user_id'));

        if ($user_id && !get_user_by('id', $user_id)) {
            return new WP_REST_Response(['error' => 'Usuario inválido.'], 400);
        }

        if ($author_name === '') {
            return new WP_REST_Response(['error' => 'Indica el nombre del autor.'], 400);
        }

        if ($author_email === '' || !is_email($author_email)) {
            return new WP_REST_Response(['error' => 'Indica un email válido.'], 400);
        }

        if ($content === '') {
            return new WP_REST_Response(['error' => 'El comentario no puede estar vacío.'], 400);
        }

        if (strlen($content) > 5000) {
            return new WP_REST_Response(['error' => 'El comentario supera la longitud permitida.'], 400);
        }

        if ($parent) {
            $parent_comment = get_comment($parent);

            if (!$parent_comment || (int) $parent_comment->comment_post_ID !== (int) $post->ID) {
                return new WP_REST_Response(['error' => 'Comentario padre inválido.'], 400);
            }
        }

        $comment_data = [
            'comment_post_ID'      => (int) $post->ID,
            'comment_author'       => $author_name,
            'comment_author_email' => $author_email,
            'comment_author_url'   => $author_url,
            'comment_content'      => $content,
            'comment_type'         => 'comment',
            'comment_parent'       => $parent,
            'user_id'              => $user_id,
            'comment_author_IP'    => self::get_request_ip($request),
            'comment_agent'        => self::get_request_user_agent($request),
        ];

        $comment_id = wp_new_comment($comment_data, true);

        if (is_wp_error($comment_id)) {
            return new WP_REST_Response(['error' => $comment_id->get_error_message()], 400);
        }

        clean_post_cache($post->ID);
        self::clear_cache();

        $comment = get_comment($comment_id);
        $comment_status = $comment ? (string) $comment->comment_approved : '0';

        return new WP_REST_Response([
            'status'         => 'success',
            'comment_id'     => (int) $comment_id,
            'post_id'        => (int) $post->ID,
            'approved'       => $comment_status === '1',
            'moderation'     => $comment_status !== '1',
            'comment_status' => $comment_status,
        ], 201);
    }

    public static function get_comments($request) {
        $token = self::validate_token($request);
        if ($token instanceof WP_REST_Response) {
            return $token;
        }

        $post_id = 0;
        $has_post_filter = absint(self::get_scalar_param($request, 'post_id')) || absint(self::get_scalar_param($request, 'id')) || self::get_scalar_param($request, 'slug') !== '';

        if ($has_post_filter) {
            $post = self::get_comment_post($request, false);

            if (is_wp_error($post)) {
                $status_code = $post->get_error_code() === 'bridgeframe_post_not_found' ? 404 : 400;
                return new WP_REST_Response(['error' => $post->get_error_message()], $status_code);
            }

            $post_id = (int) $post->ID;
        }

        $status = self::normalize_comment_status(self::get_scalar_param($request, 'status', 'hold'));
        $limit = self::sanitize_limit(self::get_scalar_param($request, 'limit', 20), 20, self::MAX_LIST_LIMIT);
        $page = max(1, absint(self::get_scalar_param($request, 'page', 1)));
        $offset = ($page - 1) * $limit;
        $search = sanitize_text_field(self::get_scalar_param($request, 's'));
        $order = strtoupper(sanitize_key(self::get_scalar_param($request, 'order', 'DESC')));
        $orderby = sanitize_key(self::get_scalar_param($request, 'orderby', 'date'));
        $parent = self::get_scalar_param($request, 'parent');

        $allowed_order = ['ASC', 'DESC'];
        $allowed_orderby = ['date', 'id', 'comment_date', 'comment_date_gmt'];

        if (!in_array($order, $allowed_order, true)) {
            $order = 'DESC';
        }

        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'date';
        }

        if ($orderby === 'id') {
            $orderby = 'comment_ID';
        }

        $args = [
            'status'  => $status,
            'type'    => 'comment',
            'number'  => $limit,
            'offset'  => $offset,
            'search'  => $search,
            'orderby' => $orderby,
            'order'   => $order,
        ];

        if ($post_id) {
            $args['post_id'] = $post_id;
        }

        if ($parent !== '') {
            $args['parent'] = intval($parent);
        }

        $comments = get_comments($args);
        $total = (int) get_comments(array_merge($args, [
            'count'  => true,
            'number' => 0,
            'offset' => 0,
        ]));

        return new WP_REST_Response([
            'items'      => array_map([__CLASS__, 'format_comment'], $comments),
            'total'      => $total,
            'pages'      => $limit > 0 ? (int) ceil($total / $limit) : 1,
            'current'    => $page,
            'status'     => $status,
            'post_id'    => $post_id,
            'counts'     => self::get_comments_counts($post_id),
            'admin_urls' => [
                'all'     => admin_url('edit-comments.php'),
                'pending' => admin_url('edit-comments.php?comment_status=moderated'),
            ],
        ], 200);
    }

    public static function moderate_comment($request) {
        $token = self::validate_token($request);
        if ($token instanceof WP_REST_Response) {
            return $token;
        }

        $comment_id = absint($request->get_param('id'));
        $comment = get_comment($comment_id);

        if (!$comment) {
            return new WP_REST_Response(['error' => 'Comentario no encontrado.'], 404);
        }

        $action = sanitize_key(self::get_scalar_param($request, 'action'));

        if ($action === '') {
            $action = sanitize_key(self::get_scalar_param($request, 'status'));
        }

        $map = [
            'approve'  => 'approve',
            'approved' => 'approve',
            'hold'     => 'hold',
            'pending'  => 'hold',
            'reject'   => 'trash',
            'rejected' => 'trash',
            'trash'    => 'trash',
            'spam'     => 'spam',
            'delete'   => 'delete',
        ];

        if (empty($map[$action])) {
            return new WP_REST_Response(['error' => 'Acción de moderación inválida.'], 400);
        }

        if ($map[$action] === 'delete') {
            $result = wp_delete_comment($comment_id, true);
        } else {
            $result = wp_set_comment_status($comment_id, $map[$action]);
        }

        if (!$result) {
            return new WP_REST_Response(['error' => 'No se pudo actualizar el comentario.'], 500);
        }

        clean_comment_cache($comment_id);
        clean_post_cache($comment->comment_post_ID);
        self::clear_cache();

        $updated = get_comment($comment_id);

        return new WP_REST_Response([
            'status'     => 'success',
            'comment_id' => $comment_id,
            'deleted'    => $map[$action] === 'delete',
            'comment'    => $updated ? self::format_comment($updated) : null,
        ], 200);
    }

    public static function get_list($request) {
        $token = self::validate_token($request);
        if ($token instanceof WP_REST_Response) {
            return $token;
        }

        $type = sanitize_key(self::get_scalar_param($request, 'type', 'post'));
        $taxonomy = sanitize_key(self::get_scalar_param($request, 'taxonomy'));
        $term = sanitize_title(self::get_scalar_param($request, 'term'));
        $search = sanitize_text_field(self::get_scalar_param($request, 's'));
        $limit = self::sanitize_limit(self::get_scalar_param($request, 'limit', 10), 10, self::MAX_LIST_LIMIT);
        $page = max(1, absint(self::get_scalar_param($request, 'page', 1)));
        $private = self::get_bool_param($request, 'private', false);
        $orderby = sanitize_key(self::get_scalar_param($request, 'orderby', 'date'));
        $order = strtoupper(sanitize_key(self::get_scalar_param($request, 'order', 'DESC')));
        $fields_param = sanitize_text_field(self::get_scalar_param($request, 'fields'));
        $fields = self::parse_fields($fields_param, self::allowed_content_fields());
        $acf_fields = self::parse_csv(self::get_scalar_param($request, 'acf_fields'), 'sanitize_key');
        $ids = self::parse_int_csv(self::get_scalar_param($request, 'ids'));
        $exclude = self::parse_int_csv(self::get_scalar_param($request, 'exclude'));
        $author = absint(self::get_scalar_param($request, 'author'));
        $parent = self::get_scalar_param($request, 'parent');
        $format = sanitize_key(self::get_scalar_param($request, 'format', 'html'));

        if (!$type || !post_type_exists($type)) {
            return new WP_REST_Response(['code' => 'invalid_content_type', 'error' => 'Tipo de contenido inválido'], 400);
        }

        if ($taxonomy && !taxonomy_exists($taxonomy)) {
            return new WP_REST_Response(['code' => 'invalid_taxonomy', 'error' => 'Taxonomía inválida'], 400);
        }

        if ($term && !$taxonomy) {
            return new WP_REST_Response(['error' => 'Indica una taxonomía para filtrar por término.'], 400);
        }

        $allowed_orderby = ['date', 'title', 'modified', 'id', 'rand', 'menu_order', 'comment_count', 'meta_value', 'meta_value_num'];
        $allowed_order = ['ASC', 'DESC'];

        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'date';
        }

        if (!in_array($order, $allowed_order, true)) {
            $order = 'DESC';
        }

        if (!in_array($format, ['html', 'json'], true)) {
            $format = 'html';
        }

        $post_status = ['publish'];
        if ($private) {
            $post_status[] = 'private';
        }

        $tax_query = self::build_tax_query($request, $taxonomy, $term);
        $meta_query = self::build_meta_query($request);
        $date_query = self::build_date_query($request);

        $cache_key = self::cache_key('list', [
            'type'     => $type,
            'taxonomy' => $taxonomy,
            'term'     => $term,
            'search'   => $search,
            'limit'    => $limit,
            'page'     => $page,
            'private'  => $private,
            'orderby'  => $orderby,
            'order'    => $order,
            'fields'   => $fields,
            'acf_fields' => $acf_fields,
            'ids'      => $ids,
            'exclude'  => $exclude,
            'author'   => $author,
            'parent'   => $parent,
            'format'   => $format,
            'tax_query' => $tax_query,
            'meta_query' => $meta_query,
            'date_query' => $date_query,
        ]);

        $cached = self::get_cached($cache_key);
        if ($cached !== false) {
            return new WP_REST_Response($cached, 200);
        }

        $args = [
            'post_type'           => $type,
            'post_status'         => $post_status,
            'posts_per_page'      => $limit,
            'paged'               => $page,
            's'                   => $search,
            'orderby'             => $orderby,
            'order'               => $order,
            'ignore_sticky_posts' => true,
        ];

        if (!empty($ids)) {
            $args['post__in'] = $ids;
        }

        if (!empty($exclude)) {
            $args['post__not_in'] = $exclude;
        }

        if ($author) {
            $args['author'] = $author;
        }

        if ($parent !== '') {
            $args['post_parent'] = intval($parent);
        }

        if (in_array($orderby, ['meta_value', 'meta_value_num'], true)) {
            $meta_key = sanitize_key(self::get_scalar_param($request, 'meta_key'));
            if ($meta_key !== '') {
                $args['meta_key'] = $meta_key;
            }
        }

        if (!empty($tax_query)) {
            $args['tax_query'] = $tax_query;
        }

        if (!empty($meta_query)) {
            $args['meta_query'] = $meta_query;
        }

        if (!empty($date_query)) {
            $args['date_query'] = $date_query;
        }

        $query = new WP_Query($args);
        $items = [];

        foreach ($query->posts as $post) {
            $items[] = self::format_post($post, $fields, $format, $acf_fields);
        }

        $response = [
            'items'   => $items,
            'total'   => (int) $query->found_posts,
            'pages'   => (int) $query->max_num_pages,
            'current' => $page,
        ];

        self::set_cached($cache_key, $response);

        return new WP_REST_Response($response, 200);
    }

    public static function get_terms_list($request) {
        $token = self::validate_token($request);
        if ($token instanceof WP_REST_Response) {
            return $token;
        }

        $taxonomy = sanitize_key(self::get_scalar_param($request, 'taxonomy', 'category'));
        $hide_empty = self::get_bool_param($request, 'hide_empty', true);
        $search = sanitize_text_field(self::get_scalar_param($request, 's'));
        $parent = self::get_scalar_param($request, 'parent');
        $orderby = sanitize_key(self::get_scalar_param($request, 'orderby', 'name'));
        $order = strtoupper(sanitize_key(self::get_scalar_param($request, 'order', 'ASC')));
        $limit = self::sanitize_limit(self::get_scalar_param($request, 'limit', 0), 0, self::MAX_TERMS_LIMIT, true);
        $page = max(1, absint(self::get_scalar_param($request, 'page', 1)));
        $offset = $limit > 0 ? ($page - 1) * $limit : 0;
        $fields_param = sanitize_text_field(self::get_scalar_param($request, 'fields'));
        $fields = self::parse_fields($fields_param, ['id', 'name', 'slug', 'count', 'description', 'parent', 'link']);
        $with_total = self::get_bool_param($request, 'with_total', false);
        $include_total = $with_total || $limit > 0;
        $include_slugs = self::parse_csv(self::get_scalar_param($request, 'include'), 'sanitize_title');
        $exclude_slugs = self::parse_csv(self::get_scalar_param($request, 'exclude'), 'sanitize_title');

        if (!$taxonomy || !taxonomy_exists($taxonomy)) {
            return new WP_REST_Response(['code' => 'invalid_taxonomy', 'error' => 'Taxonomía inválida'], 400);
        }

        $allowed_orderby = ['name', 'slug', 'count', 'term_id', 'id', 'description', 'parent', 'none'];
        $allowed_order = ['ASC', 'DESC'];

        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'name';
        }

        if (!in_array($order, $allowed_order, true)) {
            $order = 'ASC';
        }

        $args = [
            'taxonomy'   => $taxonomy,
            'hide_empty' => $hide_empty,
            'search'     => $search,
            'orderby'    => $orderby,
            'order'      => $order,
            'number'     => $limit,
            'offset'     => $offset,
        ];

        if ($parent !== '') {
            $args['parent'] = intval($parent);
        }

        if (!empty($include_slugs)) {
            $ids = get_terms([
                'taxonomy'   => $taxonomy,
                'slug'       => $include_slugs,
                'hide_empty' => false,
                'fields'     => 'ids',
            ]);

            if (!is_wp_error($ids)) {
                $args['include'] = $ids;
            }
        }

        if (!empty($exclude_slugs)) {
            $ids = get_terms([
                'taxonomy'   => $taxonomy,
                'slug'       => $exclude_slugs,
                'hide_empty' => false,
                'fields'     => 'ids',
            ]);

            if (!is_wp_error($ids)) {
                $args['exclude'] = $ids;
            }
        }

        $cache_key = self::cache_key('terms', [
            'taxonomy'      => $taxonomy,
            'hide_empty'    => $hide_empty,
            'search'        => $search,
            'orderby'       => $orderby,
            'order'         => $order,
            'limit'         => $limit,
            'page'          => $page,
            'parent'        => $parent,
            'fields'        => $fields,
            'include_total' => $include_total,
            'include'       => $include_slugs,
            'exclude'       => $exclude_slugs,
        ]);

        $cached = self::get_cached($cache_key);
        if ($cached !== false) {
            return new WP_REST_Response($cached, 200);
        }

        $terms = get_terms($args);
        if (is_wp_error($terms)) {
            return new WP_REST_Response(['error' => $terms->get_error_message()], 500);
        }

        $items = array_map(function ($term_item) use ($fields) {
            $data = [];

            if (self::should_include_field($fields, 'id')) {
                $data['id'] = $term_item->term_id;
            }

            if (self::should_include_field($fields, 'name')) {
                $data['name'] = $term_item->name;
            }

            if (self::should_include_field($fields, 'slug')) {
                $data['slug'] = $term_item->slug;
            }

            if (self::should_include_field($fields, 'count')) {
                $data['count'] = (int) $term_item->count;
            }

            if (self::should_include_field($fields, 'description')) {
                $data['description'] = $term_item->description;
            }

            if (self::should_include_field($fields, 'parent')) {
                $data['parent'] = (int) $term_item->parent;
            }

            if (self::should_include_field($fields, 'link')) {
                $link = get_term_link($term_item);

                if (!is_wp_error($link)) {
                    $data['link'] = $link;
                }
            }

            return $data;
        }, $terms);

        $response = [
            'items'    => $items,
            'current'  => $page,
            'taxonomy' => $taxonomy,
        ];

        if ($include_total) {
            $count_query = new WP_Term_Query(array_merge($args, [
                'fields' => 'count',
                'number' => 0,
                'offset' => 0,
            ]));

            $total = $count_query->get_terms();

            if (!is_wp_error($total)) {
                $total = (int) $total;
                $response['total'] = $total;
                $response['pages'] = ($limit > 0 && $total) ? (int) ceil($total / $limit) : 1;
            }
        }

        self::set_cached($cache_key, $response);

        return new WP_REST_Response($response, 200);
    }

    public static function get_menu($request) {
        $token = self::validate_token($request);
        if ($token instanceof WP_REST_Response) {
            return $token;
        }

        $menu = self::resolve_menu($request);
        if (is_wp_error($menu)) {
            return new WP_REST_Response(['error' => $menu->get_error_message()], 404);
        }

        $flat = self::get_bool_param($request, 'flat', false);
        $cache_key = self::cache_key('menu', [
            'menu_id' => (int) $menu->term_id,
            'flat'    => $flat,
        ]);

        $cached = self::get_cached($cache_key);
        if ($cached !== false) {
            return new WP_REST_Response($cached, 200);
        }

        $items = wp_get_nav_menu_items($menu->term_id, [
            'update_post_term_cache' => false,
        ]);

        if (is_wp_error($items)) {
            return new WP_REST_Response(['error' => $items->get_error_message()], 500);
        }

        $formatted_items = array_map([__CLASS__, 'format_menu_item'], $items ?: []);

        $response = [
            'menu' => [
                'id'    => (int) $menu->term_id,
                'slug'  => $menu->slug,
                'name'  => $menu->name,
                'count' => (int) $menu->count,
            ],
            'items' => $flat ? $formatted_items : self::build_menu_tree($formatted_items),
        ];

        self::set_cached($cache_key, $response);

        return new WP_REST_Response($response, 200);
    }

    public static function get_schema($request) {
        $token = self::validate_token($request);
        if ($token instanceof WP_REST_Response) {
            return $token;
        }

        $type = sanitize_key(self::get_scalar_param($request, 'type'));

        if ($type && !post_type_exists($type)) {
            return new WP_REST_Response(['code' => 'invalid_content_type', 'error' => 'Tipo de contenido inválido'], 400);
        }

        $cache_key = self::cache_key('schema', ['type' => $type]);
        $cached = self::get_cached($cache_key);
        if ($cached !== false) {
            return new WP_REST_Response($cached, 200);
        }

        $post_types = get_post_types(['show_in_rest' => true], 'objects');
        unset($post_types['attachment']);

        if ($type) {
            $post_types = isset($post_types[$type]) ? [$type => $post_types[$type]] : [];
        }

        $types = [];

        foreach ($post_types as $post_type => $object) {
            $taxonomies = [];

            foreach (get_object_taxonomies($post_type, 'objects') as $tax_slug => $taxonomy) {
                $taxonomies[] = [
                    'name'         => $tax_slug,
                    'label'        => $taxonomy->label,
                    'hierarchical' => (bool) $taxonomy->hierarchical,
                    'rest_base'    => $taxonomy->rest_base ?: $tax_slug,
                ];
            }

            $types[] = [
                'name'       => $post_type,
                'label'      => $object->label,
                'rest_base'  => $object->rest_base ?: $post_type,
                'taxonomies' => $taxonomies,
                'meta'       => Bridgeframe_Content::get_rest_registered_post_meta_schema($post_type),
                'acf'        => Bridgeframe_Content::get_acf_schema_for_post_type($post_type),
            ];
        }

        $response = [
            'namespace'     => 'bridgeframe/v1',
            'contentFields' => self::allowed_content_fields(),
            'filters'       => [
                'type',
                'id',
                'slug',
                's',
                'limit',
                'page',
                'ids',
                'exclude',
                'author',
                'parent',
                'taxonomy',
                'term',
                'terms',
                'tax_query',
                'meta_key',
                'meta_value',
                'meta_compare',
                'meta_query',
                'date_after',
                'date_before',
                'modified_after',
                'modified_before',
                'orderby',
                'order',
                'fields',
                'acf_fields',
            ],
            'imageSizes'    => Bridgeframe_Content::get_all_image_sizes(),
            'menuLocations' => get_registered_nav_menus(),
            'types'         => $types,
        ];

        self::set_cached($cache_key, $response);

        return new WP_REST_Response($response, 200);
    }
}
