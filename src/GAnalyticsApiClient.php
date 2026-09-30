<?php

/**
 * GAnalyticsApiClient class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\TComponent;
use Prado\TPropertyValue;

/**
 * GAnalyticsApiClient class.
 *
 * The base of the Google Analytics JSON API clients ({@see GAnalyticsDataApi},
 * {@see GAnalyticsAdminApi}): a {@see setBaseUrl() BaseUrl}, {@see setCredentials() Credentials}
 * that supply the bearer token, a {@see setTimeout() Timeout}, and {@see request()}, which sends a
 * JSON request through {@see transport()} and returns the decoded response or throws a
 * {@see GAnalyticsApiException}.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
abstract class GAnalyticsApiClient extends TComponent
{
	use GAnalyticsHttpTransportTrait;

	/** @var string The API base URL, without a trailing slash. */
	private string $_baseUrl;

	/** @var ?IGAnalyticsCredentials The credentials supplying the bearer token. */
	private ?IGAnalyticsCredentials $_credentials = null;

	/** @var float The request timeout, in seconds. */
	private float $_timeout = 10.0;

	/** @var ?array<string, mixed> The last decoded response. */
	private ?array $_lastResponse = null;

	/**
	 * @param ?IGAnalyticsCredentials $credentials The credentials, or null to set later.
	 */
	public function __construct(?IGAnalyticsCredentials $credentials = null)
	{
		$this->_baseUrl = static::getDefaultBaseUrl();
		$this->_credentials = $credentials;
		parent::__construct();
	}

	/**
	 * @return string The API's default base URL.
	 */
	abstract public static function getDefaultBaseUrl(): string;

	/**
	 * Sends a JSON request with the bearer token and returns the decoded response.
	 * @param string $method The HTTP method.
	 * @param string $path The path under the base URL, such as `properties/123:runReport`.
	 * @param ?array<string, mixed> $body The JSON body, or null for none.
	 * @param array<string, mixed> $query Query parameters.
	 * @throws TConfigurationException When no credentials are set.
	 * @throws GAnalyticsApiException When the status is not 2xx or the response is not a JSON object.
	 * @return array<string, mixed> The decoded response.
	 */
	public function request(string $method, string $path, ?array $body = null, array $query = []): array
	{
		return $this->requestUrl($method, $this->getBaseUrl() . '/' . \ltrim($path, '/'), $body, $query);
	}

	/**
	 * Sends a JSON request with the bearer token to an absolute URL and returns the decoded response.
	 * @param string $method The HTTP method.
	 * @param string $url The absolute URL, such as another API version's endpoint.
	 * @param ?array<string, mixed> $body The JSON body, or null for none.
	 * @param array<string, mixed> $query Query parameters.
	 * @throws TConfigurationException When no credentials are set.
	 * @throws GAnalyticsApiException When the status is not 2xx or the response is not a JSON object.
	 * @return array<string, mixed> The decoded response.
	 */
	protected function requestUrl(string $method, string $url, ?array $body = null, array $query = []): array
	{
		$credentials = $this->getCredentials();
		if ($credentials === null) {
			throw new TConfigurationException('ganalytics_credentials_unconfigured', static::class);
		}
		if (\count($query) > 0) {
			$url .= '?' . \http_build_query($query);
		}
		$headers = [
			'Authorization: Bearer ' . $credentials->getAccessToken(),
			'Accept: application/json',
		];
		$json = null;
		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
			$json = \json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if ($json === false) {
				throw new TInvalidDataValueException('ganalytics_payload_unencodable', \json_last_error_msg());
			}
		}
		[$status, $response] = $this->transport($method, $url, $headers, $json, $this->getTimeout());
		$data = ($response === null || \trim($response) === '') ? [] : \json_decode($response, true);
		if ($status < 200 || $status >= 300) {
			throw new GAnalyticsApiException($status, \is_array($data) ? $data : []);
		}
		if (!\is_array($data)) {
			throw new GAnalyticsApiException($status, [], 'the response is not JSON');
		}
		$this->_lastResponse = $data;
		return $data;
	}

	/**
	 * Requests every page of a list method, following `nextPageToken`.
	 * @param string $path The path under the base URL.
	 * @param string $key The response key holding the page's items.
	 * @param array<string, mixed> $query Query parameters; `pageToken` is managed here.
	 * @throws GAnalyticsApiException When a page request fails.
	 * @return array<int, array<string, mixed>> The items of every page.
	 */
	public function requestAll(string $path, string $key, array $query = []): array
	{
		$items = [];
		do {
			$page = $this->request('GET', $path, null, $query);
			foreach ((array) ($page[$key] ?? []) as $item) {
				$items[] = $item;
			}
			$token = $page['nextPageToken'] ?? null;
			$query['pageToken'] = $token;
		} while (\is_string($token) && $token !== '');
		return $items;
	}

	/**
	 * @return ?array<string, mixed> The last decoded response, or null before the first accepted request.
	 */
	public function getLastResponse(): ?array
	{
		return $this->_lastResponse;
	}

	/**
	 * @return string The API base URL, without a trailing slash.
	 */
	public function getBaseUrl(): string
	{
		return $this->_baseUrl;
	}

	/**
	 * @param mixed $value The API base URL, for a proxy or a mock server; empty restores the default.
	 * @throws TInvalidDataValueException When the value is not an absolute http or https URL.
	 */
	public function setBaseUrl($value)
	{
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
		if ($value === null) {
			$this->_baseUrl = static::getDefaultBaseUrl();
			return;
		}
		$url = \rtrim(\trim((string) TPropertyValue::ensureString($value)), '/');
		$scheme = \strtolower((string) \parse_url($url, PHP_URL_SCHEME));
		if (!\in_array($scheme, ['http', 'https'], true) || \filter_var($url, FILTER_VALIDATE_URL) === false || \str_contains($url, '?') || \str_contains($url, '#')) {
			throw new TInvalidDataValueException('ganalytics_tagurl_invalid', $url);
		}
		$this->_baseUrl = $url;
	}

	/**
	 * @return ?IGAnalyticsCredentials The credentials supplying the bearer token.
	 */
	public function getCredentials(): ?IGAnalyticsCredentials
	{
		return $this->_credentials;
	}

	/**
	 * @param ?IGAnalyticsCredentials $credentials The credentials supplying the bearer token.
	 */
	public function setCredentials(?IGAnalyticsCredentials $credentials)
	{
		$this->_credentials = $credentials;
	}

	/**
	 * @return float The request timeout in seconds. Defaults to 10.
	 */
	public function getTimeout(): float
	{
		return $this->_timeout;
	}

	/**
	 * @param mixed $value The request timeout in seconds; a positive number.
	 * @throws TInvalidDataValueException When the value is not positive.
	 */
	public function setTimeout($value)
	{
		$timeout = TPropertyValue::ensureFloat($value);
		if ($timeout <= 0) {
			throw new TInvalidDataValueException('ganalytics_timeout_invalid', (string) $timeout);
		}
		$this->_timeout = $timeout;
	}
}
