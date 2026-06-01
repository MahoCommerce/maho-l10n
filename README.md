# Maho localization monorepo

Central source of record for all Maho Commerce localizations. `en_US` is the source locale; every other locale lives in its own folder here and is produced via [Crowdin autotranslate](https://translate.mahocommerce.com).

The individual `maho-language-<locale>` packages on Packagist are **auto-generated** from this repository — do **not** edit those repos by hand. They are (re)published on demand via the **Publish Language Packs** GitHub Action (`workflow_dispatch`).

## Publishing a language pack

Run the **Publish Language Packs** workflow from the Actions tab. Provide a space-separated locale list (e.g. `de_DE fr_FR`) or `all` (default). For each locale it rebuilds the satellite repo from this monorepo, renders metadata from `.build/templates/`, commits, and tags `YY.M.D`.

## Strings to check after generating a language pack

```
(\\t for tab)
"CMS"
"(Shift-)Click or drag
"ID"
```
