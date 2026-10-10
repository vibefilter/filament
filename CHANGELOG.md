# Changelog

All notable changes to `vibefilter/filament` will be documented in this file.

## 0.2.1 - 2026-10-10

- The OpenRouter driver sends OpenRouter's app attribution headers (`HTTP-Referer`, `X-OpenRouter-Title`), so usage is credited to Vibefilter. They can be changed, set to hidden, or switched off in the config.
- The package description matches the repository's.

## 0.2.0 - 2026-10-02

- Optional `VibeScoreColumn`: shows each row's probability for the active statement, from the run the filter already did.
- Enter in the statement field applies the filter and closes the filter panel.
- Spanish translation, thanks to [@juandsep](https://github.com/juandsep).
- The product is spelled "Vibefilter" everywhere.
- "Retried" is singular or plural by the number of retries, in every language.

## 0.1.0 - 2026-09-29

- Requires PHP 8.2+, Laravel 12.36+ or 13, and Filament 5.9+.
- Natural-language table filter for Filament 5: keeps the rows a plain-English statement is true for.
- Decisions from TypeSafe Jev, sent in parallel batches with retries.
- OpenRouter driver: use Jev through OpenRouter with an OpenRouter key.
- Content-based decision cache, kept per service and model: equal texts are scored once, edited rows get a new score, and switching models never mixes scores.
- Filters only the rows left by the other filters and the search.
- Asks before scoring more unscored rows than the limit, with a "Run anyway" button.
- Live progress bar in the table while rows are scored, and a summary of each run: matches, rows scored, requests, time, and, with OpenRouter, the cost.
- Translatable interface, in English and Hungarian.
