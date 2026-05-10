# Changelog

Mirror of the `readme.txt` changelog from the WordPress.org repository.

For the WordPress.org formatted version see https://wordpress.org/plugins/aeo-god-mode/#developers


## 1.6.1

- Citation Tracker: Run Check now refreshes each query's per-engine result instead of stacking new rows alongside old ones
- Citation Tracker: every saved query is automatically labelled Branded or Non-branded so you can tell organic mentions from prompted ones at a glance
- Citation Tracker: API keys are validated the moment you paste them with a clear reason if a key is expired/invalid/out of credit
- Citation Tracker: provider error messages now show on the affected engine row instead of a generic error pill
- Citation Tracker: Run Check is safe to click multiple times in a row
- AI Generate: tighter conversion-focused query suggestions
- with no geo modifiers added for software products that serve customers globally

## 1.6.0

- Pro / Citation Tracker: redesigned dashboard. Cleaner three-tab layout, per-engine filtering, a 30-day hit-rate chart with period-over-period change, and a grouped view that shows every query and which AI engine cited you at a glance.
- Pro / Citation Tracker: control your own query list. Add queries one at a time, paste a list, or generate buyer-intent suggestions tailored to your site. Remove individual queries or clear everything with one click.
- Pro / Citation Tracker: API keys are checked the moment you paste them, so you know straight away if a key is valid and ready to use.
- Pro / Citation Tracker: faster, more accurate per-query results with clearer engine status (cited, not cited, error) and direct links to the cited URL plus surrounding context.
- Pro / Smart Internal Linking: refined approval flow with side-by-side BEFORE/AFTER preview for every suggested link, plus a real-time progress counter while you approve.
- Compatibility and polish: dashboard sidebar version is now correctly read from the plugin, and the admin React bundle ships with stricter hook ordering for reliability.

## 1.5.98

- Pro / Internal Link Builder: page-mode discoverability dramatically improved. The similarity floor now runs AFTER hybrid scoring, so sections with strong entity/heading overlap but weaker raw cosine survive (the comparison-post case where the page-level embedding dilutes specific topic matches).
- Pro / Internal Link Builder: multi-query retrieval. Page mode now embeds the target's title and H2/H3 headings alongside the LOP vector and takes each section's best match across the pool. sections tightly aligned with one heading are no longer drowned out by the page average.
- Pro / Internal Link Builder: structural anchors (title segments split on "vs"/"and"/"or"/":", plus H2/H3 headings) are auto-injected into the Stage 2 anchor toolkit so the AI has obvious, on-page noun phrases to choose from in addition to the AI-generated strategic anchors.

## 1.5.97

- Pro / Internal Link Builder: fix root cause of empty suggestions. Section_Index now embeds sections after indexing (previously sections were indexed without embeddings, so retrieval queries filtering by `embedding IS NOT NULL` returned zero candidates).
- Pro / Internal Link Builder: credits are now only consumed when at least one suggestion is returned. Empty results no longer cost credits.
- Pro / Internal Link Builder: structured failure reasons (no_indexed_content, no_embeddings, weak_candidates, all_duplicates, no_natural_insertion_point) replace a single generic empty message.
- Pro / Internal Link Builder: similarity floor (0.55 page mode / 0.45 query mode) drops weak candidates before any AI call.
- Pro / Internal Link Builder: hybrid scoring combines semantic similarity with heading match, entity overlap, anchor-phrase match, and same-category boost.
- Pro / Internal Link Builder: posts longer than 2,000 words can now contribute up to 2 link suggestions instead of 1.
- Pro / Internal Link Builder: Regenerate now bypasses the Link Opportunity Profile cache for genuinely fresh results.
- Pro / Internal Link Builder modal: anchor-hint input, index status row, Rebuild index button, and per-reason recovery actions (Rebuild index / Broaden search) on empty states.
- Pro / Internal Link Builder: post saves now schedule embedding via wp-cron so newly published content becomes eligible without a manual rebuild.
- Site Health: Organization schema check now reads from `asgm_settings.business` (the same source of truth used by schema output), fixing the false "No Organization schema configured" warning when the field was filled in.

## 1.5.96

- Free build no longer ships Pro classes (includes/pro/) or the self-hosted updater (class-updater.php). License activation UI now correctly hides on Free installs.
- Dashboard Pro Features teaser: added AI Metadata Generator and Smart Internal Linking cards.

## 1.5.95

- Fix: include admin React bundle in WP.org release (1.5.94 was missing assets/admin/, causing Loading AEO God Mode... to hang on the dashboard)

## 1.5.94

- Added success confirmation and error alert to llms.txt Regenerate button

## 1.5.93

- Prime llms.txt on activation so Site Health check passes on fresh install

## 1.5.92

- Updated Metronyx AI URL to metronyxai.com across plugin header and admin UI

## 1.5.91

- Hide WordPress admin footer on plugin page to fix sidebar overlap

