# CLAUDE.md

Orientation for Claude Code, Claude Desktop, and Claude API agents working in this repository.

The same guidance applies to all coding agents and is documented in [AGENTS.md](AGENTS.md). This file exists so Claude-specific tooling that looks for `CLAUDE.md` by default finds it.

**Please read [AGENTS.md](AGENTS.md) first.** Everything below is Claude-specific additions on top of that.

## Claude-specific notes

### Output rules
- No em dashes anywhere. Commas, periods, or parentheses instead. This is a hard rule for all generated content (code, commits, docs, PR descriptions).
- User-facing strings stay plain English with customer benefit framing. Never expose internal mechanisms or admit failure modes in changelogs (competitors read these).
- For schema output, prefer published patterns from schema.org and Google's structured data documentation over invented properties.

### Working in this repo
- Source of truth is WordPress.org SVN, not this repo. PRs land here, then ship through the WordPress.org release process.
- The admin UI is a React bundle in `assets/admin/`. Source for this bundle lives outside this public mirror, so prefer changes to PHP, schema, REST endpoints, Site Health checks, and prompt content.
- When asked to add a feature, first look in `includes/` for similar existing functionality. The plugin is mature enough that most patterns already exist.

### When the user asks for a Pro feature
The free build does not contain `includes/pro/`. If a request requires Pro functionality, ask the maintainer to confirm the change should land in the Pro build separately. Do not introduce Pro-only code paths into the free build.

### Useful slash commands when running in Claude Code
- `/init` to refresh this file when project structure changes significantly
- `/review` to review the current branch against the AEO standards above
- `/security-review` before any release that touches REST endpoints, schema escaping, or option handling
