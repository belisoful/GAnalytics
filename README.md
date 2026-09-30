# PRADO Google Analytics Extension

Google Analytics 4 for the [PRADO PHP Framework](https://github.com/pradosoft/prado) (version 4.4+), implemented as a PRADO 4 extension. One module, `GAnalyticsModule`, does the whole job: it puts the Google tag on every page (gtag.js, a Tag Manager container, or both), sends events from PHP for pages, ActiveControl callbacks and code without a browser, hooks PRADO's own events (errors, logins, validation), keeps a Content Security Policy working, reads reports back through the Data API, erases a user's data on request, and adds `prado-cli ganalytics/*` commands.

| Class | Role |
|---|---|
| `GAnalyticsModule` | The module: tag, queued `gtag()` calls, PRADO event hooks, consent, user id, CSP, Measurement Protocol, Data and Admin API access, realtime polling, shell registration |
| `GAnalyticsPageBehavior` | A class behavior on `TPage`: `trackEvent()`, `updateConsent()`, `setUserProperties()`, `gtag()` and `getGAnalytics()` on every page |
| `GAnalyticsMeasurementProtocol` | Server-side events: up to 25 per request for one client id, timestamped from PRADO's clock |
| `GAnalyticsDataApi`, `GAnalyticsReport` | The Data API (reports, realtime, pivots, metadata, compatibility); a report is rows that bind to a data control |
| `GAnalyticsAdminApi` | The Admin API: accounts, properties, data streams, Measurement Protocol secrets, user deletion |
| `IGAnalyticsCredentials`, `GAnalyticsServiceAccountCredentials`, `GAnalyticsAccessTokenCredentials` | The token seam for the APIs: a service account JWT flow, or a token from elsewhere |
| `IGAnalyticsConsentProvider`, `IGAnalyticsConsentStore`, `GAnalyticsCookieConsentProvider` | The consent seam and a cookie-backed store |
| `GAnalyticsPrivacyConsentProvider` | The binding to a consent management module (`belisoful/prado-privacy`) |
| `GAnalyticsPersonalDataProvider` | Erasure and export of a user's Google Analytics data, and the Google Analytics entry in the records of processing (`belisoful/prado-privacy`) |
| `GAnalyticsShellAction` | `prado-cli ganalytics/status`, `send`, `validate`, `report`, `realtime`, `properties` |
| `GAnalyticsApiException` | A Google API refusal, with the status and Google's message |

## Requirements

| Requirement | Scope | Purpose |
|---|---|---|
| PHP 8.1 – 8.5 | required | The runtime. CI runs every minor from 8.1 through 8.5 |
| PRADO Framework `^4.4` | required | `TPluginModule`, `TPageService::onPreRunPage`, `TClientScriptManager`, `TCallbackClientScript`, `TClassBehavior`, `THttpHeadersManager`, the clock seam, the Composer `extra.prado` extension mechanism |
| A GA4 property | required | Its Measurement ID (`G-XXXXXXXXXX`); Google Ads (`AW-`), Floodlight (`DC-`) and Google tag (`GT-`) ids are accepted too, and a Tag Manager container (`GTM-XXXXXXX`) works alone |
| `ext-openssl` | for the APIs | Signs the service account JWT of `GAnalyticsServiceAccountCredentials` |
| A Measurement Protocol API secret | for server-side events | Created under the data stream's "Measurement Protocol API secrets" |
| A Google Cloud service account | for the Data and Admin APIs | Its JSON key, granted Viewer on the property (Editor for Admin API writes) |

## Installation

```sh
composer require belisoful/ganalytics
```

PRADO 4.4 is not yet released, so the extension depends on its development branch (`^4.4@dev`). Composer applies stability flags only from the root project, so allow it there, for example with `"minimum-stability": "dev"` and `"prefer-stable": true`, or by requiring `pradosoft/prado:^4.4@dev` yourself.

Release notes and upgrade steps between versions are in [CHANGELOG.md](CHANGELOG.md).

## Configuration

The package declares its bootstrap module in `composer.json` (`extra.prado.bootstrap`), so an installed extension is configured by its package name, without a `class`:

```xml
<modules>
    <module id="belisoful/ganalytics" MeasurementId="G-XXXXXXXXXX" />
</modules>
```

```php
'modules' => [
    'belisoful/ganalytics' => ['properties' => ['MeasurementId' => 'G-XXXXXXXXXX']],
],
```

The class can be named as well, by its namespace or by the Prado3 short name the package's class map provides. Inside this repository itself (the package is the root project, so Composer's `installed.json` does not list it) the class form is the one that works:

