# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**Masthead** is a coordinator plugin for the WordPress editorial suite. It is not a feature plugin — it bundles and wires together sibling plugins (Edit Ledger, Rewrites, WordPress AI) behind a single settings screen, and ships a few editorial features of its own.

Sibling plugins live in separate repos and are expected to be installed alongside Masthead at `wp-content/plugins/`:
- `edit-ledger` — revision history, media tracking, AI summaries
- `rewrites` — staged revisions, publication checklist, scheduled publishing
- WordPress AI plugin (provides `wp_ai_client_prompt` + Connectors API)

## Development Environment

Dev runs against **WordPress Studio** with WordPress trunk (WP 7.1-alpha). The studio site is `smodiclaw-trunk` at `http://localhost:8883`.

```bash
studio site list
studio wp --path ~/Studio/smodiclaw-trunk plugin list
```

Masthead has no JS build step — assets under `assets/` are plain JS.

The WordPress AI plugin **does** need a build:
```bash
cd ~/Studio/smodiclaw-trunk/wp-content/plugins/ai && npm ci && npm run build
```

## Tests

PHPUnit runs through `@wordpress/env`:

```bash
npm run wp-env:start
npm run test:php
# single test:
npm run test:php -- --filter test_method_name
```

The `test:php` script expects the plugin to be mounted at `wp-content/plugins/masthead/` inside wp-env.

## Architecture: how Masthead is wired

`masthead.php` requires every class file at top level, then on `plugins_loaded` calls `masthead_init()` which instantiates singletons in three tiers:

1. **Core singletons** (always loaded) under `includes/`:
   - `Masthead_Settings` — options + admin settings page state
   - `Masthead_Module_Registry` — detects whether sibling plugins are active; **single source of truth** for "is X present?"
   - `Masthead_REST_Controller` — `/wp-json/masthead/v1/*`
   - `Masthead_AI` — wraps WP 7.0 AI Client (`wp_ai_client_prompt`) and the Abilities API (`class-masthead-abilities.php`)
   - `Masthead_Connector` — bridges to sibling plugins
   - `Masthead_GitHub_Installer` / `Masthead_GitHub_Updater` — install + auto-update suite plugins from GitHub releases

2. **Cross-plugin integrations** under `includes/integrations/` — files are required unconditionally, but their singletons are only instantiated when **both sides** are active:
   - `Masthead_Edit_Ledger_Rewrites` — guarded by `$registry->is_active( 'edit-ledger' ) && $registry->is_active( 'rewrites' )`
   - `Masthead_AI_Rewrites` — guarded by `function_exists( 'wp_ai_client_prompt' )`

   When adding a new integration, follow this pattern: require the file at top level of `masthead.php`, then instantiate inside `masthead_init()` behind a registry / `function_exists` guard. **Never call sibling-plugin functions outside that guard** — they may not be loaded.

3. **Masthead's own features** under `includes/features/` — `class-publication-checklist.php`, `class-scheduled-publishing.php`, `class-staged-revisions.php`. Distinct from coordinator glue.

Admin UI: `admin/class-masthead-admin.php` + view partials in `admin/views/`.

## AI integration notes

- Masthead targets the **WP 7.0 Connectors API + WP AI Client** (commit `3ce7bdd` was the pivot away from direct provider calls).
- `using_temperature()` was removed in `0b59097` because newer Claude models reject the param — do not reintroduce it.
- All AI calls go through `Masthead_AI` / `wp_ai_client_prompt`. Do not call provider SDKs directly from feature code.

## Commits

`commit.sh` is a frozen one-off helper for a specific past README commit — do not reuse it as a general commit script.
