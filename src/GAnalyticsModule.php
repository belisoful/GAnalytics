<?php

/**
 * GAnalyticsModule class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\THttpException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\Security\IUser;
use Prado\Security\TAuthManager;
use Prado\Shell\TShellAction;
use Prado\Shell\TShellApplication;
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
use Prado\Web\UI\TPage;
use Prado\Web\UI\WebControls\TLiteral;
use Prado\Xml\TXmlElement;

/**
 * GAnalyticsModule class.
 *
 * Google Analytics 4 for a PRADO application. The module puts the Google tag on every page the
 * {@see TPageService} runs, sends events from PHP for pages, callbacks and code without a browser,
 * reads reports back through the Data API, and hooks the application's own events. Every part is
 * a property or a method on this one module, configured by the package name:
 *
 * ```xml
 * <modules>
 *     <module id="belisoful/ganalytics" MeasurementId="G-XXXXXXXXXX" ContainerId="GTM-XXXXXXX"
 *         UserIdFromUser="true" PagePathAsContentGroup="true" EnabledModes="Normal, Performance"
 *         TrackExceptions="true" TrackLogins="true" TrackValidationErrors="true"
 *         ApiSecret="…" PropertyId="123456789" ConsentProvider="consent">
 *         <credentials class="belisoful\GAnalytics\GAnalyticsServiceAccountCredentials" KeyFile="ga4-key.json" />
 *     </module>
 * </modules>
 * ```
 *
 * **The tag.** With a {@see getMeasurementId() MeasurementId} (the property, or the application
 * parameter {@see getMeasurementIdParameter() MeasurementIdParameter}) the page head gets the
 * asynchronous `gtag/js` script and the `gtag('config', …)` block; with a
 * {@see setContainerId() ContainerId} it gets the Google Tag Manager loader and, at the top of the
 * form, the container's `<noscript>` frame; with both, the two. The tag is configured by
 * {@see setDebugMode() DebugMode}, {@see setSendPageView() SendPageView},
 * {@see setConfigOptions() ConfigOptions}, {@see setConsentDefaults() ConsentDefaults},
 * {@see setAdditionalMeasurementIds() AdditionalMeasurementIds},
 * {@see setPagePathAsContentGroup() PagePathAsContentGroup} (the PRADO page path as the GA4
 * `content_group`), {@see setUserId() UserId} and {@see setUserIdFromUser() UserIdFromUser} (the
 * `user_id` as an HMAC of the authenticated {@see IUser}'s name under the security manager's
 * validation key), {@see setTagUrl() TagUrl}, {@see setContainerUrl() ContainerUrl} and
 * {@see setDataLayerName() DataLayerName}. A page runs without the tag when the module is
 * {@see getEnabled() disabled}, when the application mode is outside
 * {@see setEnabledModes() EnabledModes}, when neither id resolves (logged as a notice), or when a
 * handler of {@see onPreRegisterScript} stops the event. The tag is armed at
 * {@see TPageService::onPreRunPage} and registered at {@see TPage::onPreRenderComplete}, when the
 * page knows its head: {@see \Prado\Web\UI\WebControls\THead} renders the head registrations,
 * and a page without one gets the scripts at the beginning of its form. A callback request gets
 * no tag, so the tag loads once per page.
 *
 * **Events from pages.** {@see trackEvent()}, {@see updateConsent()}, {@see setUserProperties()}
 * and {@see gtag()} queue calls delivered at {@see TPage::onPreRenderComplete}: on a full page as
 * a script block at the end of the form, on a callback request through the page's
 * {@see TPage::getCallbackClient() callback client}. A deferred call, or one made while no page
 * is registered or after the page's calls were delivered, waits in the session for the next page,
 * so an event survives a redirect. Without a Measurement ID (a container only) an event is a
 * `dataLayer.push({event: …})` for the container's triggers. The {@see GAnalyticsPageBehavior}
 * class behavior, attached to {@see TPage} when {@see setAttachPageBehavior() AttachPageBehavior}
 * is true, offers the same methods on the page (`$this->trackEvent(…)`).
 *
 * **PRADO events.** {@see setTrackExceptions() TrackExceptions} sends an `exception` event over
 * the Measurement Protocol from {@see TApplication::onError}; {@see setTrackLogins() TrackLogins}
 * queues `login`, `login_failed` and `logout` from every {@see TAuthManager};
 * {@see setTrackValidationErrors() TrackValidationErrors} queues a `form_error` event for a
 * postback whose validators failed.
 *
 * **Consent.** {@see setConsentProvider() ConsentProvider} names an {@see IGAnalyticsConsentProvider}
 * (a module id or an instance) whose state for the visitor overrides the configured defaults in
 * `gtag('consent', 'default', …)`; when it is an {@see IGAnalyticsConsentStore},
 * {@see updateConsent()} records the choice. {@see GAnalyticsCookieConsentProvider} is the
 * cookie-backed one.
 *
 * {@see setConsentMode() ConsentMode} selects Google's consent mode:
 *
 * | Mode | Before consent | After consent |
 * |---|---|---|
 * | `advanced` (default) | the tag loads with the defaults; Google receives cookieless pings | `gtag('consent', 'update', …)` |
 * | `basic` | no tag, and queued calls are dropped | the tag loads with the granted state |
 *
 * In basic mode the tag waits until one of the {@see setBasicConsentTypes() BasicConsentTypes}
 * (`analytics_storage`, `ad_storage`) is granted in the effective consent. A choice made during
 * a postback puts the tag on the page it renders. A page without the tag carries a small loader
 * function instead ({@see getTagLoaderFunctionScript()}: no Google code, no request), and a choice
 * made during an ActiveControl callback calls it with the tag's data
 * ({@see getTagLoaderOptions()}), so the tag loads in place, under a nonce-based Content Security
 * Policy without `unsafe-eval`, before the queued calls run.
 *
 * **Server side.** {@see sendEvent()} posts an event over the Measurement Protocol with the
 * {@see setApiSecret() ApiSecret}, under the visitor's `_ga` client id when the request has one
 * ({@see getClientId()}); {@see getMeasurementProtocol()} is the client for batches.
 * {@see runReport()}, {@see runRealtimeReport()}, {@see getDataApi()} and {@see getAdminApi()}
 * read the property {@see setPropertyId() PropertyId} with the {@see setCredentials() Credentials}
 * (an {@see IGAnalyticsCredentials}, a module id, or a `<credentials>` element). {@see pollRealtime()}
 * runs the {@see setRealtimeMetrics() RealtimeMetrics} report and raises {@see onRealtimeReport},
 * for a `TCronModule` job (`task="belisoful/ganalytics->pollRealtime"`) that publishes live
 * figures through the application's own channel.
 *
 * **Framework services.** With {@see setAmendCsp() AmendCsp} (the default) the module adds
 * Google's hosts to the `script-src`, `connect-src` and `img-src` directives of every
 * {@see THttpHeaderCsp} in the application's {@see THttpHeadersManager} modules;
 * {@see TJavaScript} emits PRADO's per-request nonce on the tag's script elements. In a
 * {@see TShellApplication} the module registers `prado-cli ganalytics/*`
 * ({@see GAnalyticsShellAction}). Time comes from PRADO's clock. Every value written into a page
 * is JavaScript-encoded.
 *
 * The extension is a Composer package with an `extra.prado.bootstrap` entry, so the module is
 * configured by its package name, without a class. Its Prado3 short names come from
 * `config/classMap.json` and its error codes from `config/errorMessages.txt`, both registered by
 * Composer from `extra.prado`.
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

	/** The default Google Tag Manager loader URL; the container id is appended as its `id` query parameter. */
	public const DEFAULT_CONTAINER_URL = 'https://www.googletagmanager.com/gtm.js';

	/** The ID of the `<noscript>` literal inserted at the top of the form for a container. */
	public const NOSCRIPT_ID = 'gtmNoScript';

	/** The accepted container id form. */
	public const CONTAINER_ID_PATTERN = '/^GTM-[A-Z0-9]{4,12}$/';

	/** The default shell action class registered with a {@see TShellApplication}. */
	public const DEFAULT_SHELL_CLASS = GAnalyticsShellAction::class;

	/** The default `method` parameter of the `login`, `login_failed` and `logout` events. */
	public const DEFAULT_LOGIN_METHOD = 'form';

	/** Google's advanced consent mode: the tag loads before consent with the defaults. */
	public const CONSENT_MODE_ADVANCED = 'advanced';

	/** Google's basic consent mode: no tag until consent. */
	public const CONSENT_MODE_BASIC = 'basic';

	/** The consent types of which one, granted, loads the tag in basic mode. */
	public const DEFAULT_BASIC_CONSENT_TYPES = ['analytics_storage', 'ad_storage'];

	/** The global function a page without the tag carries in basic mode, and its script key. */
	public const LOADER_FUNCTION = 'pradoGAnalyticsLoadTag';

	/** The longest GA4 event parameter value, in characters. */
	public const PARAM_MAX_LENGTH = 100;

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

	/** @var string The consent mode: `advanced` or `basic`. */
	private string $_consentMode = self::CONSENT_MODE_ADVANCED;

	/** @var string[] The consent types of which one, granted, loads the tag in basic mode. */
	private array $_basicConsentTypes = self::DEFAULT_BASIC_CONSENT_TYPES;

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

	/** @var ?string The Google Tag Manager container id. */
	private ?string $_containerId = null;

	/** @var string The Google Tag Manager loader URL, without the `id` parameter. */
	private string $_containerUrl = self::DEFAULT_CONTAINER_URL;

	/** @var bool Whether the container's `<noscript>` frame is inserted at the top of the form. */
	private bool $_containerNoScript = true;

	/** @var bool Whether {@see TApplication::onError} sends an `exception` event. */
	private bool $_trackExceptions = false;

	/** @var bool Whether {@see TAuthManager} logins, failures and logouts queue events. */
	private bool $_trackLogins = false;

	/** @var bool Whether a postback with failed validators queues a `form_error` event. */
	private bool $_trackValidationErrors = false;

	/** @var string The `method` parameter of the login events. */
	private string $_loginMethod = self::DEFAULT_LOGIN_METHOD;

	/** @var null|IGAnalyticsConsentProvider|string The consent provider, or the id of the module that is one. */
	private null|IGAnalyticsConsentProvider|string $_consentProvider = null;

	/** @var ?string The GA4 property id the Data API reads. */
	private ?string $_propertyId = null;

	/** @var null|IGAnalyticsCredentials|string The API credentials, or the id of the module that is them. */
	private null|IGAnalyticsCredentials|string $_credentials = null;

	/** @var ?GAnalyticsDataApi The Data API client, created on first use. */
	private ?GAnalyticsDataApi $_dataApi = null;

	/** @var ?GAnalyticsAdminApi The Admin API client, created on first use. */
	private ?GAnalyticsAdminApi $_adminApi = null;

	/** @var string[] The metrics of the realtime poll. */
	private array $_realtimeMetrics = ['activeUsers'];

	/** @var string[] The dimensions of the realtime poll. */
	private array $_realtimeDimensions = [];

	/** @var string The shell action class registered with a shell application. */
	private string $_shellClass = self::DEFAULT_SHELL_CLASS;

	/** @var bool Whether {@see attachPageServiceHandler()} hooked the application. */
	private bool $_hooked = false;

	/** @var array<int, array<int, mixed>> The gtag calls queued for the current page. */
	private array $_calls = [];

	/** @var ?TPage The page the tag is registered on in this request. */
	private ?TPage $_page = null;

	/** @var bool Whether the current page's calls were delivered. */
	private bool $_flushed = false;

	/** @var bool Whether basic consent mode held the tag back when the page was armed. */
	private bool $_tagHeld = false;

	// =========================================================================
	// Lifecycle
	// =========================================================================

	/**
	 * Initializes the module: creates the {@see setCredentials() Credentials} from a
	 * `<credentials>` child element (or the `credentials` key of an array configuration) and the
	 * {@see setConsentProvider() ConsentProvider} from a `<consent>` element (or `consent` key),
	 * then hooks the application. When the application is already initialized (a lazily loaded
	 * module) it is hooked at once; otherwise the hook waits for {@see TApplication::onInitComplete},
	 * when every module and the service exist.
	 * @param null|array|TXmlElement $config The module configuration.
	 */
	public function init($config)
	{
		if ($config instanceof TXmlElement) {
			foreach ($config->getElementsByTagName('credentials') as $element) {
				$this->setCredentials($element->getAttributes()->toArray());
			}
			foreach ($config->getElementsByTagName('consent') as $element) {
				$this->setConsentProvider($element->getAttributes()->toArray());
			}
		} elseif (\is_array($config)) {
			if (isset($config['credentials']) && \is_array($config['credentials'])) {
				$this->setCredentials($config['credentials']);
			}
			if (isset($config['consent']) && \is_array($config['consent'])) {
				$this->setConsentProvider($config['consent']);
			}
		}
		parent::init($config);

		$app = $this->getApplication();
		if ($app->hasStateFlag(TApplication::STATE_INITIALIZED)) {
			$this->attachPageServiceHandler($app, null);
		} else {
			$app->attachEventHandler('onInitComplete', [$this, 'attachPageServiceHandler']);
		}
	}

	/**
	 * Hooks the application once every module exists, once per module: attaches
	 * {@see preRunPageHandler()} to the {@see TPageService::onPreRunPage} event of the running
	 * page service (a service that is not a page service is left alone), attaches the
	 * {@see GAnalyticsPageBehavior} ({@see attachPageBehavior()}), amends the Content Security
	 * Policy ({@see amendCspPolicies()}), hooks {@see TApplication::onError} and the
	 * {@see TAuthManager} events the `Track*` properties ask for, and registers the shell action
	 * with a {@see TShellApplication} ({@see registerShellAction()}).
	 * @param mixed $sender The application raising {@see TApplication::onInitComplete}.
	 * @param mixed $param The event parameter.
	 */
	public function attachPageServiceHandler($sender, $param)
	{
		if ($this->_hooked) {
			return;
		}
		$this->_hooked = true;
		$app = $this->getApplication();
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
		if ($this->getTrackExceptions()) {
			$app->attachEventHandler('onError', [$this, 'errorHandler']);
		}
		if ($this->getTrackLogins()) {
			foreach ($this->getAuthManagers() as $manager) {
				$manager->attachEventHandler('onLogin', [$this, 'loginHandler']);
				$manager->attachEventHandler('onLoginFailed', [$this, 'loginFailedHandler']);
				$manager->attachEventHandler('onLogout', [$this, 'logoutHandler']);
			}
		}
		$this->registerShellAction();
	}

	/**
	 * Returns the {@see TAuthManager} modules of the application, lazily loaded ones included.
	 * @return TAuthManager[] The authentication managers, indexed by module id.
	 */
	public function getAuthManagers(): array
	{
		$app = $this->getApplication();
		$managers = [];
		foreach (\array_keys($app->getModulesByType(TAuthManager::class)) as $id) {
			$manager = $app->getModule($id);   // loads a lazy module; returns a loaded one as is
			\assert($manager instanceof TAuthManager);
			$managers[$id] = $manager;
		}
		return $managers;
	}

	/**
	 * Registers the {@see getShellClass() ShellClass} action with a {@see TShellApplication}, so
	 * `prado-cli ganalytics/<action>` drives this module. A web application is left alone.
	 * @return bool Whether the action was registered.
	 */
	public function registerShellAction(): bool
	{
		$app = $this->getApplication();
		if (!($app instanceof TShellApplication) || $app->hasShellActionClass($this->getShellClass())) {
			return false;
		}
		$app->addShellActionClass(['class' => $this->getShellClass(), 'Module' => $this]);
		return true;
	}

	// =========================================================================
	// PRADO event handlers
	// =========================================================================

	/**
	 * The {@see TApplication::onError} handler: sends an `exception` event over the Measurement
	 * Protocol with `description` (the class and message, cut to {@see PARAM_MAX_LENGTH}),
	 * `fatal`, `error_type`, and `status_code` for a {@see THttpException}. The page will not
	 * render, so the event goes server side. Without an {@see getApiSecret() ApiSecret} a notice
	 * is logged. A failure to send is logged and never disturbs the error handling.
	 * @param mixed $sender The application.
	 * @param mixed $param The throwable.
	 * @return bool Whether the event was sent.
	 */
	public function errorHandler($sender, $param): bool
	{
		if (!($param instanceof \Throwable)) {
			return false;
		}
		if ($this->getApiSecret() === null) {
			Prado::log('TrackExceptions needs an ApiSecret; the exception was not reported.', TLogger::NOTICE, static::class);
			return false;
		}
		try {
			return $this->sendEvent('exception', $this->getExceptionParams($param));
		} catch (\Throwable $e) {
			Prado::log('Reporting an exception to Google Analytics failed: ' . $e->getMessage(), TLogger::WARNING, static::class);
			return false;
		}
	}

	/**
	 * Returns the `exception` event parameters for a throwable.
	 * @param \Throwable $exception The throwable.
	 * @return array<string, mixed> `description`, `fatal`, `error_type`, and `status_code` for a {@see THttpException}.
	 */
	public function getExceptionParams(\Throwable $exception): array
	{
		$type = (new \ReflectionClass($exception))->getShortName();
		$params = [
			'description' => \mb_substr($type . ': ' . $exception->getMessage(), 0, static::PARAM_MAX_LENGTH),
			'fatal' => true,
			'error_type' => \mb_substr($type, 0, static::PARAM_MAX_LENGTH),
		];
		if ($exception instanceof THttpException) {
			$params['status_code'] = (int) $exception->getStatusCode();
		}
		return $params;
	}

	/**
	 * The {@see TAuthManager::onLogin} handler: queues a deferred `login` event with the
	 * {@see getLoginMethod() LoginMethod}, delivered on the next page since a login is usually
	 * followed by a redirect.
	 * @param mixed $sender The authentication manager.
	 * @param mixed $param The user logged in.
	 */
	public function loginHandler($sender, $param)
	{
		$this->trackEvent('login', ['method' => $this->getLoginMethod()], true);
	}

	/**
	 * The {@see TAuthManager::onLoginFailed} handler: queues a `login_failed` event with the
	 * {@see getLoginMethod() LoginMethod} for the current page, which a failed login re-renders.
	 * The user name is not sent.
	 * @param mixed $sender The authentication manager.
	 * @param mixed $param The user name that failed.
	 */
	public function loginFailedHandler($sender, $param)
	{
		$this->trackEvent('login_failed', ['method' => $this->getLoginMethod()]);
	}

	/**
	 * The {@see TAuthManager::onLogout} handler: queues a deferred `logout` event, delivered on the next page.
	 * @param mixed $sender The authentication manager.
	 * @param mixed $param The user logged out.
	 */
	public function logoutHandler($sender, $param)
	{
		$this->trackEvent('logout', [], true);
	}

	/**
	 * Queues a `form_error` event when a postback's validators failed: `form_id` is the page
	 * path, `error_count` the number of failed validators, `validators` their IDs (cut to
	 * {@see PARAM_MAX_LENGTH}). Called at {@see TPage::onPreRenderComplete} with
	 * {@see getTrackValidationErrors() TrackValidationErrors}.
	 * @param TPage $page The page that ran.
	 * @return bool Whether an event was queued.
	 */
	public function trackValidationErrors(TPage $page): bool
	{
		if (!$page->getIsPostBack()) {
			return false;
		}
		$failed = [];
		foreach ($page->getValidators() as $validator) {
			if (!$validator->getIsValid()) {
				$failed[] = (string) $validator->getID();
			}
		}
		if (\count($failed) === 0) {
			return false;
		}
		$this->queueCall(['event', 'form_error', [
			'form_id' => (string) $page->getPagePath(),
			'error_count' => \count($failed),
			'validators' => \mb_substr(\implode(',', $failed), 0, static::PARAM_MAX_LENGTH),
		]]);
		return true;
	}

	/**
	 * The {@see TPageService::onPreRunPage} handler: arms the tag for the page about to run.
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
	 * Arms the tag for a page: the page becomes the destination of the queued calls, and at its
	 * {@see TPage::onPreRenderComplete} the tag is registered ({@see registerTag()}), failed
	 * validators are tracked and the calls are delivered ({@see preRenderCompleteHandler()}).
	 * Nothing is armed, returning false, when the module is {@see getIsActive() inactive}, when
	 * neither a Measurement ID nor a container id resolves (a notice is logged), or when a
	 * handler of {@see onPreRegisterScript} stops the event. Registration waits for
	 * `onPreRenderComplete` because only then the page knows whether it has a `THead`; a head
	 * registration on a page without one is refused by PRADO. In basic consent mode without
	 * consent, the page is armed with the tag held back ({@see getIsConsentGranted()}), so a
	 * choice made during the request can still load it.
	 * @param TPage $page The page to put the tag on.
	 * @throws TInvalidDataValueException When the Measurement ID read from the application parameter is not valid.
	 * @return bool Whether the tag was armed for the page.
	 */
	public function registerPageScripts(TPage $page): bool
	{
		if (!$this->getIsActive()) {
			return false;
		}
		if (!$this->getHasTag()) {
			Prado::log('No Google tag Measurement ID or container id is configured; the page runs without the tag.', TLogger::NOTICE, static::class);
			return false;
		}
		$param = new TEventParameter($page);
		$this->onPreRegisterScript($param);
		if ($param->getStopped()) {
			return false;
		}
		$page->attachEventHandler('onPreRenderComplete', [$this, 'preRenderCompleteHandler']);
		$this->_page = $page;
		$this->_flushed = false;
		$this->_tagHeld = !$this->getIsConsentGranted();
		return true;
	}

	/**
	 * Registers the tag on a page's client script manager: with a `THead`, the asynchronous
	 * `gtag/js` script file (with a Measurement ID) and the script block in the head; without one,
	 * the same at the beginning of the form. With a container and
	 * {@see getContainerNoScript() ContainerNoScript}, the `<noscript>` frame
	 * ({@see getContainerNoScriptHtml()}) is inserted at the top of the form.
	 * @param TPage $page The page, after its controls initialized so its head and form are known.
	 */
	public function registerTag(TPage $page): void
	{
		$cs = $page->getClientScript();
		if ($page->getHead() !== null) {
			if ($this->getUsesGtag()) {
				$cs->registerHeadScriptFile(static::SCRIPT_KEY, $this->getTagScriptUrl(), true);
			}
			$cs->registerHeadScript(static::SCRIPT_KEY, $this->getTagScript($page));
		} else {
			if ($this->getUsesGtag()) {
				$cs->registerScriptFile(static::SCRIPT_KEY, $this->getTagScriptUrl());
			}
			$cs->registerBeginScript(static::SCRIPT_KEY, $this->getTagScript($page));
		}
		if ($this->getContainerId() !== null && $this->getContainerNoScript() && ($form = $page->getForm()) !== null) {
			$literal = new TLiteral();
			$literal->setID(static::NOSCRIPT_ID);
			$literal->setEncode(false);
			$literal->setText($this->getContainerNoScriptHtml());
			$form->getControls()->insertAt(0, $literal);
		}
	}

	/**
	 * The {@see TPage::onPreRenderComplete} handler of an armed page: registers the tag
	 * ({@see registerTag()}) on a full page (a callback request renders no head and would run
	 * the tag a second time, so it gets none), tracks failed validators
	 * ({@see trackValidationErrors()}) with {@see getTrackValidationErrors() TrackValidationErrors},
	 * and delivers the queued calls ({@see flushCalls()}).
	 *
	 * In basic consent mode the consent is checked again here, after the page's events ran:
	 *
	 * | Request | Consent granted | Not granted |
	 * |---|---|---|
	 * | full page | the tag is registered | the loader function is registered instead; the calls are dropped ({@see dropCalls()}) |
	 * | callback, tag held back when armed | the loader function loads the tag ({@see getTagLoaderOptions()}) | the calls are dropped |
	 * | callback, tag on the page | the calls run | the calls run (a withdrawal's update reaches Google) |
	 * @param mixed $sender The page raising the event.
	 * @param mixed $param The event parameter.
	 */
	public function preRenderCompleteHandler($sender, $param)
	{
		if (!($sender instanceof TPage)) {
			return;
		}
		$granted = $this->getIsConsentGranted();
		if (!$sender->getIsCallback()) {
			if ($granted) {
				$this->registerTag($sender);
			} else {
				$this->registerTagLoader($sender);
			}
		} elseif ($granted && $this->_tagHeld) {
			$sender->getCallbackClient()->callClientFunction(static::LOADER_FUNCTION, [$this->getTagLoaderOptions($sender)]);
			$this->_tagHeld = false;
		}
		if ($this->getTrackValidationErrors()) {
			$this->trackValidationErrors($sender);
		}
		if ($granted || ($sender->getIsCallback() && !$this->_tagHeld)) {
			$this->flushCalls($sender);
		} else {
			$this->dropCalls();
		}
	}

	/**
	 * Drops the queued and deferred calls: in basic consent mode, before consent, no data goes to
	 * Google, and a page without the tag has no `gtag` to run them. A notice is logged.
	 * @return int The number of calls dropped.
	 */
	public function dropCalls(): int
	{
		$calls = \array_merge($this->loadDeferredCalls(), $this->_calls);
		$this->_calls = [];
		$this->_flushed = true;
		if (\count($calls) > 0) {
			Prado::log(\count($calls) . ' gtag calls were dropped: basic consent mode, and the visitor has not consented.', TLogger::NOTICE, static::class);
		}
		return \count($calls);
	}

	/**
	 * Returns whether the tag may load: always in advanced consent mode; in basic mode, when one of
	 * the {@see getBasicConsentTypes() BasicConsentTypes} is `granted` in the
	 * {@see getEffectiveConsentDefaults() effective consent}.
	 * @return bool Whether the tag may load.
	 */
	public function getIsConsentGranted(): bool
	{
		if ($this->getConsentMode() !== static::CONSENT_MODE_BASIC) {
			return true;
		}
		$consent = $this->getEffectiveConsentDefaults();
		foreach ($this->getBasicConsentTypes() as $type) {
			if (($consent[$type] ?? null) === 'granted') {
				return true;
			}
		}
		return false;
	}

	/**
	 * Registers the loader function ({@see getTagLoaderFunctionScript()}) on a page that renders
	 * without the tag in basic consent mode: in the head with a `THead`, at the beginning of the
	 * form without one.
	 * @param TPage $page The page.
	 */
	public function registerTagLoader(TPage $page): void
	{
		$cs = $page->getClientScript();
		if ($page->getHead() !== null) {
			$cs->registerHeadScript(static::LOADER_FUNCTION, $this->getTagLoaderFunctionScript());
		} else {
			$cs->registerBeginScript(static::LOADER_FUNCTION, $this->getTagLoaderFunctionScript());
		}
	}

	/**
	 * Returns the loader function a page without the tag carries in basic consent mode. It holds
	 * no Google code and makes no request until it is called with {@see getTagLoaderOptions()}:
	 * then it creates the data layer and the global `gtag`, runs the calls (a `js` call gets the
	 * current date), marks `gtm.start` for a container, and inserts the script elements.
	 * @return string The script, without `<script>` tags.
	 */
	public function getTagLoaderFunctionScript(): string
	{
		return 'window.' . static::LOADER_FUNCTION . '=function(o){var w=window,d=document,l=o.layer,i,c,s;'
			. 'w[l]=w[l]||[];if(!w.gtag){w.gtag=function(){w[l].push(arguments);};}'
			. "for(i=0;i<o.calls.length;i++){c=o.calls[i];if(c[0]==='js'){c=['js',new Date()];}w.gtag.apply(w,c);}"
			. "if(o.gtm){w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});}"
			. "for(i=0;i<o.scripts.length;i++){s=d.createElement('script');s.async=true;s.src=o.scripts[i];d.head.appendChild(s);}};";
	}

	/**
	 * Returns what the loader function needs to load the tag into a page already in the browser:
	 * the data layer name, the calls of the inline tag script ({@see getTagScript()}: the effective
	 * consent defaults, `js`, and `config` per id), whether a container starts, and the script URLs.
	 * @param ?TPage $page The page the tag is for, or null for a page-independent tag.
	 * @return array{layer: string, calls: array<int, array<int, mixed>>, gtm: bool, scripts: string[]} The loader options.
	 */
	public function getTagLoaderOptions(?TPage $page = null): array
	{
		$calls = [];
		$scripts = [];
		$consent = $this->getEffectiveConsentDefaults();
		if (\count($consent) > 0) {
			$calls[] = ['consent', 'default', $consent];
		}
		if ($this->getUsesGtag()) {
			$calls[] = ['js'];
			$options = $this->getEffectiveConfigOptions($page);
			$calls[] = \count($options) > 0 ? ['config', (string) $this->getMeasurementId(), $options] : ['config', (string) $this->getMeasurementId()];
			foreach ($this->getAdditionalMeasurementIds() as $additional) {
				$calls[] = ['config', $additional];
			}
			$scripts[] = $this->getTagScriptUrl();
		}
		$gtm = $this->getContainerId() !== null;
		if ($gtm) {
			$scripts[] = $this->getContainerUrl() . '?id=' . \rawurlencode((string) $this->getContainerId())
				. ($this->getDataLayerName() !== static::DEFAULT_DATA_LAYER_NAME ? '&l=' . \rawurlencode($this->getDataLayerName()) : '');
		}
		return ['layer' => $this->getDataLayerName(), 'calls' => $calls, 'gtm' => $gtm, 'scripts' => $scripts];
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
		foreach (\array_keys($app->getModulesByType(THttpHeadersManager::class)) as $id) {
			$manager = $app->getModule($id);   // loads a lazy module; returns a loaded one as is
			\assert($manager instanceof THttpHeadersManager);
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
			$tokens = \preg_split('/\s+/', \trim($current), -1, PREG_SPLIT_NO_EMPTY);
			$missing = \array_diff($sources, $tokens);
			if (\count($missing) === 0 && $csp->hasPolicy($directive)) {
				continue;
			}
			$tokens = \array_values(\array_filter($tokens, static fn (string $token): bool => \strcasecmp($token, "'none'") !== 0));
			$csp->setPolicy($directive, \implode(' ', \array_merge($tokens, $missing)));
			$changed = true;
		}
		return $changed;
	}

	/**
	 * Returns the hosts a Content Security Policy needs for the tag, by directive:
	 * {@see CSP_SOURCES}, plus the {@see getTagUrl() TagUrl} and {@see getContainerUrl() ContainerUrl}
	 * origins under `script-src`, `connect-src` and `img-src` when they are not Google Tag Manager hosts.
	 * @return array<string, string[]> The sources by directive name.
	 */
	public function getCspSources(): array
	{
		$sources = static::CSP_SOURCES;
		foreach ([$this->getTagUrl(), $this->getContainerUrl()] as $url) {
			$parts = \parse_url($url);
			$host = \strtolower($parts['host'] ?? '');
			if ($host === '' || $host === 'googletagmanager.com' || \str_ends_with($host, '.googletagmanager.com')) {
				continue;
			}
			$origin = ($parts['scheme'] ?? 'https') . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
			foreach ($sources as $directive => $list) {
				if (!\in_array($origin, $list, true)) {
					$sources[$directive][] = $origin;
				}
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
		$this->queueCall(\count($params) > 0 ? ['event', $name, $params] : ['event', $name], $deferred);
	}

	/**
	 * Queues a `gtag('consent', 'update', $params)` call for a visitor's consent choice, and
	 * records the choice when the {@see getConsentProvider() ConsentProvider} is an
	 * {@see IGAnalyticsConsentStore}, so the next page's defaults carry it.
	 * @param array<string, mixed> $params The consent parameters, such as `['analytics_storage' => 'granted']`.
	 * @param bool $deferred Whether the call is delivered on the next page instead of this one.
	 */
	public function updateConsent(array $params, bool $deferred = false): void
	{
		$this->queueCall(['consent', 'update', $params], $deferred);
		$provider = $this->getConsentProvider();
		if ($provider instanceof IGAnalyticsConsentStore) {
			$state = GAnalyticsCookieConsentProvider::normalizeState($params);
			if (\count($state) > 0) {
				$provider->setConsentState($state);
			}
		}
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
	 * Queues a gtag call; see {@see trackEvent()} for the delivery rules. While the module is
	 * not {@see getIsActive() active} or has no {@see getHasTag() tag}, no page ever delivers a
	 * call, so the call is dropped with a notice instead of piling up in the session.
	 * @param array<int, mixed> $args The gtag arguments; the first is the command, a string.
	 * @param bool $deferred Whether the call is delivered on the next page instead of this one.
	 * @throws TInvalidDataValueException When there is no command or it is not a string, or the Measurement ID read from the application parameter is not valid.
	 */
	public function queueCall(array $args, bool $deferred = false): void
	{
		$args = \array_values($args);
		if (\count($args) === 0 || !\is_string($args[0]) || \trim($args[0]) === '') {
			throw new TInvalidDataValueException('ganalytics_gtag_call_invalid', \json_encode($args, JSON_UNESCAPED_SLASHES) ?: '');
		}
		if (!$this->getIsActive() || !$this->getHasTag()) {
			Prado::log('The module is inactive or has no tag; the gtag call ' . \json_encode($args[0]) . ' was dropped.', TLogger::NOTICE, static::class);
			return;
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
	 * callback request each call runs through the page's callback client, as `gtag(…)` or as the
	 * data layer push of a container-only event; otherwise the calls are registered as one script
	 * block at the end of the form. Later calls in this request are deferred to the next page.
	 * @param TPage $page The page receiving the calls.
	 * @return int The number of calls delivered.
	 */
	public function flushCalls(TPage $page): int
	{
		$calls = \array_merge($this->loadDeferredCalls(), $this->_calls);
		$this->_calls = [];
		$this->_flushed = true;
		if (\count($calls) === 0) {
			return 0;
		}
		if ($page->getIsCallback()) {
			$client = $page->getCallbackClient();
			foreach ($calls as $args) {
				if ($this->isDataLayerCall($args)) {
					$client->evaluateScript($this->getCallsScript([$args]));
				} else {
					$client->callClientFunction('gtag', $args);
				}
			}
		} else {
			$page->getClientScript()->registerEndScript(static::CALLS_SCRIPT_KEY, $this->getCallsScript($calls));
		}
		return \count($calls);
	}

	/**
	 * Renders gtag calls as JavaScript, one statement per line with every argument
	 * JavaScript-encoded: `gtag(…);`, or a `dataLayer.push({event: …});` for a container-only event.
	 * @param array<int, array<int, mixed>> $calls The calls, each an argument list.
	 * @return string The script, without `<script>` tags.
	 */
	public function getCallsScript(array $calls): string
	{
		$lines = [];
		foreach ($calls as $args) {
			if ($this->isDataLayerCall($args)) {
				$event = ['event' => $args[1]] + (\is_array($args[2] ?? null) ? $args[2] : []);
				$lines[] = $this->getDataLayerName() . '.push(' . TJavaScript::encode($event) . ');';
			} else {
				$lines[] = 'gtag(' . \implode(', ', \array_map(fn ($arg) => TJavaScript::encode($arg), $args)) . ');';
			}
		}
		return \implode("\n", $lines);
	}

	/**
	 * Whether a call is delivered as a data layer push: an `event` command when the page has a
	 * container and no Measurement ID, so Tag Manager's triggers see the event.
	 * @param array<int, mixed> $args The gtag arguments.
	 * @return bool Whether the call is a data layer push.
	 */
	public function isDataLayerCall(array $args): bool
	{
		return ($args[0] ?? null) === 'event' && \is_string($args[1] ?? null) && !$this->getUsesGtag() && $this->getContainerId() !== null;
	}

	/**
	 * Takes the deferred calls out of the session store.
	 * @return array<int, array<int, mixed>> The deferred calls, oldest first; none without a store.
	 */
	protected function loadDeferredCalls(): array
	{
		$store = $this->getDeferredStore(false);
		if ($store === null || !isset($store[static::SESSION_KEY])) {
			return [];
		}
		$calls = $store[static::SESSION_KEY];
		unset($store[static::SESSION_KEY]);
		return \is_array($calls) ? \array_values($calls) : [];
	}

	/**
	 * Appends calls to the deferred calls in the session store. Without a store the calls are
	 * dropped and a notice is logged.
	 * @param array<int, array<int, mixed>> $calls The calls to defer.
	 */
	protected function storeDeferredCalls(array $calls): void
	{
		$store = $this->getDeferredStore(true);
		if ($store === null) {
			Prado::log('No session is available; ' . \count($calls) . ' deferred gtag call(s) dropped.', TLogger::NOTICE, static::class);
			return;
		}
		$existing = isset($store[static::SESSION_KEY]) && \is_array($store[static::SESSION_KEY]) ? $store[static::SESSION_KEY] : [];
		$store[static::SESSION_KEY] = \array_merge(\array_values($existing), $calls);
	}

	/**
	 * Returns the store of the deferred calls: the application session, opened when it is not.
	 * A read without a session cookie on the request returns null without opening one, so a
	 * visitor gets no session for the sake of a lookup that cannot find anything. Override to use
	 * another store.
	 * @param bool $forWrite Whether calls are about to be stored, which opens a session in any case.
	 * @return ?\ArrayAccess The store, or null when no session holds deferred calls.
	 */
	protected function getDeferredStore(bool $forWrite = false): ?\ArrayAccess
	{
		$app = $this->getApplication();
		$session = $app->getSession();
		if (!$session->getIsStarted()) {
			if (!$forWrite && $app->getRequest()->getCookies()->findCookieByName($session->getSessionName()) === null) {
				return null;
			}
			$session->open();
		}
		return $session;
	}

	/**
	 * Whether a string is a valid GA4 event name ({@see EVENT_NAME_PATTERN}).
	 * @param string $name The name to test.
	 * @return bool Whether the name is valid.
	 */
	public static function isEventName(string $name): bool
	{
		return \preg_match(static::EVENT_NAME_PATTERN, $name) === 1;
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
		if (\count($params) > 0) {
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
	 * @return ?string The client id, or null when the request has no `_ga` cookie.
	 */
	public function getClientId(): ?string
	{
		$cookie = $this->getApplication()->getRequest()->getCookies()->findCookieByName('_ga');
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
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
		$this->_apiSecret = ($value === null) ? null : \trim((string) TPropertyValue::ensureString($value));
	}

	// =========================================================================
	// Data API and Admin API
	// =========================================================================

	/**
	 * Runs a Data API report on the {@see getPropertyId() PropertyId}.
	 * @param string[] $metrics The metric names, such as `activeUsers`.
	 * @param string[] $dimensions The dimension names, such as `pagePath`.
	 * @param string $startDate The start date: `YYYY-MM-DD`, `NdaysAgo`, `yesterday` or `today`.
	 * @param string $endDate The end date, in the same forms.
	 * @param array<string, mixed> $extra Further request fields (`limit`, `orderBys`, `dimensionFilter`, …).
	 * @throws TConfigurationException When the property id or the credentials are unset.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return GAnalyticsReport The report; its rows bind to a data control.
	 */
	public function runReport(array $metrics, array $dimensions = [], string $startDate = '28daysAgo', string $endDate = 'today', array $extra = []): GAnalyticsReport
	{
		return $this->getDataApi()->runReport(GAnalyticsDataApi::reportRequest($metrics, $dimensions, $startDate, $endDate, $extra));
	}

	/**
	 * Runs a Data API realtime report on the {@see getPropertyId() PropertyId}.
	 * @param ?string[] $metrics The metric names; null for the {@see getRealtimeMetrics() RealtimeMetrics}.
	 * @param ?string[] $dimensions The dimension names; null for the {@see getRealtimeDimensions() RealtimeDimensions}.
	 * @param array<string, mixed> $extra Further request fields (`limit`, `minuteRanges`, …).
	 * @throws TConfigurationException When the property id or the credentials are unset.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return GAnalyticsReport The report.
	 */
	public function runRealtimeReport(?array $metrics = null, ?array $dimensions = null, array $extra = []): GAnalyticsReport
	{
		return $this->getDataApi()->runRealtimeReport(GAnalyticsDataApi::realtimeRequest($metrics ?? $this->getRealtimeMetrics(), $dimensions ?? $this->getRealtimeDimensions(), $extra));
	}

	/**
	 * Polls the realtime report ({@see getRealtimeMetrics() RealtimeMetrics},
	 * {@see getRealtimeDimensions() RealtimeDimensions}) and raises {@see onRealtimeReport} with
	 * it. The Data API has no push channel, so a `TCronModule` job
	 * (`task="belisoful/ganalytics->pollRealtime"`) calls this on a schedule and a handler of the
	 * event publishes the figures through the application's channel, such as
	 * `belisoful/prado-webhooks` or `belisoful/prado-websocket`.
	 * @throws TConfigurationException When the property id or the credentials are unset.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return GAnalyticsReport The report.
	 */
	public function pollRealtime(): GAnalyticsReport
	{
		$report = $this->runRealtimeReport();
		$this->onRealtimeReport(new TEventParameter($report));
		return $report;
	}

	/**
	 * Raised by {@see pollRealtime()} with the realtime report as the parameter's Parameter.
	 * @param TEventParameter $param The event parameter, carrying the {@see GAnalyticsReport}.
	 */
	public function onRealtimeReport($param)
	{
		$this->raiseEvent('onRealtimeReport', $this, $param);
	}

	/**
	 * Returns the Data API client, configured with the {@see getPropertyId() PropertyId} and the
	 * {@see getCredentials() Credentials} on every call.
	 * @return GAnalyticsDataApi The client.
	 */
	public function getDataApi(): GAnalyticsDataApi
	{
		$this->_dataApi ??= $this->createDataApi();
		$this->_dataApi->setPropertyId($this->getPropertyId());
		$this->_dataApi->setCredentials($this->getCredentials());
		return $this->_dataApi;
	}

	/**
	 * Creates the Data API client; the seam a subclass or test replaces the transport through.
	 * @return GAnalyticsDataApi A new client.
	 */
	protected function createDataApi(): GAnalyticsDataApi
	{
		return new GAnalyticsDataApi();
	}

	/**
	 * Returns the Admin API client, configured with the {@see getCredentials() Credentials} on every call.
	 * @return GAnalyticsAdminApi The client.
	 */
	public function getAdminApi(): GAnalyticsAdminApi
	{
		$this->_adminApi ??= $this->createAdminApi();
		$this->_adminApi->setCredentials($this->getCredentials());
		return $this->_adminApi;
	}

	/**
	 * Asks Google to delete a user's data from the {@see getPropertyId() PropertyId} through
	 * {@see GAnalyticsAdminApi::submitUserDeletion()}. The credentials need
	 * {@see GAnalyticsServiceAccountCredentials::SCOPE_EDIT} and the Editor role on the property.
	 * @param string $id The identifier: a user id, a client id, an app instance id, or an email address or phone number.
	 * @param string $kind The identifier kind: `userId`, `clientId`, `appInstanceId` or `userProvidedData`.
	 * @throws TConfigurationException When the property id or the credentials are unset.
	 * @throws TInvalidDataValueException When the kind is unknown or the identifier is empty.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return string The `deletionRequestTime`: Google deletes the data collected before it.
	 */
	public function deleteUserData(string $id, string $kind = 'userId'): string
	{
		$property = $this->getPropertyId();
		if ($property === null) {
			throw new TConfigurationException('ganalytics_property_unconfigured');
		}
		return $this->getAdminApi()->submitUserDeletion($property, $kind, $id);
	}

	/**
	 * Creates the Admin API client; the seam a subclass or test replaces the transport through.
	 * @return GAnalyticsAdminApi A new client.
	 */
	protected function createAdminApi(): GAnalyticsAdminApi
	{
		return new GAnalyticsAdminApi();
	}

	/**
	 * Returns the API credentials, resolving a module id to the module on first use.
	 * @throws TConfigurationException When the id names no module, or a module that is not an {@see IGAnalyticsCredentials}.
	 * @return ?IGAnalyticsCredentials The credentials, or null when none are set.
	 */
	public function getCredentials(): ?IGAnalyticsCredentials
	{
		if (\is_string($this->_credentials)) {
			$module = $this->getApplication()->getModule($this->_credentials);
			if (!($module instanceof IGAnalyticsCredentials)) {
				throw new TConfigurationException('ganalytics_module_invalid', $this->_credentials, IGAnalyticsCredentials::class);
			}
			$this->_credentials = $module;
		}
		return $this->_credentials;
	}

	/**
	 * Sets the API credentials: an {@see IGAnalyticsCredentials}, the id of a module that is one,
	 * or a configuration array with a `class` and properties (a `<credentials>` element).
	 * @param mixed $value The credentials; empty for none.
	 * @throws TConfigurationException When a configuration array has no class or one that is not an {@see IGAnalyticsCredentials}.
	 */
	public function setCredentials($value)
	{
		if ($value === null || $value === '' || $value instanceof IGAnalyticsCredentials) {
			$this->_credentials = $value === '' ? null : $value;
		} elseif (\is_array($value)) {
			$this->_credentials = $this->createConfigured($value, IGAnalyticsCredentials::class);
		} else {
			$this->_credentials = \trim((string) TPropertyValue::ensureString($value));
		}
	}

	/**
	 * Creates a component from a configuration array (`class` plus properties) and checks its type
	 * before instantiating it, so a misconfigured class never runs a constructor.
	 * @param array<string, mixed> $properties The configuration, including `class`.
	 * @param string $interface The interface the class must implement.
	 * @throws TConfigurationException When the class is absent or does not implement the interface.
	 * @return object The configured component.
	 */
	protected function createConfigured(array $properties, string $interface): object
	{
		$class = $properties['class'] ?? null;
		unset($properties['class'], $properties['id']);
		if (!\is_string($class) || $class === '' || !\is_a($class, $interface, true) || !\is_a($class, TComponent::class, true)) {
			throw new TConfigurationException('ganalytics_class_invalid', (string) $class, $interface);
		}
		$component = Prado::createComponent($class);
		foreach ($properties as $name => $value) {
			$component->setSubProperty($name, $value);
		}
		return $component;
	}

	/**
	 * @return ?string The GA4 property id the Data API reads.
	 */
	public function getPropertyId(): ?string
	{
		return $this->_propertyId;
	}

	/**
	 * @param mixed $value The GA4 property id (`123456789` or `properties/123456789`); empty for none.
	 * @throws TInvalidDataValueException When the value is not a property id.
	 */
	public function setPropertyId($value)
	{
		$this->_propertyId = GAnalyticsDataApi::normalizePropertyId($value);
	}

	/**
	 * @return string[] The metrics of {@see pollRealtime()}. Defaults to `activeUsers`.
	 */
	public function getRealtimeMetrics(): array
	{
		return $this->_realtimeMetrics;
	}

	/**
	 * @param mixed $value The realtime metric names, as an array or a comma-separated string; empty restores `activeUsers`.
	 */
	public function setRealtimeMetrics($value)
	{
		$names = $this->ensureNames($value);
		$this->_realtimeMetrics = \count($names) > 0 ? $names : ['activeUsers'];
	}

	/**
	 * @return string[] The dimensions of {@see pollRealtime()}. Defaults to none.
	 */
	public function getRealtimeDimensions(): array
	{
		return $this->_realtimeDimensions;
	}

	/**
	 * @param mixed $value The realtime dimension names, as an array or a comma-separated string; empty for none.
	 */
	public function setRealtimeDimensions($value)
	{
		$this->_realtimeDimensions = $this->ensureNames($value);
	}

	/**
	 * Converts a property value to a list of trimmed, unique, non-empty names.
	 * @param mixed $value An array or a comma-separated string.
	 * @return string[] The names.
	 */
	protected function ensureNames($value): array
	{
		$names = [];
		foreach (TPropertyValue::ensureArray($value, TPropertyValue::ARRAY_SKIP_EMPTY) as $name) {
			if (\trim((string) $name) !== '') {
				$names[] = \trim((string) $name);
			}
		}
		return \array_values(\array_unique($names));
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
		$url .= (\str_contains($url, '?') ? '&' : '?') . 'id=' . \rawurlencode((string) $this->getMeasurementId());
		if ($this->getDataLayerName() !== static::DEFAULT_DATA_LAYER_NAME) {
			$url .= '&l=' . \rawurlencode($this->getDataLayerName());
		}
		return $url;
	}

	/**
	 * Returns the inline tag script: the data layer bootstrap and the `gtag` function, the
	 * effective consent defaults when any, and then, with a Measurement ID, `gtag('js')` and a
	 * `gtag('config')` per id with the {@see getEffectiveConfigOptions() effective options} on the
	 * first, and, with a container id, the Tag Manager loader. Every value is JavaScript-encoded,
	 * so an id or option value cannot break out of the script.
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
		$consent = $this->getEffectiveConsentDefaults();
		if (\count($consent) > 0) {
			$lines[] = "gtag('consent', 'default', " . TJavaScript::encode($consent) . ');';
		}
		if ($this->getUsesGtag()) {
			$lines[] = "gtag('js', new Date());";
			$id = TJavaScript::quoteString((string) $this->getMeasurementId());
			$options = $this->getEffectiveConfigOptions($page);
			$lines[] = \count($options) > 0
				? "gtag('config', {$id}, " . TJavaScript::encode($options) . ');'
				: "gtag('config', {$id});";
			foreach ($this->getAdditionalMeasurementIds() as $additional) {
				$lines[] = "gtag('config', " . TJavaScript::quoteString($additional) . ');';
			}
		}
		if ($this->getContainerId() !== null) {
			$lines[] = $this->getContainerScript();
		}
		return \implode("\n", $lines);
	}

	/**
	 * Returns the Google Tag Manager loader: Google's snippet, which marks `gtm.start` on the
	 * data layer and inserts the asynchronous container script from
	 * {@see getContainerUrl() ContainerUrl} with the container id and the data layer name.
	 * @return string One JavaScript statement.
	 */
	public function getContainerScript(): string
	{
		return "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});"
			. "var f=d.getElementsByTagName(s)[0],j=d.createElement(s);j.async=true;"
			. 'j.src=' . TJavaScript::quoteString($this->getContainerUrl()) . "+'?id='+encodeURIComponent(i)+(l!='dataLayer'?'&l='+encodeURIComponent(l):'');"
			. 'f.parentNode.insertBefore(j,f);})(window,document,\'script\','
			. TJavaScript::quoteString($this->getDataLayerName()) . ',' . TJavaScript::quoteString((string) $this->getContainerId()) . ');';
	}

	/**
	 * Returns the container's `<noscript>` frame, Google's fallback for a browser without
	 * JavaScript: an invisible iframe of `ns.html` beside the {@see getContainerUrl() ContainerUrl}.
	 * @return string The HTML.
	 */
	public function getContainerNoScriptHtml(): string
	{
		$parts = \parse_url($this->getContainerUrl());
		$path = $parts['path'] ?? '/';
		$url = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '')
			. \substr($path, 0, (int) \strrpos($path, '/')) . '/ns.html?id=' . \rawurlencode((string) $this->getContainerId());
		if ($this->getDataLayerName() !== static::DEFAULT_DATA_LAYER_NAME) {
			$url .= '&l=' . \rawurlencode($this->getDataLayerName());
		}
		return '<noscript><iframe src="' . \htmlspecialchars($url, ENT_QUOTES) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
	}

	/**
	 * Returns the `gtag('consent', 'default', …)` parameters: the
	 * {@see getConsentDefaults() ConsentDefaults}, overridden by the visitor's state from the
	 * {@see getConsentProvider() ConsentProvider} when one is set.
	 * @return array<string, mixed> The consent parameters.
	 */
	public function getEffectiveConsentDefaults(): array
	{
		$defaults = $this->getConsentDefaults();
		$provider = $this->getConsentProvider();
		if ($provider !== null) {
			$defaults = \array_merge($defaults, $provider->getConsentState());
		}
		return $defaults;
	}

	/**
	 * Returns the consent provider, resolving a module id to the module on first use.
	 * @throws TConfigurationException When the id names no module, or a module that is not an {@see IGAnalyticsConsentProvider}.
	 * @return ?IGAnalyticsConsentProvider The provider, or null when none is set.
	 */
	public function getConsentProvider(): ?IGAnalyticsConsentProvider
	{
		if (\is_string($this->_consentProvider)) {
			$module = $this->getApplication()->getModule($this->_consentProvider);
			if (!($module instanceof IGAnalyticsConsentProvider)) {
				throw new TConfigurationException('ganalytics_module_invalid', $this->_consentProvider, IGAnalyticsConsentProvider::class);
			}
			$this->_consentProvider = $module;
		}
		return $this->_consentProvider;
	}

	/**
	 * Sets the consent provider: an {@see IGAnalyticsConsentProvider}, the id of a module that is
	 * one, or a configuration array with a `class` and properties (a `<consent>` element).
	 * @param mixed $value The provider; empty for none.
	 * @throws TConfigurationException When a configuration array has no class or one that is not an {@see IGAnalyticsConsentProvider}.
	 */
	public function setConsentProvider($value)
	{
		if ($value === null || $value === '' || $value instanceof IGAnalyticsConsentProvider) {
			$this->_consentProvider = $value === '' ? null : $value;
		} elseif (\is_array($value)) {
			$this->_consentProvider = $this->createConfigured($value, IGAnalyticsConsentProvider::class);
		} else {
			$this->_consentProvider = \trim((string) TPropertyValue::ensureString($value));
		}
	}

	/**
	 * @return bool Whether a Measurement ID resolves, so the page carries gtag.js and events are `gtag('event')` calls.
	 */
	public function getUsesGtag(): bool
	{
		return $this->getMeasurementId() !== null;
	}

	/**
	 * @return bool Whether a Measurement ID or a container id resolves, so a page gets a tag.
	 */
	public function getHasTag(): bool
	{
		return $this->getUsesGtag() || $this->getContainerId() !== null;
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
		return $this->getUserIdForName($name);
	}

	/**
	 * Returns the `user_id` {@see getUserIdFromUser() UserIdFromUser} derives for a user name: the
	 * HMAC-SHA256 of the name under the security manager's validation key. An erasure request uses
	 * it to find a user's data after the user is gone.
	 * @param string $name The user name.
	 * @return string The derived user id.
	 */
	public function getUserIdForName(string $name): string
	{
		return \hash_hmac('sha256', $name, (string) $this->getApplication()->getSecurityManager()->getValidationKey());
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
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
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
		$id = \trim((string) TPropertyValue::ensureString($value));
		if (\strlen($id) > static::MEASUREMENT_ID_MAX_LENGTH || !\preg_match(static::MEASUREMENT_ID_PATTERN, $id)) {
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
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
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
			if (\trim((string) $id) !== '') {
				$ids[] = $this->ensureMeasurementId($id);
			}
		}
		$this->_additionalMeasurementIds = \array_values(\array_unique($ids));
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
			if (\trim((string) $mode) !== '') {
				$modes[] = TPropertyValue::ensureEnum(\trim((string) $mode), TApplicationMode::class);
			}
		}
		$this->_enabledModes = \array_values(\array_unique($modes));
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
		return \count($modes) === 0 || \in_array((string) $this->getApplication()->getMode(), $modes, true);
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
	 * @return string The consent mode: `advanced` (the default) or `basic`.
	 */
	public function getConsentMode(): string
	{
		return $this->_consentMode;
	}

	/**
	 * Sets Google's consent mode. `advanced` loads the tag before consent with the
	 * {@see getConsentDefaults() ConsentDefaults}; `basic` loads no tag until one of the
	 * {@see getBasicConsentTypes() BasicConsentTypes} is granted.
	 * @param mixed $value `advanced` or `basic`.
	 * @throws TInvalidDataValueException When the value is neither.
	 */
	public function setConsentMode($value)
	{
		$value = \strtolower(\trim(TPropertyValue::ensureString($value)));
		if ($value !== static::CONSENT_MODE_ADVANCED && $value !== static::CONSENT_MODE_BASIC) {
			throw new TInvalidDataValueException('ganalytics_consent_mode_invalid', $value);
		}
		$this->_consentMode = $value;
	}

	/**
	 * @return string[] The consent types of which one, granted, loads the tag in basic consent mode.
	 */
	public function getBasicConsentTypes(): array
	{
		return $this->_basicConsentTypes;
	}

	/**
	 * Sets the consent types of which one, granted, loads the tag in basic consent mode.
	 * @param mixed $value Google consent types, comma-separated or an array; empty restores `analytics_storage, ad_storage`.
	 * @throws TInvalidDataValueException When a value is not a Google consent type.
	 */
	public function setBasicConsentTypes($value)
	{
		$types = \array_values(\array_filter(\array_map('trim', \is_array($value) ? $value : \explode(',', (string) $value)), fn ($type) => $type !== ''));
		GAnalyticsCookieConsentProvider::normalizeState(\array_fill_keys($types, 'granted'), true);
		$this->_basicConsentTypes = $types ?: static::DEFAULT_BASIC_CONSENT_TYPES;
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
		if (\is_array($value)) {
			return $value;
		}
		if (\is_string($value) || $value instanceof \Stringable) {
			$json = \trim((string) $value);
			if ($json === '') {
				return [];
			}
			$decoded = \json_decode($json, true);
			if (\is_array($decoded)) {
				return $decoded;
			}
		}
		throw new TInvalidDataValueException('ganalytics_options_invalid', $name, \is_scalar($value) || $value instanceof \Stringable ? (string) $value : \get_debug_type($value));
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
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
		$this->_userId = ($value === null) ? null : \trim((string) TPropertyValue::ensureString($value));
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
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
		if ($value === null) {
			$this->_tagUrl = static::DEFAULT_TAG_URL;
			return;
		}
		$url = \trim((string) TPropertyValue::ensureString($value));
		$scheme = \strtolower((string) \parse_url($url, PHP_URL_SCHEME));
		if (!\in_array($scheme, ['http', 'https'], true) || \filter_var($url, FILTER_VALIDATE_URL) === false || \str_contains($url, '#')) {
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
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
		if ($value === null) {
			$this->_dataLayerName = static::DEFAULT_DATA_LAYER_NAME;
			return;
		}
		$name = \trim((string) TPropertyValue::ensureString($value));
		if (!\preg_match(static::DATA_LAYER_NAME_PATTERN, $name)) {
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

	/**
	 * @return ?string The Google Tag Manager container id, such as `GTM-XXXXXXX`.
	 */
	public function getContainerId(): ?string
	{
		return $this->_containerId;
	}

	/**
	 * Sets the Google Tag Manager container id. With one, pages get the container loader and its
	 * `<noscript>` frame; without a Measurement ID the container alone carries the tags.
	 * @param mixed $value The container id; empty for none.
	 * @throws TInvalidDataValueException When the value is not a `GTM-` id.
	 */
	public function setContainerId($value)
	{
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
		if ($value === null) {
			$this->_containerId = null;
			return;
		}
		$id = \trim((string) TPropertyValue::ensureString($value));
		if (!\preg_match(static::CONTAINER_ID_PATTERN, $id)) {
			throw new TInvalidDataValueException('ganalytics_containerid_invalid', $id);
		}
		$this->_containerId = $id;
	}

	/**
	 * @return string The Google Tag Manager loader URL, without the `id` parameter. Defaults to {@see DEFAULT_CONTAINER_URL}.
	 */
	public function getContainerUrl(): string
	{
		return $this->_containerUrl;
	}

	/**
	 * Sets the Tag Manager loader URL, for a server-side tagging host such as
	 * `https://metrics.example.com/gtm.js`; `ns.html` is expected beside it. An empty value
	 * restores {@see DEFAULT_CONTAINER_URL}.
	 * @param mixed $value An absolute http or https URL without a query.
	 * @throws TInvalidDataValueException When the value is not an absolute http or https URL without a query.
	 */
	public function setContainerUrl($value)
	{
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
		if ($value === null) {
			$this->_containerUrl = static::DEFAULT_CONTAINER_URL;
			return;
		}
		$url = \trim((string) TPropertyValue::ensureString($value));
		$scheme = \strtolower((string) \parse_url($url, PHP_URL_SCHEME));
		if (!\in_array($scheme, ['http', 'https'], true) || \filter_var($url, FILTER_VALIDATE_URL) === false || \str_contains($url, '?') || \str_contains($url, '#')) {
			throw new TInvalidDataValueException('ganalytics_tagurl_invalid', $url);
		}
		$this->_containerUrl = $url;
	}

	/**
	 * @return bool Whether a container's `<noscript>` frame is inserted at the top of the form. Defaults to true.
	 */
	public function getContainerNoScript(): bool
	{
		return $this->_containerNoScript;
	}

	/**
	 * @param mixed $value Whether a container's `<noscript>` frame is inserted at the top of the form.
	 */
	public function setContainerNoScript($value)
	{
		$this->_containerNoScript = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return bool Whether {@see TApplication::onError} sends an `exception` event over the Measurement Protocol. Defaults to false.
	 */
	public function getTrackExceptions(): bool
	{
		return $this->_trackExceptions;
	}

	/**
	 * @param mixed $value Whether uncaught exceptions are reported as `exception` events; needs the {@see setApiSecret() ApiSecret}.
	 */
	public function setTrackExceptions($value)
	{
		$this->_trackExceptions = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return bool Whether {@see TAuthManager} logins, failed logins and logouts queue events. Defaults to false.
	 */
	public function getTrackLogins(): bool
	{
		return $this->_trackLogins;
	}

	/**
	 * @param mixed $value Whether logins (`login`, deferred), failed logins (`login_failed`) and logouts (`logout`, deferred) queue events.
	 */
	public function setTrackLogins($value)
	{
		$this->_trackLogins = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return bool Whether a postback whose validators failed queues a `form_error` event. Defaults to false.
	 */
	public function getTrackValidationErrors(): bool
	{
		return $this->_trackValidationErrors;
	}

	/**
	 * @param mixed $value Whether a postback whose validators failed queues a `form_error` event; see {@see trackValidationErrors()}.
	 */
	public function setTrackValidationErrors($value)
	{
		$this->_trackValidationErrors = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return string The `method` parameter of the login events. Defaults to {@see DEFAULT_LOGIN_METHOD}.
	 */
	public function getLoginMethod(): string
	{
		return $this->_loginMethod;
	}

	/**
	 * @param mixed $value The `method` parameter of the `login`, `login_failed` and `logout` events, such as `form` or `sso`; empty restores the default.
	 */
	public function setLoginMethod($value)
	{
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
		$this->_loginMethod = ($value === null) ? static::DEFAULT_LOGIN_METHOD : \mb_substr(\trim((string) TPropertyValue::ensureString($value)), 0, static::PARAM_MAX_LENGTH);
	}

	/**
	 * @return string The shell action class registered with a {@see TShellApplication}. Defaults to {@see DEFAULT_SHELL_CLASS}.
	 */
	public function getShellClass(): string
	{
		return $this->_shellClass;
	}

	/**
	 * @param mixed $value The shell action class, a {@see TShellAction}; empty restores the default.
	 * @throws TConfigurationException When the class is not a {@see TShellAction}.
	 */
	public function setShellClass($value)
	{
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
		if ($value === null) {
			$this->_shellClass = static::DEFAULT_SHELL_CLASS;
			return;
		}
		$class = \trim((string) TPropertyValue::ensureString($value));
		if (!\is_a($class, TShellAction::class, true)) {
			throw new TConfigurationException('ganalytics_class_invalid', $class, TShellAction::class);
		}
		$this->_shellClass = $class;
	}
}
