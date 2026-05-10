# AGENTS.md

This file orients AI coding agents (Codex, Cursor, Claude Code, Aider, Continue, etc.) working in this repository.

## What this project is

AEO God Mode is a WordPress plugin that helps sites get cited and recommended by AI search engines (ChatGPT, Claude, Perplexity, Gemini, Google AI Overviews). The plugin produces structured data, controls AI crawler access, generates an llms.txt file, tracks brand citations, and ships a setup wizard for non-technical site owners.

Live site: https://aeogodmode.io
WordPress.org listing: https://wordpress.org/plugins/aeo-god-mode/

## Source of truth

The WordPress.org SVN repository is the canonical source. This GitHub repo is a read-mostly mirror updated automatically every 6 hours from WordPress.org SVN by `.github/workflows/sync-from-wp-svn.yml`.

What this means for you as an agent:

- A PR you open here will be reviewed and merged into `main`
- The merged change is then incorporated into the next WordPress.org release
- Once released, the sync workflow brings the new tag back into this repo
- Direct edits to release assets in this repo will be overwritten on the next sync

## Repo layout

```
aeo-god-mode.php       Plugin bootstrap, header, constants
readme.txt             WordPress.org metadata, changelog (canonical)
changelog.md           Markdown mirror of readme.txt changelog
includes/              All PHP. Schema, REST, AI prompts, Site Health, etc.
assets/                Pre-built admin React bundle, icons, CSS
languages/             Translations (.pot, .po, .mo)
.github/workflows/     CI and the WordPress.org SVN sync workflow
```

The plugin runs without a build step on the PHP side. The admin React bundle in `assets/admin/` is pre-built and committed.

## Coding standards

- **PHP**: WordPress Coding Standards. Use existing helpers in `includes/` before writing new ones.
- **JavaScript**: Follow the existing eslint config in `assets/admin/`.
- **Schema**: Any change to JSON-LD output must produce valid Schema.org. Verify with the Schema.org Validator and Google Rich Results Test.
- **Strings**: All user-facing strings go through `__()` / `esc_html__()` for translation. Plain English. Customer benefit framing, not internal mechanism.
- **No em dashes** in user-facing strings, commit messages, or documentation. Use commas, periods, or parentheses.
- **Settings**: New options must appear in the Settings UI. No hidden flags.
- **Security**: Sanitize all input. Escape all output. Use nonces on every state-changing REST endpoint and admin form. Capability checks on every privileged action.

## What NOT to change without discussion

- The plugin file header version field (release process owns this)
- `readme.txt` `Stable tag` field (release process owns this)
- The WordPress.org SVN sync workflow
- Anything under `includes/pro/` (this directory does not exist in the public free build by design)
- Vendor code in `includes/vendor/`

## Useful commands

```bash
# Pull the latest from WordPress.org SVN locally
git pull origin main

# Validate the JSON-LD output for a given URL
curl -s https://example.com | grep -A 999 'application/ld+json'

# Check the changelog mirror is up to date
diff <(awk '/== Changelog ==/,/== Upgrade Notice ==/' readme.txt) changelog.md
```

## When in doubt

Ask in the PR description before making large changes. The maintainer prefers small, focused PRs with a clear AEO outcome over large refactors.
