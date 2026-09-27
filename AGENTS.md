# PRADO Google Analytics Extension Agent Guidelines

## Build, Lint, and Test Commands

### Running Tests
- **All Unit Tests**: `vendor/bin/phpunit --testsuite unit` (or `composer unittest`) - runs all unit tests
- **Test Filter**: `vendor/bin/phpunit --testsuite unit --filter <test function, class, or directory>`
- **Coverage**: `composer coverage` (text) / `composer coverage-html` (HTML in `build/coverage`); phpunit.xml declares `src/` as the coverage source and the scripts set `XDEBUG_MODE`. Narrow a run with `--filter` and `--coverage-filter`.
- **Path (branch) coverage, filtered**: `composer coverage-paths` runs the unit suite with `--path-coverage`, the same branch list CI's `coverage` job reports. Over the whole suite Xdebug's path coverage takes very long (the order of an hour); over one class and its own test class it takes seconds and reproduces CI's branch list for that class exactly. Pass the filters after `--`:
  `composer coverage-paths -- --filter GAnalyticsModuleTest --coverage-filter src/GAnalyticsModule.php`
  Use this to find the uncovered branch CI names before adding the test for it; run the full script only when closing out.
- **Live tests**: `composer livetest` (`vendor/bin/phpunit --testsuite live`) talks to a real GA4 property; every test skips without `GA4_MEASUREMENT_ID`, `GA4_API_SECRET`, `GA4_PROPERTY_ID` and `GA4_SERVICE_ACCOUNT_JSON`. CI supplies them as repository secrets on pushes.
- **Playwright end-to-end tests**: `npx playwright test --project=chromium` (all browsers: `npx playwright test`); the Playwright config starts `php -S 127.0.0.1:8380 -t tests/playwright`, which serves the `app/` and `app-gtm/` PRADO applications. `PW_CHROMIUM=<path>` uses another Chromium binary. Reports land in `build/playwright-report`.

### Linting and Code Analysis
- **PHPStan Analysis**: `vendor/bin/phpstan analyse --memory-limit=1G` (or `composer stan`); level 3, `phpVersion` range 8.1 – 8.5
- **PHP CS Fixer (Dry-run)**: `vendor/bin/php-cs-fixer fix --dry-run src/` and `vendor/bin/php-cs-fixer fix --dry-run tests/` (check)
- **PHP CS Fixer (Fix)**: `vendor/bin/php-cs-fixer fix src/` and `vendor/bin/php-cs-fixer fix tests/` (or `composer fix`); the finder excludes `tests/`, so `src` and `tests` run as two invocations

### Build Commands
- **Install Dependencies**: `composer install` - installs all dependencies
- **Updating Dependencies**: `composer update` - updates all dependencies
- **Full Check**: `composer fulltest` runs fix, stan, and unittest in order

## Code Style Guidelines
- "if" has a statement block after
- Use php-cs-fixer to correct code styles

### PHP Coding Standards
- Follow PSR-4 autoloading standard
- All PHP files must begin with `<?php` tag (short open tags not allowed)
- Use 1 tab for indentations (no spaces)
- All class properties must be declared with visibility modifiers (public, protected, private)
- Uniform Access Principle - Self Encapsulation is required; for an example see `framework/TApplication.php` in PRADO
- Extract Method → Predicate/Guard Clause (Fowler) is suggested
- Code must run without deprecation notices on PHP 8.1 through 8.5: no implicitly nullable parameters (write `?Type $x = null`), no non-canonical casts (`(boolean)`, `(integer)`, `(double)`), no `E_STRICT`

### Naming Conventions
- Class names: `PascalCase` with the `GAnalytics` prefix (`GAnalyticsModule`, `GAnalyticsPageBehavior`, `GAnalyticsMeasurementProtocol`); an interface takes the `I` prefix
- Method names: `camelCase` (eg. `registerPageScripts`)
- Variables: `camelCase` (eg. `$measurementId`)
- Class Constants: `SCREAMING_SNAKE_CASE` (eg. `MEASUREMENT_ID_PARAMETER`)
- Class properties: `_camelCase` (eg. `_measurementId`, `_configOptions`)
- Property accessors: `getName()`/`setName($value)`; setters take `mixed` and coerce with `TPropertyValue` so XML string values work
- Namespace: `belisoful\GAnalytics` (PSR-4 → `src/`)
- Unit test namespace: `belisoful\GAnalytics\Test\Unit` mirroring `tests/unit/` (eg. `belisoful\GAnalytics\Test\Unit\GAnalyticsModuleTest`)
- Template file extension: ".tpl"
- Web Page template file extension: ".page"

