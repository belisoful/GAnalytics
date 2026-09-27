<?php

/**
 * GAnalyticsServiceAccountCredentials class file.
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

/**
 * GAnalyticsServiceAccountCredentials class.
 *
 * Credentials of a Google Cloud service account, the server-to-server way to call the Analytics
 * Data and Admin APIs: no user signs in, the account is granted a role on the GA4 property. The
 * account's JSON key (from the Cloud console) is set by path ({@see setKeyFile() KeyFile}) or by
 * content ({@see setKey() Key}). {@see getAccessToken()} signs a JWT with the key's RSA private
 * key (RS256, `ext-openssl`), exchanges it at the key's `token_uri` for an access token, and
 * reuses the token until a minute before it expires. The token is also kept in the application
 * cache when there is one, so processes of one application share it.
 *
 * Time comes from PRADO's clock ({@see getClock()}), so a {@see \Prado\Util\Clock\TMockClock}
 * fixes the JWT claims in tests.
 *
 * ```xml
 * <module id="belisoful/ganalytics" MeasurementId="G-XXXXXXXXXX" PropertyId="123456789">
 *     <credentials class="belisoful\GAnalytics\GAnalyticsServiceAccountCredentials" KeyFile="protected/ga4-service-account.json" />
 * </module>
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GAnalyticsServiceAccountCredentials extends TComponent implements IGAnalyticsCredentials
{
	use TApplicationClockAwareTrait;
	use GAnalyticsHttpTransportTrait;

	/** The default token endpoint, used when the key has no `token_uri`. */
	public const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

	/** The read-only Analytics scope, the default for reporting. */
	public const SCOPE_READONLY = 'https://www.googleapis.com/auth/analytics.readonly';

	/** The full Analytics scope, for Admin API writes. */
	public const SCOPE_EDIT = 'https://www.googleapis.com/auth/analytics.edit';

	/** The JWT lifetime Google accepts, in seconds. */
	public const TOKEN_LIFETIME = 3600;

	/** The margin before expiry at which a token is refreshed, in seconds. */
	public const REFRESH_MARGIN = 60;

	/** The cache key prefix of a stored token. */
	public const CACHE_PREFIX = 'belisoful/ganalytics:token:';

	/** @var ?string The path of the JSON key file. */
	private ?string $_keyFile = null;

	/** @var ?array<string, mixed> The decoded key. */
	private ?array $_key = null;

	/** @var string[] The OAuth scopes requested. */
	private array $_scopes = [self::SCOPE_READONLY];

	/** @var float The token request timeout, in seconds. */
	private float $_timeout = 10.0;

	/** @var ?string The current access token. */
	private ?string $_token = null;

	/** @var int The clock time the current token expires at. */
	private int $_expires = 0;

	/**
	 * Returns a valid access token, from memory, the application cache, or a new token exchange.
	 * @throws TConfigurationException When no key is configured.
	 * @throws TInvalidDataValueException When the key lacks its fields or its private key does not load.
	 * @throws GAnalyticsApiException When the token endpoint refuses the exchange.
	 * @return string The bearer token.
	 */
	public function getAccessToken(): string
	{
		$now = $this->getClock()->time();
		if ($this->_token !== null && $this->_expires - static::REFRESH_MARGIN > $now) {
			return $this->_token;
		}
		$cache = Prado::getApplication()?->getCache();
		$cacheKey = $this->getCacheKey();
		if ($cache !== null && ($cached = $cache->get($cacheKey)) !== false && \is_array($cached)
			&& isset($cached['token'], $cached['expires']) && $cached['expires'] - static::REFRESH_MARGIN > $now) {
			$this->_token = (string) $cached['token'];
			$this->_expires = (int) $cached['expires'];
			return $this->_token;
		}
		[$this->_token, $this->_expires] = $this->requestToken($now);
		if ($cache !== null) {
			$cache->set($cacheKey, ['token' => $this->_token, 'expires' => $this->_expires], \max(1, $this->_expires - $now));
		}
		return $this->_token;
	}

	/**
	 * Exchanges a freshly signed JWT for an access token at the key's token endpoint.
	 * @param int $now The current clock time.
	 * @throws GAnalyticsApiException When the endpoint answers with an error or without a token.
	 * @return array{0: string, 1: int} The token and its expiry time.
	 */
	protected function requestToken(int $now): array
	{
		$key = $this->getKey();
		$body = \http_build_query([
			'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
			'assertion' => $this->createAssertion($now),
		]);
		[$status, $response] = $this->transport('POST', $this->getTokenUri(), ['Content-Type: application/x-www-form-urlencoded'], $body, $this->getTimeout());
		$data = \is_string($response) ? \json_decode($response, true) : null;
		if ($status < 200 || $status >= 300 || !\is_array($data) || !isset($data['access_token'])) {
			throw new GAnalyticsApiException($status, \is_array($data) ? $data : [], \is_array($data) ? ($data['error_description'] ?? $data['error'] ?? null) : null);
		}
		$expiresIn = (int) ($data['expires_in'] ?? static::TOKEN_LIFETIME);
		return [(string) $data['access_token'], $now + $expiresIn];
	}

	/**
	 * Creates the signed JWT assertion: the RS256 header, the claims (`iss`, `scope`, `aud`, `iat`,
	 * `exp`), and the signature over both with the key's private key.
	 * @param int $now The current clock time, the `iat` claim.
	 * @throws TInvalidDataValueException When the private key does not load or signing fails.
	 * @return string The assertion.
	 */
	public function createAssertion(int $now): string
	{
		$key = $this->getKey();
		$header = static::base64UrlEncode(\json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
		$claims = static::base64UrlEncode(\json_encode([
			'iss' => $key['client_email'],
			'scope' => \implode(' ', $this->getScopes()),
			'aud' => $this->getTokenUri(),
			'iat' => $now,
			'exp' => $now + static::TOKEN_LIFETIME,
		]));
		$input = $header . '.' . $claims;
		$private = \openssl_pkey_get_private((string) $key['private_key']);
		if ($private === false || !\openssl_sign($input, $signature, $private, OPENSSL_ALGO_SHA256)) {
			throw new TInvalidDataValueException('ganalytics_credentials_key_invalid', (string) ($key['client_email'] ?? ''));
		}
		return $input . '.' . static::base64UrlEncode($signature);
	}

	/**
	 * Encodes bytes as base64url without padding (RFC 7515).
	 * @param string $data The bytes.
	 * @return string The encoding.
	 */
	public static function base64UrlEncode(string $data): string
	{
		return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
	}

	/**
	 * Decodes base64url (RFC 7515).
	 * @param string $data The encoding.
	 * @return string The bytes.
	 */
	public static function base64UrlDecode(string $data): string
	{
		return (string) \base64_decode(\strtr($data, '-_', '+/') . \str_repeat('=', (4 - \strlen($data) % 4) % 4), true);
	}

	/**
	 * Forgets the current token, in memory and in the application cache, so the next
	 * {@see getAccessToken()} exchanges a new one.
	 */
	public function clearToken(): void
	{
		$this->forgetToken();
		Prado::getApplication()?->getCache()?->delete($this->getCacheKey());
	}

	/**
	 * Forgets the token held in memory; a cached token of the same account and scopes is reused.
	 */
	protected function forgetToken(): void
	{
		$this->_token = null;
		$this->_expires = 0;
	}

	/**
	 * @return string The application cache key of the token: the account and the scopes.
	 */
	protected function getCacheKey(): string
	{
		return static::CACHE_PREFIX . \sha1(($this->_key['client_email'] ?? $this->_keyFile ?? '') . '|' . \implode(' ', $this->getScopes()));
	}

	/**
	 * Returns the decoded key, loading {@see getKeyFile() KeyFile} on first use.
	 * @throws TConfigurationException When neither a key nor a key file is set, or the file cannot be read.
	 * @throws TInvalidDataValueException When the key is not a service account key with `client_email` and `private_key`.
	 * @return array<string, mixed> The key.
	 */
	public function getKey(): array
	{
		if ($this->_key === null) {
			if ($this->_keyFile === null) {
				throw new TConfigurationException('ganalytics_credentials_unconfigured', static::class);
			}
			$json = @\file_get_contents($this->_keyFile);
			if ($json === false) {
				throw new TConfigurationException('ganalytics_credentials_keyfile_unreadable', $this->_keyFile);
			}
			$this->setKey($json);
		}
		return $this->_key;
	}

	/**
	 * Sets the service account key, as the decoded array or the JSON text of the key file.
	 * @param mixed $value The key; empty for none.
	 * @throws TInvalidDataValueException When the value is not a service account key with `client_email` and `private_key`.
	 */
	public function setKey($value)
	{
		$value = TPropertyValue::ensureNullIfEmpty($value);
		if ($value === null) {
			$this->_key = null;
			return;
		}
		$key = \is_array($value) ? $value : \json_decode(\trim((string) TPropertyValue::ensureString($value)), true);
		if (!\is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
			throw new TInvalidDataValueException('ganalytics_credentials_key_invalid', \is_array($key) ? (string) ($key['client_email'] ?? '') : '');
		}
		$this->_key = $key;
		$this->forgetToken();
	}

	/**
	 * @return ?string The path of the JSON key file, or null when the key is set by content.
	 */
	public function getKeyFile(): ?string
	{
		return $this->_keyFile;
	}

	/**
	 * Sets the path of the JSON key file. A relative path is resolved against the application's
	 * base path when an application runs. The file is read on first use.
	 * @param mixed $value The path; empty for none.
	 */
	public function setKeyFile($value)
	{
		$value = TPropertyValue::ensureNullIfEmpty($value);
		if ($value === null) {
			$this->_keyFile = null;
			return;
		}
		$path = \trim((string) TPropertyValue::ensureString($value));
		if (!\str_starts_with($path, '/') && !\preg_match('/^[A-Za-z]:[\\\\\/]/', $path) && ($app = Prado::getApplication()) !== null) {
			$path = $app->getBasePath() . DIRECTORY_SEPARATOR . $path;
		}
		$this->_keyFile = $path;
		$this->_key = null;
		$this->forgetToken();
	}

	/**
	 * @return string[] The OAuth scopes requested. Defaults to {@see SCOPE_READONLY}.
	 */
	public function getScopes(): array
	{
		return $this->_scopes;
	}

	/**
	 * @param mixed $value The scopes, as an array or a comma- or space-separated string; empty restores the default.
	 */
	public function setScopes($value)
	{
		$scopes = [];
		foreach (TPropertyValue::ensureArray(\is_string($value) ? \str_replace(' ', ',', $value) : $value, TPropertyValue::ARRAY_SKIP_EMPTY) as $scope) {
			if (\trim((string) $scope) !== '') {
				$scopes[] = \trim((string) $scope);
			}
		}
		$this->_scopes = \count($scopes) > 0 ? \array_values(\array_unique($scopes)) : [static::SCOPE_READONLY];
		$this->forgetToken();
	}

	/**
	 * @return string The token endpoint: the key's `token_uri`, or {@see DEFAULT_TOKEN_URI}.
	 */
	public function getTokenUri(): string
	{
		return (string) ($this->_key['token_uri'] ?? static::DEFAULT_TOKEN_URI);
	}

	/**
	 * @return float The token request timeout in seconds. Defaults to 10.
	 */
	public function getTimeout(): float
	{
		return $this->_timeout;
	}

	/**
	 * @param mixed $value The token request timeout in seconds; a positive number.
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
