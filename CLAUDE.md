# GAnalytics

A PRADO 4 extension for Google Analytics 4: `GAnalyticsModule` (a `TPluginModule`) adds the Google tag (gtag.js) to every page, queues `gtag()` calls for pages and callbacks, derives the `user_id`, amends the CSP and fronts the Measurement Protocol; `GAnalyticsPageBehavior` (a `TClassBehavior` on `TPage`) exposes the calls on pages; `GAnalyticsMeasurementProtocol` sends events from PHP.

## Version

- **Current version: v1.0.0** (released 2026-09-26; v0.0.1 before it). Targets PRADO 4.4 (`pradosoft/prado` `master`, aliased `4.4.x-dev`). Release notes live in [CHANGELOG.md](CHANGELOG.md).
- Symbols released through v1.0.0 carry **no `@since` tags**. A public symbol added after v1.0.0 gets `@since` with the version it ships in.
- Record every user-visible change under an `## [Unreleased]` heading in `CHANGELOG.md` as it lands.
- Supported and CI-tested PHP: **8.1, 8.2, 8.3, 8.4, 8.5**.

## Key facts

- Three classes under `src/` (PSR-4 `belisoful\GAnalytics\` → `src/`). Prado3 short names come from `config/classMap.json`; the bootstrap class, the class map and the error messages are registered by **Composer** from `composer.json` `extra.prado`.
- Error codes (keys) and messages live in `config/errorMessages.txt` (`ganalytics_*`).
- Unit tests are namespaced `belisoful\GAnalytics\Test\Unit` mirroring `tests/unit/` (Composer `autoload-dev`); the phpunit bootstrap constructs a `TApplication` on `tests/unit/app`.
- The module hooks `TPageService::onPreRunPage` (from `TApplication::onInitComplete`, or at once when the application is already initialized), attaches the page behavior and amends the CSP there, and registers the tag on the page's `TClientScriptManager` under the key `gtag`. Queued calls are delivered at `TPage::onPreRenderComplete` (end script, or the callback client on a callback; the session holds deferred calls under `SESSION_KEY`).
- Every value written into the page goes through `TJavaScript::encode()` / `rawurlencode()`. Time is read through the clock seam (`TApplicationClockAwareTrait`).
- Tests use the fixtures `ProbeGAnalyticsModule` (in-memory deferred store, recording protocol), `RecordingMeasurementProtocol` and `FakeUser`; `tearDown()` detaches the class behavior from `TPage`, restores the service, mode and user.
- The public API is published (v1.0.0 onward). Prefer compatible changes. A breaking change needs an entry under "Upgrading" in `CHANGELOG.md`.

## Checks (all must pass before commit)

```sh
php -l <file>                                        # syntax
composer fix        # php-cs-fixer on src and tests (tabs); or: vendor/bin/php-cs-fixer fix src  (and: fix tests)
composer stan       # vendor/bin/phpstan analyse --memory-limit=1G  (level 3, PHP 8.1 – 8.5)
composer unittest   # vendor/bin/phpunit --testsuite unit
```

`composer fulltest` runs the last three in order. `composer coverage` / `composer coverage-html` measure coverage (Xdebug).

See [AGENTS.md](AGENTS.md) for the full coding standards, framework conventions, and safeguards.
