<div align="center">
  <a href="https://ai2web.dev">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/ai2web-foundation/.github/main/profile/ai2web-logo-white.svg">
      <img alt="AI2Web" src="https://raw.githubusercontent.com/ai2web-foundation/.github/main/profile/ai2web-logo-black.svg" width="200">
    </picture>
  </a>
</div>

# AI2Web for WordPress

[![AI2Web on Launchpadly - Product of the Week (Gold)](https://launchpadly.co/embed/badges/startup/ai2web.svg?variant=product-week-gold)](https://launchpadly.co/startup/ai2web?ref=badge)

[![CI](https://github.com/ai2web-foundation/ai2web-wordpress/actions/workflows/ci.yml/badge.svg)](https://github.com/ai2web-foundation/ai2web-wordpress/actions/workflows/ci.yml)

Make your WordPress site AI-native. This plugin publishes an [AI2Web](https://ai2web.dev) (`ai2w`) capability manifest and live, backend-first endpoints, so AI agents can **discover, understand and act on your site** across MCP, ACP, REST and more. It auto-integrates WooCommerce and popular form plugins, with no theme changes and no frontend scraping.

> Describe your website once. AI2Web makes it understandable to every AI.

## What it does

On activation the plugin serves:

| Endpoint | Purpose |
|---|---|
| `/.well-known/ai2w` | The discovery anchor agents look for (required) |
| `/ai2w` | Your site's AI2Web manifest: identity, capabilities, transports, actions, events |
| `/ai2w/mcp` | Model Context Protocol endpoint. Add it to Claude or ChatGPT connectors and your actions become tools |
| `/ai2w/negotiate` | Capability negotiation |
| `/ai2w/content`, `/ai2w/search` | Posts, pages and site search |
| `/ai2w/products` | Product catalog (WooCommerce) |
| `/ai2w/events` | Subscribable events (order, stock, price) |
| `/ai2w/actions/*` | Declared, approval-gated actions (product search, order tracking, returns, contact) |

It is **backend-first and API-driven**: it does not scrape your frontend or depend on browser tools, and it complements MCP and ACP rather than replacing them.

## Auto-integrations

- **WooCommerce** - product search and stock checks, order tracking (verified by billing email), and return/refund **requests** that are logged for you to action. Refunds are never processed automatically and no money is moved from the public endpoint.
- **Form plugins** - Contact Form 7, Gravity Forms, WPForms, Fluent Forms and Elementor Forms, exposed as an approval-gated contact action that emails approved enquiries to your support address.
- **Content** - posts, pages and search out of the box.

## Settings and AI Readiness Score

**Settings -> AI2Web** shows your live AI Readiness Score (computed locally, so it works on staging too), lists what is missing, and lets you toggle the endpoints, MCP, WooCommerce actions and returns, and set a public support email.

## Security model

- Order actions never trust an order number alone: the caller must also supply the billing email, and it must match the order, so agents cannot enumerate other customers' orders. Lookups are rate limited per IP.
- Return and refund actions are **requests only**: they add an order note for you and never move money.
- Approval-gated actions return a preview first and run only when called again with `confirm: true`.

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

Most settings live on the **Settings -> AI2Web** page. Developers can also use filters:

```php
// Publish a public support contact (your admin email is never published by default).
// The settings-page value takes precedence if set.
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
- **`checkout missing` in the validator?** Enable **Agent checkout** in Settings &rarr; AI2Web; the plugin then declares `commerce.checkout: true` for you. (Per RFC-0005, checkout must be asserted explicitly: the boolean `commerce: true` shorthand does not assert it.)

See [`readme.txt`](readme.txt) for the WordPress.org listing (FAQ and changelog).

## Part of AI2Web

- Website and validator: [ai2web.dev](https://ai2web.dev)
- Specification and RFCs: [github.com/ai2web-foundation/ai2web-spec](https://github.com/ai2web-foundation/ai2web-spec)
- All repositories: [github.com/ai2web-foundation](https://github.com/ai2web-foundation)

Licensed under [MIT](LICENSE).
