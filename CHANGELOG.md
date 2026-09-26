# Changelog

All notable changes to `belisoful/ganalytics` are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.0] - 2026-09-26

This release brings the extension to PRADO 4.4 and its current extension conventions, and lands the audit recorded in `agents/working/AUDIT_2026-09-26.md`. It changes the package requirements and some behavior; see [Upgrading from 0.0.1](#upgrading-from-001).

### Added
- `Enabled`: `false` leaves every page without the tag.
- `DebugMode` (`debug_mode: true`) and `SendPageView` (`send_page_view: false`).
- `ConfigOptions`: further `gtag('config')` parameters, as an array or a JSON object string.
- `ConsentDefaults`: Consent Mode defaults, emitted as `gtag('consent', 'default', …)` before the configuration.
- `TagUrl`: the script host, for first-party or server-side tagging.
- `onPreRegisterScript`: raised with a `TEventParameter` carrying the page; stopping it leaves the page without the tag.
- `registerPageScripts()`, `getTagScriptUrl()`, `getTagScript()` and `getEffectiveConfigOptions()` as public API.
- A page without a `THead` receives the tag at the beginning of its form.
- A module loaded after the application initialized (a lazy module) hooks the running page service at once.
- `config/classMap.json` (the Prado3 short name `GAnalyticsModule`) and `config/errorMessages.txt` (`ganalytics_*` error codes), both registered by Composer from `extra.prado`.
- Unit tests (`tests/unit`, namespace `belisoful\GAnalytics\Test\Unit`), phpstan (level 3), php-cs-fixer, and CI on PHP 8.1 to 8.5 against PRADO `master`.
- `CHANGELOG.md`, `AGENTS.md`, `CLAUDE.md`.

### Changed
- Requires PHP 8.1+ and PRADO `^4.4@dev`. The bootstrap class is declared under `extra.prado.bootstrap`.
- The Measurement ID is validated (`G-`, `AW-`, `DC-`, `GT-`, `UA-` forms); an invalid module property is refused when set, and an invalid application parameter when the page runs.
- The application parameter is read on every page rather than cached into the module on first use.
- The id is URL-encoded in the script URL and JavaScript-encoded in the script block.
- Properties are typed and coerced with `TPropertyValue`; the docblocks, `@link` and `@license` headers follow the current PRADO conventions.
- `composer.json` no longer carries `version`, `version_normalized` or `notification-url`; the README describes installation, configuration and the properties.

### Fixed
- A page ran with `gtag('config', '')` and a `gtag/js?id=` script when no Measurement ID was configured; it now runs without the tag and a notice is logged.
- The Measurement ID read from the application parameter was cached into the module on first use, so a later parameter change, or clearing the property, had no effect; `getMeasurementId()` is now side-effect free.
- The class docblock configured the module by a nonexistent id (`belisoful/GAnalyticsModule`); the examples show the package name and the class.

### Upgrading from 0.0.1
- **PRADO 4.4 and PHP 8.1** are required.
- **Configure by package name.** `<module id="belisoful/ganalytics" MeasurementId="…"/>` replaces the old example `<module id="belisoful/GAnalyticsModule" …/>`; a `class` attribute may still name `belisoful\GAnalytics\GAnalyticsModule` or `GAnalyticsModule`.
- **Invalid Measurement IDs throw** `TInvalidDataValueException` (`ganalytics_measurementid_invalid`) instead of being written into the page.
- **Renamed handlers.** `attachPageServicePageHandlers()` is `attachPageServiceHandler()`; `initPageHandler()` is `preRunPageHandler()`, and the registration itself is `registerPageScripts(TPage $page): bool`.
- **Callback requests** no longer receive head registrations that were never rendered; behavior on the page is unchanged.

## [0.0.1] - 2022-11-30

### Added
- Initial release: `GAnalyticsModule`, a `TPluginModule` that registers the gtag.js script and configuration in every page head, with the Measurement ID from the module or the `GoogleAnalyticsMeasurementId` application parameter.

[1.0.0]: https://github.com/belisoful/GAnalytics/compare/v0.0.1...v1.0.0
[0.0.1]: https://github.com/belisoful/GAnalytics/releases/tag/v0.0.1
