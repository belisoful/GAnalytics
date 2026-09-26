<?php

/**
 * GAnalyticsMeasurementProtocol class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TComponent;
use Prado\TPropertyValue;
use Prado\Util\Clock\TApplicationClockAwareTrait;
use Prado\Util\Log\TLogger;

/**
 * GAnalyticsMeasurementProtocol class.
 *
 * Sends events to Google Analytics 4 from PHP over the Measurement Protocol, for events that
 * happen without a browser: a shell command, a cron job, an API request, or a server-side
 * conversion. {@see send()} posts one request with up to 25 events for one client; the
 * {@see GAnalyticsModule} creates a client from its own {@see GAnalyticsModule::getMeasurementId()
 * MeasurementId} and {@see GAnalyticsModule::getApiSecret() ApiSecret} and offers
 * {@see GAnalyticsModule::sendEvent()} as the one-call form.
 *
 * The request carries `timestamp_micros` from PRADO's clock ({@see getClock()}), so a
 * {@see \Prado\Util\Clock\TMockClock} on the application dates the events in tests. With
 * {@see setDebug() Debug} the request goes to the validation endpoint, which accepts nothing and
 * answers with validation messages; the messages are logged at {@see TLogger::WARNING}.
 *
 * The transport is {@see post()}, a `file_get_contents()` over an `http` stream context with
 * {@see getTimeout() Timeout}; a subclass supplies another transport by overriding it.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GAnalyticsMeasurementProtocol extends TComponent
{
	use TApplicationClockAwareTrait;

	/** The Measurement Protocol collection endpoint. */
	public const DEFAULT_ENDPOINT = 'https://www.google-analytics.com/mp/collect';

	/** The Measurement Protocol validation endpoint; it validates and discards the events. */
	public const DEFAULT_DEBUG_ENDPOINT = 'https://www.google-analytics.com/debug/mp/collect';

	/** The most events one request carries. */
	public const MAX_EVENTS = 25;

	/** @var ?string The Measurement ID the events are sent to. */
	private ?string $_measurementId = null;

	/** @var ?string The Measurement Protocol API secret of the data stream. */
	private ?string $_apiSecret = null;

	/** @var string The collection endpoint. */
	private string $_endpoint = self::DEFAULT_ENDPOINT;

	/** @var string The validation endpoint. */
	private string $_debugEndpoint = self::DEFAULT_DEBUG_ENDPOINT;

	/** @var bool Whether requests go to the validation endpoint. */
	private bool $_debug = false;

	/** @var float The request timeout, in seconds. */
	private float $_timeout = 2.0;

	/** @var ?string The last response body, for diagnostics. */
	private ?string $_lastResponse = null;

	/**
	 * Sends events for one client.
	 * @param string $clientId The GA4 client id (`_ga` cookie value without its `GA1.1.` prefix, or a generated one).
	 * @param array<int, array{name: string, params?: array<string, mixed>}> $events One to 25 events, each a `name` with optional `params`.
	 * @param ?string $userId The user id, or null for none.
	 * @param array<string, mixed> $extra Further top-level payload fields (`user_properties`, `consent`, `non_personalized_ads`, …).
	 * @throws TConfigurationException When the Measurement ID or the API secret is unset.
	 * @throws TInvalidDataValueException When the client id or the events are not valid.
	 * @return bool Whether the endpoint accepted the request (a 2xx response).
	 */
	public function send(string $clientId, array $events, ?string $userId = null, array $extra = []): bool
	{
		if ($this->getMeasurementId() === null || $this->getApiSecret() === null) {
			throw new TConfigurationException('ganalytics_measurement_protocol_unconfigured');
		}
		if (trim($clientId) === '') {
			throw new TInvalidDataValueException('ganalytics_clientid_invalid', $clientId);
		}
		if (count($events) === 0 || count($events) > static::MAX_EVENTS) {
			throw new TInvalidDataValueException('ganalytics_events_count_invalid', (string) count($events), (string) static::MAX_EVENTS);
		}
		$payload = $extra;
		$payload['client_id'] = $clientId;
		if ($userId !== null && $userId !== '') {
			$payload['user_id'] = $userId;
		}
		$payload['timestamp_micros'] = (int) round($this->getClock()->microtime() * 1_000_000);
		$payload['events'] = [];
		foreach ($events as $event) {
			$name = $event['name'] ?? null;
			if (!is_string($name) || !GAnalyticsModule::isEventName($name)) {
				throw new TInvalidDataValueException('ganalytics_event_name_invalid', is_scalar($name) ? (string) $name : get_debug_type($name));
			}
			$entry = ['name' => $name];
			if (!empty($event['params']) && is_array($event['params'])) {
				$entry['params'] = $event['params'];
			}
			$payload['events'][] = $entry;
		}
		$url = ($this->getDebug() ? $this->getDebugEndpoint() : $this->getEndpoint())
			. '?measurement_id=' . rawurlencode($this->getMeasurementId())
			. '&api_secret=' . rawurlencode($this->getApiSecret());
		$body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($body === false) {
			throw new TInvalidDataValueException('ganalytics_payload_unencodable', json_last_error_msg());
		}
		[$status, $response] = $this->post($url, $body);
		$this->_lastResponse = $response;
		if ($this->getDebug() && is_string($response) && $response !== '') {
			$messages = json_decode($response, true);
			foreach ((array) ($messages['validationMessages'] ?? []) as $message) {
				Prado::log('Measurement Protocol validation: ' . json_encode($message, JSON_UNESCAPED_SLASHES), TLogger::WARNING, static::class);
			}
		}
		if ($status < 200 || $status >= 300) {
			Prado::log("Measurement Protocol request failed with status {$status}.", TLogger::WARNING, static::class);
			return false;
		}
		return true;
	}

	/**
	 * Posts a JSON body and returns the HTTP status and the response body. The transport seam:
	 * a `file_get_contents()` over an `http` stream context with {@see getTimeout() Timeout}. A
	 * transport failure is status 0.
	 * @param string $url The request URL.
	 * @param string $body The JSON request body.
	 * @return array{0: int, 1: ?string} The HTTP status code and the response body.
	 */
	protected function post(string $url, string $body): array
	{
		$context = stream_context_create(['http' => [
			'method' => 'POST',
			'header' => "Content-Type: application/json\r\n",
			'content' => $body,
			'timeout' => $this->getTimeout(),
			'ignore_errors' => true,
		]]);
		$response = @file_get_contents($url, false, $context);
		$status = 0;
		// PHP defines $http_response_header only when the http wrapper received a response.
		$headers = get_defined_vars()['http_response_header'] ?? [];
		foreach ($headers as $header) {
			if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $match)) {
				$status = (int) $match[1];
			}
		}
		return [$status, $response === false ? null : $response];
	}

	/**
	 * Returns a new client id in the `_ga` cookie form: a random 32-bit number, a dot, and the
	 * current clock time in seconds.
	 * @return string The client id.
	 */
	public function newClientId(): string
	{
		return random_int(1_000_000_000, 4_294_967_295) . '.' . $this->getClock()->time();
	}

	/**
	 * Extracts the client id from a `_ga` cookie value, such as `GA1.1.1234567890.1700000000`.
	 * @param ?string $cookie The cookie value.
	 * @return ?string The client id (`1234567890.1700000000`), or null when the value is not a `_ga` cookie.
	 */
	public static function clientIdFromCookie(?string $cookie): ?string
	{
		if ($cookie === null || !preg_match('/^GA1\.\d+\.(\d+\.\d+)$/', trim($cookie), $match)) {
			return null;
		}
		return $match[1];
	}

	/**
	 * @return ?string The last response body, or null before the first request or after a transport failure.
	 */
	public function getLastResponse(): ?string
	{
		return $this->_lastResponse;
	}

	/**
	 * @return ?string The Measurement ID the events are sent to.
	 */
	public function getMeasurementId(): ?string
	{
		return $this->_measurementId;
	}

	/**
	 * @param mixed $value The Measurement ID; empty for none.
	 */
	public function setMeasurementId($value)
	{
		$value = TPropertyValue::ensureNullIfEmpty($value);
		$this->_measurementId = ($value === null) ? null : trim((string) TPropertyValue::ensureString($value));
	}

	/**
	 * @return ?string The Measurement Protocol API secret of the data stream.
	 */
	public function getApiSecret(): ?string
	{
		return $this->_apiSecret;
	}

	/**
	 * @param mixed $value The API secret, created under the data stream's "Measurement Protocol API secrets"; empty for none.
	 */
	public function setApiSecret($value)
	{
		$value = TPropertyValue::ensureNullIfEmpty($value);
		$this->_apiSecret = ($value === null) ? null : trim((string) TPropertyValue::ensureString($value));
	}

	/**
	 * @return string The collection endpoint. Defaults to {@see DEFAULT_ENDPOINT}.
	 */
	public function getEndpoint(): string
	{
		return $this->_endpoint;
	}

	/**
	 * @param mixed $value The collection endpoint, for a regional or first-party host; empty restores the default.
	 * @throws TInvalidDataValueException When the value is not an absolute http or https URL.
	 */
	public function setEndpoint($value)
	{
		$this->_endpoint = $this->ensureEndpoint($value, static::DEFAULT_ENDPOINT);
	}

	/**
	 * @return string The validation endpoint. Defaults to {@see DEFAULT_DEBUG_ENDPOINT}.
	 */
	public function getDebugEndpoint(): string
	{
		return $this->_debugEndpoint;
	}

	/**
	 * @param mixed $value The validation endpoint; empty restores the default.
	 * @throws TInvalidDataValueException When the value is not an absolute http or https URL.
	 */
	public function setDebugEndpoint($value)
	{
		$this->_debugEndpoint = $this->ensureEndpoint($value, static::DEFAULT_DEBUG_ENDPOINT);
	}

	/**
	 * Validates an endpoint URL.
	 * @param mixed $value The property value.
	 * @param string $default The URL an empty value restores.
	 * @throws TInvalidDataValueException When the value is not an absolute http or https URL.
	 * @return string The endpoint.
	 */
	protected function ensureEndpoint($value, string $default): string
	{
		$value = TPropertyValue::ensureNullIfEmpty($value);
		if ($value === null) {
			return $default;
		}
		$url = trim((string) TPropertyValue::ensureString($value));
		$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
		if (!in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false || str_contains($url, '?') || str_contains($url, '#')) {
			throw new TInvalidDataValueException('ganalytics_tagurl_invalid', $url);
		}
		return $url;
	}

	/**
	 * @return bool Whether requests go to the validation endpoint. Defaults to false.
	 */
	public function getDebug(): bool
	{
		return $this->_debug;
	}

	/**
	 * @param mixed $value Whether requests go to the validation endpoint, which validates and discards them.
	 */
	public function setDebug($value)
	{
		$this->_debug = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return float The request timeout in seconds. Defaults to 2.
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
