<?php

/**
 * IGAnalyticsCredentials interface file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace belisoful\GAnalytics;

/**
 * IGAnalyticsCredentials interface.
 *
 * Supplies the OAuth 2.0 access token the Google Analytics Data and Admin APIs are called with.
 * {@see GAnalyticsServiceAccountCredentials} signs a service account JWT for one;
 * {@see GAnalyticsAccessTokenCredentials} holds a token obtained elsewhere. A user-consent
 * (three-legged OAuth) implementation belongs with PRADO's user manager and plugs in through this
 * interface.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
interface IGAnalyticsCredentials
{
	/**
	 * Returns a valid access token, refreshing it when needed.
	 * @throws \Prado\Exceptions\TConfigurationException When the credentials are not configured.
	 * @throws GAnalyticsApiException When the token cannot be obtained.
	 * @return string The bearer token.
	 */
	public function getAccessToken(): string;
}
