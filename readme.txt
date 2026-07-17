=== AI2Web ===
Contributors: rolandfarkas, ai2webfoundation
Tags: ai, agents, mcp, woocommerce, chatgpt
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.3.0
License: MIT

Make your website AI-native. Expose an AI2Web (ai2w) manifest plus REST and MCP endpoints so AI agents can discover, understand and act on your site.

== Description ==

Describe your website once. AI2Web makes it understandable to every AI.

AI2Web adds a vendor-neutral capability layer to your site. On activation it serves:

* `/ai2w` - your site's AI2Web manifest (identity, capabilities, transports, actions, events)
* `/.well-known/ai2w` - the discovery anchor agents look for
* `/ai2w/mcp` - a Model Context Protocol endpoint you can add to Claude or ChatGPT connectors, so an assistant can use your site's actions directly
* `/ai2w/content`, `/ai2w/search`, `/ai2w/products`, `/ai2w/events`, `/ai2w/actions/*` - live, backend-first endpoints
* `/ai2w/negotiate` - capability negotiation

It auto-integrates:

* **WooCommerce** - product search and stock, order tracking (verified by billing email), and return/refund requests that are logged for you to action. It never issues a refund or moves money automatically.
* **Form plugins** - Contact Form 7, Gravity Forms, WPForms, Fluent Forms, Elementor Forms, exposed as an approval-gated contact action.
* **Content** - posts, pages, search.

A settings page (Settings -> AI2Web) shows your live AI Readiness Score, lets you toggle features, and set a public support email.

AI2Web is backend-first and API-driven. It does not scrape your frontend or rely on browser tools. It complements MCP and ACP rather than replacing them.

== Privacy and security ==

* The manifest and discovery endpoints expose only public metadata.
* Order actions never trust an order number alone: the caller must also provide the billing email, and it must match the order, so agents cannot enumerate other customers' orders. Order lookups are rate limited.
* Return and refund actions are requests only. They add a note to the order for you to review in WooCommerce and never process a refund or move money.
* Your admin email is never published. A support contact appears in the manifest only if you set one on the settings page.

== Installation ==

1. Upload the `ai2web` folder to `/wp-content/plugins/`, or install the zip from Plugins -> Add New -> Upload.
2. Activate the plugin.
3. Make sure Permalinks are not set to "Plain" (Settings -> Permalinks).
4. Visit Settings -> AI2Web to see your AI Readiness Score and options.
5. Visit `https://your-site.com/ai2w` to see your manifest.
6. To let an assistant use your store, add `https://your-site.com/ai2w/mcp` as a custom MCP connector.

== Frequently Asked Questions ==

= Does this expose private customer data? =
No. The manifest and discovery endpoints expose only public metadata. Order tracking requires the correct billing email for that order, lookups are rate limited, and return/refund actions only log a request for you, they never move money.

= Can an AI issue a refund on my store? =
No. Refund and return actions are requests. They add an order note for you to review and action in WooCommerce. The plugin never calls WooCommerce's refund process from the public endpoint.

= How do I connect this to Claude or ChatGPT? =
Enable the MCP endpoint on the settings page (on by default), then add `https://your-site.com/ai2w/mcp` as a custom connector / MCP server in the assistant. Your declared actions appear as tools.

= Do I need OpenAI/Anthropic to support AI2Web? =
No. The manifest, feeds and endpoints are useful today, and the MCP endpoint works with current assistant connectors.

= /.well-known/ai2w returns a 404 on my server =
On Apache with the standard WordPress .htaccess, requests reach WordPress and the plugin serves the anchor. On some nginx setups a dedicated `location ^~ /.well-known/` block serves that path directly and never reaches WordPress. If so, add a rule to pass `/.well-known/ai2w` to WordPress (index.php), or serve it as a static pointer to `/ai2w`.

= How do I add a support contact to the manifest? =
Set it on the settings page (Settings -> AI2Web). Developers can also use the `ai2web_support_contact` filter.

== Changelog ==

= 0.3.0 =
* Manifest upgraded to AI2Web protocol v0.2 (additive, backward compatible).
* Adds governance (rate limits and consent mode), a protective usage policy, opt-in legal fields, and knowledge sources. All filterable: `ai2web_governance`, `ai2web_usage_policy`, `ai2web_legal`, `ai2web_knowledge`.

= 0.2.0 =
* WooCommerce actions: product search, stock check, order tracking (billing-email verified), and return/refund requests (logged for the merchant, never auto-processed).
* MCP endpoint at `/ai2w/mcp` exposing declared actions as tools for Claude / ChatGPT connectors.
* Admin settings page with a live AI Readiness Score, feature toggles and a support-email field.
* Contact action now delivers approved enquiries to your support address (rate limited).
* Per-IP rate limiting on order lookups and enquiries.

= 0.1.0 =
* Initial draft: manifest, discovery, content/search/products/events, WooCommerce + form detection, negotiation.
