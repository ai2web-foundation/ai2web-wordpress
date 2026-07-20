=== AI2Web ===
Contributors: rolandfarkas, ai2webfoundation
Tags: ai, agents, mcp, woocommerce, chatgpt
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.4.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Make your website AI-native. One open manifest plus REST and MCP endpoints so AI agents can discover, understand and safely act on your site.

== Description ==

**Describe your website once. AI2Web makes it understandable to every AI.**

AI agents are starting to use the web, but the web was built for human eyes. Agents scrape HTML, guess at forms, and render whole pages just to find one price or one button. AI2Web fixes that by publishing a single, structured description of what your site can do, and serving backend-first endpoints agents can call directly. No scraping, no per-vendor rebuild.

AI2Web is a vendor-neutral capability layer. It sits *above* protocols like MCP and ACP and speaks whichever one an assistant understands, rather than competing with them.

= What it serves =

On activation the plugin serves, from your own domain:

* `/.well-known/ai2w` - the discovery anchor agents look for
* `/ai2w` - your site's AI2Web manifest: identity, capabilities, transports, declared actions, events, governance
* `/ai2w/mcp` - a Model Context Protocol endpoint. Add it to Claude, ChatGPT, Grok or any MCP client and your declared actions become tools the assistant can call
* `/ai2w/content`, `/ai2w/search`, `/ai2w/products`, `/ai2w/events` - live, structured content and catalog
* `/ai2w/actions/*` - the secure action endpoints
* `/ai2w/negotiate` - capability negotiation (agree a capability set and transport)
* `/llms.txt` - a plain-text summary and links, projected from the same manifest
* `/.well-known/agent.json` - a generic agent-capability document, also projected from the manifest

The last two mean agents that speak `llms.txt` or a generic `agent.json` can use your site without understanding AI2Web first, while `/ai2w` stays the authoritative source.

= WooCommerce =

When WooCommerce is active, AI2Web exposes safe commerce actions:

* **search_products** - search the catalogue by keyword
* **check_stock** - availability, price and stock by SKU or id
* **track_order** - order status, verified by the billing email on the order
* **check_return_status** - whether a return or refund request already exists
* **start_return** / **request_refund** - request only. They log the request as an order note for you to action in WooCommerce and never issue a refund or move money automatically. Approval-gated.
* **start_checkout** - an agent assembles a cart and the plugin creates a *pending* order, returning WooCommerce's own secure payment link for the customer to pay in the browser. The agent never handles payment details, and no money moves until the customer pays.

= Contact forms =

If Contact Form 7, Gravity Forms, WPForms, Fluent Forms or Elementor Forms is active, AI2Web exposes a single approval-gated **submit_contact** action. On confirmation the enquiry is emailed to your support address (never an arbitrary recipient, so it is not an open relay) and is rate limited per IP.

= WordPress 7.0 AI: Abilities API =

On WordPress 6.9+/7.0, AI2Web also registers its actions as native **WordPress Abilities**, with AI annotations (read vs. write, destructive). This exposes the same ownership-verified, approval-gated actions to WordPress's own AI Client and MCP Adapter, so a connected assistant can use them through WordPress too. Two surfaces, one definition:

* `/ai2w` - the public, anonymous, open-protocol surface (ownership and approval gated).
* WordPress Abilities - the authenticated WordPress-native surface, gated by WordPress's own auth.

= Agent service =

If you connect an AI provider in WordPress 7.0's Connectors hub, AI2Web exposes `/ai2w/agent`, a natural-language endpoint answered by WordPress's built-in AI Client using your provider. The plugin never handles an AI key.

= OAuth2 (PKCE) =

Agents can authenticate via an OAuth2 authorization-code + PKCE flow, where a logged-in user approves access on a consent screen. Codes are single-use and short-lived, tokens are stored hashed, PKCE uses S256, and the flow is served over HTTPS. Anonymous, ownership-gated access remains the fallback, so a token is never required. OAuth is a security-sensitive feature; review it for your threat model before relying on it, and it can be turned off on the settings page.

= AI Readiness Score =

A settings page (Settings -> AI2Web) shows a live **AI Readiness Score out of 100** and a compliance tier, lets you toggle each feature (MCP, agent service, OAuth2, WooCommerce actions, returns/refunds, agent checkout), and set a public support email.

= Safe by design =

The most important part is what an agent *cannot* do without asking. See the security section below.

AI2Web is backend-first and API-driven. It does not scrape your frontend or rely on browser tools.

== How it works ==

