<?php

/**
 * GAnalyticsPrivacyConsentProvider class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/belisoful/GAnalytics/blob/main/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\TApplication;
use Prado\TModule;
use Prado\TPropertyValue;

/**
 * GAnalyticsPrivacyConsentProvider class.
 *
 * GAnalyticsPrivacyConsentProvider binds a consent management module (`TConsentModule` of
 * `belisoful/prado-privacy`, or any module with the same surface) to Google Consent Mode. It is the
 * module's `ConsentProvider`: the visitor's consent categories become Google consent types for the
 * `gtag('consent', 'default', …)` of each page, and every change the visitor makes (banner,
 * preferences, withdrawal) becomes a `gtag('consent', 'update', …)` on the same page or callback.
 *
 * | Category | Google consent types |
 * |---|---|
 * | `analytics` | `analytics_storage` |
 * | `marketing` | `ad_storage`, `ad_user_data`, `ad_personalization` |
 * | `functional` | `functionality_storage` |
 * | `personalization` | `personalization_storage` |
 * | (always) | `security_storage` = `granted` |
 *
 * `CategoryMap` replaces the mapping. An `undecided` category leaves its types out, so
 * `GAnalyticsModule::ConsentDefaults` applies to them: with Google's advanced consent mode the
 * defaults deny them, the tag loads, and Google receives cookieless pings until the visitor
 * decides. For basic consent mode, keep `GAnalyticsModule` disabled until analytics is granted.
 *
 * ```xml
 * <module id="belisoful/prado-privacy" Version="2026-09" />
 * <module id="privacy-analytics" class="belisoful\GAnalytics\GAnalyticsPrivacyConsentProvider" />
 * <module id="belisoful/ganalytics" MeasurementId="G-XXXXXXXXXX" ConsentProvider="privacy-analytics"
 *     ConsentDefaults='{"analytics_storage": "denied", "ad_storage": "denied", "ad_user_data": "denied", "ad_personalization": "denied"}' />
 * ```
 *
 * The consent module is `ConsentModule` (a module id) or the application's first module with
 * `getConsent()`, `setConsent()` and an `onConsentChanged` event; the analytics module is
 * `AnalyticsModule` or the first `GAnalyticsModule`. No class of `belisoful/prado-privacy` is
 * referenced, so neither package depends on the other.
 *
 * `GAnalyticsModule::updateConsent()` calls made by application code are written back to the
 * consent module through {@see setConsentState()} with the source `api`: a category is granted
 * when every one of its types is granted.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GAnalyticsPrivacyConsentProvider extends TModule implements IGAnalyticsConsentStore
{
	/** The default mapping: category => Google consent types. */
	public const CATEGORY_MAP = [
		'analytics' => ['analytics_storage'],
		'marketing' => ['ad_storage', 'ad_user_data', 'ad_personalization'],
		'functional' => ['functionality_storage'],
		'personalization' => ['personalization_storage'],
	];

	/** The consent state that is always granted. */
	public const ALWAYS_GRANTED = ['security_storage' => 'granted'];

	/** @var string the id of the consent module */
	private string $_consentModule = '';

	/** @var string the id of the analytics module */
	private string $_analyticsModule = '';

	/** @var array<string, string[]> category => Google consent types */
	private array $_categoryMap = self::CATEGORY_MAP;

	/** @var bool whether consent changes are sent to Google on the same page */
	private bool $_updateOnChange = true;

	/** @var ?array{0: TModule, 1: \Closure, 2: \Closure} the consent module, its getConsent() and its setConsent() */
	private ?array $_binding = null;

	/** @var bool whether a change is being forwarded, so the write-back does not loop */
	private bool $_forwarding = false;

	/**
	 * Attaches to the consent module's `onConsentChanged`, now or once the application initialized.
	 * @param mixed $config the module configuration
	 */
	public function init($config)
	{
		parent::init($config);
		$app = $this->getApplication();
		if ($app->hasStateFlag(TApplication::STATE_INITIALIZED)) {
			$this->attachConsentHandler($app, null);
		} else {
			$app->attachEventHandler('onInitComplete', [$this, 'attachConsentHandler']);
		}
	}

	/**
	 * Attaches {@see forwardConsentChange()} to the consent module's `onConsentChanged` when `UpdateOnChange` is on.
	 * @param mixed $sender the application
	 * @param mixed $param the event parameter
	 */
	public function attachConsentHandler($sender, $param): void
	{
		if ($this->getUpdateOnChange()) {
			$this->getConsentModule()->attachEventHandler('onConsentChanged', [$this, 'forwardConsentChange']);
		}
	}

	/**
	 * Sends a consent change to Google as `gtag('consent', 'update', …)`; an `onConsentChanged` handler.
	 * @param mixed $sender the consent module
	 * @param mixed $param the change; `getCurrent()` gives category => state
	 */
	public function forwardConsentChange($sender, $param): void
	{
		$analytics = $this->getAnalyticsModule();
		if ($analytics === null) {
			return;
		}
		$this->_forwarding = true;
		try {
			$analytics->updateConsent($this->mapConsent($param->getCurrent()));
		} finally {
			$this->_forwarding = false;
		}
	}

	/**
	 * @return array<string, string> the visitor's decided consent as Google consent types, `granted` or `denied`, with `security_storage` granted
	 */
	public function getConsentState(): array
	{
		return $this->mapConsent(($this->bindConsentModule()[1])());
	}

	/**
	 * Writes Google consent types back to the consent module as a choice with the source `api`.
	 * A category takes a decision when any of its types is given: granted when every given type
	 * is granted. A call made while forwarding the module's own change is ignored.
	 * @param array<string, string> $state Google consent type => `granted` or `denied`
	 */
	public function setConsentState(array $state): void
	{
		if ($this->_forwarding) {
			return;
		}
		$choice = [];
		foreach ($this->getCategoryMap() as $category => $types) {
			$given = \array_intersect_key($state, \array_flip($types));
			if ($given !== []) {
				$choice[$category] = !\in_array('denied', $given, true);
			}
		}
		if ($choice !== []) {
			($this->bindConsentModule()[2])($choice, 'api');
		}
	}

	/**
	 * @param array<string, string> $consent category => `granted`, `denied` or `undecided`
	 * @return array<string, string> Google consent type => `granted` or `denied`; undecided categories are left out
	 */
	public function mapConsent(array $consent): array
	{
		$state = [];
		foreach ($this->getCategoryMap() as $category => $types) {
			$value = $consent[$category] ?? null;
			if ($value === 'granted' || $value === 'denied') {
				foreach ($types as $type) {
					$state[$type] = $value;
				}
			}
		}
		return $state + self::ALWAYS_GRANTED;
	}

	/**
	 * @throws TConfigurationException when no consent module is found
	 * @return TModule the consent module: `ConsentModule`, or the first module with `getConsent()`, `setConsent()` and `onConsentChanged`
	 */
	public function getConsentModule(): TModule
	{
		return $this->bindConsentModule()[0];
	}

	/**
	 * Finds the consent module once, and binds its `getConsent()` and `setConsent()`: the module is
	 * matched by its surface, so the provider references no class of the consent package.
	 * @throws TConfigurationException when no consent module is found
	 * @return array{0: TModule, 1: \Closure, 2: \Closure} the module, its getConsent() and its setConsent()
	 */
	protected function bindConsentModule(): array
	{
		if ($this->_binding !== null) {
			return $this->_binding;
		}
		$app = $this->getApplication();
		$candidates = $this->_consentModule !== '' ? [$app->getModule($this->_consentModule)] : $app->getModules();
		foreach ($candidates as $module) {
			if ($module instanceof TModule && \method_exists($module, 'getConsent') && \method_exists($module, 'setConsent') && $module->hasEvent('onConsentChanged')) {
				return $this->_binding = [$module, \Closure::fromCallable([$module, 'getConsent']), \Closure::fromCallable([$module, 'setConsent'])];
			}
		}
		throw new TConfigurationException('ganalytics_module_invalid', $this->_consentModule ?: '(any)', 'consent module (getConsent, setConsent, onConsentChanged)');
	}

	/**
	 * @param mixed $value the id of the consent module; empty finds one
	 * @return static the provider
	 */
	public function setConsentModule($value): static
	{
		$this->_consentModule = \trim(TPropertyValue::ensureString($value));
		$this->_binding = null;
		return $this;
	}

	/**
	 * @return ?GAnalyticsModule the analytics module: `AnalyticsModule`, or the first `GAnalyticsModule`; null when none
	 */
	public function getAnalyticsModule(): ?GAnalyticsModule
	{
		$app = $this->getApplication();
		if ($this->_analyticsModule !== '') {
			$module = $app->getModule($this->_analyticsModule);
			return $module instanceof GAnalyticsModule ? $module : null;
		}
		foreach ($app->getModulesByType(GAnalyticsModule::class) as $module) {
			return $module;
		}
		return null;
	}

	/**
	 * @param mixed $value the id of the analytics module; empty finds the first `GAnalyticsModule`
	 * @return static the provider
	 */
	public function setAnalyticsModule($value): static
	{
		$this->_analyticsModule = \trim(TPropertyValue::ensureString($value));
		return $this;
	}

	/**
	 * @return array<string, string[]> category => Google consent types
	 */
	public function getCategoryMap(): array
	{
		return $this->_categoryMap;
	}

	/**
	 * @param mixed $value category => type or types, as an array or a JSON object; empty restores the default
	 * @throws TInvalidDataValueException when the value is not a map, or a type is not a Google consent type
	 * @return static the provider
	 */
	public function setCategoryMap($value): static
	{
		if ($value === null || $value === '' || $value === []) {
			$this->_categoryMap = self::CATEGORY_MAP;
			return $this;
		}
		$map = \is_array($value) ? $value : \json_decode((string) $value, true);
		if (!\is_array($map)) {
			throw new TInvalidDataValueException('ganalytics_category_map_invalid', (string) $value);
		}
		$normalized = [];
		foreach ($map as $category => $types) {
			foreach ((array) $types as $type) {
				GAnalyticsCookieConsentProvider::normalizeState([$type => 'granted'], true);
				$normalized[(string) $category][] = (string) $type;
			}
		}
		$this->_categoryMap = $normalized;
		return $this;
	}

	/**
	 * @return bool whether consent changes are sent to Google on the page or callback that makes them
	 */
	public function getUpdateOnChange(): bool
	{
		return $this->_updateOnChange;
	}

	/**
	 * @param mixed $value whether consent changes are sent to Google on the page or callback that makes them
	 * @return static the provider
	 */
	public function setUpdateOnChange($value): static
	{
		$this->_updateOnChange = TPropertyValue::ensureBoolean($value);
		return $this;
	}
}
