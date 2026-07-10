# AI2Web for WordPress

Make your WordPress site AI-native. This plugin publishes an [AI2Web](https://ai2web.dev) (`ai2w`) capability manifest and live, backend-first endpoints, so AI agents can **discover, understand and act on your site** across MCP, ACP, REST and more. It auto-integrates WooCommerce and popular form plugins, with no theme changes and no frontend scraping.

> Describe your website once. AI2Web makes it understandable to every AI.

## What it does

On activation the plugin serves:

| Endpoint | Purpose |
|---|---|
| `/.well-known/ai2w` | The discovery anchor agents look for (required) |
| `/ai2w` | Your site's AI2Web manifest: identity, capabilities, transports, events |
| `/ai2w/negotiate` | Capability negotiation |
| `/ai2w/content`, `/ai2w/search` | Posts, pages and site search |
| `/ai2w/products` | Product catalog (WooCommerce) |
| `/ai2w/events` | Subscribable events (order, stock, price) |
| `/ai2w/actions/*` | Declared, approval-gated actions (for example form submissions) |
| `/ai2w/acp` | ACP checkout transport hook (WooCommerce) |

It is **backend-first and API-driven**: it does not scrape your frontend or depend on browser tools, and it complements MCP and ACP rather than replacing them.

## Auto-integrations

- **WooCommerce** - products, stock, pricing and categories, plus order and stock events.
- **Form plugins** - Contact Form 7, Gravity Forms, WPForms, Fluent Forms and Elementor Forms, exposed as approval-gated actions.
- **Content** - posts, pages and search out of the box.

## Install

1. Copy the `ai2web` folder into `wp-content/plugins/` (or install the zip from the WordPress plugin screen).
2. Activate **AI2Web** in **Plugins**.
3. Visit `https://your-site.com/ai2w` to see your manifest.
4. Validate it and get your AI Readiness Score:
   ```bash
   npx -p @ai2web/validator ai2web validate https://your-site.com
   ```

Requires WordPress 6.0+ and PHP 8.0+.

## Configuration

The plugin exposes only public metadata by default. Two filters let you customise it:

```php
// Publish a public support contact (your admin email is never published by default)
add_filter('ai2web_support_contact', fn() => 'support@example.com');

// Adjust the full manifest before it is served
add_filter('ai2web_manifest', function (array $manifest): array {
    $manifest['site']['description'] = 'Handmade goods since 2004';
    return $manifest;
});
```

## Notes

- **Privacy:** the manifest and discovery endpoints expose public metadata only. Account and order specific actions require authentication and are approval-gated, so an agent cannot buy, refund or export data without the user approving first.
- **`/.well-known/ai2w` returns 404?** On some nginx setups a `location ^~ /.well-known/` block serves that path directly and never reaches WordPress. Add a rule to pass `/.well-known/ai2w` to `index.php`, or serve it as a static pointer to `/ai2w`.
- **`checkout missing` in the validator?** Declare it explicitly: `commerce: { enabled: true, checkout: true }`. The shorthand `commerce: true` does not assert checkout support.

See [`readme.txt`](readme.txt) for the WordPress.org listing (FAQ and changelog).

## Part of AI2Web

- Website and validator: [ai2web.dev](https://ai2web.dev)
- Specification and RFCs: [github.com/ai2web-foundation/ai2web-spec](https://github.com/ai2web-foundation/ai2web-spec)
- All repositories: [github.com/ai2web-foundation](https://github.com/ai2web-foundation)

Licensed under [MIT](LICENSE).
