# Changelog

All notable changes to `belisoful/ganalytics` are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed
- `queueCall()` (and `trackEvent()`, `updateConsent()`, `setUserProperties()`, `gtag()`) drops a call with a notice while the module is inactive or has no tag, instead of deferring it to the session forever and opening a session for every visitor.
- `getContainerNoScriptHtml()` builds the `ns.html` URL from the `ContainerUrl` origin and directory, so a host-only URL, a trailing slash or a port no longer yield a broken frame source.
- `amendCspHeader()` drops a `'none'` token from a directive it adds sources to; `'none'` beside a source is an invalid policy.
- `GAnalyticsServiceAccountCredentials` shares one application-cache token whether the key is set by `KeyFile` or by `Key`: the cache key is the account's `client_email`.
- `ganalytics/status` reports an unresolvable `ConsentProvider` or `Credentials` in its own row instead of aborting the report.
- The string `'0'` is a value, not an empty one, for `UserId`, `ApiSecret`, `AccessToken`, `Key`, the endpoints and the other optional properties (`TPropertyValue::ensureNullIf()` with `FILTER_EMPTY`).
- The configuration examples resolve `KeyFile` against the application's base path (`protected`), so `KeyFile="ga4-key.json"` names `protected/ga4-key.json`.

## [1.0.0] - 2026-09-26

