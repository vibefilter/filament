# Changelog

All notable changes to `vibefilter/filament` will be documented in this file.

## 0.1.0 - 2026-09-29

- Requires PHP 8.2+, Laravel 12.36+ or 13, and Filament 5.9+.
- Natural-language table filter for Filament 5: keeps the rows a plain-English statement is true for.
- Decisions from TypeSafe Jev, sent in parallel batches with retries.
- OpenRouter driver: use Jev through OpenRouter with an OpenRouter key.
- Content-based decision cache, kept per service and model: equal texts are scored once, edited rows get a new score, and switching models never mixes scores.
- Filters only the rows left by the other filters and the search.
- Asks before scoring more unscored rows than the limit, with a "Run anyway" button.
- Live progress bar in the table while rows are scored, and a summary of each run: matches, rows scored, requests, time, and, with OpenRouter, the cost.
- Translatable interface, in English, Hungarian and Spanish.
