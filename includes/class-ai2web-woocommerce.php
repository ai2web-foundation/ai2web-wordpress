<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce integration: exposes products and declares order/stock events.
 * Commerce is one module among equals - not the centre of AI2Web.
 */
final class Ai2Web_WooCommerce
{
    /** @return string[] */
    public static function event_types(): array
    {
        if (!class_exists('WooCommerce')) {
            return [];
        }
        return [
            'order.created', 'order.shipped', 'order.delivered',
            'refund.processed', 'product.back_in_stock', 'price.drop',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function products(int $limit = 20): array
    {
        if (!function_exists('wc_get_products')) {
            return [];
        }
        $out = [];
        $products = wc_get_products(['limit' => $limit, 'status' => 'publish']);
        foreach ($products as $product) {
            $item = [
                'id' => $product->get_id(),
                'sku' => $product->get_sku(),
                'title' => $product->get_name(),
                'url' => get_permalink($product->get_id()),
                'type' => $product->get_type(),
                'price' => $product->get_price(),
                'currency' => get_woocommerce_currency(),
                'availability' => $product->is_in_stock() ? 'in_stock' : 'out_of_stock',
                'stock' => $product->get_stock_quantity(),
                'categories' => wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']),
            ];
            // For variable products surface the price range + variant count; the per-variation
            // detail (SKU, price, attributes, stock) is available from check_stock.
            if ($product instanceof WC_Product_Variable) {
                $item['price_min'] = $product->get_variation_price('min');
                $item['price_max'] = $product->get_variation_price('max');
                $item['variation_count'] = count($product->get_children());
            }
            $out[] = $item;
        }
        return $out;
    }
}
