# Consent management: recommendation — 2026-09-27

## The question

Cookie consent (ePrivacy Directive, GDPR, the UK PECR, and Google's EU user consent policy)
needs a banner, categories, a stored choice, a way to change it, and a record. Google
Analytics needs the choice as Consent Mode signals (`analytics_storage`, `ad_storage`,
`ad_user_data`, `ad_personalization`). Where should each part live?

## Recommendation

Three layers, each in its own place:

| Layer | Where | Why |
|---|---|---|
| **Consent framework**: categories, the stored choice, the change event, a consent log, banner and preference controls | A PRADO core module and controls (`Prado\Web\Consent`: `TConsentModule`, `TConsentBanner`, `TConsentPreferences`, `IConsentStore`) | Consent is not a Google concern. Every third-party script (analytics, ads, maps, video embeds, fonts) needs the same categories and the same banner. The framework already owns cookies, sessions, controls and CSP; consent belongs beside them, vendor-neutral, and every PRADO application benefits. |
| **Analytics binding**: consent categories to Google consent types, defaults before the tag, `gtag('consent', 'update')` on change, the stored choice as the next request's defaults | This extension, through `IGAnalyticsConsentProvider` / `IGAnalyticsConsentStore` | Done in this pass. The module reads any provider and records through any store, so the framework's module (or an application's own) plugs in without touching analytics code. `GAnalyticsCookieConsentProvider` is the minimal cookie-backed store for applications without a consent framework. |
| **Site-specific policy**: which categories exist, texts, legal basis, region rules (EU/EEA/UK/CH/California), the look of the banner | The application, or a `belisoful/prado-consent` repository when the customization is reusable across sites | Regulations and wording differ per site and jurisdiction; the framework supplies the mechanism, the site the policy. |

So: propose `TConsentModule` and its controls to `pradosoft/prado`, keep the analytics binding
here, and start `belisoful/prado-consent` only for the reusable customizations (a banner theme,
region detection, category presets, a consent log table via `TActiveRecord`). If the core module
is not wanted in the framework, the same design lands in `belisoful/prado-consent` unchanged; the
extension's seam does not care which.

## What the framework module should offer (for the proposal)

- `TConsentModule` (a `TModule`): `Categories` (`necessary` always granted, `analytics`,
  `marketing`, `functional`, `personalization`, extensible), `getConsent(): array`
  (category → granted/denied/undecided), `setConsent(array)`, `onConsentChanged` event,
  `Store` (`IConsentStore`: cookie by default, session or database optional), `Expires`,
  `Version` (a policy version that invalidates old choices), `getIsDecided()`.
- `IConsentStore`: `load(): ?array`, `save(array $consent, int $version): void`, `clear(): void`.
- `TConsentBanner` (a `TTemplateControl`): renders only while undecided; accept-all, reject-all,
  and a link to preferences; posts back or calls back into `TConsentModule::setConsent()`.
- `TConsentPreferences` (a `TTemplateControl`): a checkbox per category, save.
- `TConsentGate` (a `TControl`): renders its children only when a category is granted, so a
  template gates a map or video embed declaratively:
  `<com:TConsentGate Category="marketing">…</com:TConsentGate>`.
- Region: a `RequireConsentFor` list of regions (EU, EEA, UK, CH, US-CA) plus a
  `getRegion()` seam (header, IP database, or a behavior), defaulting to "everyone".
- A `TConsentLog` behavior recording changes (time, categories, version, IP hash) for the record
  regulators expect.

## How this extension binds to it

Already in place:

- `IGAnalyticsConsentProvider::getConsentState()` maps to Google's consent types. The
  framework module's provider maps categories: `analytics` → `analytics_storage`;
  `marketing` → `ad_storage`, `ad_user_data`, `ad_personalization`; `functional` →
  `functionality_storage`; `personalization` → `personalization_storage`; `security_storage`
  granted.
- `GAnalyticsModule::ConsentDefaults` holds the "before the choice" state (Google's advanced
  consent mode: all denied, tag still loads and sends cookieless pings), or the module runs with
  `Enabled=false` until consent for basic consent mode.
- `GAnalyticsModule::updateConsent()` sends `gtag('consent', 'update')` on the page or callback
  that records the choice and persists through `IGAnalyticsConsentStore`.

To add when the framework module exists: a `TConsentModule::onConsentChanged` handler in this
module (one method, opt-in) that calls `updateConsent()` with the mapped types, so a banner built
on the framework needs no analytics code at all.

## Decision points for the owner

1. Propose `TConsentModule` to `pradosoft/prado` (recommended) or start `belisoful/prado-consent`
   directly.
2. Basic or advanced consent mode as the documented default for EU sites. Advanced (tag loads,
   all denied) keeps modelled data in GA4; basic (no tag until consent) is the stricter reading
   some DPAs prefer. The extension supports both today (`ConsentDefaults` vs. `Enabled`).
3. Whether a consent log is required for the sites in scope; it drives the `TActiveRecord` table.
