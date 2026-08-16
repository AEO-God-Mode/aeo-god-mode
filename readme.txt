=== AEO God Mode – AI Search and Answer Engine Optimization ===
Contributors: ariellejphoenix
Tags: answer engine optimization, ai seo, llms.txt, schema, chatgpt
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.6.44
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Improve WordPress content for AI search with crawler controls, schema, llms.txt, content health checks, and citation tracking.


== Description ==

AEO God Mode helps you prepare WordPress content for AI search without replacing your existing SEO plugin.

It shows what needs fixing, what needs a human check, and whether AI crawlers can reach your pages. Use it alongside Yoast SEO, Rank Math, SEOPress, or All in One SEO.

https://youtu.be/1wimw25YxWw

= Start with the work that matters =

**Content Health** scans published content and ranks confirmed problems separately from items that need judgement. Find duplicate or missing descriptions, heading problems, overlong titles, and images that may need alt text.

**Link Health** checks links without treating every blocked automated request as a broken page. Redirects are followed, uncertain results are separated, and every reported URL can be opened for a manual check before you change anything.

**Answer Density** checks whether question-shaped headings lead with a direct answer. It explains which pages are not graded and lets you rescan after editing.

= Free features =

* **AI crawler controls:** review search, user-fetch, training, and usage-control agents separately. A missing rule is reported as “No rule,” not falsely labelled blocked.
* **Crawler access report:** see which AI search crawlers robots.txt allows, blocks, or does not explicitly mention.
* **Content Health and Link Health:** scan the site, prioritise work, and open affected pages or links for review.
* **Answer Density and Content Gaps:** find pages that bury answers or lack useful question-led sections.
* **Schema engine:** output supported structured data and detect conflicts with other SEO plugins.
* **llms.txt generator:** publish and edit a plain-text summary at your site root. llms.txt is a proposed convention, not a ranking guarantee.
* **Crawler log:** record visits from recognised AI user agents.
* **AI metadata:** generate titles or descriptions when you choose to. Free includes 10 credits per month; nothing is generated or saved automatically.
* **Light and dark admin themes** and a guided setup flow.

= Pro adds measurement and automation =

Pro is an optional companion plugin. The free plugin remains required because it owns the shared dashboard and REST API.

* **Citation Tracker:** run your tracked questions against supported AI providers and see whether your domain was cited.
* **AI Referrals:** separate visits arriving from AI assistants from ordinary referral traffic.
* **Citability Score:** review page-level signals and actions.
* **Google Search Console:** bring indexing, query, and content-opportunity data into the AEO dashboard.
* **E-E-A-T author profiles:** add structured author credentials and optional author cards.
* **Topical Map, Knowledge Base, and internal-link tools:** plan coverage and ground supported generation in your own business facts.
* **Bulk fixes:** generate, review, and approve metadata for selected content. Pro includes 500 credits per month.

Learn more at <https://aeogodmode.io/>.

= Built for safe decisions =

AEO God Mode does not promise rankings or citations. It reports what it can verify and labels uncertainty. Blocking an AI training crawler is treated as a content-use choice, not automatically as a site problem. Metadata, rewrites, and bulk changes require an explicit action from an authorised WordPress user.


== Installation ==

1. In WordPress, go to **Plugins → Add New**.
2. Search for **AEO God Mode**.
3. Install and activate the plugin.
4. Open **AEO** in the WordPress admin menu and complete the setup guide.
5. Run Content Health, check crawler access, and review your first recommended action.

The plugin can also be installed by uploading the `aeo-god-mode` folder to `/wp-content/plugins/` and activating it from the Plugins screen.


== Frequently Asked Questions ==

= What is Answer Engine Optimization? =

Answer Engine Optimization, or AEO, is the work of making content easier for search and AI systems to discover, understand, and quote accurately. It complements traditional SEO rather than replacing it.

= Does AEO God Mode guarantee citations or Google AI Overview placement? =

No. No plugin can guarantee a citation, ranking, or inclusion in an AI-generated answer. AEO God Mode helps you find technical and content issues, publish structured context, and measure supported outcomes.

= Does it replace Yoast SEO, Rank Math, SEOPress, or All in One SEO? =

No. It runs alongside them and checks for overlapping schema. It does not silently replace titles, descriptions, canonicals, or sitemaps.

If you explicitly approve an AI metadata result, AEO God Mode writes it to the compatible SEO field used by your active plugin. You can review generated text before saving.

= What does “No rule” mean for an AI crawler? =

It means robots.txt contains no crawler-specific allow or disallow directive. It does not mean the crawler is blocked, and it does not prove that the crawler has visited the site.

= Why can a working link show 403 or “not tested”? =

