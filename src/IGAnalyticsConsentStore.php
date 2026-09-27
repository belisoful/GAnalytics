<?php

/**
 * IGAnalyticsConsentStore interface file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace belisoful\GAnalytics;

/**
 * IGAnalyticsConsentStore interface.
 *
 * A consent provider that also records the visitor's choice. {@see GAnalyticsModule::updateConsent()}
 * stores the update through it, so the next request's `gtag('consent', 'default', …)` carries the
 * choice without a further update call.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
interface IGAnalyticsConsentStore extends IGAnalyticsConsentProvider
{
	/**
	 * Records consent types the visitor decided, merged over the stored state.
	 * @param array<string, string> $state The consent types, each `granted` or `denied`.
	 */
	public function setConsentState(array $state): void;
}