```xml
<module id="ganalytics" class="belisoful\GAnalytics\GAnalyticsModule" MeasurementId="G-XXXXXXXXXX" />
<module id="ganalytics" class="GAnalyticsModule" MeasurementId="G-XXXXXXXXXX" />
```

A full configuration, with the parts described below:

```xml
<modules>
    <module id="consent" class="belisoful\GAnalytics\GAnalyticsCookieConsentProvider" CookieName="site_consent" />
    <module id="belisoful/ganalytics" MeasurementId="G-XXXXXXXXXX" ContainerId="GTM-XXXXXXX"
        AdditionalMeasurementIds="AW-123456789" EnabledModes="Normal, Performance"
        PagePathAsContentGroup="true" UserIdFromUser="true"
        ConsentProvider="consent" ConsentDefaults='{"analytics_storage": "denied", "ad_storage": "denied", "ad_user_data": "denied", "ad_personalization": "denied"}'
        TrackExceptions="true" TrackLogins="true" TrackValidationErrors="true"
        ApiSecret="…" PropertyId="123456789" RealtimeMetrics="activeUsers, screenPageViews">
        <credentials class="belisoful\GAnalytics\GAnalyticsServiceAccountCredentials" KeyFile="ga4-service-account.json" />
    </module>
</modules>
```

### Measurement ID from an application parameter

When `MeasurementId` is not set on the module, it is read from the application parameter `GoogleAnalyticsMeasurementId`, so one configuration serves several deployments (a parameter can come from a `TParameterModule` file, a `TDbParameterModule`, or an environment-specific include):

```xml
<parameters>
    <parameter id="GoogleAnalyticsMeasurementId" value="G-XXXXXXXXXX" />
</parameters>
<modules>
    <module id="belisoful/ganalytics" />
</modules>
```

`MeasurementIdParameter` renames the parameter. The parameter is read on every page, so a change takes effect on the next request. A page runs without the tag, and a notice is logged, when neither an id nor a container resolves.

## The tag on the page

For `MeasurementId="G-XXXXXXXXXX"` the page head carries Google's snippet:

```html
<script src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX" async nonce="…"></script>
<script nonce="…">
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', "G-XXXXXXXXXX");
</script>
```

The module arms the tag at `TPageService::onPreRunPage` and registers it at `TPage::onPreRenderComplete`, when the page knows whether it has a `THead`: with one, the scripts go in the head (registered under the key `gtag`, so a page can test `isHeadScriptRegistered('gtag')`); without one, at the beginning of the form. A callback request gets no tag, so the tag loads once per page. The `nonce` attributes appear when PRADO's `THttpHeaderCsp` sets a per-request nonce.

With `ContainerId="GTM-XXXXXXX"` the snippet also carries Google Tag Manager's loader, and the container's `<noscript>` frame is inserted at the top of the form (`ContainerNoScript`). A container alone (no Measurement ID) is a valid setup: the container carries the tags, and events from page code become `dataLayer.push({event: …})` for its triggers. `ContainerUrl` points the loader (and `ns.html` beside it) at a server-side tagging host.

Every value is JavaScript-encoded on output (`TJavaScript::encode`), so an id or option value cannot break out of the script.

### Properties

