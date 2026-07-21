<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * NLWeb (nlweb.ai) projection: serves an NLWeb-compatible `ask` endpoint over the site's own
 * content and WooCommerce catalogue, so agents that speak NLWeb can query the site without it
 * deploying the full NLWeb stack. AI2Web advertises the surface at transports.nlweb.
 *
 * This is a basic keyword projection (WordPress search), not NLWeb's semantic/embedding retrieval.
 * Results use NLWeb's schema.org-flavoured Item shape, `list` mode. Read-only, like /ai2w/search.
 */
final class Ai2Web_Nlweb
{
    public const VERSION = '0.55';   // NLWeb response protocol version this projection targets
    private const LIMIT = 10;

    public static function enabled(): bool
    {
        return (bool) Ai2Web_Settings::get('nlweb', true);
    }

    /**
     * Handle GET/POST /ai2w/nlweb/ask. Returns ['status'=>int,'body'=>mixed].
     *
     * @param array<string,mixed> $body
     * @return array{status:int,body:mixed}
     */
    public static function ask(string $method, array $body): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only query.
        $query = isset($_GET['query']) ? sanitize_text_field(wp_unslash($_GET['query'])) : '';
        if ($query === '' && isset($body['query'])) {
            $query = sanitize_text_field((string) $body['query']);
        }
        if ($query === '') {
            return ['status' => 400, 'body' => ['error' => ['code' => 'invalid_request', 'message' => 'A query is required.']]];
        }
        if (mb_strlen($query) > 500) {
            return ['status' => 400, 'body' => ['error' => ['code' => 'invalid_request', 'message' => 'Query is too long.']]];
        }

        $items = [];
        foreach (get_posts(['s' => $query, 'numberposts' => self::LIMIT, 'post_type' => ['post', 'page'], 'post_status' => 'publish']) as $p) {
            $items[] = self::content_item($p);
        }
        if (class_exists('WooCommerce') && function_exists('wc_get_product')) {
            foreach (get_posts(['s' => $query, 'numberposts' => self::LIMIT, 'post_type' => 'product', 'post_status' => 'publish']) as $pp) {
                $product = wc_get_product($pp->ID);
                if ($product instanceof WC_Product) {
                    $items[] = self::product_item($product);
                }
            }
        }

        // A simple descending relevance score so clients can rank; keyword order is the signal.
        $i = 0;
        foreach ($items as &$it) {
            $it['score'] = max(1, 100 - ($i++ * 3));
        }
        unset($it);
        $items = array_slice($items, 0, self::LIMIT);

        return ['status' => 200, 'body' => [
            'query' => $query,
            'query_id' => 'q_' . wp_generate_password(16, false, false),
            'message_type' => empty($items) ? 'no_results' : 'result',
            'results' => $items,
        ]];
    }

    /** @return array<string,mixed> */
    private static function content_item(WP_Post $p): array
    {
        $url = (string) get_permalink($p);
        $desc = wp_strip_all_tags(get_the_excerpt($p));
        return [
            '@type' => 'Item',
            'url' => $url,
            'name' => get_the_title($p),
            'site' => self::site_token(),
            'siteUrl' => home_url('/'),
            'score' => 100,
            'description' => $desc,
            'schema_object' => array_filter([
                '@type' => $p->post_type === 'page' ? 'WebPage' : 'Article',
                'name' => get_the_title($p),
                'url' => $url,
                'description' => $desc,
                'datePublished' => get_post_time('c', true, $p),
            ], static fn($v) => $v !== '' && $v !== false && $v !== null),
        ];
    }

    /** @param WC_Product $product @return array<string,mixed> */
    private static function product_item($product): array
    {
        $url = (string) get_permalink($product->get_id());
        $desc = wp_strip_all_tags($product->get_short_description() ?: $product->get_description());
        return [
            '@type' => 'Item',
            'url' => $url,
            'name' => $product->get_name(),
            'site' => self::site_token(),
            'siteUrl' => home_url('/'),
            'score' => 100,
            'description' => $desc,
            'schema_object' => [
                '@type' => 'Product',
                'name' => $product->get_name(),
                'url' => $url,
                'sku' => $product->get_sku(),
                'description' => $desc,
                'offers' => [
                    '@type' => 'Offer',
                    'price' => $product->get_price(),
                    'priceCurrency' => get_woocommerce_currency(),
                    'availability' => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                ],
            ],
        ];
    }

    private static function site_token(): string
    {
        $slug = sanitize_title(get_bloginfo('name'));
        return $slug !== '' ? $slug : (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
    }
}
