# Masthead

**The WordPress editorial suite.**

Masthead is a lightweight coordinator plugin that unifies [Edit Ledger](https://github.com/alansmodic/edit-ledger), [Rewrites](https://github.com/alansmodic/rewrites), and the [WordPress AI plugin](https://github.com/WordPress/ai) into a single admin screen — and wires them together with cross-plugin integrations that only activate when both sides are present.

## Suite Modules

| Plugin | What it does |
|--------|-------------|
| [Edit Ledger](https://github.com/alansmodic/edit-ledger) | Revision history, media change tracking, and AI summaries |
| [Rewrites](https://github.com/alansmodic/rewrites) | Staged revisions, publication checklist, and scheduled publishing |
| [WordPress AI](https://github.com/WordPress/ai) | AI-powered content review, alt text generation, content classification, and more |

Each plugin works standalone. Masthead adds the glue.

## Cross-Plugin Integrations

**Edit Ledger + Rewrites**
- When a staged revision is submitted via Rewrites, Masthead automatically calls Edit Ledger's `summarize-revision` ability and attaches the AI summary to the approval panel
- Reviewers see a plain-English summary of what changed before opening the diff

**WordPress AI + Rewrites**
- Surfaces WordPress AI's Review Notes as a checklist item before a staged revision can be published
- AI review status shown directly in the Rewrites publication checklist

## Masthead's Own Features

Masthead also ships a few features not bundled in the standalone plugins:

- **Suite Settings Screen** — configure all modules from one place
- **Module Registry** — detect which suite plugins are active and surface their status
- **GitHub Updater** — auto-update all suite plugins from their GitHub releases
- **[Editorial Calendar](https://github.com/alansmodic/editorial-calendar)** — visual drag-and-drop publishing calendar (separate plugin in the suite)

## Requirements

- WordPress 7.0+
- PHP 7.4+
- [Edit Ledger](https://github.com/alansmodic/edit-ledger) (recommended)
- [Rewrites](https://github.com/alansmodic/rewrites) (recommended)
- [WordPress AI plugin](https://github.com/WordPress/ai) (recommended, replaces Redline)

## Installation

1. Upload the `masthead` folder to `/wp-content/plugins/`
2. Activate **Masthead** in the WordPress admin
3. Go to **Masthead → Settings** to see the suite status
4. Install and activate the suite plugins you want

## Development

The dev environment uses [WordPress Studio](https://developer.wordpress.com/docs/developer-tools/studio/) with WordPress trunk.

```bash
# Site: smodiclaw-trunk
# URL: http://localhost:8883
# WP: 7.1-alpha

studio site list
studio wp --path ~/Studio/smodiclaw-trunk plugin list
```

Build assets (Masthead has no JS build step — assets are plain JS):
```bash
# WordPress AI plugin requires a build step:
cd ~/Studio/smodiclaw-trunk/wp-content/plugins/ai
npm ci && npm run build
```

## Project Board

Development tracked on [Fizzy](https://app.fizzy.do/6168043/boards/03g29ozgzuveeohe1bt4h0n7z).

## License

GPL-2.0-or-later
