<?php

/**
 * GAnalyticsAdminApi class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\Exceptions\TInvalidDataValueException;

/**
 * GAnalyticsAdminApi class.
 *
 * The Google Analytics Admin API v1beta: the accounts, properties and data streams the
 * credentials can see, and the Measurement Protocol secrets of a stream. The helpers cover the
 * lookups an application needs to configure itself (which property, which Measurement ID, which
 * API secret), and {@see submitUserDeletion()} erases a user's data; every other method of the API is reachable through {@see request()} and
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

	/** The identifier kinds {@see submitUserDeletion()} accepts. */
	public const USER_DELETION_KINDS = ['userId', 'clientId', 'appInstanceId', 'userProvidedData'];

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
	 * Asks Google to delete a user's data from a property (Admin API v1alpha `submitUserDeletion`,
	 * needs {@see GAnalyticsServiceAccountCredentials::SCOPE_EDIT}). Google deletes the events
	 * collected before the request time; the deletion completes asynchronously.
	 *
	 * | Kind | Identifier |
	 * |---|---|
	 * | `userId` | the GA4 `user_id` |
	 * | `clientId` | the GA4 client id (`_ga` cookie) |
	 * | `appInstanceId` | a Firebase app instance id |
	 * | `userProvidedData` | one email address or phone number, normalized by {@see normalizeUserProvidedData()} |
	 * @param string $property The property resource name, `properties/{id}`, or the id.
	 * @param string $kind The identifier kind, one of {@see USER_DELETION_KINDS}.
	 * @param string $id The identifier.
	 * @throws TInvalidDataValueException When the kind is unknown or the identifier is empty.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return string The `deletionRequestTime`: Google deletes the data collected before it.
	 */
	public function submitUserDeletion(string $property, string $kind, string $id): string
	{
		if (!\in_array($kind, self::USER_DELETION_KINDS, true)) {
			throw new TInvalidDataValueException('ganalytics_user_deletion_kind_invalid', $kind, \implode(', ', self::USER_DELETION_KINDS));
		}
		$id = $kind === 'userProvidedData' ? static::normalizeUserProvidedData($id) : \trim($id);
		if ($id === '') {
			throw new TInvalidDataValueException('ganalytics_user_deletion_id_empty', $kind);
		}
		$url = $this->getAlphaBaseUrl() . '/' . static::resourceName('properties', $property) . ':submitUserDeletion';
		$response = $this->requestUrl('POST', $url, [$kind => $id]);
		return (string) ($response['deletionRequestTime'] ?? '');
	}

	/**
	 * Returns the base URL of the Admin API v1alpha, where `submitUserDeletion` lives: the
	 * {@see getBaseUrl() BaseUrl} with a trailing `/v1beta` replaced by `/v1alpha`. A proxy or mock
	 * base URL without that suffix is used as is.
	 * @return string The v1alpha base URL, without a trailing slash.
	 */
	public function getAlphaBaseUrl(): string
	{
		$base = $this->getBaseUrl();
		return \str_ends_with($base, '/v1beta') ? \substr($base, 0, -7) . '/v1alpha' : $base;
	}

	/**
	 * Normalizes an email address or phone number the way Google matches user-provided data:
	 * an email is trimmed and lowercased, with the periods before the `@` removed for `gmail.com`
	 * and `googlemail.com`; a phone number keeps its digits after a `+`.
	 * @param string $value The email address or phone number.
	 * @return string The normalized value; empty when nothing remains.
	 */
	public static function normalizeUserProvidedData(string $value): string
	{
		$value = \strtolower(\trim($value));
		if (\str_contains($value, '@')) {
			[$local, $domain] = \explode('@', $value, 2);
			if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
				$local = \str_replace('.', '', $local);
			}
			return $local . '@' . $domain;
		}
		$digits = \preg_replace('/\D+/', '', $value);
		return $digits === '' ? '' : '+' . $digits;
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