1. **Discovery.** An agent fetches `/.well-known/ai2w`, which points to `/ai2w`. Reading the manifest never changes anything.
2. **Understanding.** The manifest declares your identity, capabilities, transports, and each action's input schema, risk level and whether it needs approval.
3. **Negotiation.** The agent can `POST /ai2w/negotiate` to agree a capability set and a transport (REST or MCP).
4. **Acting.** The agent calls an action over REST (`/ai2w/actions/{name}`) or as an MCP tool. Requests are validated against the declared schema. Sensitive actions return a preview and require explicit confirmation.

Everything is generated from your live site and detected integrations, and the manifest is filterable so themes and plugins can extend it.

== Privacy and security ==

* Discovery and the manifest expose only public metadata. Your admin email is never published; a support contact appears only if you set one.
* **Ownership before private data.** Order actions never trust an order number alone. The caller must also provide the billing email, and it must match the order. A wrong email and a nonexistent order return the identical "not found" response, so agents cannot enumerate which orders exist.
* **Approval before money or commitment.** Refunds, returns, checkout and contact enquiries return a preview first and only proceed on explicit confirmation.
* **Request only for money.** Refund and return actions add an order note for you to review and never call WooCommerce's refund process. Checkout creates a *pending* order and hands the customer a payment link; the agent never handles payment.
* **Rate limited.** Order lookups, checkout and enquiries are throttled per IP.
* The WordPress Abilities surface requires an authenticated WordPress user; it is gated by WordPress's own permissions.
* **OAuth2 (PKCE)** is served over HTTPS, uses S256, issues single-use short-lived codes, stores tokens hashed, and only issues them after a logged-in user approves on a consent screen. A bearer token authenticates a request but does not elevate its WordPress capabilities.

== Installation ==

1. Upload the `ai2web` folder to `/wp-content/plugins/`, or install the zip from Plugins -> Add New -> Upload.
2. Activate the plugin.
3. Make sure Permalinks are not set to "Plain" (Settings -> Permalinks).
4. Visit Settings -> AI2Web to see your AI Readiness Score and toggle features.
5. Visit `https://your-site.com/ai2w` to see your manifest.
6. To let an assistant use your site, add `https://your-site.com/ai2w/mcp` as a custom MCP connector.

== Frequently Asked Questions ==

= Does this expose private customer data? =
No. The manifest and discovery endpoints expose only public metadata. Order tracking requires the correct billing email for that order, lookups are rate limited, and a wrong email is indistinguishable from a missing order, so nothing can be enumerated.

= Can an AI issue a refund or take payment on my store? =
No. Refund and return actions only log a request as an order note for you to action in WooCommerce. Checkout creates a pending order and returns a secure payment link; the customer pays in the browser and the agent never handles payment details. No money moves without a human.

= How do I connect this to Claude, ChatGPT or Grok? =
Enable the MCP endpoint on the settings page (on by default), then add `https://your-site.com/ai2w/mcp` as a custom connector / MCP server in the assistant. Your declared actions appear as tools.

= What is /llms.txt and /.well-known/agent.json? =
They are alternative projections of the same manifest, for agents that read those formats. You do not maintain them separately; they are generated from `/ai2w`.

= What are the WordPress Abilities it registers? =
On WordPress 6.9+/7.0, the plugin registers its actions with the native Abilities API so WordPress's own AI Client and MCP Adapter can use them. This surface is authenticated (gated by WordPress permissions), separate from the public `/ai2w` surface.

= Does it clash with WooCommerce 10.9's own agent abilities? =
No. WooCommerce 10.9+ ships its own canonical abilities (product and order query, create, update, etc.) into the same Abilities API. Those are merchant-facing and authenticated, for a store operator driving their shop from an AI client. AI2Web's actions are the opposite: customer-facing, anonymous, and ownership-gated (an external shopper's agent with no WordPress account), served from `/ai2w/mcp` and REST. To keep the native, authenticated surface tidy, AI2Web stops registering its read actions that WooCommerce now covers canonically (`search_products`, `check_stock`, `track_order`) as WordPress abilities when WooCommerce 10.9+ is active. They stay available on the public `/ai2w` surface, which WooCommerce's abilities do not serve.

= Do I need OpenAI/Anthropic to use AI2Web? =
No. The manifest, feeds and endpoints are useful today, and the MCP endpoint works with current assistant connectors without any AI key on your side.

