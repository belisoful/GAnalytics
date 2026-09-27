<?php

/**
 * GAnalyticsAdminApi class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace belisoful\GAnalytics;

/**
 * GAnalyticsAdminApi class.
 *
 * The Google Analytics Admin API v1beta: the accounts, properties and data streams the
 * credentials can see, and the Measurement Protocol secrets of a stream. The helpers cover the
 * lookups an application needs to configure itself (which property, which Measurement ID, which
 * API secret); every other method of the API is reachable through {@see request()} and
 * {@see requestAll()}. Writes need {@see GAnalyticsServiceAccountCredentials::SCOPE_EDIT}.
 *
 * ```php
 * $admin = new GAnalyticsAdminApi($credentials);
 * foreach ($admin->listAccountSummaries() as $account) {
 *     foreach ($account['propertySummaries'] ?? [] as $property) {
 *         echo $property['property'], ' ', $property['displayName'], "\n";   // properties/123456789 My Site
 *     }
 * }
 * $streams = $admin->listDataStreams('properties/123456789');
 * $measurementId = $streams[0]['webStreamData']['measurementId'] ?? null;
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GAnalyticsAdminApi extends GAnalyticsApiClient
{
	/** The Admin API v1beta base URL. */
	public const DEFAULT_BASE_URL = 'https://analyticsadmin.googleapis.com/v1beta';

	/**
	 * @return string {@see DEFAULT_BASE_URL}.
	 */
	public static function getDefaultBaseUrl(): string
	{
		return static::DEFAULT_BASE_URL;
	}

	/**
	 * Returns every account the credentials can see, each with its `propertySummaries`.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return array<int, array<string, mixed>> The `AccountSummary` resources.
	 */
	public function listAccountSummaries(): array
	{
		return $this->requestAll('accountSummaries', 'accountSummaries', ['pageSize' => 200]);
	}

	/**
	 * Returns the properties of an account.
	 * @param string $account The account resource name, `accounts/{id}`, or the id.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return array<int, array<string, mixed>> The `Property` resources.
	 */
	public function listProperties(string $account): array
	{
		return $this->requestAll('properties', 'properties', ['filter' => 'parent:' . static::resourceName('accounts', $account), 'pageSize' => 200]);
	}

	/**
	 * Returns one property.
	 * @param string $property The property resource name, `properties/{id}`, or the id.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return array<string, mixed> The `Property` resource.
	 */
	public function getProperty(string $property): array
	{
		return $this->request('GET', static::resourceName('properties', $property));
	}

	/**
	 * Returns the data streams of a property; a web stream's `webStreamData.measurementId` is its Measurement ID.
	 * @param string $property The property resource name, `properties/{id}`, or the id.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return array<int, array<string, mixed>> The `DataStream` resources.
	 */
	public function listDataStreams(string $property): array
	{
		return $this->requestAll(static::resourceName('properties', $property) . '/dataStreams', 'dataStreams', ['pageSize' => 200]);
	}

	/**
	 * Returns one data stream.
	 * @param string $stream The stream resource name, `properties/{id}/dataStreams/{id}`.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return array<string, mixed> The `DataStream` resource.
	 */
	public function getDataStream(string $stream): array
	{
		return $this->request('GET', $stream);
	}

	/**
	 * Returns the Measurement Protocol secrets of a data stream.
	 * @param string $stream The stream resource name, `properties/{id}/dataStreams/{id}`.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return array<int, array<string, mixed>> The `MeasurementProtocolSecret` resources, each with a `secretValue`.
	 */
	public function listMeasurementProtocolSecrets(string $stream): array
	{
		return $this->requestAll($stream . '/measurementProtocolSecrets', 'measurementProtocolSecrets');
	}

	/**
	 * Creates a Measurement Protocol secret on a data stream (needs the edit scope).
	 * @param string $stream The stream resource name, `properties/{id}/dataStreams/{id}`.
	 * @param string $displayName The secret's display name.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return array<string, mixed> The created `MeasurementProtocolSecret`, with its `secretValue`.
	 */
	public function createMeasurementProtocolSecret(string $stream, string $displayName): array
	{
		return $this->request('POST', $stream . '/measurementProtocolSecrets', ['displayName' => $displayName]);
	}

	/**
	 * Returns a resource name from an id or a name: `123` becomes `properties/123`; `properties/123` stays.
	 * @param string $collection The collection, such as `properties`.
	 * @param string $idOrName The id or the resource name.
	 * @return string The resource name.
	 */
	public static function resourceName(string $collection, string $idOrName): string
	{
		$idOrName = \trim($idOrName);
		return \str_starts_with($idOrName, $collection . '/') ? $idOrName : $collection . '/' . $idOrName;
	}
}
