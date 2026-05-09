# AEO God Mode

> Free AEO + AI search readiness plugin for WordPress. Get cited by ChatGPT, Claude, Perplexity, Gemini and Google AI Overviews.

[![WordPress.org Plugin](https://img.shields.io/wordpress/plugin/v/aeo-god-mode.svg?label=WordPress.org)](https://wordpress.org/plugins/aeo-god-mode/)
[![WordPress.org Rating](https://img.shields.io/wordpress/plugin/r/aeo-god-mode.svg?label=Rating)](https://wordpress.org/plugins/aeo-god-mode/#reviews)
[![Active Installs](https://img.shields.io/wordpress/plugin/installs/aeo-god-mode.svg?label=Active%20installs)](https://wordpress.org/plugins/aeo-god-mode/)
[![Tested up to](https://img.shields.io/wordpress/plugin/tested/aeo-god-mode.svg?label=Tested%20up%20to)](https://wordpress.org/plugins/aeo-god-mode/)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

**Live demo + free AI search audit:** [aeogodmode.io/ai-search-audit/](https://aeogodmode.io/ai-search-audit/)

---

## What this is

AEO God Mode is the open-source WordPress plugin that makes your site visible to AI search engines. It controls which AI bots can crawl you, generates the schema and structured data AI engines look for when they pick sources to cite, and runs alongside Yoast, Rank Math, SEOPress and AIOSEO without conflicts.

Traditional SEO plugins optimise for Google's blue links. AEO God Mode optimises for the box at the top of every modern search result: the AI Overview. The Answer Engine. The thing your customer reads before they decide whether to click.

This repository is a public mirror of the plugin published on [WordPress.org](https://wordpress.org/plugins/aeo-god-mode/). Issues, code review and visibility live here. Installs and updates flow through WP.org so your site updates the way every other plugin does.

## Install

The supported install path is the WordPress plugin directory:

1. **From WP admin:** Plugins → Add New → search "AEO God Mode" → Install → Activate
2. **From WP.org direct:** [wordpress.org/plugins/aeo-god-mode/](https://wordpress.org/plugins/aeo-god-mode/) → Download → upload via Plugins → Add New → Upload Plugin
3. **WP-CLI:** `wp plugin install aeo-god-mode --activate`

The repository here is for code review, contributions and audit. Do not install from the `Code → Download ZIP` button if you want auto-updates.

## What you get for free

- AI Crawler Allowlist for 18 bots (GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, ChatGPT-User, Claude-User, Perplexity-User, Google-Extended, Meta-ExternalAgent, Bytespider, Applebot-Extended and more)
- Schema engine: FAQPage, HowTo, Article, Product, Organization, LocalBusiness, Speakable, BreadcrumbList, more
- Schema Conflict Detector: defers to Yoast / Rank Math / SEOPress instead of double-publishing
- Schema Validator with pass / warn / fail per page
- llms.txt generator
- AI Crawler Log: every AI bot visit timestamped, so you can prove ChatGPT, Claude and Perplexity are crawling your site
- AEO Health Dashboard inside `wp-admin`
- Content Gap Scanner: pages missing FAQ, schema, or answer-shaped content
- Editor Panel inside Gutenberg
- AI Metadata Generator with 5 free credits per month

## What Pro adds

License at [aeogodmode.io/pricing/](https://aeogodmode.io/pricing/).

- **Citation Tracker** — query ChatGPT, Perplexity, Gemini and Claude weekly with your real customer queries and report which engines cite you, how often, and whether your share is rising or falling
- **Citability Score (0-100)** per page against the 10 signals AI engines actually weigh
- **Google Search Console integration** with AI Query Explorer, Fan-Out Cluster detection, Orphaned Page detection
- **AI Internal Link Builder** powered by your real GSC queries
- **EEAT Author Schema** for named expertise
- **AI Referral Analytics** — track visitors from chatgpt.com, perplexity.ai, gemini.google.com, claude.ai as separate traffic sources
- **Section Index** — analyses every H2 / H3 on your site to surface the quote-worthy paragraphs
- **Bulk actions** for hundreds of posts at once
- **500 metadata credits / month**

## Compatible with your existing SEO stack

Runs alongside [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/), [Rank Math](https://wordpress.org/plugins/seo-by-rank-math/), [SEOPress](https://wordpress.org/plugins/wp-seopress/) and [All in One SEO](https://wordpress.org/plugins/all-in-one-seo-pack/) without doubling up schema. The Conflict Detector reads what your existing SEO plugin is publishing and only fills the gaps.

## Free AEO audit (no install required)

Want to see what AI engines see when they crawl your WordPress site? Run the [free AEO audit at aeogodmode.io/ai-search-audit/](https://aeogodmode.io/ai-search-audit/). Results in 30 seconds, no signup.

## Links

- **Plugin homepage:** [aeogodmode.io](https://aeogodmode.io/)
- **WordPress.org listing:** [wordpress.org/plugins/aeo-god-mode/](https://wordpress.org/plugins/aeo-god-mode/)
- **Free audit tool:** [aeogodmode.io/ai-search-audit/](https://aeogodmode.io/ai-search-audit/)
- **Pricing (Pro):** [aeogodmode.io/pricing/](https://aeogodmode.io/pricing/)
- **Citation Tracker:** [aeogodmode.io/plugin/citation-tracker/](https://aeogodmode.io/plugin/citation-tracker/)
- **Smart Internal Linking:** [aeogodmode.io/plugin/smart-internal-linking/](https://aeogodmode.io/plugin/smart-internal-linking/)
- **Support:** support@aeogodmode.io

## How this mirror works

This repository is automatically synced from the [WordPress.org SVN repository](https://plugins.svn.wordpress.org/aeo-god-mode/) for the plugin. Every WordPress.org release tag becomes a GitHub release here. The sync runs every 6 hours via [`.github/workflows/sync-from-wp-svn.yml`](.github/workflows/sync-from-wp-svn.yml). You can manually trigger a sync from the Actions tab.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Changelog

See [readme.txt](readme.txt) for the full changelog. Each released version is also tagged here as a GitHub Release.
