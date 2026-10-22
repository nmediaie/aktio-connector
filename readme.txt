=== Aktio Connector ===
Contributors: nmmedia
Tags: schema, llms.txt, robots.txt, ai, woocommerce
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Schema.org, robots.txt and llms.txt for AI bots, Site Health and updates over REST — standalone or together with Aktio.

== Description ==

Aktio Connector makes a WordPress site easier for search engines and AI bots to read, and lets you check its health remotely, but only on your terms.

**What the plugin does**

* **schema.org structured data (JSON-LD):** the business (Organization or LocalBusiness), the website, pages, articles and breadcrumbs. With WooCommerce also products (Product, ProductGroup, offers and ratings).
* **robots.txt for AI bots:** appends a block for GPTBot, OAI-SearchBot, ClaudeBot, PerplexityBot, Google-Extended and Applebot-Extended at the end of robots.txt. Bots are allowed by default and you can block each one in the settings.
* **llms.txt:** serves `/llms.txt` with a list of pages, recent posts and products. Password-protected and unpublished content is left out.
* **Site Health over REST:** Site Health test results and environment information available to logged-in administrators through `aktio/v1/health`.
* **Updates over REST with a log:** the list of available core, plugin and theme updates and a way to apply them through `aktio/v1/updates`. Every update is written to a log (the last 50 entries) shown in the settings. The plugin never rolls anything back automatically.

You edit the business details and bot settings under **Settings → Aktio Connector**. The screen also shows which features are provided by this plugin and which by another SEO plugin or a physical file, plus the update log.

**Privacy and security**

* The plugin does not connect to any server, sends no telemetry and does not require an account.
* The REST endpoints require authentication (for example a WordPress application password) and capabilities matching the operation, such as `manage_options` or `update_plugins`.
* The plugin stores no secrets.

**Working with Aktio**

Aktio (aktioapp.com) is a tool for managing content and visibility of a site. If you use it, it connects to your site through REST with an application password, and Aktio initiates every connection. Without Aktio the plugin works fully on its own.

== Installation ==

1. Upload the `aktio-connector` directory to `/wp-content/plugins/` or install the plugin from the dashboard.
2. Activate it on the **Plugins** screen.
3. Fill in the business details under **Settings → Aktio Connector**.

== Frequently Asked Questions ==

= Does the plugin connect to any server or require an account? =

No. The plugin does not connect to any server, sends no telemetry and does not require an account. It runs entirely on your site. A connection with Aktio, if you use it, is initiated only by Aktio, through your site's REST API.

= I already have an SEO plugin (Yoast SEO, Rank Math, All in One SEO and others). Will the data be duplicated? =

No. If an active SEO plugin or a physical file in the site directory already provides a feature (schema, robots.txt, llms.txt), Aktio Connector does not duplicate it. The settings screen shows who provides each feature. SEO plugins that are installed but not active are ignored.

= My SEO plugin builds its own robots.txt. Where does the AI bot block go? =

The AI bot block is appended at the end of robots.txt. If the SEO plugin already defines rules for the same bots, the first matching group applies, that is the one placed higher in the file (usually the one from the SEO plugin). In that case the Aktio Connector setting may have no effect for that bot; change the rule in the SEO plugin instead. A physical robots.txt file in the site directory takes precedence and Aktio Connector adds nothing to it.

= Are updates performed without my knowledge? =

No. Updates over REST are performed only by an authenticated user with the right capabilities, and each one is written to the log in the settings. The plugin does not enable automatic updates on its own and does not roll changes back automatically, so make a backup before updating.

= Can I block AI bots? =

Yes. Under **Settings → Aktio Connector** choose "Block" next to any of the six bots. The block is not added when the site is set to discourage search engines.

== Changelog ==

= 0.1.0 =
* First release: schema.org, AI bots in robots.txt, llms.txt, Site Health and updates over REST with a log, settings screen.
