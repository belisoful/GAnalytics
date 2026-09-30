# GAnalytics

A PRADO 4 extension for Google Analytics 4: `GAnalyticsModule` (a `TPluginModule`) puts the tag on every page (gtag.js and/or a Tag Manager container), queues `gtag()` calls for pages and callbacks, hooks PRADO's error, login and validation events, binds a consent provider, derives the `user_id`, amends the CSP, fronts the Measurement Protocol and the Data/Admin APIs, polls realtime for cron, and registers `prado-cli ganalytics/*`. `GAnalyticsPageBehavior` (a `TClassBehavior` on `TPage`) exposes the calls on pages; `GAnalyticsMeasurementProtocol`, `GAnalyticsDataApi`, `GAnalyticsAdminApi` and `GAnalyticsServiceAccountCredentials` talk to Google through the one transport seam `GAnalyticsHttpTransportTrait::transport()`.

## Version

- **Current version: v0.1.0; the work on `main` is the next release, 0.2.0** (unreleased). Targets PRADO 4.4 (`pradosoft/prado` `master`, aliased `4.4.x-dev`). Release notes live in [CHANGELOG.md](CHANGELOG.md) under `[Unreleased]`.
- Symbols released through 0.2.0 carry **no `@since` tags**. A public symbol added after 0.2.0 gets `@since` with the version it ships in.
- Record every user-visible change under an `## [Unreleased]` heading in `CHANGELOG.md` as it lands.
- Supported and CI-tested PHP: **8.1, 8.2, 8.3, 8.4, 8.5**.

## Key facts

- Seventeen classes, interfaces and traits under `src/` (PSR-4 `belisoful\GAnalytics\` → `src/`), one file each. Prado3 short names come from `config/classMap.json`; the bootstrap class, the class map and the error messages are registered by **Composer** from `composer.json` `extra.prado`. Inside this repository the package is the root project, so an application here configures the module by `class`, not by package name.
- Error codes (keys) and messages live in `config/errorMessages.txt` (`ganalytics_*`).
- Unit tests are namespaced `belisoful\GAnalytics\Test\Unit` mirroring `tests/unit/` (Composer `autoload-dev`); the phpunit bootstrap constructs a `TApplication` on `tests/unit/app`.
- The module hooks the application once (`attachPageServiceHandler()`, from `TApplication::onInitComplete` or at once when the application is already initialized): `TPageService::onPreRunPage`, the page behavior, the CSP, `onError`, the `TAuthManager` events and the shell action. A page is armed at `onPreRunPage` and everything happens at `TPage::onPreRenderComplete`: the tag is registered on the `TClientScriptManager` under the key `gtag` (head with a `THead`, form without one; PRADO throws on a head registration without a `THead`), validation errors are tracked, and queued calls are delivered (end script, or the callback client on a callback; the session holds deferred calls under `SESSION_KEY`, opened for a write and, for a read, only with a session cookie).
- Every value written into the page goes through `TJavaScript::encode()` / `rawurlencode()`. Time is read through the clock seam (`TApplicationClockAwareTrait`).
- Tests use the fixtures `ProbeGAnalyticsModule` (in-memory deferred store, recording protocol and API clients), `RecordingTransportTrait` and its `Recording*` clients, `FakeUser`, `FakeSession`, `FakeCredentialsModule`, `FakeConsentModule`, `RecordingResponse`, `TransportProbe` and `LocalHttpServer`; `tearDown()` detaches the class behavior from `TPage`, restores the service, mode, user and the application hooks. No test reaches the network (the transport test talks to a PHP server on the loopback), a PHP session or randomness.
- End-to-end: `tests/playwright/app` (gtag.js, CSP with a fixed `ValidationKey`, consent cookie, validation tracking) `app-gtm` (container only) and `app-basic` (basic consent mode under a nonce CSP), served by `php -S` from the Playwright config; specs stub Google's hosts and read `window.dataLayer`. Live tests in `tests/live` skip without `GA4_*` variables.
- The public API is settling for 0.2.0. Prefer compatible changes. A breaking change needs an entry under "Upgrading" in `CHANGELOG.md`.

## Checks (all must pass before commit)

```sh
php -l <file>                                        # syntax
composer fix        # php-cs-fixer on src and tests (tabs); or: vendor/bin/php-cs-fixer fix src  (and: fix tests)
composer stan       # vendor/bin/phpstan analyse --memory-limit=1G  (level 3, PHP 8.1 – 8.5)
composer unittest   # vendor/bin/phpunit --testsuite unit
npx playwright test --project=chromium   # end-to-end (PW_CHROMIUM=<binary> when Playwright's Chromium is not installed)
```

`composer fulltest` runs the last three PHP checks in order. `composer coverage` / `composer coverage-html` measure coverage (Xdebug). The target is 100% of lines and branches: `composer coverage-branches -- --filter <TestClass> --coverage-filter src/<Class>.php` lists the unexecuted branches of one class in seconds (the unfiltered run takes about an hour). Native functions are written fully qualified (`\trim()`), because an unqualified call compiles to a never-taken fallback branch on PHP 8.4. `composer livetest` runs the live suite against Google when the `GA4_*` variables are set.

See [AGENTS.md](AGENTS.md) for the full coding standards, framework conventions, and safeguards.