| Property | Default | Effect |
|---|---|---|
| `MeasurementId` | none | The tag id, such as `G-XXXXXXXXXX`; an invalid id is refused when set. Empty means: read the application parameter |
| `MeasurementIdParameter` | `GoogleAnalyticsMeasurementId` | The application parameter read when `MeasurementId` is unset |
| `AdditionalMeasurementIds` | none | Further tags configured on the page with a plain `gtag('config')`, such as a Google Ads `AW-` id; a list or a comma-separated string |
| `ContainerId` | none | A Google Tag Manager container; the loader and the `<noscript>` frame are added, with or without a Measurement ID |
| `ContainerUrl` | `https://www.googletagmanager.com/gtm.js` | The Tag Manager loader host; `ns.html` is expected beside it |
| `ContainerNoScript` | `true` | Inserts the container's `<noscript>` frame at the top of the form |
| `Enabled` | `true` | `false` leaves every page without the tag |
| `EnabledModes` | none (all) | The `TApplicationMode` names the tag is registered in, such as `Normal, Performance`, to keep development traffic out of the property |
| `DebugMode` | `false` | `true` sets `debug_mode: true` (GA4 DebugView) and sends Measurement Protocol events to the validation endpoint |
| `SendPageView` | `true` | `false` sets `send_page_view: false`, for applications that send their own `page_view` events |
| `PagePathAsContentGroup` | `false` | `true` reports the PRADO page path (`Admin.Users`) as the GA4 `content_group`, so reports group by page |
| `ConfigOptions` | none | Further `gtag('config')` parameters (`cookie_domain`, `cookie_flags`, `allow_google_signals`, …) as an array, or a JSON object in XML |
| `ConsentDefaults` | none | Consent Mode defaults, emitted as `gtag('consent', 'default', …)` before the configuration |
| `ConsentProvider` | none | An `IGAnalyticsConsentProvider`: a module id, an instance, or a `<consent>` element; its state for the visitor overrides `ConsentDefaults` |
| `UserId` | none | The GA4 `user_id` for the request, set by application code; an application-defined stable id, never personal data |
| `UserIdFromUser` | `false` | `true` derives `user_id` from the authenticated PRADO user: the HMAC-SHA256 of the user name under the security manager's validation key, stable per user and per application, so no name reaches Google; a guest has none |
| `TagUrl` | `https://www.googletagmanager.com/gtag/js` | The gtag.js host, for first-party or server-side tagging; must be an absolute http(s) URL |
| `DataLayerName` | `dataLayer` | The data layer variable, for a page whose `dataLayer` another tag owns; passed to the scripts as `l` |
| `AttachPageBehavior` | `true` | Attaches `GAnalyticsPageBehavior` to `TPage` |
| `AmendCsp` | `true` | Adds Google's hosts to the application's Content Security Policy |
| `TrackExceptions`, `TrackLogins`, `TrackValidationErrors`, `LoginMethod` | `false`, `false`, `false`, `form` | The PRADO event hooks below |
| `ApiSecret` | none | The data stream's Measurement Protocol API secret |
| `PropertyId`, `Credentials`, `RealtimeMetrics`, `RealtimeDimensions` | none, none, `activeUsers`, none | The Data API access below |
| `ShellClass` | `GAnalyticsShellAction` | The `prado-cli` action class registered in a shell application |

### Skipping the tag for a page

The module raises `onPreRegisterScript` before it arms the tag. The event parameter is a `TEventParameter` whose `Parameter` is the `TPage` about to run; a handler that stops the event leaves that page without the tag:

```xml
<module id="belisoful/ganalytics" MeasurementId="G-XXXXXXXXXX" OnPreRegisterScript="Application.Analytics.skipPrivatePages" />
```

```php
public static function skipPrivatePages($module, TEventParameter $param)
{
    if (str_starts_with($param->getParameter()->getPagePath(), 'Admin.')) {
        $param->stopImmediatePropagation();
    }
}
```

`registerPageScripts($page)` arms a page and returns whether it did; `registerTag($page)` registers the scripts at once; `getTagScriptUrl()`, `getTagScript($page)`, `getContainerScript()` and `getContainerNoScriptHtml()` return the pieces, for a page or control that renders them itself; `getIsActive()` tells whether `Enabled` and `EnabledModes` allow the tag in this request.

## Events from pages

The module queues `gtag()` calls for the page and delivers them at `TPage::onPreRenderComplete`, after the tag is registered, so the same code works on a full page and on an ActiveControl callback:

| Request | Delivery |
|---|---|
| Full page (GET or postback) | One `<script>` block at the end of the form: `gtag("event", "sign_up", {...});` |
| Callback (ActiveControls) | Each call runs through the page's callback client, no page load |
| No page registered yet, or after the page's calls were delivered, or `$deferred = true` | Kept in the session and delivered on the next page |
| Container without a Measurement ID | An `event` call is `dataLayer.push({event: "sign_up", ...})`; consent and `set` calls stay `gtag()` |