## 1.5.90

- Auto-redirect to Setup Wizard on activation
- Added loading fallback and setup admin notice
- Expanded readme Installation guide for WordPress.org review

## 1.5.89

- Fixed REST API permission_callback on GSC OAuth endpoint per WordPress.org review

## 1.5.88

- Moved admin menu position below core WordPress items for wp.org compliance
- Fixed GSC internal links count not persisting between syncs
- Fixed Not Indexed counter not counting uninspected pages

## 1.5.87

- Adjusted admin menu position for better WordPress.org compliance

## 1.5.86

- Fixed JSON-LD schema output escaping with JSON_HEX_TAG and JSON_HEX_AMP flags to prevent script breakout
- Added ariellejphoenix to plugin contributors

## 1.5.84

- Resolved final Plugin Check warnings (OPCache reset, gmdate, secure sql, excluded claude dir)

## 1.5.83

- Injected dynamic current year into AI prompt to prevent outdated year suggestions like 2024

## 1.5.82

- Fixed JSON parsing for title generation and enabled free meta generation

## 1.5.81

- Fix free version credit tracking and upsell

## 1.5.6

- Fix WP.org review feedback

## 1.5.5

- Added WooCommerce and EDD product support to generative AI pipelines
- Fixed AEO Titles bulk generation task routing bug
- Enhanced AI meta prompt rules for products

## 1.5.4

- Fixed Editor Panel overlapping issues and updated tag visibility
- Fixed incorrect error flagging on existing Meta data in Content Gaps

## 1.5.3

- Added support for passing product context to generative AI pipelines

## 1.5.2

- Fixed EDD updater dropping plugin context
- Fixed updates failing silently

## 1.5.1

- FAQ preview and undo in Content Gap Scanner
- Meta description preview
- Google Analytics gtag support

## 1.5.0

- Added AI Metadata Generator with 5 description styles
- Credit system
- SEO plugin compatibility (Yoast/Rank Math/native)
- Server-side AI proxy

## 1.4.1

- Redesigned AI Signals page into interactive dashboard with status cards and configurable headers
- Added honest 'No known crawler support' badges to experimental HTTP headers
- Added IETF AIPref standards roadmap card
- Fixed search dropdown transparent background on Schema page
- Added timestamp tracking for robots and llms.txt saves

## 1.4.0

- AI Crawler Intelligence dashboard with 30-day activity chart hero cards and tabbed intelligence panel
- Pro-gated Top Crawled Pages and Blind Spots tabs with backend data protection
- Bot Activity cards remain free

## 1.3.5

- Added AEO Site Health dashboard widget
- Removed site health from Content Gap Scanner

## 1.3.4

- Added 4 new AI crawlers: OAI-SearchBot (ChatGPT Search), Claude-SearchBot, Claude-User, Perplexity-User
- Added Meta-ExternalAgent (LLaMA) and DeepSeekBot
- Fixed Applebot-Extended user-agent pattern
- Deprecated Anthropic-AI (kept for backward compatibility)
- Total recognised AI crawlers: 18
- Reordered WP sidebar: free features first
- Pro features at bottom with crown badge. Greyed out GSC settings for free users

## 1.3.3

- Built updated React JS with Setup Wizard Documentation link
- Pro toggle disablement
- API cache busting

## 1.3.2

- Added Complete Setup Wizard to Free and Pro versions with documentation links
- Disabled Pro features toggle for Free tier
- Simplified dashboard redirect logic to fix loop

## 1.3.1

- Added password reset POST trace logger

## 1.3.0

- Added Google Search Console integration (Pro): query analytics, page performance, sitemap management, index coverage, manual URL inspection
- Added GSC OAuth proxy for secure API communication
- Added site sync with batch processing and progress indicators
- Improved llms.txt with spec-compliant sections and editable context area
- Added GSC toggle to wizard Features step
- Updated Settings page with GSC connection management

## 1.2.1

- Merged AEO Layer into Schema page with Speakable toggle and CSS selector editor
- Removed AEO Layer page from sidebar
- Added informative tooltips to all module toggles in Settings
- Renamed AEO Meta Layer toggle to Meta Description Fallback

## 1.2.0

- Added Search Console page with AI query classification and fan-out detection
- Rewrote health score to use real signals instead of module toggles
- Added GSC submenu registration

## 1.1.0

- E-E-A-T per-field display toggles with live preview
- Author avatar upload fix

## 1.0.5

- Initial public release
- Setup wizard with Yoast and Rank Math import
- AI Crawler Allowlist and Visit Log
- llms.txt generation
- Schema Engine with auto-detection
- Content Gap Scanner with one-click fixes
- Schema Validator
- Citation Tracker (Pro)
- AI Referral Traffic (Pro)
- Citability Score (Pro)
- E-E-A-T Schema Enrichment (Pro)
- Google Search Console Integration (Pro)


