=== AEO God Mode – AI Search Visibility ===
Contributors: ariellejphoenix
Tags: answer engine optimization, ai seo, llms.txt, schema, chatgpt
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.6.80
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See and improve how AI finds and understands your site with crawler controls, content audits, schema and llms.txt. Pro adds citation tracking.


== Description ==

AEO God Mode is the WordPress AI visibility toolkit for teams that want evidence, not another generic AI writer.

See whether AI crawlers can reach your pages, identify content and schema gaps, and publish machine-readable context. Pro adds Citation Tracker, AI referrals, Google Search Console, topical planning, and review-first automation.

Use it alongside Yoast SEO, Rank Math, SEOPress, or All in One SEO. It detects supported SEO plugins and avoids duplicating the work they already own.

https://youtu.be/1wimw25YxWw

= See what blocks AI visibility =

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

= What does AEO God Mode actually do? =

Three things. It controls which AI crawlers may read your site and records every visit they make, so you can see whether ChatGPT, Claude, Perplexity and Google are reading you at all. It scores your pages on how directly they answer real questions, and shows you which ones to fix first. And it publishes machine readable context about your site, including llms.txt and structured data.

= Does it replace Yoast SEO, Rank Math, SEOPress, or All in One SEO? =

No, and it is built to sit alongside them. AEO God Mode detects the SEO plugin you already run and steps back from anything it already handles, so you do not get duplicate titles, descriptions or schema. Keep your SEO plugin for search rankings and use this for AI answer visibility.

= Does AEO God Mode guarantee citations or Google AI Overview placement? =

No. No plugin can guarantee a citation, ranking, or inclusion in an AI-generated answer. Anyone who promises that is guessing. What this does is find the technical and content problems that keep you out, publish the context AI systems can read, and measure what actually happens.

= Is the plugin free, and what does Pro add? =

The free plugin is a working product, not a trial. You get AI crawler controls, the crawler visit log, Answer Density scoring, content gap scanning, llms.txt, structured data, the setup guide, and monthly AI credits.

Pro adds the measurement and production side: checking whether ChatGPT, Perplexity, Gemini and Claude actually quote you, Google Search Console data, author credibility markup, topical planning, and higher AI credit limits.

= Will it slow my site down? =

The visitor-facing footprint is small by design. The content blocks render as plain HTML with no JavaScript, their styling is printed once per page, and the scoring and scanning work all happens in your admin area rather than on page loads. Crawler logging records bot visits only, never your human visitors.

= Do I need to be technical to use it? =

No. The setup guide walks you through the first configuration, and the dashboard tells you which page to improve next and why. Nothing requires editing code, robots.txt or template files by hand.

= Is my content sent to AI companies? =

Only when you choose to use an AI feature, such as generating a draft or checking citations, and then only the content that feature needs. Crawler logging, scoring, schema and llms.txt all run entirely on your own server. Documents you upload to the Knowledge Base are stored as text on your site and never leave it.

= How soon will I see a difference? =

Crawler data appears as soon as a bot visits, often within days. Content scores appear as soon as your first scan finishes. Getting quoted by an AI system depends on your content and your competition, so treat it as ongoing work rather than a switch you flip.

= Is llms.txt required by AI companies? =

No. llms.txt is a proposed convention, not an adopted standard, and no AI company has committed to reading it. It costs nothing to publish and the plugin keeps yours current automatically, but treat it as a courtesy to machine readers rather than a ranking lever.

= Does it work with my theme, page builder, and WooCommerce? =

Yes. The plugin works with any correctly built theme and does not depend on a page builder. Product and download post types are supported when WooCommerce or Easy Digital Downloads is active.

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

When an authorised user manually starts an AI task, the relevant title, content text, and selected task settings are sent securely to the AEO God Mode service for processing. The service returns the generated text or analysis to the WordPress site.

When an authorised user asks the plugin to generate featured-image alt text, the selected image and the page and attachment context shown in the review are sent securely to the AEO God Mode service. The free Content Health scan stays on the WordPress site and sends no images or page content. No AI task runs because someone visits a page or opens the dashboard.

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

