# PRADO Google Analytics Extension

Google Analytics 4 for the [PRADO PHP Framework](https://github.com/pradosoft/prado) (version 4.4+), implemented as a PRADO 4 extension. `GAnalyticsModule` registers the Google tag (`gtag.js`) in the head of every page the page service runs, so each page view is measured under your Measurement ID. The tag is configured from the application configuration, an application parameter, or both. Page code sends events through the module or straight from the page (`$this->trackEvent(…)`), on full pages and on ActiveControl callbacks alike; PHP code without a browser sends them over the Measurement Protocol; and the module keeps a PRADO Content Security Policy working with the tag.

| Class | Role |
|---|---|
| `GAnalyticsModule` | The module: registers the tag on every page, queues and delivers `gtag()` calls, derives the `user_id`, amends the CSP, and fronts the Measurement Protocol |
| `GAnalyticsPageBehavior` | A class behavior on `TPage` giving pages `trackEvent()`, `updateConsent()`, `setUserProperties()`, `gtag()` and `getGAnalytics()` |
| `GAnalyticsMeasurementProtocol` | The server-side client: posts up to 25 events for one client id to Google, with PRADO's clock as the timestamp |

## Requirements

| Requirement | Scope | Purpose |
|---|---|---|
| PHP 8.1 – 8.5 | required | The runtime. CI runs every minor from 8.1 through 8.5 |
| PRADO Framework `^4.4` | required | `TPluginModule`, `TPageService::onPreRunPage`, `TClientScriptManager`, the Composer `extra.prado` extension mechanism |
| A GA4 property | required | Its Measurement ID (`G-XXXXXXXXXX`); Google Ads (`AW-`), Floodlight (`DC-`) and Google tag (`GT-`) ids are accepted too |

## Installation

```sh
composer require belisoful/ganalytics
```

PRADO 4.4 is not yet released, so the extension depends on its development branch (`^4.4@dev`). Composer applies stability flags only from the root project, so allow it there, for example with `"minimum-stability": "dev"` and `"prefer-stable": true`, or by requiring `pradosoft/prado:^4.4@dev` yourself.

Release notes and upgrade steps between versions are in [CHANGELOG.md](CHANGELOG.md).

## Usage

The package declares its bootstrap module in `composer.json` (`extra.prado.bootstrap`), so the module is configured by its package name, without a `class`:

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

The class can be named as well, by its namespace or by the Prado3 short name the package's class map provides:

```xml
<module id="ganalytics" class="belisoful\GAnalytics\GAnalyticsModule" MeasurementId="G-XXXXXXXXXX" />
<module id="ganalytics" class="GAnalyticsModule" MeasurementId="G-XXXXXXXXXX" />
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

`MeasurementIdParameter` renames the parameter. The parameter is read on every page, so a change takes effect on the next request. A page runs without the tag, and a notice is logged, when neither the module nor the parameter holds an id.

### What the page receives

For `MeasurementId="G-XXXXXXXXXX"` the page head carries Google's snippet:

```html
<script src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX" async></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', "G-XXXXXXXXXX");
</script>
```

Both are registered on the page's `TClientScriptManager` under the key `gtag` (`GAnalyticsModule::SCRIPT_KEY`), so a page can test `isHeadScriptRegistered('gtag')`. `THead` renders them; a page without a `THead` gets the same two scripts at the beginning of its form instead. A callback request renders neither, so the tag loads once per page.

## Properties

| Property | Default | Effect |
|---|---|---|
| `MeasurementId` | none | The tag id, such as `G-XXXXXXXXXX`; an invalid id is refused when set. Empty means: read the application parameter |
| `MeasurementIdParameter` | `GoogleAnalyticsMeasurementId` | The application parameter read when `MeasurementId` is unset |
| `Enabled` | `true` | `false` leaves every page without the tag, for example in a development configuration |
| `DebugMode` | `false` | `true` sets `debug_mode: true`, so hits show in the GA4 DebugView |
| `SendPageView` | `true` | `false` sets `send_page_view: false`, for applications that send their own `page_view` events |
| `ConfigOptions` | none | Further `gtag('config')` parameters (`user_id`, `cookie_domain`, `cookie_flags`, `allow_google_signals`, …) as an array, or a JSON object in XML |
| `ConsentDefaults` | none | Consent Mode defaults, emitted as `gtag('consent', 'default', …)` before the configuration |
| `AdditionalMeasurementIds` | none | Further tags configured on the page with a plain `gtag('config')`, such as a Google Ads `AW-` id; a list or a comma-separated string |
| `EnabledModes` | none (all) | The `TApplicationMode` names the tag is registered in, such as `Normal, Performance`, to keep development traffic out of the property |
| `PagePathAsContentGroup` | `false` | `true` reports the PRADO page path (`Admin.Users`) as the GA4 `content_group`, so reports group by page |
| `UserId` | none | The GA4 `user_id` for the request, set by application code; an application-defined stable id, never personal data |
| `UserIdFromUser` | `false` | `true` derives `user_id` from the authenticated PRADO user: the HMAC-SHA256 of the user name under the security manager's validation key, stable per user and per application, so no name reaches Google; a guest has none |
| `TagUrl` | `https://www.googletagmanager.com/gtag/js` | The script host, for first-party or server-side tagging; must be an absolute http(s) URL |
| `DataLayerName` | `dataLayer` | The data layer variable, for a page whose `dataLayer` another tag owns; passed to the script as `l` |
| `AttachPageBehavior` | `true` | Attaches `GAnalyticsPageBehavior` to `TPage` (see [Events from pages](#events-from-pages)) |
| `AmendCsp` | `true` | Adds Google's hosts to the application's Content Security Policy (see [Content Security Policy](#content-security-policy)) |
| `ApiSecret` | none | The data stream's Measurement Protocol API secret (see [Events from PHP](#events-from-php-measurement-protocol)) |

```xml
<module id="belisoful/ganalytics" MeasurementId="G-XXXXXXXXXX" SendPageView="false"
    AdditionalMeasurementIds="AW-123456789" EnabledModes="Normal, Performance"
    PagePathAsContentGroup="true" UserIdFromUser="true"
    ConfigOptions='{"cookie_domain": "example.com", "allow_google_signals": false}'
    ConsentDefaults='{"ad_storage": "denied", "ad_user_data": "denied", "ad_personalization": "denied", "analytics_storage": "denied", "wait_for_update": 500}'
    TagUrl="https://metrics.example.com/gtag/js" />
```

Every value is JavaScript-encoded on output (`TJavaScript::encode`), so an id or option value cannot break out of the script. PRADO's per-request CSP nonce, when `THttpHeaderCsp` sets one, is emitted on the tag's `<script>` elements by `TJavaScript`.

## Events from pages

The module queues `gtag()` calls for the page and delivers them at `TPage::onPreRenderComplete`, after the tag is registered, so the same code works on a full page and on an ActiveControl callback:

| Request | Delivery |
|---|---|
| Full page (GET or postback) | One `<script>` block at the end of the form: `gtag("event", "sign_up", {...});` |
| Callback (ActiveControls) | Each call runs through the page's callback client as `gtag(...)`, no page load |
| No page registered yet, or after the page's calls were delivered, or `$deferred = true` | Kept in the session and delivered on the next page |

```php
$module = $this->getApplication()->getModule('belisoful/ganalytics');
$module->trackEvent('sign_up', ['method' => 'form']);           // this page (or this callback)
$module->trackEvent('login', ['method' => 'form'], true);        // the next page, after $this->getResponse()->redirect(...)
$module->updateConsent(['analytics_storage' => 'granted']);      // gtag('consent', 'update', {...})
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

Event names follow GA4: a letter, then up to 39 letters, digits or underscores; an invalid name is refused (`ganalytics_event_name_invalid`). Deferred calls need the application session; without one they are dropped and a notice is logged. A call made during rendering, after the page's calls were delivered, is deferred to the next page.

## Events from PHP (Measurement Protocol)

An event without a browser (a shell command, a cron job, an API request, a server-side conversion) goes to Google over the Measurement Protocol. Create an API secret under the data stream's "Measurement Protocol API secrets" and set `ApiSecret`:

```php
$module->sendEvent('refund', ['transaction_id' => $orderId, 'value' => 9.99, 'currency' => 'USD']);
```

`sendEvent()` uses the visitor's client id from the request's `_ga` cookie when there is one (`getClientId()`), or a new one; the `user_id` is the module's effective user id (`UserId`, or the derived one). It returns whether Google accepted the request; a refused or failed request is logged at warning level. With `DebugMode` the request goes to Google's validation endpoint, which accepts nothing and answers with validation messages, logged as warnings.

`getMeasurementProtocol()` returns the `GAnalyticsMeasurementProtocol` client for batches: `send($clientId, $events, $userId, $extra)` posts up to 25 events with `timestamp_micros` from PRADO's clock (`TApplicationClockAwareTrait`, so a `TMockClock` dates them in tests), and `Endpoint`, `DebugEndpoint` and `Timeout` are properties. The transport is a `file_get_contents()` over an `http` stream context; override `post()` for another one.

## Content Security Policy

With `AmendCsp` (the default) the module adds the hosts the tag needs to every `THttpHeaderCsp` of every `THttpHeadersManager` in the application when it hooks the application: `https://*.googletagmanager.com` to `script-src`, and the `google-analytics.com`, `analytics.google.com` and `googletagmanager.com` wildcards to `connect-src` and `img-src`, plus the `TagUrl` origin when it is not a Google host. A directive is amended when it exists, or created from `default-src` when only that exists, so the browser's fallback stays in force; a policy that restricts neither is left alone. The module's `getCspSources()` lists the hosts, and `amendCspHeader($csp)` amends one header for a policy the module cannot see.

### Skipping the tag for a page

The module raises `onPreRegisterScript` before it registers the tag. The event parameter is a `TEventParameter` whose `Parameter` is the `TPage` about to run; a handler that stops the event leaves that page without the tag:

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

`registerPageScripts($page)` returns whether the tag was registered, and `getTagScriptUrl()` and `getTagScript($page)` return the script URL and the inline block, for a page or control that renders them itself. `getIsActive()` tells whether `Enabled` and `EnabledModes` allow the tag in this request.

## Development

```sh
composer install
composer fix          # php-cs-fixer on src and tests
composer stan         # phpstan level 3, PHP 8.1 – 8.5
composer unittest     # phpunit
composer fulltest     # all three, in order
```

CI (`.github/workflows/ganalytics.yml`) runs the checks on PHP 8.1 through 8.5 against the PRADO `master` branch, installed as a Composer path repository. Coding standards and conventions are in [AGENTS.md](AGENTS.md).

## License

BSD 3-Clause. See [LICENSE](LICENSE).
