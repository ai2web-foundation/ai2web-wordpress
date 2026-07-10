=== AI2Web ===
Contributors: rolandfarkas, ai2webfoundation
Tags: ai, agents, mcp, ai2web, woocommerce, api
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.1.0
License: MIT

Make your website AI-native. Expose an AI2Web (ai2w) capability manifest and endpoints so AI agents can discover, understand and act on your site.

== Description ==

Describe your website once. AI2Web makes it understandable to every AI.

AI2Web adds a vendor-neutral capability layer to your site. On activation it serves:

* `/ai2w` - your site's AI2Web manifest (identity, capabilities, transports, events)
* `/.well-known/ai2w` - the discovery anchor agents look for
* `/ai2w/content`, `/ai2w/search`, `/ai2w/products`, `/ai2w/events`, `/ai2w/actions/*` - live, backend-first endpoints
* `/ai2w/negotiate` - capability negotiation

It auto-integrates:

* **WooCommerce** - products, stock, pricing, categories, and order/stock events
* **Form plugins** - Contact Form 7, Gravity Forms, WPForms, Fluent Forms, Elementor Forms (exposed as approval-gated actions)
* **Content** - posts, pages, search

AI2Web is backend-first and API-driven. It does not scrape your frontend or rely on browser tools. It complements MCP and ACP rather than replacing them.

== Installation ==

1. Upload the `ai2web` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Visit `https://your-site.com/ai2w` to see your manifest.
4. Validate it: `npx @ai2web/validator validate https://your-site.com`

== Frequently Asked Questions ==

= Does this expose private customer data? =
No. The manifest and discovery endpoints expose only public metadata. Account- and order-specific actions require authentication and are approval-gated.

= Do I need OpenAI/Anthropic to support AI2Web? =
No. The manifest, feeds and endpoints are useful today, and an MCP endpoint works with current assistant connectors.

= /.well-known/ai2w returns a 404 on my server =
On Apache with the standard WordPress .htaccess, requests reach WordPress and the plugin serves the anchor. On some nginx setups a dedicated `location ^~ /.well-known/` block serves that path directly and never reaches WordPress. If so, add a rule to pass `/.well-known/ai2w` to WordPress (index.php), or serve it as a static pointer to `/ai2w`.

= How do I add a support contact to the manifest? =
For privacy, the plugin does NOT publish your admin email by default. Provide a public support address via the `ai2web_support_contact` filter:
`add_filter('ai2web_support_contact', fn() => 'support@example.com');`

= Why does the validator say "checkout missing" for my store? =
Declare checkout explicitly in the object form of the commerce capability (`commerce: { enabled: true, checkout: true }`). The boolean shorthand `commerce: true` does not assert checkout support.

== Changelog ==

= 0.1.0 =
* Initial draft: manifest, discovery, content/search/products/events, WooCommerce + form detection, negotiation.