= Does it work with multisite? =
Subdomain multisite works: each subsite serves its own manifest. Subdirectory multisite also works for `/ai2w` and its routes (the request path is resolved against each site's home URL); the domain-root `/.well-known/` anchor belongs to the network's main site, so subsites are discovered via `/ai2w` and the `<link rel="ai2w">` tag.

= /.well-known/ai2w returns a 404 on my server =
On Apache with the standard WordPress .htaccess, requests reach WordPress and the plugin serves the anchor. On some nginx setups a `location ^~ /.well-known/` block serves that path directly and never reaches WordPress; add a rule to pass `/.well-known/ai2w` to WordPress, or serve it as a static pointer to `/ai2w`.

= How do I authenticate an agent (OAuth2)? =
Enable OAuth2 (PKCE) on the settings page. An agent sends the user to `/ai2w/oauth/authorize`, the user approves on a consent screen, and the agent exchanges the code for a token at `/ai2w/oauth/token` (PKCE S256). The token is then sent as an `Authorization: Bearer` header. Anonymous, ownership-gated access still works without a token.

= How do I customise the manifest? =
Use the `ai2web_manifest` filter, or the targeted `ai2web_support_contact`, `ai2web_governance`, `ai2web_usage_policy`, `ai2web_legal`, `ai2web_knowledge`, `ai2web_oauth_allowed_clients` and `ai2web_oauth_allow_insecure` filters.

== Changelog ==

= 0.4.0 =
* New **analytics** (RFC-0016 parity with the reference server): personal-data-free, server-side interaction events stored in a local plugin table and fired as an `ai2web_event` action so operators can forward them anywhere. Records discovery, query, and action events, including query "misses" (the demand signal a read-only crawl cannot produce). Filters are sanitised to non-identifying scalars, the agent identity is coarse (User-Agent only, never an end-user), rows auto-prune after 90 days, and the whole thing is filterable off via `ai2web_analytics_enabled`.
* **WooCommerce 10.9+ interop**: WooCommerce 10.9 ships its own canonical product/order abilities into WordPress's Abilities API and MCP Adapter. To avoid listing near-duplicates next to them on that authenticated, merchant-facing surface, AI2Web no longer registers its `search_products`, `check_stock`, or `track_order` reads as WordPress abilities when WooCommerce 10.9+ is active (WooCommerce's `products-query` / `orders-query` cover them). Every AI2Web action, including those three, remains fully available on the anonymous, ownership-gated `/ai2w/mcp` and REST surfaces, which WooCommerce's abilities do not provide. Filterable via `ai2web_abilities_superseded_by_woocommerce`.
* **Hardened OAuth2 server**: least-privilege bearer authentication, so a token authenticates a request without elevating its WordPress capabilities.
* **Strict PKCE and scope validation** on the authorize endpoint: the `code_challenge` and requested scopes are validated up front and rejected if malformed.

= 0.3.0 =
* Manifest upgraded to AI2Web protocol v0.2 (additive, backward compatible): governance (rate limits and consent mode), a protective usage policy, opt-in legal fields, and knowledge sources. All filterable.
* New **agent service** at `/ai2w/agent`, answered by WordPress 7.0's built-in AI Client using your connected provider (no AI key handled by the plugin).
* New **OAuth2 (PKCE)** authenticated access: authorization-code + PKCE (S256) flow with a consent screen, single-use codes, hashed tokens and HTTPS enforcement. Anonymous, ownership-gated access remains the fallback.
* New **agent checkout**: agents can assemble a cart into a pending order and receive a secure payment link. The customer pays in the browser; the agent never handles payment.
* New multi-surface projections: serves `/llms.txt` and `/.well-known/agent.json` from the same manifest.
* WordPress 7.0 **Abilities API** integration: registers the actions as native WordPress abilities (with AI annotations) so the built-in AI Client and MCP Adapter can use them.
* Added a `<link rel="ai2w">` discovery tag and a subdirectory / subdirectory-multisite path fix.
* Admin now surfaces detected contact-form plugins.

= 0.2.0 =
* WooCommerce actions: product search, stock check, order tracking (billing-email verified), and return/refund requests (logged for the merchant, never auto-processed).
* MCP endpoint at `/ai2w/mcp` exposing declared actions as tools for Claude / ChatGPT connectors.
* Admin settings page with a live AI Readiness Score, feature toggles and a support-email field.
* Contact action delivers approved enquiries to your support address (rate limited).
* Per-IP rate limiting on order lookups and enquiries.

= 0.1.0 =
* Initial draft: manifest, discovery, content/search/products/events, WooCommerce + form detection, negotiation.

== Upgrade Notice ==

= 0.4.0 =
Adds privacy-preserving analytics (a new local events table, created on activation) and hardens the OAuth2 server with least-privilege bearer auth and strict PKCE/scope validation. Backward compatible.

= 0.3.0 =
Adds agent checkout, llms.txt and agent.json surfaces, and WordPress 7.0 Abilities integration. Backward compatible; review Settings -> AI2Web to toggle the new agent checkout.
