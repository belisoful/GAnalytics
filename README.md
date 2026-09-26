# PRADO Google Analytics Extension

Google Analytics 4 for the [PRADO PHP Framework](https://github.com/pradosoft/prado) (version 4.4+), implemented as a PRADO 4 extension. `GAnalyticsModule` registers the Google tag (`gtag.js`) in the head of every page the page service runs, so each page view is measured under your Measurement ID. The tag is configured from the application configuration, an application parameter, or both.

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
| `TagUrl` | `https://www.googletagmanager.com/gtag/js` | The script host, for first-party or server-side tagging; must be an absolute http(s) URL |

```xml
<module id="belisoful/ganalytics" MeasurementId="G-XXXXXXXXXX" SendPageView="false"
    ConfigOptions='{"cookie_domain": "example.com", "allow_google_signals": false}'
    ConsentDefaults='{"ad_storage": "denied", "ad_user_data": "denied", "ad_personalization": "denied", "analytics_storage": "denied", "wait_for_update": 500}'
    TagUrl="https://metrics.example.com/gtag/js" />
```

Every value is JavaScript-encoded on output (`TJavaScript::encode`), so an id or option value cannot break out of the script.

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

`registerPageScripts($page)` returns whether the tag was registered, and `getTagScriptUrl()` and `getTagScript()` return the script URL and the inline block, for a page or control that renders them itself.

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