Some sites allow normal visitors but reject automated requests. Link Health separates refused or inconclusive checks from confirmed broken links. Open the URL yourself, then choose Ignore if you do not want it counted or shown again.

= Does Link Health follow redirects? =

Yes. A working 301 or 302 redirect is not treated as a broken destination. Confirmed replacement pages may be offered as a safer update than unlinking.

= Is llms.txt required by AI companies? =

No. It is a proposed convention. The plugin can publish a clear file at `/llms.txt`, but you should treat it as low-cost supporting context, not as proof that an AI crawler will read or use it.

= What happens when I use an AI action? =

Only after you click a Generate, Analyze, or Fix action does the plugin send the relevant title and content text for processing. The result is returned for review. Credit costs are shown before generation, and failed requests are not presented as successful edits.

= Is the plugin free? =

The crawler controls, health checks, schema tools, llms.txt, crawler log, setup guide, and monthly free AI credits are available in the free plugin. Pro adds citation measurement, Search Console data, author enrichment, planning tools, and higher AI credit limits.

= Does it support WooCommerce and Easy Digital Downloads? =

Yes. Product and download post types can use the supported metadata and schema workflows when those plugins are active.


== Screenshots ==

1. Content Health ranks confirmed fixes separately from checks that need human judgement.
2. Citation Tracker (Pro) keeps each question, provider response, source, and cited page together.
3. AI crawler controls separate search, user fetch, training, and data-use rules clearly.
4. Link Health follows redirects and lets users open, replace, unlink, or permanently ignore a URL.
5. Answer Density shows exactly which answers are direct or buried.
6. Schema validation checks required fields and avoids duplicate output from supported SEO plugins.
7. E-E-A-T profiles (Pro) keep credentials, expertise, reviewed content, and public author details consistent.
8. The llms.txt workspace curates important pages and previews the file before publishing.
9. Content Gaps ranks missing answers, weak structure, and absent schema by impact.
10. Pro bulk fixes generate one result per page and save only approved changes.


== External Services ==

This plugin connects to external services only for the features described below.

**AI generation and analysis**

When an authorised user manually starts an AI task, the relevant title, content text, and selected task settings are sent securely to the AEO God Mode service for processing. The service returns the generated text or analysis to the WordPress site. No AI task is triggered simply because someone visits a page.

Service: <https://aeogodmode.io/>
Privacy policy: <https://aeogodmode.io/privacy/>
Terms of service: <https://aeogodmode.io/terms/>

**Pro licence validation and updates**

The Pro companion sends its licence key and site URL to `https://aeogodmode.io/` to validate access and check for updates.

**Citation Tracker (Pro)**

When a user runs a citation check, the plugin queries the selected Perplexity, OpenAI, Google Gemini, and Anthropic services. Queries, the target domain, and provider credentials needed for that request are transmitted. User-provided API keys are encrypted at rest with WordPress salts.

**Google Search Console (Pro)**

OAuth token exchanges and authorised Search Console requests are proxied through `https://aeogodmode.io/`. Site content is not sent through the Search Console proxy.

== Changelog ==

= 1.6.44 =
* No fixed ceiling for topical authority plans
* Safe duplicate-resistant expansion across repeated design passes

= 1.6.43 =
* Keeps redesigned topical maps clean by retiring obsolete AI suggestions
* Preserves and reactivates previously selected ideas when relevant again

= 1.6.42 =
* Curated topic research signals for stronger authority maps
* evidence-safe demand handling
* and research visibility in the Topical Map

= 1.6.41 =
* Expanded Topical Map authority plans to 100–150 focused article opportunities
* Added planned WordPress categories, publish-next priorities, and future internal links
* Generated Topical Map drafts now reuse or create their planned category automatically

= 1.6.40 =
* Content Health bulk fixes and verified rescans
* safer Yoast llms.txt ownership switching
* improved llms.txt controls and custom post type support

= 1.6.39 =
* Fixed empty and stale HowTo overrides with safe per-post recovery
* Kept HowTo regeneration synced to current content without unexpected AI credits
* Improved Rank Math and Yoast schema ownership parity

= 1.6.38 =
* Aligned Draft Quality
* Answer Density
* schema quality
* answer-first checks and domain audit truthfulness

= 1.6.37 =
* Human-readable provider errors and a corrected Agency price calculator.

= 1.6.36 =
* More consistent editor scoring and reusable Agency client-site seats.

= 1.6.35 =
* Secured Agency account access and co-branding.

= 1.6.34 =
* Added openable Link Health results and evidence-based crawler status.
* Rebuilt Content Health as a ranked action dashboard with a full report workspace.
* Improved Answer Density rescans and rewrite context.
* Removed retired duplicate metadata bulk actions.


== Upgrade Notice ==

= 1.6.37 =
Improves provider error messages and Agency pricing behaviour.