### Documentation Standards
- All public methods must have PHPDoc comments with:
  - `@param` for parameters
  - `@return` for return values
  - `@throws` for exceptions
- Classes must have a clear and comprehensive docblock at the top with class description with:
  - Examples, where necessary
  - `@author` for attribution
  - `@method` for dynamic events with prefix 'dy-'; which are called (on "$this->dy-") but not defined.
- Inline comments should be in English and start with `//`
- Use `?` for single nullable types and in doc blocks
- `@since`: symbols released through v1.0.0 carry none; a public symbol added after v1.0.0 gets `@since` with the version it ships in.
- Method Doc Blocks must be **tight**, and have at minimum one sentence in the description.
- Documentation additions/changes/removals should be integrated into the whole, at each level (of detail).

### Documentation Style (enforced)

Docblocks are technical documentation written with direct technical statements.
Language and National Variety: English - American
Qualities of the writing: clear, thorough, easy to comprehend, not verbose (brevity), timeless/always was, integrated, wholistic
Tense: Present - tune for ease of comprehension
_Banned constructions_:
- **Antithesis / "not merely X — it Ys"**: no "does not just X, it Ys", "is not a Y, it's a Z",
  "rather than X, it Ys". State what it does, once.
- **Em-dash dramatic asides** used for emphasis or reveal ("— and that's the point", "— never stronger").
  Use a period or plain clause.
- **Editorializing / filler** is unnecessary.
- **Rule-of-three rhetorical lists** and build-up sentences. One fact per sentence.

Prefer: subject–verb–object declaratives, tables and bullet lists of `condition → result` where appropriate.
Docblocks inform and describe; it is not persuasive writing.

### Error Handling
- Throw appropriate PRADO exceptions (`TInvalidDataValueException` for a refused property value, `TConfigurationException` for a configuration that cannot work, `TInvalidOperationException` for a call out of sequence)
- Return false or null for methods that are designed to fail gracefully (`registerPageScripts()` returns false when the page runs without the tag; `getMeasurementId()` returns null when none resolves; `sendEvent()` returns false and logs when Google refuses)
- All methods should handle edge cases and validate input parameters
- Extension Exceptions use error codes (keys) defined in `config/errorMessages.txt`; the message text is purely for user information display only. Every code thrown must exist in that file, and the file should carry no unused codes. A Google API refusal is a `GAnalyticsApiException` (`ganalytics_api_error`) carrying the status and Google's message.
- Conditions that are not errors (no Measurement ID configured) are logged through `Prado::log()` with `\Prado\Util\Log\TLogger` levels, under the module's class as category. An event handler on a framework event (`errorHandler()`) never throws; it logs.
- Every Google request goes through `GAnalyticsHttpTransportTrait::transport()` (a `file_get_contents()` over an `http` stream context); tests override it with `RecordingTransportTrait`. No other HTTP client is used.

