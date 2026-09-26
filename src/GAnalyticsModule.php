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
use Prado\Security\IUser;
use Prado\TApplication;
use Prado\TApplicationMode;
use Prado\TComponent;
use Prado\TEventParameter;
use Prado\TPropertyValue;
use Prado\Util\Log\TLogger;
use Prado\Util\TPluginModule;
use Prado\Web\HttpHeaders\TCspDirective;
use Prado\Web\HttpHeaders\THttpHeaderCsp;
use Prado\Web\HttpHeaders\THttpHeadersManager;
use Prado\Web\Javascripts\TJavaScript;
use Prado\Web\Services\TPageService;
use Prado\Web\THttpRequest;
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
 * when the application mode is outside {@see setEnabledModes() EnabledModes}, when no
 * Measurement ID resolves (logged as a notice), or when a handler of
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
 *  - {@see setAdditionalMeasurementIds() AdditionalMeasurementIds} configures further tags
 *    (a Google Ads `AW-` id, a second property) on the same page;
 *  - {@see setPagePathAsContentGroup() PagePathAsContentGroup} reports the PRADO page path
 *    (`Admin.Users`) as the GA4 `content_group`;
 *  - {@see setUserId() UserId} and {@see setUserIdFromUser() UserIdFromUser} set the GA4
 *    `user_id`; the latter derives it from the authenticated PRADO {@see IUser} as an HMAC of the
 *    user name under the security manager's validation key, so no name reaches Google;
 *  - {@see setTagUrl() TagUrl} and {@see setDataLayerName() DataLayerName} point the script file
 *    at a first-party or server-side tagging host and rename the data layer.
 *
 * Page code sends events through {@see trackEvent()}, {@see updateConsent()},
 * {@see setUserProperties()} and {@see gtag()}. A call queued during the page's life is
 * delivered at {@see TPage::onPreRenderComplete}: on a full page as a script block at the end of
 * the form, on a callback request through the page's {@see TPage::getCallbackClient() callback
 * client}, so an ActiveControl handler measures an event without a page load. A call marked
 * deferred, or made while no page is running or after the page's calls were delivered, is kept
 * in the session and delivered on the next page, which carries an event across a redirect. The
 * {@see GAnalyticsPageBehavior} class behavior, attached to {@see TPage} when
 * {@see setAttachPageBehavior() AttachPageBehavior} is true, offers the same methods on the page
 * (`$this->trackEvent(…)`).
 *
 * Events without a browser (a shell command, a cron job, an API request) go through the
 * Measurement Protocol: {@see sendEvent()} posts to Google with the
 * {@see setApiSecret() ApiSecret}, under the visitor's client id from the `_ga` cookie when the
 * request has one ({@see getClientId()}). {@see getMeasurementProtocol()} is the client for
 * batches.
 *
 * With {@see setAmendCsp() AmendCsp} (the default) the module adds Google's hosts to the
 * `script-src`, `connect-src` and `img-src` directives of every {@see THttpHeaderCsp} a
 * {@see THttpHeadersManager} in the application carries, when that directive or `default-src`
 * restricts the source, so a Content Security Policy keeps working with the tag. PRADO's per-request
 * CSP nonce is emitted on the tag's script elements by {@see TJavaScript}.
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
 *     <module id="belisoful/ganalytics" MeasurementId="G-XXXXXXXXXX" UserIdFromUser="true"
 *         PagePathAsContentGroup="true" EnabledModes="Normal, Performance" />
 * </modules>
 * ```
 *
 * ```php
 * 'modules' => [
 *     'belisoful/ganalytics' => ['properties' => [
 *         'MeasurementIdParameter' => 'GA4MeasurementId',
 *         'ConsentDefaults' => ['ad_storage' => 'denied', 'analytics_storage' => 'denied'],
 *         'ApiSecret' => getenv('GA4_API_SECRET'),
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

	/** The default data layer name of gtag.js. */
	public const DEFAULT_DATA_LAYER_NAME = 'dataLayer';

	/** The key the script file and the script block are registered under on the page's client script manager. */
	public const SCRIPT_KEY = 'gtag';

	/** The key the queued calls block is registered under on the page's client script manager. */
	public const CALLS_SCRIPT_KEY = 'gtag-calls';

	/** The name the {@see GAnalyticsPageBehavior} is attached to {@see TPage} under. */
	public const PAGE_BEHAVIOR_NAME = 'ganalytics';

	/** The session key holding the deferred calls. */
	public const SESSION_KEY = 'belisoful/ganalytics:deferred';

	/**
	 * The accepted Measurement ID form: a one to three letter prefix (`G`, `AW`, `DC`, `GT`, `UA`), a dash,
	 * and dash-separated groups of capital letters and digits.
	 */
	public const MEASUREMENT_ID_PATTERN = '/^[A-Z]{1,3}-[A-Z0-9]+(?:-[A-Z0-9]+)*$/';

	/** The longest Measurement ID accepted. */
	public const MEASUREMENT_ID_MAX_LENGTH = 40;

	/** The accepted data layer name form: a JavaScript identifier. */
	public const DATA_LAYER_NAME_PATTERN = '/^[A-Za-z_$][A-Za-z0-9_$]*$/';

	/** The accepted GA4 event name form: a letter, then up to 39 letters, digits or underscores. */
	public const EVENT_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,39}$/';

	/** The Google hosts a Content Security Policy needs for the tag, by directive. */
	public const CSP_SOURCES = [
		TCspDirective::ScriptSrc => ['https://*.googletagmanager.com'],
		TCspDirective::ConnectSrc => ['https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://*.googletagmanager.com'],
		TCspDirective::ImgSrc => ['https://*.google-analytics.com', 'https://*.googletagmanager.com'],
	];

	/** @var ?string The Measurement ID set on the module; the application parameter is read when null. */
	private ?string $_measurementId = null;

	/** @var string The application parameter that holds the Measurement ID. */
	private string $_measurementIdParameter = self::MEASUREMENT_ID_PARAMETER;

	/** @var string[] Further Measurement IDs configured on the page. */
	private array $_additionalMeasurementIds = [];

	/** @var bool Whether the tag is registered on pages. */
	private bool $_enabled = true;

	/** @var string[] The application modes the tag is registered in; all when empty. */
	private array $_enabledModes = [];

	/** @var bool Whether `debug_mode: true` is set in the tag configuration. */
	private bool $_debugMode = false;

	/** @var bool Whether the tag sends its automatic `page_view` event. */
	private bool $_sendPageView = true;

	/** @var bool Whether the page path is reported as the `content_group`. */
	private bool $_pagePathAsContentGroup = false;

	/** @var array<string, mixed> Additional `gtag('config')` parameters. */
	private array $_configOptions = [];

	/** @var array<string, mixed> The `gtag('consent', 'default')` parameters; none when empty. */
	private array $_consentDefaults = [];

	/** @var ?string The GA4 user id set for the request. */
	private ?string $_userId = null;

	/** @var bool Whether the user id is derived from the authenticated application user. */
	private bool $_userIdFromUser = false;

	/** @var string The Google tag script URL, without the `id` parameter. */
	private string $_tagUrl = self::DEFAULT_TAG_URL;

	/** @var string The data layer name. */
	private string $_dataLayerName = self::DEFAULT_DATA_LAYER_NAME;

	/** @var bool Whether the {@see GAnalyticsPageBehavior} is attached to TPage. */
	private bool $_attachPageBehavior = true;

	/** @var ?GAnalyticsPageBehavior The attached page behavior. */
	private ?GAnalyticsPageBehavior $_pageBehavior = null;

	/** @var bool Whether Google's hosts are added to the application's Content Security Policy. */
	private bool $_amendCsp = true;

	/** @var ?string The Measurement Protocol API secret. */
	private ?string $_apiSecret = null;

	/** @var ?GAnalyticsMeasurementProtocol The Measurement Protocol client, created on first use. */
	private ?GAnalyticsMeasurementProtocol $_measurementProtocol = null;

	/** @var array<int, array<int, mixed>> The gtag calls queued for the current page. */
	private array $_calls = [];

	/** @var ?TPage The page the tag is registered on in this request. */
	private ?TPage $_page = null;

	/** @var bool Whether the current page's calls were delivered. */
	private bool $_flushed = false;

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
	 * Hooks the application once every module exists: attaches {@see preRunPageHandler()} to the
	 * {@see TPageService::onPreRunPage} event of the running page service, attaches the
	 * {@see GAnalyticsPageBehavior} ({@see attachPageBehavior()}) and amends the Content Security
	 * Policy ({@see amendCspPolicies()}). A service that is not a page service is left alone.
	 * @param mixed $sender The application raising {@see TApplication::onInitComplete}.
	 * @param mixed $param The event parameter.
	 */
	public function attachPageServiceHandler($sender, $param)
	{
		$service = $this->getService();
		if ($service instanceof TPageService) {
			$service->attachEventHandler('onPreRunPage', [$this, 'preRunPageHandler']);
		}
		if ($this->getAttachPageBehavior()) {
			$this->attachPageBehavior();
		}
		if ($this->getAmendCsp()) {
			$this->amendCspPolicies();
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
	 * Registers the Google tag script file and configuration block in the head of a page, and
	 * makes the page the destination of the queued calls. Registration is skipped, returning
	 * false, when the module is {@see getIsActive() inactive}, when no Measurement ID resolves (a
	 * notice is logged), or when a handler of {@see onPreRegisterScript} stops the event. A page
	 * without a `THead` receives the scripts at the beginning of its form at
	 * {@see TPage::onPreRenderComplete} instead; a callback request renders neither.
	 * @param TPage $page The page to register the tag on.
	 * @throws TInvalidDataValueException When the Measurement ID read from the application parameter is not valid.
	 * @return bool Whether the tag was registered.
	 */
	public function registerPageScripts(TPage $page): bool
	{
		if (!$this->getIsActive()) {
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
		$cs->registerHeadScript(static::SCRIPT_KEY, $this->getTagScript($page));
		$page->attachEventHandler('onPreRenderComplete', [$this, 'preRenderCompleteHandler']);
		$this->_page = $page;
		$this->_flushed = false;
		return true;
	}

	/**
	 * The {@see TPage::onPreRenderComplete} handler: a page without a `THead` never renders its
	 * head scripts, so the tag is registered at the beginning of the form instead (a callback
	 * request is left alone: its response would run the tag a second time). Then the queued
	 * calls are delivered ({@see flushCalls()}).
	 * @param mixed $sender The page raising the event.
	 * @param mixed $param The event parameter.
	 */
	public function preRenderCompleteHandler($sender, $param)
	{
		if (!($sender instanceof TPage)) {
			return;
		}
		if ($sender->getHead() === null && !$sender->getIsCallback()) {
			$cs = $sender->getClientScript();
			$cs->registerScriptFile(static::SCRIPT_KEY, $this->getTagScriptUrl());
			$cs->registerBeginScript(static::SCRIPT_KEY, $this->getTagScript($sender));
		}
		$this->flushCalls($sender);
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
	// Page behavior and Content Security Policy
	// =========================================================================

	/**
	 * Attaches the {@see GAnalyticsPageBehavior} to {@see TPage} under {@see PAGE_BEHAVIOR_NAME},
	 * once. Every page created afterwards offers the behavior's methods.
	 * @throws \Prado\Exceptions\TInvalidOperationException When another behavior already holds the name on TPage.
	 * @return GAnalyticsPageBehavior The attached behavior.
	 */
	public function attachPageBehavior(): GAnalyticsPageBehavior
	{
		if ($this->_pageBehavior === null) {
			$behavior = new GAnalyticsPageBehavior($this);
			TComponent::attachClassBehavior(static::PAGE_BEHAVIOR_NAME, $behavior, TPage::class);
			$this->_pageBehavior = $behavior;
		}
		return $this->_pageBehavior;
	}

	/**
	 * Detaches the {@see GAnalyticsPageBehavior} from {@see TPage} when this module attached it.
	 * @return bool Whether a behavior was detached.
	 */
	public function detachPageBehavior(): bool
	{
		if ($this->_pageBehavior === null) {
			return false;
		}
		TComponent::detachClassBehavior(static::PAGE_BEHAVIOR_NAME, TPage::class);
		$this->_pageBehavior = null;
		return true;
	}

	/**
	 * @return ?GAnalyticsPageBehavior The page behavior this module attached, or null when none is attached.
	 */
	public function getPageBehavior(): ?GAnalyticsPageBehavior
	{
		return $this->_pageBehavior;
	}

	/**
	 * Adds the Google hosts ({@see getCspSources()}) to every {@see THttpHeaderCsp} of every
	 * {@see THttpHeadersManager} module in the application. A directive is amended when it exists,
	 * or when `default-src` exists (the directive is then created from `default-src`, so the
	 * fallback the browser applied stays in force); a policy that restricts neither is left alone,
	 * as is a header whose policies are a raw string.
	 * @return int The number of CSP headers amended.
	 */
	public function amendCspPolicies(): int
	{
		$app = $this->getApplication();
		$amended = 0;
		foreach ($app->getModulesByType(THttpHeadersManager::class) as $id => $manager) {
			$manager ??= $app->getModule($id);
			if (!($manager instanceof THttpHeadersManager)) {
				continue;
			}
			foreach ($manager->getHeadersByClass(THttpHeaderCsp::class) as $csp) {
				if ($this->amendCspHeader($csp)) {
					$amended++;
				}
			}
		}
		return $amended;
	}

	/**
	 * Adds the Google hosts to one CSP header; see {@see amendCspPolicies()}.
	 * @param THttpHeaderCsp $csp The header to amend.
	 * @return bool Whether a directive changed.
	 */
	public function amendCspHeader(THttpHeaderCsp $csp): bool
	{
		if (!$csp->isPoliciesStructured()) {
			return false;
		}
		$changed = false;
		foreach ($this->getCspSources() as $directive => $sources) {
			$current = $csp->getPolicy($directive) ?? $csp->getPolicy(TCspDirective::DefaultSrc);
			if ($current === null) {
				continue;
			}
			$tokens = preg_split('/\s+/', trim($current), -1, PREG_SPLIT_NO_EMPTY);
			$missing = array_diff($sources, $tokens);
			if (count($missing) === 0 && $csp->hasPolicy($directive)) {
				continue;
			}
			$csp->setPolicy($directive, implode(' ', array_merge($tokens, $missing)));
			$changed = true;
		}
		return $changed;
	}

	/**
	 * Returns the hosts a Content Security Policy needs for the tag, by directive:
	 * {@see CSP_SOURCES}, plus the {@see getTagUrl() TagUrl} origin under `script-src`,
	 * `connect-src` and `img-src` when it is not a Google Tag Manager host.
	 * @return array<string, string[]> The sources by directive name.
	 */
	public function getCspSources(): array
	{
		$sources = static::CSP_SOURCES;
		$parts = parse_url($this->getTagUrl());
		$host = strtolower($parts['host'] ?? '');
		if ($host !== '' && $host !== 'googletagmanager.com' && !str_ends_with($host, '.googletagmanager.com')) {
			$origin = ($parts['scheme'] ?? 'https') . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
			foreach ($sources as $directive => $list) {
				$sources[$directive][] = $origin;
			}
		}
		return $sources;
	}

	// =========================================================================
	// Calls
	// =========================================================================

	/**
	 * Queues a `gtag('event', $name, $params)` call for the page. On a full page the call is
	 * written at the end of the form; on a callback request it is run through the callback
	 * client. A deferred call is kept in the session for the next page, so an event survives a
	 * redirect; a call made while no page is registered, or after the page's calls were
	 * delivered, is deferred as well.
	 * @param string $name The GA4 event name: a letter, then up to 39 letters, digits or underscores.
	 * @param array<string, mixed> $params The event parameters.
	 * @param bool $deferred Whether the event is delivered on the next page instead of this one.
	 * @throws TInvalidDataValueException When the event name is not valid.
	 */
	public function trackEvent(string $name, array $params = [], bool $deferred = false): void
	{
		if (!static::isEventName($name)) {
			throw new TInvalidDataValueException('ganalytics_event_name_invalid', $name);
		}
		$this->queueCall(count($params) > 0 ? ['event', $name, $params] : ['event', $name], $deferred);
	}

	/**
	 * Queues a `gtag('consent', 'update', $params)` call, for a visitor's consent choice.
	 * @param array<string, mixed> $params The consent parameters, such as `['analytics_storage' => 'granted']`.
	 * @param bool $deferred Whether the call is delivered on the next page instead of this one.
	 */
	public function updateConsent(array $params, bool $deferred = false): void
	{
		$this->queueCall(['consent', 'update', $params], $deferred);
	}

	/**
	 * Queues a `gtag('set', 'user_properties', $properties)` call.
	 * @param array<string, mixed> $properties The user properties.
	 * @param bool $deferred Whether the call is delivered on the next page instead of this one.
	 */
	public function setUserProperties(array $properties, bool $deferred = false): void
	{
		$this->queueCall(['set', 'user_properties', $properties], $deferred);
	}

	/**
	 * Queues any `gtag(…)` call for the page, with the given arguments.
	 * @param mixed ...$args The gtag arguments; the first is the command, a string.
	 * @throws TInvalidDataValueException When there is no command or it is not a string.
	 */
	public function gtag(mixed ...$args): void
	{
		$this->queueCall($args, false);
	}

	/**
	 * Queues a gtag call; see {@see trackEvent()} for the delivery rules.
	 * @param array<int, mixed> $args The gtag arguments; the first is the command, a string.
	 * @param bool $deferred Whether the call is delivered on the next page instead of this one.
	 * @throws TInvalidDataValueException When there is no command or it is not a string.
	 */
	public function queueCall(array $args, bool $deferred = false): void
	{
		$args = array_values($args);
		if (count($args) === 0 || !is_string($args[0]) || trim($args[0]) === '') {
			throw new TInvalidDataValueException('ganalytics_gtag_call_invalid', json_encode($args, JSON_UNESCAPED_SLASHES) ?: '');
		}
		if ($deferred || $this->_page === null || $this->_flushed) {
			$this->storeDeferredCalls([$args]);
		} else {
			$this->_calls[] = $args;
		}
	}

	/**
	 * @return array<int, array<int, mixed>> The calls queued for the current page and not yet delivered.
	 */
	public function getQueuedCalls(): array
	{
		return $this->_calls;
	}

	/**
	 * Delivers the queued calls, the deferred ones from the session first, on a page: on a
	 * callback request each call runs through the page's callback client as
	 * `gtag(…)`; otherwise the calls are registered as one script block at the end of the form.
	 * Later calls in this request are deferred to the next page.
	 * @param TPage $page The page receiving the calls.
	 * @return int The number of calls delivered.
	 */
	public function flushCalls(TPage $page): int
	{
		$calls = array_merge($this->loadDeferredCalls(), $this->_calls);
		$this->_calls = [];
		$this->_flushed = true;
		if (count($calls) === 0) {
			return 0;
		}
		if ($page->getIsCallback()) {
			$client = $page->getCallbackClient();
			foreach ($calls as $args) {
				$client->callClientFunction('gtag', $args);
			}
		} else {
			$page->getClientScript()->registerEndScript(static::CALLS_SCRIPT_KEY, $this->getCallsScript($calls));
		}
		return count($calls);
	}

	/**
	 * Renders gtag calls as JavaScript, one `gtag(…);` statement per line with every argument
	 * JavaScript-encoded.
	 * @param array<int, array<int, mixed>> $calls The calls, each an argument list.
	 * @return string The script, without `<script>` tags.
	 */
	public function getCallsScript(array $calls): string
	{
		$lines = [];
		foreach ($calls as $args) {
			$lines[] = 'gtag(' . implode(', ', array_map(fn ($arg) => TJavaScript::encode($arg), $args)) . ');';
		}
		return implode("\n", $lines);
	}

	/**
	 * Takes the deferred calls out of the session store.
	 * @return array<int, array<int, mixed>> The deferred calls, oldest first; none without a store.
	 */
	protected function loadDeferredCalls(): array
	{
		$store = $this->getDeferredStore();
		if ($store === null || !isset($store[static::SESSION_KEY])) {
			return [];
		}
		$calls = $store[static::SESSION_KEY];
		unset($store[static::SESSION_KEY]);
		return is_array($calls) ? array_values($calls) : [];
	}

	/**
	 * Appends calls to the deferred calls in the session store. Without a store the calls are
	 * dropped and a notice is logged.
	 * @param array<int, array<int, mixed>> $calls The calls to defer.
	 */
	protected function storeDeferredCalls(array $calls): void
	{
		$store = $this->getDeferredStore();
		if ($store === null) {
			Prado::log('No session is available; ' . count($calls) . ' deferred gtag call(s) dropped.', TLogger::NOTICE, static::class);
			return;
		}
		$existing = isset($store[static::SESSION_KEY]) && is_array($store[static::SESSION_KEY]) ? $store[static::SESSION_KEY] : [];
		$store[static::SESSION_KEY] = array_merge(array_values($existing), $calls);
	}

	/**
	 * Returns the store of the deferred calls: the application session. Override to use another store.
	 * @return ?\ArrayAccess The store, or null when the application has no session.
	 */
	protected function getDeferredStore(): ?\ArrayAccess
	{
		$session = $this->getApplication()->getSession();
		return $session instanceof \ArrayAccess ? $session : null;
	}

	/**
	 * Whether a string is a valid GA4 event name ({@see EVENT_NAME_PATTERN}).
	 * @param string $name The name to test.
	 * @return bool Whether the name is valid.
	 */
	public static function isEventName(string $name): bool
	{
		return preg_match(static::EVENT_NAME_PATTERN, $name) === 1;
	}

	// =========================================================================
	// Measurement Protocol
	// =========================================================================

	/**
	 * Sends one event to Google Analytics from PHP over the Measurement Protocol, for an event
	 * without a browser. The client id is the visitor's from the `_ga` cookie
	 * ({@see getClientId()}) or a new one; the user id is {@see getEffectiveUserId()}.
	 * @param string $name The GA4 event name.
	 * @param array<string, mixed> $params The event parameters.
	 * @param ?string $clientId The client id, or null for the request's cookie or a new id.
	 * @throws \Prado\Exceptions\TConfigurationException When the Measurement ID or the {@see getApiSecret() ApiSecret} is unset.
	 * @throws TInvalidDataValueException When the event name is not valid.
	 * @return bool Whether Google accepted the request.
	 */
	public function sendEvent(string $name, array $params = [], ?string $clientId = null): bool
	{
		$mp = $this->getMeasurementProtocol();
		$event = ['name' => $name];
		if (count($params) > 0) {
			$event['params'] = $params;
		}
		return $mp->send($clientId ?? $this->getClientId() ?? $mp->newClientId(), [$event], $this->getEffectiveUserId());
	}

	/**
	 * Returns the Measurement Protocol client, configured with the module's Measurement ID,
	 * {@see getApiSecret() ApiSecret} and {@see getDebugMode() DebugMode} on every call.
	 * @return GAnalyticsMeasurementProtocol The client.
	 */
	public function getMeasurementProtocol(): GAnalyticsMeasurementProtocol
	{
		$this->_measurementProtocol ??= $this->createMeasurementProtocol();
		$this->_measurementProtocol->setMeasurementId($this->getMeasurementId());
		$this->_measurementProtocol->setApiSecret($this->getApiSecret());
		$this->_measurementProtocol->setDebug($this->getDebugMode());
		return $this->_measurementProtocol;
	}

	/**
	 * Creates the Measurement Protocol client; the seam a subclass or test replaces the transport through.
	 * @return GAnalyticsMeasurementProtocol A new client.
	 */
	protected function createMeasurementProtocol(): GAnalyticsMeasurementProtocol
	{
		return new GAnalyticsMeasurementProtocol();
	}

	/**
	 * Returns the visitor's GA4 client id from the request's `_ga` cookie.
	 * @return ?string The client id, or null when the request has no `_ga` cookie or is not an HTTP request.
	 */
	public function getClientId(): ?string
	{
		$request = $this->getApplication()->getRequest();
		if (!($request instanceof THttpRequest)) {
			return null;
		}
		$cookie = $request->getCookies()->findCookieByName('_ga');
		return $cookie === null ? null : GAnalyticsMeasurementProtocol::clientIdFromCookie((string) $cookie->getValue());
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

	// =========================================================================
	// Script
	// =========================================================================

	/**
	 * Returns the Google tag script file URL: the {@see getTagUrl() TagUrl} with the Measurement ID
	 * as its `id` query parameter, and the {@see getDataLayerName() DataLayerName} as `l` when it
	 * is not the default.
	 * @return string The script URL.
	 */
	public function getTagScriptUrl(): string
	{
		$url = $this->getTagUrl();
		$url .= (str_contains($url, '?') ? '&' : '?') . 'id=' . rawurlencode((string) $this->getMeasurementId());
		if ($this->getDataLayerName() !== static::DEFAULT_DATA_LAYER_NAME) {
			$url .= '&l=' . rawurlencode($this->getDataLayerName());
		}
		return $url;
	}

	/**
	 * Returns the inline Google tag script: the data layer bootstrap, the consent defaults when
	 * any are set, `gtag('js')`, `gtag('config')` with the {@see getEffectiveConfigOptions()
	 * effective options}, and a `gtag('config')` per additional Measurement ID. Every value is
	 * JavaScript-encoded, so a Measurement ID or option value cannot break out of the script.
	 * @param ?TPage $page The page the script is for, or null for a page-independent script.
	 * @return string The script block, without `<script>` tags.
	 */
	public function getTagScript(?TPage $page = null): string
	{
		$layer = $this->getDataLayerName();
		$lines = [
			"window.{$layer} = window.{$layer} || [];",
			"function gtag(){{$layer}.push(arguments);}",
		];
		$consent = $this->getConsentDefaults();
		if (count($consent) > 0) {
			$lines[] = "gtag('consent', 'default', " . TJavaScript::encode($consent) . ');';
		}
		$lines[] = "gtag('js', new Date());";
		$id = TJavaScript::quoteString((string) $this->getMeasurementId());
		$options = $this->getEffectiveConfigOptions($page);
		$lines[] = count($options) > 0
			? "gtag('config', {$id}, " . TJavaScript::encode($options) . ');'
			: "gtag('config', {$id});";
		foreach ($this->getAdditionalMeasurementIds() as $additional) {
			$lines[] = "gtag('config', " . TJavaScript::quoteString($additional) . ');';
		}
		return implode("\n", $lines);
	}

	/**
	 * Returns the `gtag('config')` parameters: the {@see getConfigOptions() ConfigOptions}, with
	 * `debug_mode` set when {@see getDebugMode() DebugMode} is on, `send_page_view` set to false
	 * when {@see getSendPageView() SendPageView} is off, `user_id` set to the
	 * {@see getEffectiveUserId() effective user id} when one resolves, and `content_group` set to
	 * the page path when {@see getPagePathAsContentGroup() PagePathAsContentGroup} is on and a page is given.
	 * @param ?TPage $page The page the configuration is for, or null.
	 * @return array<string, mixed> The configuration parameters.
	 */
	public function getEffectiveConfigOptions(?TPage $page = null): array
	{
		$options = $this->getConfigOptions();
		if ($this->getDebugMode()) {
			$options['debug_mode'] = true;
		}
		if (!$this->getSendPageView()) {
			$options['send_page_view'] = false;
		}
		if (($userId = $this->getEffectiveUserId()) !== null) {
			$options['user_id'] = $userId;
		}
		if ($page !== null && $this->getPagePathAsContentGroup() && ($path = (string) $page->getPagePath()) !== '') {
			$options['content_group'] = $path;
		}
		return $options;
	}

	/**
	 * Returns the GA4 user id: the {@see getUserId() UserId} when set; otherwise, with
	 * {@see getUserIdFromUser() UserIdFromUser}, the HMAC-SHA256 of the authenticated user's name
	 * under the security manager's validation key, so the id is stable per user and per
	 * application and reveals no name; null for a guest or when neither applies.
	 * @return ?string The user id, or null.
	 */
	public function getEffectiveUserId(): ?string
	{
		if ($this->_userId !== null) {
			return $this->_userId;
		}
		if (!$this->getUserIdFromUser()) {
			return null;
		}
		$app = $this->getApplication();
		$user = $app->getUser();
		if (!($user instanceof IUser) || $user->getIsGuest()) {
			return null;
		}
		$name = (string) $user->getName();
		if ($name === '') {
			return null;
		}
		return hash_hmac('sha256', $name, (string) $app->getSecurityManager()->getValidationKey());
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
	 * @return string[] Further Measurement IDs configured on the page with a plain `gtag('config')`. Defaults to none.
	 */
	public function getAdditionalMeasurementIds(): array
	{
		return $this->_additionalMeasurementIds;
	}

	/**
	 * Sets further Measurement IDs configured on the page, such as a Google Ads `AW-` id or a
	 * second property, as an array or a comma-separated string.
	 * @param mixed $value The ids; empty for none.
	 * @throws TInvalidDataValueException When an id is not a valid Measurement ID.
	 */
	public function setAdditionalMeasurementIds($value)
	{
		$ids = [];
		foreach (TPropertyValue::ensureArray($value, TPropertyValue::ARRAY_SKIP_EMPTY) as $id) {
			if (trim((string) $id) !== '') {
				$ids[] = $this->ensureMeasurementId($id);
			}
		}
		$this->_additionalMeasurementIds = array_values(array_unique($ids));
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
	 * @return string[] The {@see TApplicationMode} names the tag is registered in; empty for every mode. Defaults to empty.
	 */
	public function getEnabledModes(): array
	{
		return $this->_enabledModes;
	}

	/**
	 * Sets the application modes the tag is registered in, for example `Normal, Performance` to
	 * keep development traffic out of the property.
	 * @param mixed $value The {@see TApplicationMode} names, as an array or a comma-separated string; empty for every mode.
	 * @throws TInvalidDataValueException When a value is not an application mode.
	 */
	public function setEnabledModes($value)
	{
		$modes = [];
		foreach (TPropertyValue::ensureArray($value, TPropertyValue::ARRAY_SKIP_EMPTY) as $mode) {
			if (trim((string) $mode) !== '') {
				$modes[] = TPropertyValue::ensureEnum(trim((string) $mode), TApplicationMode::class);
			}
		}
		$this->_enabledModes = array_values(array_unique($modes));
	}

	/**
	 * @return bool Whether the module is {@see getEnabled() enabled} and the application runs in one of the {@see getEnabledModes() EnabledModes}.
	 */
	public function getIsActive(): bool
	{
		if (!$this->getEnabled()) {
			return false;
		}
		$modes = $this->getEnabledModes();
		return count($modes) === 0 || in_array((string) $this->getApplication()->getMode(), $modes, true);
	}

	/**
	 * @return bool Whether `debug_mode: true` is set in the tag configuration. Defaults to false.
	 */
	public function getDebugMode(): bool
	{
		return $this->_debugMode;
	}

	/**
	 * @param mixed $value Whether `debug_mode: true` is set, so hits show in the GA4 DebugView; the Measurement Protocol then uses its validation endpoint.
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
	 * @return bool Whether the PRADO page path is reported as the GA4 `content_group`. Defaults to false.
	 */
	public function getPagePathAsContentGroup(): bool
	{
		return $this->_pagePathAsContentGroup;
	}

	/**
	 * @param mixed $value Whether the page path (`Admin.Users`) is reported as `content_group`, so reports group by PRADO page.
	 */
	public function setPagePathAsContentGroup($value)
	{
		$this->_pagePathAsContentGroup = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return array<string, mixed> Additional `gtag('config')` parameters. Defaults to none.
	 */
	public function getConfigOptions(): array
	{
		return $this->_configOptions;
	}

	/**
	 * Sets additional `gtag('config')` parameters, such as `cookie_domain`, `cookie_flags` or
	 * `allow_google_signals`. A string value is a JSON object; an empty value clears the options.
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
	 * @return ?string The GA4 `user_id` set for the request, or null for none. Defaults to null.
	 */
	public function getUserId(): ?string
	{
		return $this->_userId;
	}

	/**
	 * Sets the GA4 `user_id` for the request, an application-defined stable id that is no personal
	 * data. It takes precedence over {@see setUserIdFromUser() UserIdFromUser}.
	 * @param mixed $value The user id; empty for none.
	 */
	public function setUserId($value)
	{
		$value = TPropertyValue::ensureNullIfEmpty($value);
		$this->_userId = ($value === null) ? null : trim((string) TPropertyValue::ensureString($value));
	}

	/**
	 * @return bool Whether the `user_id` is derived from the authenticated application user. Defaults to false.
	 */
	public function getUserIdFromUser(): bool
	{
		return $this->_userIdFromUser;
	}

	/**
	 * @param mixed $value Whether the `user_id` is the HMAC of the authenticated user's name under the security manager's validation key; see {@see getEffectiveUserId()}.
	 */
	public function setUserIdFromUser($value)
	{
		$this->_userIdFromUser = TPropertyValue::ensureBoolean($value);
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

	/**
	 * @return string The data layer name. Defaults to {@see DEFAULT_DATA_LAYER_NAME}.
	 */
	public function getDataLayerName(): string
	{
		return $this->_dataLayerName;
	}

	/**
	 * Sets the data layer name, a JavaScript identifier, for a page whose `dataLayer` another tag
	 * owns. A name other than the default is passed to the script as its `l` parameter. An empty
	 * value restores the default.
	 * @param mixed $value The data layer name.
	 * @throws TInvalidDataValueException When the value is not a JavaScript identifier.
	 */
	public function setDataLayerName($value)
	{
		$value = TPropertyValue::ensureNullIfEmpty($value);
		if ($value === null) {
			$this->_dataLayerName = static::DEFAULT_DATA_LAYER_NAME;
			return;
		}
		$name = trim((string) TPropertyValue::ensureString($value));
		if (!preg_match(static::DATA_LAYER_NAME_PATTERN, $name)) {
			throw new TInvalidDataValueException('ganalytics_datalayername_invalid', $name);
		}
		$this->_dataLayerName = $name;
	}

	/**
	 * @return bool Whether the {@see GAnalyticsPageBehavior} is attached to TPage when the application is hooked. Defaults to true.
	 */
	public function getAttachPageBehavior(): bool
	{
		return $this->_attachPageBehavior;
	}

	/**
	 * @param mixed $value Whether the {@see GAnalyticsPageBehavior} is attached to TPage, giving pages `trackEvent()` and the other calls.
	 */
	public function setAttachPageBehavior($value)
	{
		$this->_attachPageBehavior = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return bool Whether Google's hosts are added to the application's Content Security Policy headers. Defaults to true.
	 */
	public function getAmendCsp(): bool
	{
		return $this->_amendCsp;
	}

	/**
	 * @param mixed $value Whether Google's hosts are added to every {@see THttpHeaderCsp} in the application; see {@see amendCspPolicies()}.
	 */
	public function setAmendCsp($value)
	{
		$this->_amendCsp = TPropertyValue::ensureBoolean($value);
	}
}