```php
$module = $this->getApplication()->getModule('belisoful/ganalytics');
$module->trackEvent('sign_up', ['method' => 'form']);           // this page (or this callback)
$module->trackEvent('login', ['method' => 'form'], true);        // the next page, after $this->getResponse()->redirect(...)
$module->updateConsent(['analytics_storage' => 'granted']);      // gtag('consent', 'update', {...}), and stored by the consent provider
$module->setUserProperties(['plan' => 'pro']);                   // gtag('set', 'user_properties', {...})
$module->gtag('event', 'tutorial_begin');                        // any gtag() call
```

With `AttachPageBehavior` (the default) the module attaches `GAnalyticsPageBehavior` to `TPage` as a class behavior, so every page and every control's `getPage()` offers the same methods, and `getGAnalytics()` returns the module:

```php
class Checkout extends TPage
{
    public function orderPlaced($sender, $param)      // an ActiveButton callback
    {
        $this->trackEvent('purchase', ['value' => 9.99, 'currency' => 'USD', 'transaction_id' => $orderId]);
    }

    public function loggedIn()
    {
        $this->trackEvent('login', ['method' => 'form'], true);
        $this->getResponse()->redirect($this->getService()->constructUrl('Home'));
    }
}
```

Event names follow GA4: a letter, then up to 39 letters, digits or underscores; an invalid name is refused (`ganalytics_event_name_invalid`). Deferred calls use the application session: a write opens it, a read opens it only when the request already carries a session cookie, so a visitor gets no session for nothing. Without a session module the calls are dropped and a notice is logged. A call made during rendering, after the page's calls were delivered, is deferred to the next page.

## PRADO events

Three hooks turn framework events into analytics events, each opt-in:

| Property | PRADO event | GA4 event |
|---|---|---|
| `TrackExceptions` | `TApplication::onError` | `exception` over the Measurement Protocol (the page will not render): `description` (class and message, 100 characters), `fatal`, `error_type`, and `status_code` for a `THttpException`. Needs `ApiSecret` |
| `TrackLogins` | `TAuthManager::onLogin`, `onLoginFailed`, `onLogout` of every auth manager module | `login` (deferred, a login is usually followed by a redirect), `login_failed` (on the page), `logout` (deferred), each with `method` = `LoginMethod`; no user name is sent |
| `TrackValidationErrors` | `TPage::onPreRenderComplete` of a postback whose validators failed | `form_error` with `form_id` (the page path), `error_count`, and `validators` (the IDs) |

## Consent

