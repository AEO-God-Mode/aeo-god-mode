# Contributing to AEO God Mode

Thanks for your interest in helping improve AEO God Mode, the WordPress plugin that gets sites cited and recommended by ChatGPT, Claude, Perplexity, Gemini, and Google AI Overviews.

This file explains how to report bugs, suggest features, and submit code.

## This repo is a mirror

The source of truth for releases lives on the WordPress.org plugin repository:

- WordPress.org listing: https://wordpress.org/plugins/aeo-god-mode/
- Live site: https://aeogodmode.io

This GitHub repo is updated automatically every 6 hours from the WordPress.org SVN. That means:

- New releases land here as soon as the WordPress.org sync workflow runs
- You can read the code, file issues, open discussions, and propose patches
- Direct pushes to this repo's `main` branch are not the release path. Code lands in WordPress.org first, then mirrors here

## Reporting a bug

Please [open a Bug Report issue](https://github.com/AEO-God-Mode/aeo-god-mode/issues/new?template=bug_report.md) and include:

1. AEO God Mode version (Plugins screen in WP admin)
2. WordPress version
3. PHP version (Tools, Site Health, Info)
4. Active theme
5. Other SEO plugins active (Yoast, Rank Math, AIOSEO, SEOPress, etc.)
6. The page URL where the issue appears
7. Steps to reproduce
8. What you expected vs what actually happened
9. Any relevant screenshot or browser console error

If the bug touches schema output, please paste the full JSON-LD block from page source so we can reproduce against the same data.

## Suggesting a feature

Please [open a Feature Request issue](https://github.com/AEO-God-Mode/aeo-god-mode/issues/new?template=feature_request.md) and tell us:

1. The AEO outcome you are trying to reach (more citations, better schema coverage, AI crawler control, brand sentiment, etc.)
2. The current workaround you are using
3. How you would expect the feature to behave inside the plugin
4. Anything similar you have seen in other tools

## Reporting a security issue

Please do NOT open a public issue for security vulnerabilities. See [SECURITY.md](SECURITY.md) for the private disclosure process.

## Submitting code

We accept pull requests against `main`. Because the WordPress.org SVN is the source of truth, an accepted PR follows this path:

1. You open the PR here on GitHub
2. We review, request changes if needed, and merge
3. The change is included in the next WordPress.org release
4. The release lands back in this repo via the sync workflow

Things that help your PR get merged quickly:

- One change per PR. A schema fix and a UI tweak should be two PRs
- Match the existing code style. PHP follows the WordPress Coding Standards. JavaScript follows the existing eslint config in `assets/admin/`
- If you change a user-facing string, keep it plain English. Customer benefit, not internal mechanism
- If you touch JSON-LD output, paste a Schema.org Validator screenshot in the PR description
- If you add a new option, add the corresponding entry to the Settings UI. Do not introduce hidden options
- No em dashes in user-facing copy or commit messages

## Local development

Clone, then symlink into a WordPress install:

```
cd /path/to/wp-content/plugins
ln -s /path/to/your/clone aeo-god-mode
```

The plugin runs out of the box with no build step required for the PHP side. The admin React bundle in `assets/admin/` is pre-built and committed.

## Translations

Translations live on https://translate.wordpress.org/projects/wp-plugins/aeo-god-mode/. Pull requests against `languages/` are welcome but the canonical translation home is GlotPress.

## Questions

For general usage questions, please use the WordPress.org support forum: https://wordpress.org/support/plugin/aeo-god-mode/

For commercial questions about Pro features, see https://aeogodmode.io
