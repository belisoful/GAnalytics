<?php

/**
 * GAnalyticsCookieConsentProvider class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\TModule;
use Prado\TPropertyValue;
use Prado\Util\Clock\TApplicationClockAwareTrait;
use Prado\Web\THttpCookie;

/**
 * GAnalyticsCookieConsentProvider class.
 *
 * Keeps a visitor's Consent Mode state in one first-party cookie: a JSON object of consent types
 * to `granted` or `denied`. {@see getConsentState()} reads the request cookie;
 * {@see setConsentState()} merges a choice and writes the cookie on the response, with
 * {@see setExpires() Expires} days of life, `SameSite=Lax`, `HttpOnly`, and `Secure` on a secure
 * request. A consent banner calls {@see GAnalyticsModule::updateConsent()} with the visitor's
 * choice, which both updates the running tag and, through this store, persists it.
 *
 * The provider is a module, so it is configured beside the analytics module and named by
 * {@see GAnalyticsModule::setConsentProvider() ConsentProvider}:
 *
 * ```xml
 * <module id="consent" class="belisoful\GAnalytics\GAnalyticsCookieConsentProvider" CookieName="site_consent" Expires="180" />
 * <module id="belisoful/ganalytics" MeasurementId="G-XXXXXXXXXX" ConsentProvider="consent"
 *     ConsentDefaults='{"analytics_storage": "denied", "ad_storage": "denied"}' />
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GAnalyticsCookieConsentProvider extends TModule implements IGAnalyticsConsentStore
{
	use TApplicationClockAwareTrait;

	/** The default cookie name. */
	public const DEFAULT_COOKIE_NAME = 'ganalytics_consent';

	/** The consent types Google defines. */
	public const CONSENT_TYPES = ['ad_storage', 'ad_user_data', 'ad_personalization', 'analytics_storage', 'functionality_storage', 'personalization_storage', 'security_storage'];

	/** @var string The cookie name. */
	private string $_cookieName = self::DEFAULT_COOKIE_NAME;

	/** @var int The cookie life in days. */
	private int $_expires = 365;

	/** @var ?array<string, string> The state of this request, read once and updated by {@see setConsentState()}. */
	private ?array $_state = null;

	/**
	 * Returns the visitor's stored consent state from the request cookie.
	 * @return array<string, string> The consent types, each `granted` or `denied`; empty without a cookie.
	 */
	public function getConsentState(): array
	{
		if ($this->_state === null) {
			$cookie = $this->getApplication()->getRequest()->getCookies()->findCookieByName($this->getCookieName());
			$this->_state = $cookie === null ? [] : static::normalizeState(\json_decode((string) $cookie->getValue(), true));
		}
		return $this->_state;
	}

	/**
	 * Merges a choice over the stored state and writes the cookie on the response.
	 * @param array<string, string> $state The consent types, each `granted` or `denied`.
	 * @throws TInvalidDataValueException When a value is neither `granted` nor `denied`.
	 */
	public function setConsentState(array $state): void
	{
		$state = static::normalizeState($state, true);
		$this->_state = \array_merge($this->getConsentState(), $state);
		$this->getApplication()->getResponse()->getCookies()->add($this->createCookie($this->_state));
	}

	/**
	 * Builds the consent cookie for a state.
	 * @param array<string, string> $state The consent state.
	 * @return THttpCookie The cookie: JSON value, {@see getExpires() Expires} days, `SameSite=Lax`, `HttpOnly`, `Secure` on a secure request.
	 */
	public function createCookie(array $state): THttpCookie
	{
		$cookie = new THttpCookie($this->getCookieName(), \json_encode($state, JSON_UNESCAPED_SLASHES));
		$cookie->setPath('/');
		$cookie->setExpire($this->getClock()->time() + $this->getExpires() * 86400);
		$cookie->setHttpOnly(true);
		$cookie->setSameSite(\Prado\Web\THttpCookieSameSite::Lax);
		$cookie->setSecure($this->getApplication()->getRequest()->getIsSecureConnection());
		return $cookie;
	}

	/**
	 * Keeps the known consent types with a `granted` or `denied` value.
	 * @param mixed $state The decoded state.
	 * @param bool $strict Whether an unknown type or value throws instead of being dropped.
	 * @throws TInvalidDataValueException With $strict, when a type is unknown or a value is neither `granted` nor `denied`.
	 * @return array<string, string> The normalized state.
	 */
	public static function normalizeState(mixed $state, bool $strict = false): array
	{
		$result = [];
		foreach (\is_array($state) ? $state : [] as $type => $value) {
			$value = \is_string($value) ? \strtolower(\trim($value)) : $value;
			$known = \in_array($type, static::CONSENT_TYPES, true);
			$valid = \in_array($value, ['granted', 'denied'], true);
			if ($known && $valid) {
				$result[$type] = $value;
			} elseif ($strict) {
				throw new TInvalidDataValueException('ganalytics_consent_invalid', (string) $type, \is_scalar($value) ? (string) $value : \get_debug_type($value));
			}
		}
		return $result;
	}

	/**
	 * @return string The cookie name. Defaults to {@see DEFAULT_COOKIE_NAME}.
	 */
	public function getCookieName(): string
	{
		return $this->_cookieName;
	}

	/**
	 * @param mixed $value The cookie name; empty restores the default.
	 * @throws TInvalidDataValueException When the name has characters a cookie name cannot carry.
	 */
	public function setCookieName($value)
	{
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
		$name = ($value === null) ? static::DEFAULT_COOKIE_NAME : \trim((string) TPropertyValue::ensureString($value));
		if (!\preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name)) {
			throw new TInvalidDataValueException('ganalytics_cookiename_invalid', $name);
		}
		$this->_cookieName = $name;
		$this->_state = null;
	}

	/**
	 * @return int The cookie life in days. Defaults to 365.
	 */
	public function getExpires(): int
	{
		return $this->_expires;
	}

	/**
	 * @param mixed $value The cookie life in days; at least 1.
	 * @throws TInvalidDataValueException When the value is below 1.
	 */
	public function setExpires($value)
	{
		$days = TPropertyValue::ensureInteger($value);
		if ($days < 1) {
			throw new TInvalidDataValueException('ganalytics_expires_invalid', (string) $days);
		}
		$this->_expires = $days;
	}
}