= 1.6.80 =
* Fix Citation Tracker credit preflight
* Restore hosted Google Search Console connection

= 1.6.79 =
* Block AI actions when credits are exhausted
* Show Pro and Growth credit top-up screen
* Make credit charging atomic and fail closed

= 1.6.78 =
* Free account connection with secure installation tokens and 20 monthly credits

= 1.6.77 =
* Fixed URL Inspection for verified domain properties

= 1.6.76 =
* Separate Google Search Console consent from free site connection

= 1.6.75 =
* Fix the Wrong Match answer-page chooser in dark mode

= 1.6.74 =
* Show source favicons immediately while the local cache fills

= 1.6.73 =
* Show earned-source favicons automatically after Citation Tracker checks
* Restore the Pro Citation Tracker first-run demo

= 1.6.72 =
* Secure Google Search Console connection
* Restore Pro Citation Tracker first-run demo
* Fail-closed paid entitlements

= 1.6.71 =
* Social copy now keeps your article's full answer instead of shortening it to a bare yes or no

= 1.6.70 =
* Social copy now leads with the question your article answers and keeps product names consistent across platforms

= 1.6.69 =
* Choose how long a video script should be
* clearer script structure
* and the copy buttons now confirm the copy

= 1.6.68 =
* Rewritten social copy instructions for stronger angles
* platform-native writing and stricter source fidelity

= 1.6.67 =
* Social copy keeps the requested number of posts and variations
* and always uses natural contractions

= 1.6.66 =
* Social copy now follows the AEO God Mode writing style
* with human cadence and hooks that are not generic

= 1.6.65 =
* A LinkedIn post now always comes back as one post
* never split in two

= 1.6.64 =
* Fixes the social copy panel so the latest controls and character counts load correctly

= 1.6.63 =
* A single X post now always comes back as one post
* never split across two

= 1.6.62 =
* X character count now matches how X counts links
* so posts ending in a link are no longer flagged as too long

= 1.6.61 =
* X copy can now be a single post as well as a thread

= 1.6.60 =
* Repurpose now shows the real reason when a generation cannot run
* instead of a generic message

= 1.6.59 =
* Repurpose any post into an X thread
* LinkedIn post or video script
* built from the post's own key points

= 1.6.58 =
* Topical Map now works on new sites before Search Console has data
* Search Console messages say when a property is simply too new
* Affiliate badge stays on without an affiliate ID and links to AEO God Mode

= 1.6.57 =
* Pros and Cons blocks now sit side by side however the shortcodes are spaced in the editor

= 1.6.56 =
* ChatGPT citation checks using your own API key now work again after OpenAI retired the previous model

= 1.6.55 =
* Page builder templates and widget areas no longer appear as content types to optimise

= 1.6.54 =
* Pages built with Elementor
* Beaver Builder or Bricks are now read and scored properly instead of appearing empty

= 1.6.53 =
* Dashboard widget no longer flags pages whose content is built by a theme template or page builder

= 1.6.52 =
* New Content Blocks settings tab with four front-end designs
* TL;DR blocks now use bullet points with a choice of bullet style
* FAQ blocks can be added to new drafts automatically
* New dashboard widget showing which AI bots read your site
* Gemini citation checks can now run on your plan credits without an API key

= 1.6.51 =
* Fixed Growth Topical Map designer controls on mature sites

= 1.6.50 =
* Required formatting rules in Knowledge Base
* Automatic shortcode validation before charging

= 1.6.49 =
* Actionable AEO Readiness edits
* Contextual internal-link anchors

= 1.6.48 =
* Charge Keyword Optimize only for safe edits saved
* Refund zero-result runs automatically
* Cache repeated no-result checks

= 1.6.47 =
* Content Gaps now shows analysis-ready recommendations with safe preview and verified save
* Improved content recipes and earned-source opportunities
* Stronger Content Health and topical-map workflows

= 1.6.46 =
* Added image thumbnails and two-credit AI alt-text generation to Content Health image review

= 1.6.45 =
* Improved Search Console opportunity data
* Topical Map planning
* contextual anchors
* Content Health bulk fixes
* AI alt text
* and clearer workflow UI

= 1.6.44 =
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