### Imports and Includes
- Use PSR-4 autoloading - no manual includes required
- Unit test classes autoload through the Composer `autoload-dev` PSR-4 mapping (`belisoful\GAnalytics\Test\Unit\` → `tests/unit/`); tests do not `require` class files
- All framework classes are accessed via namespace prefixes
- Third-party libraries are loaded via Composer
- Use proper `use` statements for namespaces at the top of PHP files

### Framework Specific Guidelines
- All components inherit from `TComponent` base class
- `TComponent` has features for dynamic event and extension by attached Behaviors (__call, __callStatic), dynamic properties (__get, __set, __isset, __unset), __clone, __sleep, __wakeup, and _getZappableSleepProps
- Behaviors can be attached to any `TComponent` to alter its behavior and functionality.
- Use the event-driven programming model with events; like `onLoad`, `onInit`, `onPreRender`
- Methods with prefix 'dy' are dynamic events to call attached and active Behaviors; like 'dyShouldContinue', 'dyClone', and 'dyValidate'
- Behaviors and Events are called in Collection Priority order
- Called Dynamic Events must be documented in the class phpdoc with "@method"
- Dynamic event are implemented by attached behaviors not in the calling class
- The first parameter of a dynamic event is always filtered and returned.
- Optional class methods can directly be called on non-behavior classes as "dynamic events"
- Methods with prefix 'fx' are global events that may or may not be automatically registered depending on getAutoGlobalListen(); like 'fxAttachClassBehavior'
- getAutoGlobalListen() is optimized by class hierarchy for utility and performance
- Follow the TApplication Lifecycle: onConfiguration → onInitComplete (at end of TApplication::initApplication) → onBeginRequest → onLoadState → onLoadStateComplete → onAuthentication → onAuthenticationComplete → onAuthorization → onAuthorizationComplete → onPreRunService → runService → onSaveState → onSaveStateComplete → onPreFlushOutput → flushOutput → onEndRequest or onError (both at end of TApplication::run)
- Follow the TPage Lifecycle (via TPageService::runPage): onPreInit → initRecursive → onInitComplete → loadPageState (POST/Callback) → processPostData (POST/Callback) → onPreLoad → loadRecursive → processPostData (POST/Callback) → raiseChangedEvents (POST/Callback) → raisePostBackEvent (POST-only) → processCallbackEvent (Callback-only) → onLoadComplete → preRenderRecursive  onPreRenderComplete → savePageState → onSaveStateComplete → renderControl (GET/POST) → renderCallbackResponse (Callback-only) → unloadRecursive
- XML and PHP is supported for application configuration
- TPageService::onPreRunPage gives PRADO Modules event access to the TPage Lifecycle before it runs; this module arms the tag there and does the work at `TPage::onPreRenderComplete`, when `TPage::getHead()` and `getIsCallback()` are known: the tag is registered in the head (with a `THead`) or the form (without one; `TPage::onPreRenderComplete` throws `page_head_required` when head scripts are registered on a page without a `THead`), a full page gets an end script with the calls, a callback gets `TCallbackClientScript::callClientFunction('gtag', …)` (the client resolves the global `gtag` function by name) or `evaluateScript()` for a container's data layer push.
- Deferred calls live in the application session; `THttpSession::AutoStart` defaults to false, so the module opens the session for a write and, for a read, only when the request carries the session cookie (`THttpSession::getSessionName()`).
- `THttpHeaderCsp::init()` reads `TSecurityManager::getCSPNonce()` while the modules initialize, before `onLoadState` loads the application state that holds a generated validation key; an application with a CSP nonce and no configured `ValidationKey` generates a new key per request and every postback fails with "Page state is corrupted". The end-to-end application sets a fixed `ValidationKey`; the README tells users to.
- An application in this repository configures the module by `class`: the package is the root project and Composer's `installed.json` does not list it, so the `belisoful/ganalytics` module id resolves only in an application that installed the package.
- `TPage::getCallbackClient()` returns the adapter's client on a callback and a throwaway object otherwise; only the callback path uses it.
- Class behaviors (`TComponent::attachClassBehavior(name, behavior, TPage::class)`) inject the owner as the first method argument (`IClassBehavior`); `GAnalyticsPageBehavior` methods take `$page` first. A name can be attached once per class; tests detach it in `tearDown()`.
- `THttpHeadersManager` modules carry `THttpHeaderCsp` headers whose directives are read with `getPolicy()` and replaced with `setPolicy()` (`addPolicy()` is an alias, not an append); `TJavaScript` emits the CSP nonce on every script tag it renders.
- The `user_id` derivation uses `TSecurityManager::getValidationKey()` (HMAC-SHA256 of the `IUser` name); `computeHMAC()` is protected.
- The Measurement Protocol client and the service account credentials read `TApplicationClockAwareTrait::getClock()` for `timestamp_micros`, generated client ids and the JWT `iat`/`exp`; tests set a `TMockClock`.
- `TAuthManager` raises `onLogin($user)`, `onLoginFailed($username)` and `onLogout($user)`; the module finds every auth manager with `getModulesByType()` and loads lazy ones.
- `TApplication::onError($param)` carries the throwable; the error handler runs after it, so the page will not render and the `exception` event goes over the Measurement Protocol.
- `TShellApplication::addShellActionClass(['class' => …, 'Module' => $this])` creates the action with `Prado::createComponent()` and sets the `Module` property; the module registers it at hook time, before `processArguments()` installs the built-in actions.
- `TCronModule` runs `task="belisoful/ganalytics->pollRealtime"` (`TCronMethodTask`); the Data API has no push channel.
- Modules configured in the application initialize before `onInitComplete`; a lazily loaded module initializes later, when `TApplication::hasStateFlag(TApplication::STATE_INITIALIZED)` is already true. `init()` handles both.
- Framework core updates 'framework/classes.php' with new classes; this does NOT apply to this extension (see the PSR-4 / class-map note below).
- Web Pages are PHP classes with a ".page" TTemplate file with the same base name
- UI Portlets are PHP classes with a ".tpl" TTemplate file with the same base name
- Head scripts (`registerHeadScriptFile`/`registerHeadScript`) render only through `THead`; form scripts (`registerScriptFile`/`registerBeginScript`/`registerEndScript`) render in the form, and begin scripts also render in a callback response. Time is read through PRADO's clock seam (`TApplicationClockAwareTrait`), never `time()` directly.
- Logging goes through `Prado::log()` with `\Prado\Util\Log\TLogger` levels (the logger moved to `Prado\Util\Log` in PRADO 4.4).
- The public API is published (v1.0.0 onward): prefer compatible changes, and document any breaking change under "Upgrading" in `CHANGELOG.md`
- Record every user-visible change under `## [Unreleased]` in `CHANGELOG.md` (Keep a Changelog format) as it lands
- A full check consists of the 4 checks (in order): `php -l` compile, php-cs-fixer, phpstan, phpunit (all checks must pass successfully)
- A full check must be done for code to be ready for git commit.
- The current version of this extension is **v1.0.0** (released 2026-09-26). It targets PRADO 4.4+ (the `pradosoft/prado` `master` branch, aliased `4.4.x-dev`). Release history and upgrade notes are in `CHANGELOG.md`.
- This extension namespaces its class under `belisoful\GAnalytics` (PSR-4 → `src/`); extensions do NOT update the framework's `classes.php`. The Prado3 short class name is supplied via `config/classMap.json`, registered by Composer from `composer.json` `extra.prado.class-map`. The bootstrap module is `extra.prado.bootstrap`, so `<module id="belisoful/ganalytics"/>` configures it without a class.
- Error codes (keys) and their messages live in `config/errorMessages.txt`, registered by Composer from `composer.json` `extra.prado.error-messages`; the framework's `messages.txt` is not used. `TPluginModule` also looks for an `errorMessages.txt` next to the module class (`src/`); this extension keeps the file under `config/` and relies on Composer.

## Testing Guidelines
- The testing platforms are "phpunit" (unit, live) and "playwright" (browser end-to-end)
- Unit test classes use the `belisoful\GAnalytics\Test\Unit\` namespace, autoloaded by Composer (`autoload-dev` PSR-4 → `tests/unit/`).
  - The namespace follows the directory: `tests/unit/GAnalyticsModuleTest.php` → `belisoful\GAnalytics\Test\Unit\GAnalyticsModuleTest`.
  - A class used by another file lives in its own file named after the class. Fixtures used only by one test file stay in that file.
  - Class names in strings and XML configuration are fully qualified; use `Foo::class` in PHP.
  - Helper classes in their own file must not end in `Test`; phpunit collects `*Test.php` files as tests.
  - Global classes are written with a leading backslash inside the test namespace (`new \stdClass()`).
- `tests/test_tools/phpunit_bootstrap.php` registers the error messages, defines `PRADO_TEST_RUN` (so a test may construct another `TApplication`), and constructs a `TApplication` on `tests/unit/app` without running it. A test that needs a page sets a `TPageService` as the application's service (`TPage::getClientScript()` asks the service for its manager class) and restores the previous service in `tearDown()`.
- Shared fixtures live in their own files: `ProbeGAnalyticsModule` (an `\ArrayObject` deferred store; recording Measurement Protocol, Data API and Admin API clients), `RecordingTransportTrait` (records `transport()` calls, answers from a queue) with `RecordingDataApi`, `RecordingAdminApi`, `RecordingServiceAccountCredentials`, `RecordingMeasurementProtocol` (records `post()`), `FakeUser` (an `IUser`), `FakeSession` (a `THttpSession` over an array), `FakeCredentialsModule`, `FakeConsentModule`, `RecordingResponse` (records cookies). Network, PHP sessions and randomness never reach a unit test; a service account test generates its own RSA key with `openssl_pkey_new()`.
- The phpunit bootstrap creates the default response module and closes its output buffer, so no test is flagged risky for a buffer PRADO opened.
- Live tests (`tests/live`, namespace `belisoful\GAnalytics\Test\Live`, `LiveTestCase`) read the `GA4_*` environment and skip without it; they are the only tests that reach Google.
- Playwright specs (`tests/playwright/*.spec.js`) use `helpers.js`: `stubGoogle(page)` answers Google's hosts locally, `dataLayer(page)` and `gtagCalls(page)` read the page's data layer. A spec never depends on Google being reachable.
- All new code must include unit tests
- Unit test functions must comprehensively assert both typical and edge cases
- Maximal coverage of code execution paths of a class is required
- Test error conditions and exception handling
- Use mock objects where appropriate; inject `TMockClock` instead of sleeping when a test depends on elapsed time
- Tests should be isolated from each other (no shared state): remove the application parameters a test adds, restore the service and the application
- When unit testing one or cluster of classes, only run the unit tests for that class or cluster/directory.
- NEVER add/change phpunit command options when unit testing; only run project unit tests as specified. Measuring coverage is the exception: use the `composer coverage` scripts, with `--filter` and `--coverage-filter` after `--` to narrow `composer coverage-paths` to one class (see Running Tests).
- phpunit DOES NOT have the cli option "--verbose"

## Development Environment
- PHP 8.1 through 8.5 are supported and exercised in CI (`.github/workflows/ganalytics.yml`)
- PHP extensions: ctype, dom, intl, json, pcre, spl (required by PRADO)
- Composer for dependency management
- Required developer dependencies for code checking: phpunit/phpunit, phpstan/phpstan, friendsofphp/php-cs-fixer
- Presume that project dependencies are installed
- `ext-openssl` for the service account JWT; Node (see `.nvmrc`) for Playwright
- CI installs the sibling `pradosoft/prado` (`master`) checkout as a Composer path repository, so the extension is always built against the framework's development HEAD; the `live` job needs the `GA4_*` repository secrets and skips its tests without them

## Directory Structure
```
./
├── .github/workflows/          # CI: php-cs-fixer, phpstan, phpunit on PHP 8.1 – 8.5 against PRADO master
├── agents/                     # The Coding Agents directory
│   └── working/                # Temporary working, planning, and analysis files for Agents (see its INDEX.md)
├── config/
│   ├── classMap.json           # Prado3 short class name → fully qualified name (composer extra.prado.class-map)
│   └── errorMessages.txt       # Error codes and messages (composer extra.prado.error-messages)
├── src/                        # PSR-4 root for belisoful\GAnalytics
│   ├── GAnalyticsModule.php                # The module: tag, calls, PRADO hooks, consent, user id, CSP, MP, Data API, realtime, shell
│   ├── GAnalyticsPageBehavior.php          # TClassBehavior on TPage: trackEvent() and friends on pages
│   ├── GAnalyticsMeasurementProtocol.php   # Server-side Measurement Protocol client
│   ├── GAnalyticsApiClient.php             # Base of the JSON API clients (request, requestAll)
│   ├── GAnalyticsDataApi.php               # Data API v1beta; GAnalyticsReport.php is its report model
│   ├── GAnalyticsAdminApi.php              # Admin API v1beta
│   ├── GAnalyticsApiException.php          # A Google API refusal
│   ├── IGAnalyticsCredentials.php          # Token seam; GAnalyticsServiceAccountCredentials.php, GAnalyticsAccessTokenCredentials.php
│   ├── IGAnalyticsConsentProvider.php      # Consent seam; IGAnalyticsConsentStore.php, GAnalyticsCookieConsentProvider.php
│   ├── GAnalyticsHttpTransportTrait.php    # The one HTTP transport seam
│   └── GAnalyticsShellAction.php           # prado-cli ganalytics/*
├── tests/
│   ├── live/                   # phpunit tests against a real property; namespace belisoful\GAnalytics\Test\Live; skip without GA4_* variables
│   ├── playwright/             # Browser end-to-end specs, helpers, and the app/ and app-gtm/ PRADO applications they drive
│   ├── test_tools/             # phpunit and phpstan bootstraps
│   └── unit/                   # phpunit tests and fixtures; namespace belisoful\GAnalytics\Test\Unit (autoload-dev PSR-4)
│       └── app/                # The minimal application the tests construct
├── playwright.config.js        # Playwright: the PHP server, the browsers, the report
├── package.json                # @playwright/test
├── AGENTS.md                   # This file
├── CHANGELOG.md                # Release notes (Keep a Changelog)
├── CLAUDE.md                   # Short memory file for the directory
├── composer.json               # Package configuration and scripts
└── README.md                   # Documentation
```

# PRADO Framework Agent Safeguards -- ANTI-PATTERNS
Between the next brackets, it is required without exception:
{
- NEVER (without exception) execute the following "git" commands without asking the developer for approval first: clone, mv, restore, rm, branch, commit, merge, rebase, reset, pull, push
- NEVER (without exception) execute "rm" commands on any paths without asking the developer for approval first
- NEVER remove composer --dev dependencies required for development
- NEVER perform an action that erases or overwrites files for the task of unit testing and fixing; file changes are important and must be kept, because the changes themselves are being unit tested.
- NEVER delete any folders or files until the associated task is absolutely and totally complete.
}
