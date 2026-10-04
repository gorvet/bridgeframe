<?php
defined('ABSPATH') || exit;

class Bridgeframe_Content {

    public static function get_featured_or_first_image($post_id) {
        $image_data = [];

        if (has_post_thumbnail($post_id)) {
            $thumb_id = get_post_thumbnail_id($post_id);
            return self::get_attachment_image_data($thumb_id);
        }

        $post = get_post($post_id);
        if ($post) {
            preg_match("/<img.+src=['\"](?P<src>.+?)['\"].*>/i", $post->post_content, $image);

            if (!empty($image['src'])) {
                $image_data['full'] = esc_url_raw($image['src']);
            }
        }

        return $image_data ?: null;
    }

    public static function get_attachment_image_data($attachment_id) {
        $attachment_id = absint($attachment_id);

        if (!$attachment_id) {
            return null;
        }

        $image_data = [
            'id'    => $attachment_id,
            'alt'   => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            'title' => get_the_title($attachment_id),
        ];

        $used_urls = [];

        foreach (self::get_all_image_sizes() as $size) {
            $src = wp_get_attachment_image_src($attachment_id, $size);

            if ($src && isset($src[0]) && !in_array($src[0], $used_urls, true)) {
                $image_data[$size] = esc_url_raw($src[0]);
                $used_urls[] = $src[0];
            }
        }

        $full = wp_get_attachment_url($attachment_id);
        if ($full && empty($image_data['full'])) {
            $image_data['full'] = esc_url_raw($full);
        }

        return $image_data;
    }

    public static function get_all_image_sizes() {
        global $_wp_additional_image_sizes;

        $sizes = ['thumbnail', 'medium', 'medium_large', 'large', 'full'];
        if (isset($_wp_additional_image_sizes) && is_array($_wp_additional_image_sizes)) {
            $sizes = array_unique(array_merge($sizes, array_keys($_wp_additional_image_sizes)));
        }

        return array_values($sizes);
    }

    public static function get_post_meta_data($post_id) {
        $all_meta = get_post_meta($post_id);
        $clean = [];
        $post = get_post($post_id);
        $rest_meta_keys = $post ? self::get_rest_registered_post_meta_keys($post->post_type) : [];

        foreach ($all_meta as $key => $value) {
            if (!is_protected_meta($key, 'post') || in_array($key, $rest_meta_keys, true)) {
                $clean[$key] = maybe_unserialize($value[0]);
            }
        }

        $seo_keys = [
            '_yoast_wpseo_title',
            '_yoast_wpseo_metadesc',
            '_rank_math_title',
            '_rank_math_description',
            '_aioseo_title',
            '_aioseo_description',
        ];

        foreach ($seo_keys as $key) {
            if (isset($all_meta[$key])) {
                $clean[$key] = maybe_unserialize($all_meta[$key][0]);
            }
        }

        if (!empty($clean['_yoast_wpseo_metadesc'])) {
            $clean['seo_description'] = $clean['_yoast_wpseo_metadesc'];
        } elseif (!empty($clean['_rank_math_description'])) {
            $clean['seo_description'] = $clean['_rank_math_description'];
        } elseif (!empty($clean['_aioseo_description'])) {
            $clean['seo_description'] = $clean['_aioseo_description'];
        } else {
            $clean['seo_description'] = wp_trim_words(strip_tags(get_the_excerpt($post_id)), 30);
        }

        if (!empty($clean['_yoast_wpseo_title'])) {
            $clean['seo_title'] = $clean['_yoast_wpseo_title'];
        } elseif (!empty($clean['_rank_math_title'])) {
            $clean['seo_title'] = $clean['_rank_math_title'];
        } elseif (!empty($clean['_aioseo_title'])) {
            $clean['seo_title'] = $clean['_aioseo_title'];
        } else {
            $clean['seo_title'] = get_the_title($post_id);
        }

        return $clean;
    }

    private static function get_rest_registered_post_meta($post_type) {
        if (!function_exists('get_registered_meta_keys')) {
            return [];
        }

        $global_meta = get_registered_meta_keys('post');
        $type_meta = get_registered_meta_keys('post', $post_type);
        $registered = array_merge(
            is_array($global_meta) ? $global_meta : [],
            is_array($type_meta) ? $type_meta : []
        );
        $rest_meta = [];

        foreach ($registered as $key => $args) {
            if (empty($args['show_in_rest'])) {
                continue;
            }

            $rest_meta[$key] = $args;
        }

        return $rest_meta;
    }

    private static function get_rest_registered_post_meta_keys($post_type) {
        return array_keys(self::get_rest_registered_post_meta($post_type));
    }

    public static function get_rest_registered_post_meta_schema($post_type) {
        $schema = [];

        foreach (self::get_rest_registered_post_meta($post_type) as $key => $args) {
            $rest_schema = [];

            if (is_array($args['show_in_rest'] ?? null) && isset($args['show_in_rest']['schema']) && is_array($args['show_in_rest']['schema'])) {
                $rest_schema = $args['show_in_rest']['schema'];
            }

            $schema[] = [
                'name'        => $key,
                'type'        => $rest_schema['type'] ?? ($args['type'] ?? 'string'),
                'single'      => !empty($args['single']),
                'description' => $args['description'] ?? '',
                'protected'   => is_protected_meta($key, 'post'),
            ];
        }

        return $schema;
    }

