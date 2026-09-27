<?php

/**
 * GAnalyticsAccessTokenCredentials class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\Exceptions\TConfigurationException;
use Prado\TComponent;
use Prado\TPropertyValue;

/**
 * GAnalyticsAccessTokenCredentials class.
 *
 * Credentials holding an access token obtained elsewhere: a token minted by another OAuth flow,
 * passed in from the environment, or a test value. The token is used as is and never refreshed.
 *
 * ```php
 * $api->setCredentials(new GAnalyticsAccessTokenCredentials(getenv('GA4_ACCESS_TOKEN')));
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GAnalyticsAccessTokenCredentials extends TComponent implements IGAnalyticsCredentials
{
	/** @var ?string The access token. */
	private ?string $_accessToken = null;

	/**
	 * @param mixed $accessToken The access token, or empty for none.
	 */
	public function __construct($accessToken = null)
	{
		$this->setAccessToken($accessToken);
		parent::__construct();
	}

	/**
	 * @throws TConfigurationException When no token is set.
	 * @return string The access token.
	 */
	public function getAccessToken(): string
	{
		if ($this->_accessToken === null) {
			throw new TConfigurationException('ganalytics_credentials_unconfigured', static::class);
		}
		return $this->_accessToken;
	}

	/**
	 * @param mixed $value The access token; empty for none.
	 */
	public function setAccessToken($value)
	{
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
		$this->_accessToken = ($value === null) ? null : \trim((string) TPropertyValue::ensureString($value));
	}
}
