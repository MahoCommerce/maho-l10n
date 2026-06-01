# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Maho L10N is the central localization **monorepo** for Maho Commerce. It is the source of record for translation strings (CSV) and email templates (HTML) across every locale configured in Crowdin. `en_US` is the source locale; every other locale lives in its own committed folder here.

Translations are produced via **Crowdin autotranslate** (https://translate.mahocommerce.com). Crowdin's GitHub integration is **disabled** (no auto-PRs); the repo drives Crowdin via its CLI in two workflows: a daily job pushes `en_US` sources up, and the manual publish job pulls translations back and commits them to `main`. The individual `maho-language-<locale>` repos on Packagist are **generated artifacts** — published from this monorepo on demand and never edited by hand.

Requires two repo secrets for the Crowdin CLI: `CROWDIN_PROJECT_ID` and `CROWDIN_PERSONAL_TOKEN`.

## Workflow

```
en_US sources ──upload──► Crowdin (autotranslate) ──download──► this monorepo (en_US/, de_DE/, …) ──build──► maho-language-<locale> repos ──► Packagist
```

Two workflows split the two directions:
- **Upload Sources to Crowdin** (`upload-sources-to-crowdin.yml`) — daily cron (05:00 UTC) + manual. Pushes `en_US` to Crowdin and triggers MT pre-translation across all languages. One-way; commits nothing here.
- **Publish Language Packs** (`publish-language-packs.yml`) — manual (`workflow_dispatch`) with a locale list or `all`. Downloads translations, commits them to `main`, then rebuilds and tags each satellite.

## Architecture

- **`en_US/`** — Source locale with 61 CSV files and email templates under `template/email/`
- **`<locale>/`** — Target locale directories mirroring `en_US`, populated by Crowdin (committed here permanently)
- **`crowdin.yml`** — Crowdin source→translation path mapping; reads credentials from `CROWDIN_PROJECT_ID` / `CROWDIN_PERSONAL_TOKEN` env vars
- **`.github/workflows/upload-sources-to-crowdin.yml`** — Daily one-way push of `en_US` sources to Crowdin + MT pre-translate. Pre-translate runs only if the repo variable `CROWDIN_MT_ENGINE_ID` is set (the project's machine-translation engine id)
- **`.build/templates/`** — Files used to generate each satellite repo: `composer.json.tpl`, `README.md.tpl`, `.gitignore`. LICENSE files are reused from the repo root. The locale list and language names are read live from Crowdin's target languages (no hardcoded list); a locale publishes when its `maho-language-<locale>` repo exists.
- **`.github/workflows/publish-language-packs.yml`** — Manual workflow that, per locale, does an **authoritative rebuild** of the satellite repo (wipes everything except `.git`, lays down only the generated set, so files the build no longer produces are removed), renders metadata from `.build/templates/`, commits, and tags a version (format: `YY.M.D`). Never delete or recreate a satellite repo — that would drop its version tags and break Packagist/installed `composer require`s; the workflow reconciles them in place.

Each generated package is named `mahocommerce/maho-language-<locale lowercased>`, type `maho-module`, requiring `mahocommerce/maho:*`.

### How this interacts with `maho-infrastructure`

Shared org files flow **infra → l10n → language packs**, so there is a single distribution point and no file is written by two systems:

- **`maho-infrastructure`'s `sync.php`** syncs org-wide files (`.github/FUNDING.yml`, `.github/dependabot.yml`) into **this repo only**. Its `language-packs` group opts the `maho-language-*` repos **out** of file sync, while still reconciling their settings/security and forcing them read-only (`has_issues`/`has_wiki`/`has_projects` = false).
- **This publish workflow** owns everything in the satellites: `app/locale/<locale>/…`, `composer.json`, `README.md`, `LICENSE*.txt`, `.gitignore`, the version tags — and **fans out l10n's own `.github/FUNDING.yml`** to each pack (it copies the file infra placed here; it does not keep a second copy).

So the only edit needed when funding/sponsor info changes is in `maho-infrastructure`; it lands in l10n, and the next `publish` run propagates it to every pack.

## CSV Format

Files use quoted comma-separated format: `"source_phrase","translation"`, preserving leading/trailing whitespace and original casing. Terms like "openmage"/"magento" are replaced with "Maho". Some terms are kept untranslated: CMS, newsletter, URL rewrites, layered navigation.

## QA Checks

After translations are updated, verify these strings render correctly: `CMS`, `(Shift-)Click or drag`, `ID`.
