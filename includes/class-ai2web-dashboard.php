<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Agent Sales" admin dashboard: what AI agents are doing on the store, from local data only.
 *
 * Two local sources, no external service and no tracking code to add:
 *  - WooCommerce orders tagged at creation with created_via = ai2web (agent checkout),
 *    ai2web-acp (ACP / ChatGPT Instant Checkout) or ai2web-ap2 (Google AP2) - the revenue.
 *  - The RFC-0016 ai2web_events table - discovery, queries, query "misses" (unmet demand a
 *    read-only crawl cannot reveal) and action calls - the engagement.
 */
final class Ai2Web_Dashboard
{
    /** created_via tags => human label. */
    private const SOURCES = [
        'ai2web' => 'Agent checkout',
        'ai2web-acp' => 'ACP (ChatGPT)',
        'ai2web-ap2' => 'AP2 (Google)',
    ];

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            'options-general.php',
            __('AI2Web Agent Sales', 'ai2web'),
            __('AI2Web Agent Sales', 'ai2web'),
            'manage_woocommerce',
            'ai2web-sales',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only range selector.
        $range = isset($_GET['range']) ? absint($_GET['range']) : 30;
        $days = in_array($range, [7, 30, 90, 365], true) ? $range : 30;

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('AI2Web Agent Sales', 'ai2web') . '</h1>';

        if (!class_exists('WooCommerce')) {
            echo '<p>' . esc_html__('WooCommerce is not active, so there are no agent orders to show.', 'ai2web') . '</p></div>';
            return;
        }

        // Range selector.
        echo '<p class="subsubsub" style="float:none;margin:0 0 12px">';
        foreach ([7 => __('7 days', 'ai2web'), 30 => __('30 days', 'ai2web'), 90 => __('90 days', 'ai2web'), 365 => __('12 months', 'ai2web')] as $d => $label) {
            $url = admin_url('options-general.php?page=ai2web-sales&range=' . $d);
            $cur = $d === $days;
            echo '<a href="' . esc_url($url) . '"' . ($cur ? ' style="font-weight:700"' : '') . '>' . esc_html($label) . '</a>';
            if ($d !== 365) {
                echo ' &nbsp;|&nbsp; ';
            }
        }
        echo '</p>';

        $orders = self::agent_orders($days);
        $stats = self::summarise($orders);

        // KPI cards.
        echo '<div style="display:flex;flex-wrap:wrap;gap:14px;margin:8px 0 22px">';
        self::kpi(__('Agent revenue', 'ai2web'), wc_price($stats['paid_revenue']), sprintf(
            /* translators: %d: number of days */
            __('paid, last %d days', 'ai2web'),
            $days
        ));
        self::kpi(__('Agent orders', 'ai2web'), (string) $stats['count'], sprintf(
            /* translators: %d: number of paid orders */
            __('%d paid', 'ai2web'),
            $stats['paid_count']
        ));
        self::kpi(__('Avg order value', 'ai2web'), $stats['paid_count'] ? wc_price($stats['paid_revenue'] / $stats['paid_count']) : '-', __('paid orders', 'ai2web'));
        self::kpi(__('Pending value', 'ai2web'), wc_price($stats['pending_revenue']), __('awaiting payment', 'ai2web'));
        echo '</div>';

        // By protocol.
        echo '<h2>' . esc_html__('By protocol', 'ai2web') . '</h2>';
        echo '<table class="widefat striped" style="max-width:640px"><thead><tr>';
        echo '<th>' . esc_html__('Source', 'ai2web') . '</th><th>' . esc_html__('Orders', 'ai2web') . '</th><th>' . esc_html__('Paid', 'ai2web') . '</th><th>' . esc_html__('Revenue (paid)', 'ai2web') . '</th></tr></thead><tbody>';
        if (empty($stats['by_source'])) {
            echo '<tr><td colspan="4">' . esc_html__('No agent orders in this period yet.', 'ai2web') . '</td></tr>';
        }
        foreach ($stats['by_source'] as $via => $row) {
            echo '<tr><td>' . esc_html(self::SOURCES[$via] ?? $via) . '</td><td>' . esc_html((string) $row['count']) . '</td><td>' . esc_html((string) $row['paid_count']) . '</td><td>' . wp_kses_post(wc_price($row['paid_revenue'])) . '</td></tr>';
        }
        echo '</tbody></table>';