`ConsentDefaults` is the state before the visitor decides (Google's advanced consent mode: the tag loads with everything denied). A `ConsentProvider` supplies the visitor's stored choice for the request, overriding the defaults in `gtag('consent', 'default', …)`, and, when it is an `IGAnalyticsConsentStore`, `updateConsent()` records the choice through it. `GAnalyticsCookieConsentProvider` is the cookie-backed store: one first-party cookie (`CookieName`, `Expires` days, `HttpOnly`, `SameSite=Lax`, `Secure` on https) holding the consent types the visitor decided:

```xml
<module id="consent" class="belisoful\GAnalytics\GAnalyticsCookieConsentProvider" CookieName="site_consent" Expires="180" />
<module id="belisoful/ganalytics" MeasurementId="G-XXXXXXXXXX" ConsentProvider="consent"
    ConsentDefaults='{"analytics_storage": "denied", "ad_storage": "denied"}' />
```

```php
public function acceptAnalytics($sender, $param)     // the banner's button
{
    $this->updateConsent(['analytics_storage' => 'granted']);   // runs gtag('consent','update') now and sets the cookie
}
```

A consent management module (banner, categories, a consent log) implements `IGAnalyticsConsentProvider` and plugs in the same way; the recommendation for one is in `agents/working/CONSENT_RECOMMENDATION_2026-09-27.md`.

### Basic and advanced consent mode

`ConsentMode` selects Google's consent mode:

| `ConsentMode` | Before consent | When consent is granted |
|---|---|---|
| `advanced` (default) | the tag loads with `ConsentDefaults`; Google receives cookieless pings | `gtag('consent', 'update', …)` |
| `basic` | no tag, no Google request; queued calls are dropped | the tag loads with the granted state |

```xml
<module id="belisoful/ganalytics" MeasurementId="G-XXXXXXXXXX" ConsentProvider="consent" ConsentMode="basic"
    ConsentDefaults='{"analytics_storage": "denied", "ad_storage": "denied"}' />
```

In basic mode the tag waits until one of `BasicConsentTypes` (`analytics_storage, ad_storage` by default) is granted in the effective consent (the provider's state over `ConsentDefaults`). The consent is checked again after the page's events, so:

- a grant during a postback puts the tag on the page that renders;
- a grant during an ActiveControl callback loads the tag into the page already open: a page without the tag carries a small loader function (`pradoGAnalyticsLoadTag`, no Google code and no request), and the callback calls it with the consent defaults, the `config` calls and the script URL, then runs the queued calls. It needs no `eval`, so it works under a nonce Content Security Policy;
- a withdrawal on a page that has the tag sends the `denied` update.

Events queued before consent (`trackEvent()`, validation errors, deferred calls) are dropped with a notice. `prado-cli ganalytics/status` shows the mode.

### With belisoful/prado-privacy

[belisoful/prado-privacy](https://github.com/belisoful/prado-privacy) supplies the banner, the preferences, the consent log, regions and Global Privacy Control. `GAnalyticsPrivacyConsentProvider` binds its `TConsentModule` to Consent Mode: the visitor's categories set the page's consent defaults, and every change (banner, preferences, withdrawal) sends `gtag('consent', 'update', …)` on the same page or callback, with no analytics code in the banner.

```xml
<module id="belisoful/prado-privacy" Version="2026-09" />
<module id="privacy-analytics" class="belisoful\GAnalytics\GAnalyticsPrivacyConsentProvider" />
<module id="belisoful/ganalytics" MeasurementId="G-XXXXXXXXXX" ConsentProvider="privacy-analytics"
    ConsentDefaults='{"analytics_storage": "denied", "ad_storage": "denied", "ad_user_data": "denied", "ad_personalization": "denied"}' />
```

| Consent category | Google consent types |
|---|---|
| `analytics` | `analytics_storage` |
| `marketing` | `ad_storage`, `ad_user_data`, `ad_personalization` |
| `functional` | `functionality_storage` |
| `personalization` | `personalization_storage` |
| (always) | `security_storage` = `granted` |

An undecided category leaves its types to `ConsentDefaults` (advanced consent mode: denied, the tag loads and sends cookieless pings; with `ConsentMode="basic"`, no tag until `analytics` or `marketing` is granted). `CategoryMap` (a JSON object) replaces the mapping; `ConsentModule` and `AnalyticsModule` name the modules when there are several; `UpdateOnChange="false"` stops the live updates. The provider finds the consent module by its surface (`getConsent()`, `setConsent()`, `onConsentChanged`), so neither package requires the other.

## Events from PHP (Measurement Protocol)

An event without a browser (a shell command, a cron job, an API request, a server-side conversion) goes to Google over the Measurement Protocol. Create an API secret under the data stream's "Measurement Protocol API secrets" and set `ApiSecret`:

```php
$module->sendEvent('refund', ['transaction_id' => $orderId, 'value' => 9.99, 'currency' => 'USD']);
```

`sendEvent()` uses the visitor's client id from the request's `_ga` cookie when there is one (`getClientId()`), or a new one; the `user_id` is the module's effective user id (`UserId`, or the derived one). It returns whether Google accepted the request; a refused or failed request is logged at warning level. With `DebugMode` the request goes to Google's validation endpoint, which accepts nothing and answers with validation messages, logged as warnings.

`getMeasurementProtocol()` returns the `GAnalyticsMeasurementProtocol` client for batches: `send($clientId, $events, $userId, $extra)` posts up to 25 events with `timestamp_micros` from PRADO's clock (`TApplicationClockAwareTrait`, so a `TMockClock` dates them in tests), and `Endpoint`, `DebugEndpoint` and `Timeout` are properties.

## Reports (Data API) and administration (Admin API)

The APIs need `PropertyId` (the numeric GA4 property id) and `Credentials`: an `IGAnalyticsCredentials`, a module id, or a `<credentials>` element. `GAnalyticsServiceAccountCredentials` is the server-to-server flow: a service account's JSON key (`KeyFile`, a path resolved against the application's base path, the `protected` directory, when it is relative; or `Key`, the content), the `Scopes` (read-only by default), a JWT signed with the key's RSA private key and exchanged for an access token, which is reused until a minute before it expires and shared through the application cache. `GAnalyticsAccessTokenCredentials` holds a token obtained elsewhere; a user-consent OAuth flow belongs with PRADO's user manager and plugs in through the interface.

```php
$report = $module->runReport(['activeUsers', 'screenPageViews'], ['pagePath'], '7daysAgo', 'today', ['limit' => 20]);
$this->Grid->setDataSource($report->getRows());   // [['pagePath' => '/Home', 'activeUsers' => 12, 'screenPageViews' => 40], …]
$this->Grid->dataBind();

$live = $module->runRealtimeReport(['activeUsers'], ['country']);
$api = $module->getDataApi();                     // runReport(), runRealtimeReport(), batchRunReports(), runPivotReport(), getMetadata(), checkCompatibility(), call()
$admin = $module->getAdminApi();                  // listAccountSummaries(), listProperties(), listDataStreams(), listMeasurementProtocolSecrets(), createMeasurementProtocolSecret(), submitUserDeletion(), request()
$module->deleteUserData($userId);                 // or ($clientId, 'clientId'): Google deletes the data collected before the returned time
```

`GAnalyticsReport` is countable and iterable; its rows are associative arrays keyed by dimension and metric name with metric values cast to `int` or `float` by their type, and `getTotals()`, `getRowCount()`, `getMetadata()` and `getResponse()` expose the rest. `GAnalyticsDataApi::reportRequest()` and `realtimeRequest()` build the API's request bodies from names; any other request shape is passed as an array. A refused request throws `GAnalyticsApiException` with the status and Google's message.

### Realtime

The Data API is pull-only; Google offers no push channel for realtime data. `pollRealtime()` runs the `RealtimeMetrics` and `RealtimeDimensions` report and raises `onRealtimeReport` with it. PRADO's `TCronModule` runs it on a schedule, and a handler publishes the figures through the application's own channel, such as [`belisoful/prado-webhooks`](https://github.com/belisoful/prado-webhooks) to other systems or [`belisoful/prado-websocket`](https://github.com/belisoful/prado-websocket) to browsers:

```xml
<module id="cron" class="Prado\Util\Cron\TCronModule">
    <job Schedule="* * * * *" Task="belisoful/ganalytics->pollRealtime" />
</module>
<module id="belisoful/ganalytics" … RealtimeMetrics="activeUsers, screenPageViews" RealtimeDimensions="country"
    OnRealtimeReport="Application.Dashboard.publishRealtime" />
```

## Content Security Policy

With `AmendCsp` (the default) the module adds the hosts the tag needs to every `THttpHeaderCsp` of every `THttpHeadersManager` in the application when it hooks the application: `https://*.googletagmanager.com` to `script-src`, and the `google-analytics.com`, `analytics.google.com` and `googletagmanager.com` wildcards to `connect-src` and `img-src`, plus the `TagUrl` and `ContainerUrl` origins when they are not Google hosts. A directive is amended when it exists, or created from `default-src` when only that exists, so the browser's fallback stays in force; a policy that restricts neither is left alone. The module's `getCspSources()` lists the hosts, and `amendCspHeader($csp)` amends one header for a policy the module cannot see. The per-request nonce of a `NONCE` policy is emitted on the tag's script elements by `TJavaScript`.

Two framework notes for a CSP setup: the response module must name the headers manager (`<module id="response" class="THttpResponse" HeadersManager="headers" />`), and the security manager should carry a configured `ValidationKey`, because `THttpHeaderCsp` reads the security manager for its nonce while the modules initialize, before the application state that holds a generated key is loaded; a generated key then differs per request and postbacks fail with "Page state is corrupted".

## Data subject rights and records of processing

`deleteUserData($id, $kind)` asks Google to delete one user's data from the property through the Admin API's `submitUserDeletion` (v1alpha; the Universal Analytics User Deletion API is retired). The kind is `userId` (the default), `clientId`, `appInstanceId` or `userProvidedData` (an email address or phone number, normalized as Google matches it). Google deletes the events collected before the returned `deletionRequestTime`; the deletion completes asynchronously. The credentials need the `analytics.edit` scope and the Editor role on the property.

With [belisoful/prado-privacy](https://github.com/belisoful/prado-privacy), `GAnalyticsPersonalDataProvider` does this for every erasure request. `TPrivacyModule` and `TProcessingRegistry` discover it as a loaded module:

```xml
<module id="belisoful/ganalytics" MeasurementId="G-XXXXXXXXXX" PropertyId="123456789" UserIdFromUser="true">
    <credentials class="belisoful\GAnalytics\GAnalyticsServiceAccountCredentials" KeyFile="ga4-service-account.json"
        Scopes="https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/analytics.edit" />
</module>
<module id="privacy-google" class="belisoful\GAnalytics\GAnalyticsPersonalDataProvider" />
```

| Right | Effect |
|---|---|
| Erasure | One deletion request per identifier of the subject; each accepted request counts as one erased item. A refused request is an error in the result, with its status and without the identifier; when every request is refused, the refusal is thrown and the privacy request is recorded as failed |
| Export | The property and the identifiers Google Analytics holds the subject's events under. GA4 has no per-user export API; the property's User Explorer report shows the events |
| Rectification | None: Google Analytics data is deleted, never corrected |

The subject's identifiers come from the user name (the derived `user_id`, when the analytics module's `UserIdFromUser` is on), from `TDataSubject` identifiers of the kinds `ga_user_id`, `ga_client_id` and `ga_app_instance_id` (each a comma- or space-separated list, for ids the application recorded), from the request's `_ga` cookie when the subject is the logged-in requester, and, with `EraseUserProvidedData="true"`, from the subject's email. Store a user's client ids at login if their data from other devices must be found later.

`getProcessingActivities()` adds Google Analytics to the records of processing: the purpose, consent as the legal basis, the data and subject categories (registered users and the pseudonymous id when `UserIdFromUser` is on), Google as the recipient, the transfer to the United States, and the property's data retention setting as the retention period. `Activity` (an array, or a JSON object in XML) overrides any field, such as `{"LegalBasis": "LegitimateInterests", "RetentionPolicy": "ga4"}`. The defaults describe a typical GA4 setup; review them against the property's settings and the contracts with Google.

`AnalyticsModule` names the analytics module when there are several; `PersonalDataName` renames the provider's key in an export (`google-analytics`). The class implements `belisoful/prado-privacy` interfaces, so it loads only where that package is installed; nothing else in this extension requires it.

## Command line

In a `TShellApplication` the module registers `ganalytics/*`:

```sh
php prado-cli.php ganalytics/status                                          # effective configuration and the tag script
php prado-cli.php ganalytics/send purchase '{"value": 9.99}' --clientid=1.2  # Measurement Protocol
php prado-cli.php ganalytics/validate purchase '{"value": 9.99}'             # the validation endpoint and its messages
php prado-cli.php ganalytics/report activeUsers,screenPageViews pagePath 7daysAgo today --limit=20
php prado-cli.php ganalytics/realtime activeUsers country
php prado-cli.php ganalytics/properties                                      # accounts, properties, web streams and their Measurement IDs
php prado-cli.php ganalytics/delete-user 1234567890.1700000000 --kind=clientId  # Admin API user deletion (--property= for another property)
```

## Development

```sh
composer install
composer fix          # php-cs-fixer on src and tests
composer stan         # phpstan level 3, PHP 8.1 – 8.5
composer unittest     # phpunit: the unit suite (no network)
composer livetest     # phpunit: the live suite against a real property (skips without GA4_* variables)
composer coverage     # unit coverage (Xdebug)
composer fulltest     # fix, stan, unittest
npm install && npx playwright install chromium
npx playwright test --project=chromium     # browser end-to-end tests against tests/playwright/app and app-gtm
```

The live suite reads `GA4_MEASUREMENT_ID`, `GA4_API_SECRET`, `GA4_PROPERTY_ID` and `GA4_SERVICE_ACCOUNT_JSON` (the key's JSON text or a path); in CI they are repository secrets, and every live test skips when one is absent. The end-to-end suite serves two PRADO applications with PHP's built-in server, stubs Google's hosts in the browser, and asserts the script elements, the nonce, the CSP header and the data layer entries the snippet and the delivered calls push.

CI (`.github/workflows/ganalytics.yml`) runs the checks on PHP 8.1 through 8.5 against the PRADO `master` branch, installed as a Composer path repository, plus a coverage job, Playwright on Chromium, Firefox and WebKit, and the live job. Coding standards and conventions are in [AGENTS.md](AGENTS.md).

## License

BSD 3-Clause. See [LICENSE](LICENSE).
