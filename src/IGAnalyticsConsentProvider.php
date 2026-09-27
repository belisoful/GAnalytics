<?php

/**
 * IGAnalyticsConsentProvider interface file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace belisoful\GAnalytics;

/**
 * IGAnalyticsConsentProvider interface.
 *
 * Supplies a visitor's Consent Mode state for the current request, so the tag's
 * `gtag('consent', 'default', …)` reflects a choice the visitor already made instead of the
 * configured defaults. The state maps Google's consent types (`analytics_storage`, `ad_storage`,
 * `ad_user_data`, `ad_personalization`, `functionality_storage`, `personalization_storage`,
 * `security_storage`) to `granted` or `denied`; a type left out keeps the configured default.
 *
 * {@see GAnalyticsCookieConsentProvider} reads the state from a cookie. A consent management
 * module (a banner, categories, a consent log) implements this interface, and
 * {@see IGAnalyticsConsentStore} when it also records the visitor's choice.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
interface IGAnalyticsConsentProvider
{
	/**
	 * @return array<string, string> The consent types the visitor decided, each `granted` or `denied`.
	 */
	public function getConsentState(): array;
}
