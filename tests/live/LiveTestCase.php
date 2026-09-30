<?php

namespace belisoful\GAnalytics\Test\Live;

use belisoful\GAnalytics\GAnalyticsModule;
use belisoful\GAnalytics\GAnalyticsServiceAccountCredentials;
use PHPUnit\Framework\TestCase;

/**
 * The base of the live tests, which talk to Google with a real property. Each test skips unless
 * the environment carries the credentials it needs:
 *
 * | Variable | Used by |
 * |---|---|
 * | `GA4_MEASUREMENT_ID` | Measurement Protocol (`G-XXXXXXXXXX`) |
 * | `GA4_API_SECRET` | Measurement Protocol |
 * | `GA4_PROPERTY_ID` | Data API and Admin API (the numeric property id) |
 * | `GA4_SERVICE_ACCOUNT_JSON` | Data API and Admin API (the key file's JSON text, or a path to it) |
 *
 * In CI they come from repository secrets; locally, export them before `composer livetest`.
 */
abstract class LiveTestCase extends TestCase
{
	/**
	 * Returns an environment variable or skips the test.
	 * @param string $name The variable.
	 * @return string The value.
	 */
	protected function env(string $name): string
	{
		$value = \getenv($name);
		if ($value === false || \trim($value) === '') {
			self::markTestSkipped("{$name} is not set; the live test needs a Google Analytics property.");
		}
		return \trim($value);
	}

	/**
	 * Returns a module configured for the Measurement Protocol from the environment, or skips.
	 * @return GAnalyticsModule The module.
	 */
	protected function measurementModule(): GAnalyticsModule
	{
		$module = new GAnalyticsModule();
		$module->setAttachPageBehavior(false);
		$module->setAmendCsp(false);
		$module->setMeasurementId($this->env('GA4_MEASUREMENT_ID'));
		$module->setApiSecret($this->env('GA4_API_SECRET'));
		return $module;
	}

	/**
	 * Returns service account credentials from the environment, or skips.
	 * @return GAnalyticsServiceAccountCredentials The credentials.
	 */
	protected function credentials(): GAnalyticsServiceAccountCredentials
	{
		$json = $this->env('GA4_SERVICE_ACCOUNT_JSON');
		$credentials = new GAnalyticsServiceAccountCredentials();
		if (\str_starts_with(\trim($json), '{')) {
			$credentials->setKey($json);
		} else {
			$credentials->setKeyFile($json);
		}
		return $credentials;
	}
}
