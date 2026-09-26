<?php

/**
 * GAnalyticsModule class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TApplication;
use Prado\TEventParameter;
use Prado\TPropertyValue;
use Prado\Util\Log\TLogger;
use Prado\Util\TPluginModule;
use Prado\Web\Javascripts\TJavaScript;
use Prado\Web\Services\TPageService;
use Prado\Web\UI\TPage;

/**
 * GAnalyticsModule class.
 *
 * Adds the Google tag (gtag.js) for Google Analytics 4 to every page of a PRADO application.
 * Once configured, the module registers the asynchronous `gtag/js` script file and the
 * `gtag('config', …)` block in the page head of each page the {@see TPageService} runs, so a
 * page view is measured under the configured {@see getMeasurementId() MeasurementId}.
 *
 * The Measurement ID is taken from the module property, or, when the property is unset, from
 * the application parameter named by {@see getMeasurementIdParameter() MeasurementIdParameter}
 * (`GoogleAnalyticsMeasurementId` by default), so one configuration can serve several
 * deployments. A page runs without the tag when the module is {@see getEnabled() disabled},
 * when no Measurement ID resolves (logged as a notice), or when a handler of
 * {@see onPreRegisterScript} stops the event.
 *
 * The tag is configured by these properties:
 *  - {@see setDebugMode() DebugMode} sets `debug_mode: true`, so hits show in the GA4 DebugView;
 *  - {@see setSendPageView() SendPageView} `false` sets `send_page_view: false`, for applications
 *    that send their own `page_view` events;
 *  - {@see setConfigOptions() ConfigOptions} holds any other `gtag('config')` parameters, as an
 *    array or a JSON object string;
 *  - {@see setConsentDefaults() ConsentDefaults} emits a `gtag('consent', 'default', …)` call
 *    before the configuration, for Consent Mode;
 *  - {@see setTagUrl() TagUrl} points the script file at a first-party or server-side tagging host.
 *
 * The script is registered in the page head, which {@see \Prado\Web\UI\WebControls\THead} renders.
 * A page without a `THead` gets the same script at the beginning of its form instead. A callback
 * request renders neither, so the tag loads once per page and never again on a callback.
 *
 * The extension is a Composer package with an `extra.prado.bootstrap` entry, so the module is
 * configured by its package name, without a class. Its Prado3 short name `GAnalyticsModule` comes
 * from `config/classMap.json` and its error codes from `config/errorMessages.txt`, both registered
 * by Composer from `extra.prado`.
 *
 * ```xml
 * <modules>
 *     <module id="belisoful/ganalytics" MeasurementId="G-XXXXXXXXXX" />
 * </modules>
 * ```
 *
 * ```php
 * 'modules' => [
 *     'belisoful/ganalytics' => ['properties' => [
 *         'MeasurementIdParameter' => 'GA4MeasurementId',
 *         'ConsentDefaults' => ['ad_storage' => 'denied', 'analytics_storage' => 'denied'],
 *     ]],
 * ],
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GAnalyticsModule extends TPluginModule
{
	/** The default application parameter that holds the Measurement ID. */
	public const MEASUREMENT_ID_PARAMETER = 'GoogleAnalyticsMeasurementId';

	/** The default Google tag script URL; the Measurement ID is appended as its `id` query parameter. */
	public const DEFAULT_TAG_URL = 'https://www.googletagmanager.com/gtag/js';

	/** The key the script file and the script block are registered under on the page's client script manager. */
	public const SCRIPT_KEY = 'gtag';

	/**
	 * The accepted Measurement ID form: a one to three letter prefix (`G`, `AW`, `DC`, `GT`, `UA`), a dash,
	 * and dash-separated groups of capital letters and digits.
	 */
	public const MEASUREMENT_ID_PATTERN = '/^[A-Z]{1,3}-[A-Z0-9]+(?:-[A-Z0-9]+)*$/';

	/** The longest Measurement ID accepted. */
	public const MEASUREMENT_ID_MAX_LENGTH = 40;

	/** @var ?string The Measurement ID set on the module; the application parameter is read when null. */
	private ?string $_measurementId = null;

	/** @var string The application parameter that holds the Measurement ID. */
	private string $_measurementIdParameter = self::MEASUREMENT_ID_PARAMETER;

	/** @var bool Whether the tag is registered on pages. */
	private bool $_enabled = true;

	/** @var bool Whether `debug_mode: true` is set in the tag configuration. */
	private bool $_debugMode = false;

	/** @var bool Whether the tag sends its automatic `page_view` event. */
	private bool $_sendPageView = true;

	/** @var array<string, mixed> Additional `gtag('config')` parameters. */
	private array $_configOptions = [];

	/** @var array<string, mixed> The `gtag('consent', 'default')` parameters; none when empty. */
	private array $_consentDefaults = [];

	/** @var string The Google tag script URL, without the `id` parameter. */
	private string $_tagUrl = self::DEFAULT_TAG_URL;

	// =========================================================================
	// Lifecycle
	// =========================================================================

	/**
	 * Initializes the module and hooks the page service. When the application is already
	 * initialized (a lazily loaded module) the page service is hooked at once; otherwise the hook
	 * waits for {@see TApplication::onInitComplete}, when the service exists.
	 * @param null|array|\Prado\Xml\TXmlElement $config The module configuration.
	 */
	public function init($config)
	{
		parent::init($config);

		$app = $this->getApplication();
		if ($app->hasStateFlag(TApplication::STATE_INITIALIZED)) {
			$this->attachPageServiceHandler($app, null);
		} else {
			$app->attachEventHandler('onInitComplete', [$this, 'attachPageServiceHandler']);
		}
	}

	/**
	 * Attaches {@see preRunPageHandler()} to the {@see TPageService::onPreRunPage} event of the
	 * running service. A service that is not a page service is left alone.
	 * @param mixed $sender The application raising {@see TApplication::onInitComplete}.
	 * @param mixed $param The event parameter.
	 */
	public function attachPageServiceHandler($sender, $param)
	{
		$service = $this->getService();
		if ($service instanceof TPageService) {
			$service->attachEventHandler('onPreRunPage', [$this, 'preRunPageHandler']);
		}
	}

	/**
	 * The {@see TPageService::onPreRunPage} handler: registers the tag on the page about to run.
	 * @param mixed $sender The page service raising the event.
	 * @param mixed $param The {@see TPage} about to run.
	 */
	public function preRunPageHandler($sender, $param)
	{
		if ($param instanceof TPage) {
			$this->registerPageScripts($param);
		}
	}

	/**
	 * Registers the Google tag script file and configuration block in the head of a page.
	 * Registration is skipped, returning false, when the module is disabled, when no Measurement ID
	 * resolves (a notice is logged), or when a handler of {@see onPreRegisterScript} stops the
	 * event. A page without a `THead` receives the scripts at the beginning of its form at
	 * {@see TPage::onPreRenderComplete} instead; a callback request renders neither.
	 * @param TPage $page The page to register the tag on.
	 * @throws TInvalidDataValueException When the Measurement ID read from the application parameter is not valid.
	 * @return bool Whether the tag was registered.
	 */
	public function registerPageScripts(TPage $page): bool
	{
		if (!$this->getEnabled()) {
			return false;
		}
		if ($this->getMeasurementId() === null) {
			Prado::log('No Google tag Measurement ID is configured; the page runs without the tag.', TLogger::NOTICE, static::class);
			return false;
		}
		$param = new TEventParameter($page);
		$this->onPreRegisterScript($param);
		if ($param->getStopped()) {
			return false;
		}
		$cs = $page->getClientScript();
		$cs->registerHeadScriptFile(static::SCRIPT_KEY, $this->getTagScriptUrl(), true);
		$cs->registerHeadScript(static::SCRIPT_KEY, $this->getTagScript());
		$page->attachEventHandler('onPreRenderComplete', [$this, 'preRenderCompleteHandler']);
		return true;
	}

	/**
	 * The {@see TPage::onPreRenderComplete} handler: a page without a `THead` never renders its
	 * head scripts, so the tag is registered at the beginning of the form instead. A callback
	 * request is left alone: its response would run the tag a second time.
	 * @param mixed $sender The page raising the event.
	 * @param mixed $param The event parameter.
	 */
	public function preRenderCompleteHandler($sender, $param)
	{
		if (!($sender instanceof TPage) || $sender->getHead() !== null || $sender->getIsCallback()) {
			return;
		}
		$cs = $sender->getClientScript();
		$cs->registerScriptFile(static::SCRIPT_KEY, $this->getTagScriptUrl());
		$cs->registerBeginScript(static::SCRIPT_KEY, $this->getTagScript());
	}

	/**
	 * Raised before the tag is registered on a page. The parameter carries the {@see TPage} as
	 * its Parameter; a handler that calls {@see TEventParameter::stopImmediatePropagation()}
	 * leaves the page without the tag, for example for pages a visitor's consent excludes.
	 * @param TEventParameter $param The event parameter, carrying the page.
	 */
	public function onPreRegisterScript($param)
	{
		$this->raiseEvent('onPreRegisterScript', $this, $param);
	}

	// =========================================================================
	// Script
	// =========================================================================

	/**
	 * Returns the Google tag script file URL: the {@see getTagUrl() TagUrl} with the Measurement ID
	 * as its `id` query parameter.
	 * @return string The script URL.
	 */
	public function getTagScriptUrl(): string
	{
		$url = $this->getTagUrl();
		return $url . (str_contains($url, '?') ? '&' : '?') . 'id=' . rawurlencode((string) $this->getMeasurementId());
	}

	/**
	 * Returns the inline Google tag script: the `dataLayer` bootstrap, the consent defaults when
	 * any are set, `gtag('js')`, and `gtag('config')` with the {@see getEffectiveConfigOptions()
	 * effective options}. Every value is JavaScript-encoded, so a Measurement ID or option value
	 * cannot break out of the script.
	 * @return string The script block, without `<script>` tags.
	 */
	public function getTagScript(): string
	{
		$lines = [
			'window.dataLayer = window.dataLayer || [];',
			'function gtag(){dataLayer.push(arguments);}',
		];
		$consent = $this->getConsentDefaults();
		if (count($consent) > 0) {
			$lines[] = "gtag('consent', 'default', " . TJavaScript::encode($consent) . ');';
		}
		$lines[] = "gtag('js', new Date());";
		$id = TJavaScript::quoteString((string) $this->getMeasurementId());
		$options = $this->getEffectiveConfigOptions();
		$lines[] = count($options) > 0
			? "gtag('config', {$id}, " . TJavaScript::encode($options) . ');'
			: "gtag('config', {$id});";
		return implode("\n", $lines);
	}

	/**
	 * Returns the `gtag('config')` parameters: the {@see getConfigOptions() ConfigOptions}, with
	 * `debug_mode` set when {@see getDebugMode() DebugMode} is on and `send_page_view` set to
	 * false when {@see getSendPageView() SendPageView} is off.
	 * @return array<string, mixed> The configuration parameters.
	 */
	public function getEffectiveConfigOptions(): array
	{
		$options = $this->getConfigOptions();
		if ($this->getDebugMode()) {
			$options['debug_mode'] = true;
		}
		if (!$this->getSendPageView()) {
			$options['send_page_view'] = false;
		}
		return $options;
	}

	// =========================================================================
	// Properties
	// =========================================================================

	/**
	 * Returns the Measurement ID: the one set on the module, otherwise the value of the
	 * {@see getMeasurementIdParameter() MeasurementIdParameter} application parameter.
	 * @throws TInvalidDataValueException When the application parameter value is not a valid Measurement ID.
	 * @return ?string The Measurement ID, or null when neither the module nor the parameter holds one.
	 */
	public function getMeasurementId(): ?string
	{
		if ($this->_measurementId !== null) {
			return $this->_measurementId;
		}
		$app = $this->getApplication();
		if ($app === null) {
			return null;
		}
		$value = $app->getParameters()->itemAt($this->getMeasurementIdParameter());
		if ($value === null || $value === '') {
			return null;
		}
		return $this->ensureMeasurementId($value);
	}

	/**
	 * Sets the Measurement ID, for example `G-XXXXXXXXXX`. An empty value unsets it, so the
	 * application parameter is read.
	 * @param mixed $value The Measurement ID, or empty for none.
	 * @throws TInvalidDataValueException When the value is not a valid Measurement ID.
	 */
	public function setMeasurementId($value)
	{
		$value = TPropertyValue::ensureNullIfEmpty($value);
		$this->_measurementId = ($value === null) ? null : $this->ensureMeasurementId($value);
	}

	/**
	 * Validates a Measurement ID against {@see MEASUREMENT_ID_PATTERN} and {@see MEASUREMENT_ID_MAX_LENGTH}.
	 * @param mixed $value The value to validate.
	 * @throws TInvalidDataValueException When the value is not a valid Measurement ID.
	 * @return string The Measurement ID, trimmed.
	 */
	protected function ensureMeasurementId($value): string
	{
		$id = trim((string) TPropertyValue::ensureString($value));
		if (strlen($id) > static::MEASUREMENT_ID_MAX_LENGTH || !preg_match(static::MEASUREMENT_ID_PATTERN, $id)) {
			throw new TInvalidDataValueException('ganalytics_measurementid_invalid', $id);
		}
		return $id;
	}

	/**
	 * @return string The application parameter read for the Measurement ID when none is set on the module.
	 */
	public function getMeasurementIdParameter(): string
	{
		return $this->_measurementIdParameter;
	}

	/**
	 * @param mixed $value The application parameter read for the Measurement ID; empty restores {@see MEASUREMENT_ID_PARAMETER}.
	 */
	public function setMeasurementIdParameter($value)
	{
		$value = TPropertyValue::ensureNullIfEmpty($value);
		$this->_measurementIdParameter = ($value === null) ? static::MEASUREMENT_ID_PARAMETER : (string) TPropertyValue::ensureString($value);
	}

	/**
	 * @return bool Whether the tag is registered on pages. Defaults to true.
	 */
	public function getEnabled(): bool
	{
		return $this->_enabled;
	}

	/**
	 * @param mixed $value Whether the tag is registered on pages.
	 */
	public function setEnabled($value)
	{
		$this->_enabled = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return bool Whether `debug_mode: true` is set in the tag configuration. Defaults to false.
	 */
	public function getDebugMode(): bool
	{
		return $this->_debugMode;
	}

	/**
	 * @param mixed $value Whether `debug_mode: true` is set, so hits show in the GA4 DebugView.
	 */
	public function setDebugMode($value)
	{
		$this->_debugMode = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return bool Whether the tag sends its automatic `page_view` event. Defaults to true.
	 */
	public function getSendPageView(): bool
	{
		return $this->_sendPageView;
	}

	/**
	 * @param mixed $value Whether the tag sends its automatic `page_view` event; false sets `send_page_view: false`.
	 */
	public function setSendPageView($value)
	{
		$this->_sendPageView = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return array<string, mixed> Additional `gtag('config')` parameters. Defaults to none.
	 */
	public function getConfigOptions(): array
	{
		return $this->_configOptions;
	}

	/**
	 * Sets additional `gtag('config')` parameters, such as `user_id`, `cookie_domain` or
	 * `anonymize_ip`. A string value is a JSON object; an empty value clears the options.
	 * @param mixed $value The parameters, as an array or a JSON object string.
	 * @throws TInvalidDataValueException When the value is neither an array nor a JSON object.
	 */
	public function setConfigOptions($value)
	{
		$this->_configOptions = $this->ensureOptions($value, 'ConfigOptions');
	}

	/**
	 * @return array<string, mixed> The `gtag('consent', 'default')` parameters. Defaults to none.
	 */
	public function getConsentDefaults(): array
	{
		return $this->_consentDefaults;
	}

	/**
	 * Sets the Consent Mode defaults emitted as `gtag('consent', 'default', …)` before the
	 * configuration, for example `{"ad_storage": "denied", "analytics_storage": "denied"}`.
	 * A string value is a JSON object; an empty value emits no consent call.
	 * @param mixed $value The consent parameters, as an array or a JSON object string.
	 * @throws TInvalidDataValueException When the value is neither an array nor a JSON object.
	 */
	public function setConsentDefaults($value)
	{
		$this->_consentDefaults = $this->ensureOptions($value, 'ConsentDefaults');
	}

	/**
	 * Converts a property value to a parameter map: an array is taken as is, a string is decoded
	 * as a JSON object, and null or a blank string is an empty map.
	 * @param mixed $value The property value.
	 * @param string $name The property name, for the error message.
	 * @throws TInvalidDataValueException When the value is neither an array nor a JSON object.
	 * @return array<string, mixed> The parameter map.
	 */
	protected function ensureOptions($value, string $name): array
	{
		if ($value === null) {
			return [];
		}
		if (is_array($value)) {
			return $value;
		}
		if (is_string($value) || $value instanceof \Stringable) {
			$json = trim((string) $value);
			if ($json === '') {
				return [];
			}
			$decoded = json_decode($json, true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}
		throw new TInvalidDataValueException('ganalytics_options_invalid', $name, is_scalar($value) || $value instanceof \Stringable ? (string) $value : get_debug_type($value));
	}

	/**
	 * @return string The Google tag script URL, without the `id` parameter. Defaults to {@see DEFAULT_TAG_URL}.
	 */
	public function getTagUrl(): string
	{
		return $this->_tagUrl;
	}

	/**
	 * Sets the Google tag script URL, for a first-party or server-side tagging host such as
	 * `https://metrics.example.com/gtag/js`. An empty value restores {@see DEFAULT_TAG_URL}.
	 * @param mixed $value An absolute http or https URL.
	 * @throws TInvalidDataValueException When the value is not an absolute http or https URL.
	 */
	public function setTagUrl($value)
	{
		$value = TPropertyValue::ensureNullIfEmpty($value);
		if ($value === null) {
			$this->_tagUrl = static::DEFAULT_TAG_URL;
			return;
		}
		$url = trim((string) TPropertyValue::ensureString($value));
		$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
		if (!in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false || str_contains($url, '#')) {
			throw new TInvalidDataValueException('ganalytics_tagurl_invalid', $url);
		}
		$this->_tagUrl = $url;
	}
}