        // Recent agent orders.
        echo '<h2 style="margin-top:26px">' . esc_html__('Recent agent orders', 'ai2web') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Order', 'ai2web') . '</th><th>' . esc_html__('Date', 'ai2web') . '</th><th>' . esc_html__('Source', 'ai2web') . '</th><th>' . esc_html__('Status', 'ai2web') . '</th><th>' . esc_html__('Total', 'ai2web') . '</th></tr></thead><tbody>';
        $recent = array_slice($orders, 0, 20);
        if (empty($recent)) {
            echo '<tr><td colspan="5">' . esc_html__('No agent orders yet. When an AI agent buys through ACP, AP2 or agent checkout, it appears here.', 'ai2web') . '</td></tr>';
        }
        foreach ($recent as $o) {
            $edit = $o->get_edit_order_url();
            echo '<tr>';
            echo '<td><a href="' . esc_url($edit) . '">#' . esc_html($o->get_order_number()) . '</a></td>';
            echo '<td>' . esc_html($o->get_date_created() ? $o->get_date_created()->date_i18n(get_option('date_format') . ' H:i') : '-') . '</td>';
            echo '<td>' . esc_html(self::SOURCES[$o->get_created_via()] ?? $o->get_created_via()) . '</td>';
            echo '<td>' . esc_html(wc_get_order_status_name($o->get_status())) . '</td>';
            echo '<td>' . wp_kses_post(wc_price($o->get_total(), ['currency' => $o->get_currency()])) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // Engagement (events table).
        self::render_engagement($days);

        echo '<p style="margin-top:22px;color:#666">' . esc_html__('All figures are computed locally from your WooCommerce orders and the AI2Web events table. Nothing is sent to any external service.', 'ai2web') . '</p>';
        echo '</div>';
    }

    /**
     * Agent-attributed orders in the window, newest first (drafts excluded).
     * @return WC_Order[]
     */
    private static function agent_orders(int $days): array
    {
        if (!function_exists('wc_get_orders')) {
            return [];
        }
        $orders = wc_get_orders([
            'limit' => -1,
            'type' => 'shop_order',
            'date_created' => '>=' . gmdate('Y-m-d', time() - $days * DAY_IN_SECONDS),
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        $out = [];
        foreach ($orders as $o) {
            // Filter by created_via in PHP so this is correct whether or not the query backend
            // supports created_via as a filter, and across HPOS / legacy storage.
            if ($o instanceof WC_Order
                && isset(self::SOURCES[$o->get_created_via()])
                && $o->get_status() !== 'checkout-draft') {
                $out[] = $o;
            }
        }
        return $out;
    }

    /**
     * @param WC_Order[] $orders
     * @return array{count:int,paid_count:int,paid_revenue:float,pending_revenue:float,by_source:array<string,array{count:int,paid_count:int,paid_revenue:float}>}
     */
    private static function summarise(array $orders): array
    {
        $s = ['count' => 0, 'paid_count' => 0, 'paid_revenue' => 0.0, 'pending_revenue' => 0.0, 'by_source' => []];
        foreach ($orders as $o) {
            $via = $o->get_created_via();
            $total = (float) $o->get_total();
            $paid = $o->is_paid();
            $s['count']++;
            $s['by_source'][$via] ??= ['count' => 0, 'paid_count' => 0, 'paid_revenue' => 0.0];
            $s['by_source'][$via]['count']++;
            if ($paid) {
                $s['paid_count']++;
                $s['paid_revenue'] += $total;
                $s['by_source'][$via]['paid_count']++;
                $s['by_source'][$via]['paid_revenue'] += $total;
            } else {
                $s['pending_revenue'] += $total;
            }
        }
        return $s;
    }

    private static function render_engagement(int $days): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ai2web_events';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare("SELECT type, result, COUNT(*) c FROM {$table} WHERE ts >= %s GROUP BY type, result", $cutoff), ARRAY_A);
        if (empty($rows)) {
            return;
        }
        $discovery = 0;
        $queries = 0;
        $misses = 0;
        $actions = 0;
        foreach ($rows as $r) {
            $c = (int) $r['c'];
            if ($r['type'] === 'discovery') {
                $discovery += $c;
            } elseif ($r['type'] === 'query') {
                $queries += $c;
                if ($r['result'] === 'miss') {
                    $misses += $c;
                }
            } elseif ($r['type'] === 'action') {
                $actions += $c;
            }
        }

        echo '<h2 style="margin-top:26px">' . esc_html__('Agent engagement', 'ai2web') . '</h2>';
        echo '<div style="display:flex;flex-wrap:wrap;gap:14px">';
        self::kpi(__('Discovery hits', 'ai2web'), (string) $discovery, __('manifest fetched', 'ai2web'));
        self::kpi(__('Queries', 'ai2web'), (string) $queries, __('content / product / search', 'ai2web'));
        self::kpi(__('Query misses', 'ai2web'), (string) $misses, __('unmet demand', 'ai2web'));
        self::kpi(__('Actions', 'ai2web'), (string) $actions, __('agent action calls', 'ai2web'));
        echo '</div>';
        if ($misses > 0) {
            echo '<p style="color:#666">' . esc_html__('Query misses are searches an agent ran that returned nothing - demand you are not yet meeting. A read-only crawl of your site cannot surface this.', 'ai2web') . '</p>';
        }
    }

    private static function kpi(string $label, string $value, string $sub): void
    {
        echo '<div class="card" style="margin:0;padding:14px 18px;min-width:170px">';
        echo '<div style="color:#666;font-size:12px;text-transform:uppercase;letter-spacing:.03em">' . esc_html($label) . '</div>';
        echo '<div style="font-size:26px;font-weight:700;margin:4px 0">' . wp_kses_post($value) . '</div>';
        echo '<div style="color:#888;font-size:12px">' . esc_html($sub) . '</div>';
        echo '</div>';
    }
}