    public static function get_acf_data($post_id, $field_names = []) {
        if (!function_exists('get_field_objects')) {
            return null;
        }

        $objects = get_field_objects($post_id);
        if (empty($objects) || !is_array($objects)) {
            return null;
        }

        $field_names = array_filter(array_map('sanitize_key', (array) $field_names));
        $data = [];

        foreach ($objects as $name => $field) {
            $key = sanitize_key($name);

            if (!empty($field_names) && !in_array($key, $field_names, true)) {
                continue;
            }

            $data[$key] = self::normalize_acf_value($field['value'] ?? null, $field);
        }

        return $data ?: null;
    }

    public static function get_acf_schema_for_post_type($post_type) {
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return [];
        }

        $groups = acf_get_field_groups(['post_type' => $post_type]);
        $schema = [];

        foreach ($groups as $group) {
            $fields = acf_get_fields($group);
            $schema[] = [
                'key'    => $group['key'] ?? '',
                'title'  => $group['title'] ?? '',
                'fields' => self::format_acf_schema_fields($fields ?: []),
            ];
        }

        return $schema;
    }

    private static function format_acf_schema_fields($fields) {
        $schema = [];

        foreach ($fields as $field) {
            $item = [
                'key'      => $field['key'] ?? '',
                'name'     => $field['name'] ?? '',
                'label'    => $field['label'] ?? '',
                'type'     => $field['type'] ?? '',
                'required' => !empty($field['required']),
            ];

            if (!empty($field['choices']) && is_array($field['choices'])) {
                $item['choices'] = $field['choices'];
            }

            if (!empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                $item['sub_fields'] = self::format_acf_schema_fields($field['sub_fields']);
            }

            $schema[] = $item;
        }

        return $schema;
    }

    private static function normalize_acf_value($value, $field = []) {
        $type = $field['type'] ?? '';

        if ($value instanceof WP_Post) {
            return self::format_post_reference($value);
        }

        if ($value instanceof WP_Term) {
            return self::format_term_reference($value);
        }

        if ($type === 'image') {
            return self::normalize_acf_image($value);
        }

        if ($type === 'gallery' && is_array($value)) {
            return array_values(array_filter(array_map([__CLASS__, 'normalize_acf_image'], $value)));
        }

        if (in_array($type, ['post_object', 'relationship'], true)) {
            return self::normalize_reference_list($value, 'post');
        }

        if ($type === 'taxonomy') {
            return self::normalize_reference_list($value, 'term');
        }

        if (is_array($value)) {
            $clean = [];

            foreach ($value as $key => $item) {
                $clean[$key] = self::normalize_acf_value($item);
            }

            return $clean;
        }

        return $value;
    }

    private static function normalize_acf_image($value) {
        if (is_numeric($value)) {
            return self::get_attachment_image_data((int) $value);
        }

        if (is_array($value)) {
            $attachment_id = !empty($value['ID']) ? absint($value['ID']) : absint($value['id'] ?? 0);

            if ($attachment_id) {
                return self::get_attachment_image_data($attachment_id);
            }

            if (!empty($value['url'])) {
                return ['full' => esc_url_raw($value['url'])];
            }
        }

        if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
            return ['full' => esc_url_raw($value)];
        }

        return null;
    }

    private static function normalize_reference_list($value, $type) {
        if (!is_array($value)) {
            $value = [$value];
        }

        $items = [];

        foreach ($value as $item) {
            if ($type === 'post' && $item instanceof WP_Post) {
                $items[] = self::format_post_reference($item);
            } elseif ($type === 'post' && is_numeric($item)) {
                $post = get_post((int) $item);
                if ($post) {
                    $items[] = self::format_post_reference($post);
                }
            } elseif ($type === 'term' && $item instanceof WP_Term) {
                $items[] = self::format_term_reference($item);
            }
        }

        return $items;
    }

    private static function format_post_reference($post) {
        return [
            'id'     => $post->ID,
            'title'  => get_the_title($post),
            'slug'   => $post->post_name,
            'type'   => $post->post_type,
            'status' => $post->post_status,
            'link'   => get_permalink($post),
        ];
    }

    private static function format_term_reference($term) {
        $link = get_term_link($term);

        return [
            'id'       => $term->term_id,
            'name'     => $term->name,
            'slug'     => $term->slug,
            'taxonomy' => $term->taxonomy,
            'link'     => is_wp_error($link) ? '' : $link,
        ];
    }

    public static function get_post_taxonomies($post_id, $post_type) {
        $result = [];
        $taxonomies = get_object_taxonomies($post_type, 'objects');

        foreach ($taxonomies as $tax_slug => $tax_obj) {
            $terms_data = [];
            $terms = wp_get_post_terms($post_id, $tax_slug);

            if (is_wp_error($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $terms_data[] = self::format_term_reference($term);
            }

            if (!empty($terms_data)) {
                $result[] = [
                    'name'  => $tax_obj->label,
                    'slug'  => $tax_obj->name,
                    'terms' => $terms_data,
                ];
            }
        }

        return $result;
    }
}