This release brings the extension to PRADO 4.4 and its current extension conventions, and lands the audit recorded in `agents/working/AUDIT_2026-09-26.md`. It changes the package requirements and some behavior; see [Upgrading from 0.0.1](#upgrading-from-001).

### Added
- `Enabled`: `false` leaves every page without the tag.
- `DebugMode` (`debug_mode: true`) and `SendPageView` (`send_page_view: false`).
- `ConfigOptions`: further `gtag('config')` parameters, as an array or a JSON object string.
- `ConsentDefaults`: Consent Mode defaults, emitted as `gtag('consent', 'default', …)` before the configuration.
- `TagUrl`: the script host, for first-party or server-side tagging.
- `AdditionalMeasurementIds` (further `gtag('config')` tags), `EnabledModes` (the `TApplicationMode`s the tag runs in), `PagePathAsContentGroup` (the page path as GA4 `content_group`), `DataLayerName`.
- `UserId`, and `UserIdFromUser`: the GA4 `user_id` as an HMAC of the authenticated PRADO user's name under the security manager's validation key.
- Events from page code: `trackEvent()`, `updateConsent()`, `setUserProperties()`, `gtag()` and `queueCall()` queue `gtag()` calls delivered at `TPage::onPreRenderComplete`, as a script block on a full page or through the callback client on an ActiveControl callback; deferred calls survive a redirect in the session.
- `GAnalyticsPageBehavior`, a class behavior the module attaches to `TPage` (`AttachPageBehavior`), so pages call `$this->trackEvent(…)` and `$this->getGAnalytics()`.
- `GAnalyticsMeasurementProtocol` and `sendEvent()`: events from PHP over the Measurement Protocol (`ApiSecret`), under the visitor's `_ga` client id (`getClientId()`), timestamped from PRADO's clock; `DebugMode` uses the validation endpoint.
- `AmendCsp`: Google's hosts are added to every `THttpHeaderCsp` of the application's `THttpHeadersManager` modules (`amendCspPolicies()`, `amendCspHeader()`, `getCspSources()`).
- Google Tag Manager: `ContainerId`, `ContainerUrl`, `ContainerNoScript`; the loader in the snippet, the `<noscript>` frame at the top of the form, and `dataLayer.push({event: …})` delivery for a container without a Measurement ID (`getContainerScript()`, `getContainerNoScriptHtml()`, `isDataLayerCall()`).
- PRADO event hooks: `TrackExceptions` (`TApplication::onError` → `exception` over the Measurement Protocol), `TrackLogins` (`TAuthManager` `onLogin`/`onLoginFailed`/`onLogout` → `login`, `login_failed`, `logout`, with `LoginMethod`), `TrackValidationErrors` (failed validators on a postback → `form_error`); `getAuthManagers()`, `errorHandler()`, `getExceptionParams()`, `trackValidationErrors()`.
- Consent seam: `IGAnalyticsConsentProvider`, `IGAnalyticsConsentStore`, `ConsentProvider` (a module id, an instance, or a `<consent>` element), `getEffectiveConsentDefaults()`; `updateConsent()` records the choice through a store. `GAnalyticsCookieConsentProvider`, a cookie-backed store module (`CookieName`, `Expires`).
- Data API: `GAnalyticsDataApi` (`runReport`, `runRealtimeReport`, `batchRunReports`, `runPivotReport`, `batchRunPivotReports`, `getMetadata`, `checkCompatibility`, `call`, `reportRequest()`, `realtimeRequest()`), `GAnalyticsReport` (typed rows for data binding, totals, iteration), `GAnalyticsAdminApi` (account summaries, properties, data streams, Measurement Protocol secrets), `GAnalyticsApiClient` (the JSON client base with `request()` and `requestAll()`), `GAnalyticsApiException`.
- Credentials seam: `IGAnalyticsCredentials`, `GAnalyticsServiceAccountCredentials` (service account JWT, RS256, token reuse and application-cache sharing, `KeyFile`/`Key`/`Scopes`/`Timeout`), `GAnalyticsAccessTokenCredentials`. On the module: `PropertyId`, `Credentials` (a module id, an instance, or a `<credentials>` element), `runReport()`, `runRealtimeReport()`, `getDataApi()`, `getAdminApi()`, `getCredentials()`.
- Realtime polling: `RealtimeMetrics`, `RealtimeDimensions`, `pollRealtime()` and the `onRealtimeReport` event, for a `TCronModule` job that publishes live figures.
- `GAnalyticsShellAction`: `prado-cli ganalytics/status`, `send`, `validate`, `report`, `realtime` and `properties`, registered by the module in a `TShellApplication` (`ShellClass`, `registerShellAction()`).
- `GAnalyticsHttpTransportTrait`: the one HTTP transport seam of the extension, which the Measurement Protocol client uses too; tested against a local PHP web server.
- `composer coverage-paths` and `composer coverage-branches` (`tests/test_tools/coverage-branches.php`): path coverage with the unexecuted branches listed; 100% of lines and branches is the coverage target, and native functions are written fully qualified so PHP 8.4's frameless-call fallback adds no phantom branch.
- End-to-end tests: two PRADO applications under `tests/playwright` served by PHP's built-in server, Playwright specs for the tag, the CSP header and nonce, callbacks, deferred events, validation tracking and Tag Manager; a `live` phpunit suite against a real property gated on `GA4_*` secrets (`composer livetest`); CI jobs for coverage, Playwright (Chromium, Firefox, WebKit) and the live suite.
- `onPreRegisterScript`: raised with a `TEventParameter` carrying the page; stopping it leaves the page without the tag.
- `registerPageScripts()`, `registerTag()`, `getIsActive()`, `getUsesGtag()`, `getHasTag()`, `getTagScriptUrl()`, `getTagScript()`, `getEffectiveConfigOptions()` and `getEffectiveUserId()` as public API.
- A page without a `THead` receives the tag at the beginning of its form.
- A module loaded after the application initialized (a lazy module) hooks the running page service at once.
- `config/classMap.json` (the Prado3 short names) and `config/errorMessages.txt` (`ganalytics_*` error codes), both registered by Composer from `extra.prado`.
- Unit tests (`tests/unit`, namespace `belisoful\GAnalytics\Test\Unit`, 192 tests), phpstan (level 3), php-cs-fixer, and CI on PHP 8.1 to 8.5 against PRADO `master`.
- `CHANGELOG.md`, `AGENTS.md`, `CLAUDE.md`.

### Changed
- Requires PHP 8.1+ and PRADO `^4.4@dev`. The bootstrap class is declared under `extra.prado.bootstrap`.
- The Measurement ID is validated (`G-`, `AW-`, `DC-`, `GT-`, `UA-` forms); an invalid module property is refused when set, and an invalid application parameter when the page runs.
- The tag is armed at `TPageService::onPreRunPage` and registered at `TPage::onPreRenderComplete`, when the page knows whether it has a `THead`; a page without one gets the scripts at the beginning of its form (PRADO refuses a head registration on such a page), and a callback request gets no tag.
- Deferred calls open the session for a write, and for a read only when the request carries a session cookie.
- `composer.json` suggests `ext-openssl`, `belisoful/prado-webhooks` and `belisoful/prado-websocket`.
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
