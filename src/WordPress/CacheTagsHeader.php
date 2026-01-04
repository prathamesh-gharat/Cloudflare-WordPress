<?php

namespace Cloudflare\APO\WordPress;

final class CacheTagsHeader
{
    const HEADER_NAME = 'Cache-Tag';

    public static function init(): void
    {
        add_action('send_headers', [__CLASS__, 'send_cache_tag_headers'], 20);
    }

    /**
     * Decide whether to tag this response at all.
     */
    private static function should_tag_response(): bool
    {
        // Allow turning off via filter.
        $enabled = apply_filters('cf_cache_tags_enabled', true);
        if (!$enabled) {
            return false;
        }

        // Avoid non-front-end contexts.
        if (is_admin()) {
            return false;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return false;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }

        // Avoid responses that generally shouldn't be edge-cached.
        if (is_feed() || is_trackback() || is_robots() || (function_exists('is_favicon') && is_favicon())) {
            return false;
        }

        // Only for safe cacheable methods.
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return false;
        }

        // If headers already sent, we can't add ours.
        if (headers_sent()) {
            return false;
        }

        return true;
    }

    /**
     * Sanitize a tag to printable ASCII without spaces (Cloudflare requirement).
     */
    private static function sanitize_tag(string $tag): string
    {
        $tag = strtolower($tag);
        $tag = str_replace(' ', '', $tag);            // Spaces not allowed
        // Keep only printable ASCII; replace other chars with underscore
        $tag = preg_replace('/[^\x21-\x7E]/', '_', $tag);
        // Remove commas (since commas are separators in the header)
        $tag = str_replace(',', '_', $tag);
        // Collapse repeats
        $tag = preg_replace('/_+/', '_', $tag);
        // Trim separators
        $tag = trim($tag, "_-:");
        // Truncate to 1024 chars (Cloudflare API limit for purging)
        $tag = substr($tag, 0, 1024);
        // Ensure at least 1 byte
        return $tag !== '' ? $tag : 'tag';
    }

    /**
     * Build the tag list based on WP conditionals.
     */
    private static function build_tags(): array
    {
        $tags = [];

        // Home (front page or posts page)
        if (is_front_page() || is_home()) {
            $tags[] = 'home';
        }

        // Taxonomy archives: category, tag, custom tax
        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term && !empty($term->taxonomy)) {
                $taxonomy = (string) $term->taxonomy;
                $tags[] = 'tax:' . $taxonomy; // {taxonomy_name}
            }
        }

        // Post type single: post, page, custom post types
        if (is_singular()) {
            $pt = get_post_type();
            if ($pt) {
                $tags[] = 'pt:' . $pt;
            }
        }

        // Post type archive
        if (is_post_type_archive()) {
            $pt = get_query_var('post_type');
            if (is_array($pt)) {
                foreach ($pt as $one) {
                    if ($one) {
                        $tags[] = 'pt:' . $one;
                        $tags[] = 'pt_archive:' . $one;
                    }
                }
            } elseif (is_string($pt) && $pt !== '') {
                $tags[] = 'pt:' . $pt;
                $tags[] = 'pt_archive:' . $pt;
            } else {
                // Fallback: sometimes queried object can help
                $obj = get_queried_object();
                if ($obj && !empty($obj->name)) {
                    $tags[] = 'pt:' . (string) $obj->name;
                    $tags[] = 'pt_archive:' . (string) $obj->name;
                }
            }
        }

        // Let you extend/override tags.
        $tags = apply_filters('cf_cache_tags', $tags);

        // Sanitize + unique
        $tags = array_values(array_unique(array_map([__CLASS__, 'sanitize_tag'], $tags)));

        return $tags;
    }

    /**
     * Send Cache-Tag headers. Cloudflare supports multiple Cache-Tag header fields.
     * Also keep the aggregate header size under 16KB.
     */
    public static function send_cache_tag_headers(): void
    {
        if (!self::should_tag_response()) {
            return;
        }

        $tags = self::build_tags();
        if (!$tags) {
            return;
        }

        // Chunk tags into multiple headers to stay safely under limits.
        // Cloudflare aggregate Cache-Tag header size limit is 16KB.
        $maxBytesPerHeader = 7500; // conservative; allows multiple headers well under 16KB aggregate
        $current = [];
        $currentLen = 0;

        foreach ($tags as $tag) {
            $tagLen = strlen($tag);
            $extra = ($currentLen === 0) ? $tagLen : ($tagLen + 1); // + comma
            if ($currentLen + $extra > $maxBytesPerHeader) {
                header(self::HEADER_NAME . ':' . implode(',', $current), false);
                $current = [$tag];
                $currentLen = $tagLen;
            } else {
                $current[] = $tag;
                $currentLen += $extra;
            }
        }

        if (!empty($current)) {
            header(self::HEADER_NAME . ':' . implode(',', $current), false);
        }

        /**
         * Optional debugging: Cloudflare removes Cache-Tag before sending to visitors.
         * If you want to see what was generated at origin, enable this:
         *
         * define('CLOUDFLARE_CACHE_TAGS_DEBUG', true);
         */
        if (defined('CLOUDFLARE_CACHE_TAGS_DEBUG') && CLOUDFLARE_CACHE_TAGS_DEBUG) {
            header('X-Test-Cache-Tag:' . implode(',', $tags), true);
        }
    }
}
