<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsModule;
use belisoful\GAnalytics\GAnalyticsPageBehavior;
use PHPUnit\Framework\TestCase;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TApplication;
use Prado\TApplicationMode;
use Prado\TComponent;
use Prado\TEventParameter;
use Prado\TService;
use Prado\Util\TPluginModule;
use Prado\Web\HttpHeaders\TCspDirective;
use Prado\Web\HttpHeaders\THttpHeaderCsp;
use Prado\Web\HttpHeaders\THttpHeadersManager;
use Prado\Web\Services\TPageService;
use Prado\Web\THttpCookie;
use Prado\Web\UI\ActiveControls\TCallbackClientScript;
use Prado\Web\UI\TPage;
use Prado\Web\UI\WebControls\THead;

/** An application whose initialized state a test sets, to exercise the lazily loaded module path. */
class InitializedTestApplication extends TApplication
{
	public function markInitialized(): void
	{
		$this->setStateFlag(static::STATE_INITIALIZED);
	}
}

/** A page that reports a callback request, with one callback client the test can inspect. */
class CallbackPage extends TPage
{
	public TCallbackClientScript $client;

	public function __construct()
	{
		$this->client = new TCallbackClientScript();
		parent::__construct();
	}

	public function getIsCallback()
	{
		return true;
	}

	public function getCallbackClient()
	{
		return $this->client;
	}
}

/** A page whose THead is attached by the test without rendering. */
class HeadedPage extends TPage
{
	public function attachHead(): THead
	{
		$head = new THead();
		$this->setHead($head);
		return $head;
	}
}

/** A service that is not a page service, so the module must leave it alone. */
class OtherService extends TService
{
}

class GAnalyticsModuleTest extends TestCase
{
	private const ID = 'G-TEST1234AB';

	private TApplication $_app;

	private ?\Prado\IService $_previousService = null;

	private string $_previousMode;

	protected function setUp(): void
	{
		$this->_app = Prado::getApplication();
		$this->_previousService = $this->_app->getService();
		$this->_previousMode = (string) $this->_app->getMode();
		// A page resolves its client script manager class through the running page service.
		$this->_app->setService(new TPageService());
		$this->_app->getParameters()->remove(GAnalyticsModule::MEASUREMENT_ID_PARAMETER);
	}

	protected function tearDown(): void
	{
		$this->_app->getParameters()->remove(GAnalyticsModule::MEASUREMENT_ID_PARAMETER);
		$this->_app->getParameters()->remove('OtherParameter');
		$this->_app->setService($this->_previousService);
		$this->_app->setMode($this->_previousMode);
		$this->_app->setUser(new FakeUser());
		TComponent::detachClassBehavior(GAnalyticsModule::PAGE_BEHAVIOR_NAME, TPage::class);
		if (Prado::getApplication() !== $this->_app) {
			Prado::setApplication($this->_app);
		}
	}

	private function probe(?string $id = self::ID): ProbeGAnalyticsModule
	{
		$module = new ProbeGAnalyticsModule();
		$module->setAttachPageBehavior(false);
		$module->setAmendCsp(false);
		if ($id !== null) {
			$module->setMeasurementId($id);
		}
		return $module;
	}

	private function module(?string $id = self::ID): GAnalyticsModule
	{
		$module = new GAnalyticsModule();
		if ($id !== null) {
			$module->setMeasurementId($id);
		}
		return $module;
	}

	// =========================================================================
	// Defaults and properties
	// =========================================================================

	public function testDefaults()
	{
		$module = new GAnalyticsModule();
		self::assertInstanceOf(TPluginModule::class, $module);
		self::assertNull($module->getMeasurementId());
		self::assertSame(GAnalyticsModule::MEASUREMENT_ID_PARAMETER, $module->getMeasurementIdParameter());
		self::assertSame('GoogleAnalyticsMeasurementId', $module->getMeasurementIdParameter());
		self::assertTrue($module->getEnabled());
		self::assertFalse($module->getDebugMode());
		self::assertTrue($module->getSendPageView());
		self::assertSame([], $module->getConfigOptions());
		self::assertSame([], $module->getConsentDefaults());
		self::assertSame([], $module->getEffectiveConfigOptions());
		self::assertSame(GAnalyticsModule::DEFAULT_TAG_URL, $module->getTagUrl());
		self::assertSame('https://www.googletagmanager.com/gtag/js', $module->getTagUrl());
		self::assertSame([], $module->getAdditionalMeasurementIds());
		self::assertSame([], $module->getEnabledModes());
		self::assertTrue($module->getIsActive());
		self::assertFalse($module->getPagePathAsContentGroup());
		self::assertNull($module->getUserId());
		self::assertFalse($module->getUserIdFromUser());
		self::assertNull($module->getEffectiveUserId());
		self::assertSame('dataLayer', $module->getDataLayerName());
		self::assertTrue($module->getAttachPageBehavior());
		self::assertTrue($module->getAmendCsp());
		self::assertNull($module->getApiSecret());
		self::assertNull($module->getPageBehavior());
		self::assertSame([], $module->getQueuedCalls());
	}

	public function testAdditionalMeasurementIdsAcceptListsAndConfigureMoreTags()
	{
		$module = $this->module();
		$module->setAdditionalMeasurementIds('AW-123456789, DC-1234567,, AW-123456789');
		self::assertSame(['AW-123456789', 'DC-1234567'], $module->getAdditionalMeasurementIds());
		self::assertStringEndsWith(
			"gtag('config', \"G-TEST1234AB\");\ngtag('config', \"AW-123456789\");\ngtag('config', \"DC-1234567\");",
			$module->getTagScript()
		);
		$module->setAdditionalMeasurementIds(['G-SECOND1234']);
		self::assertSame(['G-SECOND1234'], $module->getAdditionalMeasurementIds());
		$module->setAdditionalMeasurementIds('');
		self::assertSame([], $module->getAdditionalMeasurementIds());
		$this->expectException(TInvalidDataValueException::class);
		$module->setAdditionalMeasurementIds('AW-1, nope');
	}

	public function testDataLayerNameChangesTheUrlAndTheSnippet()
	{
		$module = $this->module();
		$module->setDataLayerName('siteData');
		self::assertSame('siteData', $module->getDataLayerName());
		self::assertSame('https://www.googletagmanager.com/gtag/js?id=G-TEST1234AB&l=siteData', $module->getTagScriptUrl());
		self::assertStringStartsWith("window.siteData = window.siteData || [];\nfunction gtag(){siteData.push(arguments);}\n", $module->getTagScript());
		$module->setDataLayerName('');
		self::assertSame('dataLayer', $module->getDataLayerName());
		self::assertStringNotContainsString('&l=', $module->getTagScriptUrl());
		$module->setDataLayerName('_$ok9');
		self::assertSame('_$ok9', $module->getDataLayerName());
	}

	/** @return array<string, array{0: string}> */
	public static function invalidDataLayerNames(): array
	{
		return [
			'digit first' => ['9layer'],
			'dash' => ['data-layer'],
			'space' => ['data layer'],
			'dot' => ['window.dataLayer'],
			'bracket' => ['dataLayer[0]'],
		];
	}

	/** @dataProvider invalidDataLayerNames */
	public function testDataLayerNameRefusesNonIdentifiers(string $name)
	{
		$this->expectException(TInvalidDataValueException::class);
		$this->module()->setDataLayerName($name);
	}

	public function testEnabledModesGateTheTag()
	{
		$module = $this->module();
		$module->setEnabledModes('Normal, Performance,, Normal');
		self::assertSame([TApplicationMode::Normal, TApplicationMode::Performance], $module->getEnabledModes());
		$this->_app->setMode(TApplicationMode::Debug);
		self::assertFalse($module->getIsActive());
		self::assertFalse($module->registerPageScripts(new TPage()));
		$this->_app->setMode(TApplicationMode::Normal);
		self::assertTrue($module->getIsActive());
		self::assertTrue($module->registerPageScripts(new TPage()));
		$module->setEnabled(false);
		self::assertFalse($module->getIsActive(), 'Enabled still gates.');
		$module->setEnabled(true);
		$module->setEnabledModes([]);
		$this->_app->setMode(TApplicationMode::Debug);
		self::assertTrue($module->getIsActive(), 'No modes means every mode.');
		$this->expectException(TInvalidDataValueException::class);
		$module->setEnabledModes('Production');
	}

	public function testPagePathIsReportedAsTheContentGroup()
	{
		$module = $this->module();
		$page = new TPage();
		$page->setPagePath('Admin.Users');
		self::assertSame([], $module->getEffectiveConfigOptions($page), 'Off by default.');
		$module->setPagePathAsContentGroup('true');
		self::assertSame(['content_group' => 'Admin.Users'], $module->getEffectiveConfigOptions($page));
		self::assertSame([], $module->getEffectiveConfigOptions(), 'No page, no group.');
		self::assertStringContainsString("gtag('config', \"G-TEST1234AB\", {'content_group':\"Admin.Users\"});", $module->getTagScript($page));
		$module->registerPageScripts($page);
		self::assertTrue($page->getClientScript()->isHeadScriptRegistered(GAnalyticsModule::SCRIPT_KEY));
	}

	public function testUserIdComesFromThePropertyOrTheAuthenticatedUser()
	{
		$module = $this->module();
		$this->_app->getSecurityManager()->setValidationKey('validation-key');
		$this->_app->setUser(new FakeUser('alice', false));
		self::assertNull($module->getEffectiveUserId(), 'Off by default.');

		$module->setUserIdFromUser(true);
		$expected = hash_hmac('sha256', 'alice', 'validation-key');
		self::assertSame($expected, $module->getEffectiveUserId());
		self::assertSame(['user_id' => $expected], $module->getEffectiveConfigOptions());
		self::assertStringNotContainsString('alice', $module->getTagScript(), 'The name never reaches the page.');

		$this->_app->setUser(new FakeUser('guest', true));
		self::assertNull($module->getEffectiveUserId(), 'A guest has no user id.');
		$this->_app->setUser(new FakeUser('', false));
		self::assertNull($module->getEffectiveUserId(), 'An unnamed user has no user id.');

		$module->setUserId(' customer-42 ');
		self::assertSame('customer-42', $module->getUserId());
		self::assertSame('customer-42', $module->getEffectiveUserId(), 'The explicit id wins.');
		$module->setUserId('');
		self::assertNull($module->getUserId());
	}

	// =========================================================================
	// Calls
	// =========================================================================

	public function testCallsQueuedForThePageAreWrittenAtTheEndOfTheForm()
	{
		$module = $this->probe();
		$page = new TPage();
		self::assertTrue($module->registerPageScripts($page));
		$module->trackEvent('sign_up', ['method' => 'form']);
		$module->updateConsent(['analytics_storage' => 'granted']);
		$module->setUserProperties(['plan' => 'pro']);
		$module->gtag('event', 'tutorial_begin');
		$module->gtag('set', ['currency' => 'USD']);
		self::assertCount(5, $module->getQueuedCalls());
		self::assertSame([], $module->deferred());

		$module->preRenderCompleteHandler($page, null);
		$cs = $page->getClientScript();
		self::assertTrue($cs->isEndScriptRegistered(GAnalyticsModule::CALLS_SCRIPT_KEY));
		self::assertSame([], $module->getQueuedCalls());
		self::assertSame(
			"gtag(\"event\", \"sign_up\", {'method':\"form\"});\n"
			. "gtag(\"consent\", \"update\", {'analytics_storage':\"granted\"});\n"
			. "gtag(\"set\", \"user_properties\", {'plan':\"pro\"});\n"
			. "gtag(\"event\", \"tutorial_begin\");\n"
			. "gtag(\"set\", {'currency':\"USD\"});",
			$module->getCallsScript([
				['event', 'sign_up', ['method' => 'form']],
				['consent', 'update', ['analytics_storage' => 'granted']],
				['set', 'user_properties', ['plan' => 'pro']],
				['event', 'tutorial_begin'],
				['set', ['currency' => 'USD']],
			])
		);

		$module->trackEvent('late_event');
		self::assertSame([['event', 'late_event']], $module->deferred(), 'A call after delivery goes to the next page.');
		self::assertSame([], $module->getQueuedCalls());
	}

	public function testCallsWithoutAPageAreDeferredAndDeliveredOnTheNextPage()
	{
		$module = $this->probe();
		$module->trackEvent('login', ['method' => 'form']);
		$module->trackEvent('purchase', [], true);
		self::assertSame([['event', 'login', ['method' => 'form']], ['event', 'purchase']], $module->deferred());
		self::assertSame([], $module->getQueuedCalls());

		$page = new TPage();
		$module->registerPageScripts($page);
		$module->trackEvent('page_event');
		self::assertSame(3, $module->flushCalls($page));
		self::assertSame([], $module->deferred(), 'The store is emptied.');
		self::assertTrue($page->getClientScript()->isEndScriptRegistered(GAnalyticsModule::CALLS_SCRIPT_KEY));
		self::assertSame(0, $module->flushCalls($page), 'Nothing is delivered twice.');
	}

	public function testDeferredCallsAppendToTheStore()
	{
		$module = $this->probe();
		$module->store[GAnalyticsModule::SESSION_KEY] = [['event', 'earlier']];
		$module->trackEvent('later', [], true);
		self::assertSame([['event', 'earlier'], ['event', 'later']], $module->deferred());
		$module->store[GAnalyticsModule::SESSION_KEY] = 'corrupt';
		$module->trackEvent('after_corrupt', [], true);
		self::assertSame([['event', 'after_corrupt']], $module->deferred(), 'A corrupt store entry is replaced.');
		$module->store[GAnalyticsModule::SESSION_KEY] = 'corrupt';
		$page = new TPage();
		$module->registerPageScripts($page);
		self::assertSame(0, $module->flushCalls($page), 'A corrupt store entry delivers nothing.');
		self::assertSame([], $module->deferred());
	}

	public function testWithoutASessionDeferredCallsAreDropped()
	{
		$module = $this->probe();
		$module->store = null;
		$module->trackEvent('lost', [], true);
		self::assertSame([], $module->deferred());
		$page = new TPage();
		$module->registerPageScripts($page);
		self::assertSame(0, $module->flushCalls($page));
	}

	public function testCallbackRequestsDeliverCallsThroughTheCallbackClient()
	{
		$module = $this->probe();
		$page = new CallbackPage();
		self::assertTrue($module->registerPageScripts($page));
		$module->trackEvent('add_to_cart', ['value' => 9.99]);
		$module->updateConsent(['ad_storage' => 'granted']);
		$module->preRenderCompleteHandler($page, null);
		self::assertSame([
			['gtag' => ['event', 'add_to_cart', ['value' => 9.99]]],
			['gtag' => ['consent', 'update', ['ad_storage' => 'granted']]],
		], $page->client->getClientFunctionsToExecute());
		$cs = $page->getClientScript();
		self::assertFalse($cs->isEndScriptRegistered(GAnalyticsModule::CALLS_SCRIPT_KEY));
		self::assertFalse($cs->isBeginScriptRegistered(GAnalyticsModule::SCRIPT_KEY), 'The form fallback leaves a callback alone.');
	}

	/** @return array<string, array{0: array<int, mixed>}> */
	public static function invalidCalls(): array
	{
		return [
			'no arguments' => [[]],
			'empty command' => [['', 'x']],
			'blank command' => [[' ']],
			'array command' => [[['event'], 'x']],
			'integer command' => [[1, 'x']],
		];
	}

	/** @dataProvider invalidCalls */
	public function testQueueCallRefusesACallWithoutACommand(array $args)
	{
		$this->expectException(TInvalidDataValueException::class);
		$this->probe()->queueCall($args);
	}

	/** @return array<string, array{0: string}> */
	public static function invalidEventNames(): array
	{
		return [
			'digit first' => ['1login'],
			'dash' => ['sign-up'],
			'space' => ['sign up'],
			'too long' => [str_repeat('a', 41)],
			'empty' => [''],
		];
	}

	/** @dataProvider invalidEventNames */
	public function testTrackEventRefusesAnInvalidName(string $name)
	{
		self::assertFalse(GAnalyticsModule::isEventName($name));
		$this->expectException(TInvalidDataValueException::class);
		$this->probe()->trackEvent($name);
	}

	public function testEventNames()
	{
		self::assertTrue(GAnalyticsModule::isEventName('login'));
		self::assertTrue(GAnalyticsModule::isEventName('Sign_Up2'));
		self::assertTrue(GAnalyticsModule::isEventName(str_repeat('a', 40)));
	}

	// =========================================================================
	// Measurement Protocol
	// =========================================================================

	public function testSendEventPostsThroughTheMeasurementProtocol()
	{
		$module = $this->probe();
		$module->setApiSecret('s3cret');
		$module->setUserId('customer-42');
		self::assertTrue($module->sendEvent('purchase', ['value' => 9.99]));
		$mp = $module->protocol;
		self::assertSame($mp, $module->getMeasurementProtocol());
		self::assertSame('G-TEST1234AB', $mp->getMeasurementId());
		self::assertSame('s3cret', $mp->getApiSecret());
		self::assertFalse($mp->getDebug());
		self::assertCount(1, $mp->posts);
		self::assertStringStartsWith('https://www.google-analytics.com/mp/collect?measurement_id=G-TEST1234AB&api_secret=s3cret', $mp->posts[0]['url']);
		$payload = $mp->lastPayload();
		self::assertMatchesRegularExpression('/^\d+\.\d+$/', $payload['client_id'], 'A client id is generated without a cookie.');
		self::assertSame('customer-42', $payload['user_id']);
		self::assertSame([['name' => 'purchase', 'params' => ['value' => 9.99]]], $payload['events']);

		self::assertTrue($module->sendEvent('login', [], '111.222'));
		$payload = $mp->lastPayload();
		self::assertSame('111.222', $payload['client_id']);
		self::assertSame([['name' => 'login']], $payload['events']);

		$module->setDebugMode(true);
		$mp->status = 500;
		self::assertFalse($module->sendEvent('login'));
		self::assertTrue($module->getMeasurementProtocol()->getDebug());
		self::assertStringStartsWith('https://www.google-analytics.com/debug/mp/collect?', $mp->posts[2]['url']);
	}

	public function testSendEventUsesTheVisitorsGaCookie()
	{
		$module = $this->probe();
		$module->setApiSecret('s3cret');
		$cookies = $this->_app->getRequest()->getCookies();
		$cookie = new THttpCookie('_ga', 'GA1.1.1234567890.1700000000');
		$cookies->add($cookie);
		try {
			self::assertSame('1234567890.1700000000', $module->getClientId());
			$module->sendEvent('login');
			self::assertSame('1234567890.1700000000', $module->protocol->lastPayload()['client_id']);
		} finally {
			$cookies->remove($cookie);
		}
		self::assertNull($module->getClientId());
	}

	public function testSendEventRequiresTheApiSecret()
	{
		$module = $this->probe();
		$this->expectException(TConfigurationException::class);
		$module->sendEvent('login');
	}

	public function testSendEventRefusesAnInvalidName()
	{
		$module = $this->probe();
		$module->setApiSecret('s3cret');
		$this->expectException(TInvalidDataValueException::class);
		$module->sendEvent('bad-name');
	}

	public function testApiSecretTrimsAndClears()
	{
		$module = $this->module();
		$module->setApiSecret(' abc ');
		self::assertSame('abc', $module->getApiSecret());
		$module->setApiSecret('');
		self::assertNull($module->getApiSecret());
	}

	// =========================================================================
	// Content Security Policy
	// =========================================================================

	public function testCspSourcesIncludeACustomTagHost()
	{
		$module = $this->module();
		self::assertSame(GAnalyticsModule::CSP_SOURCES, $module->getCspSources(), 'Google hosts only for the default tag URL.');
		$module->setTagUrl('https://www.googletagmanager.com/gtag/js?l=x');
		self::assertSame(GAnalyticsModule::CSP_SOURCES, $module->getCspSources());
		$module->setTagUrl('https://metrics.example.com:8443/gtag/js');
		$sources = $module->getCspSources();
		foreach ([TCspDirective::ScriptSrc, TCspDirective::ConnectSrc, TCspDirective::ImgSrc] as $directive) {
			self::assertSame('https://metrics.example.com:8443', end($sources[$directive]), $directive);
		}
	}

	public function testAmendCspHeaderCreatesDirectivesFromDefaultSrcAndAppendsToExistingOnes()
	{
		$module = $this->module();
		$csp = new THttpHeaderCsp();
		$csp->setPolicies([TCspDirective::DefaultSrc => "'self' NONCE", TCspDirective::ScriptSrc => "'self' https://*.googletagmanager.com cdn.example.com", TCspDirective::FrameSrc => "'none'"]);
		self::assertTrue($module->amendCspHeader($csp));
		self::assertSame("'self' https://*.googletagmanager.com cdn.example.com", $csp->getPolicy(TCspDirective::ScriptSrc), 'Already allowed: unchanged, no duplicate.');
		self::assertSame("'self' NONCE https://*.google-analytics.com https://*.analytics.google.com https://*.googletagmanager.com", $csp->getPolicy(TCspDirective::ConnectSrc), 'Created from default-src.');
		self::assertSame("'self' NONCE https://*.google-analytics.com https://*.googletagmanager.com", $csp->getPolicy(TCspDirective::ImgSrc));
		self::assertSame("'self' NONCE", $csp->getPolicy(TCspDirective::DefaultSrc), 'default-src itself is untouched.');
		self::assertSame("'none'", $csp->getPolicy(TCspDirective::FrameSrc));
		self::assertFalse($module->amendCspHeader($csp), 'Amending again changes nothing.');
	}

	public function testAmendCspHeaderLeavesUnrestrictedAndRawPoliciesAlone()
	{
		$module = $this->module();
		$csp = new THttpHeaderCsp();
		$csp->setPolicies([TCspDirective::FrameAncestors => "'none'"]);
		self::assertFalse($module->amendCspHeader($csp));
		self::assertFalse($csp->hasPolicy(TCspDirective::ScriptSrc), 'No script-src or default-src: nothing restricts the tag.');

		$csp->setPolicies([TCspDirective::ScriptSrc => "'self'"]);
		self::assertTrue($module->amendCspHeader($csp));
		self::assertSame("'self' https://*.googletagmanager.com", $csp->getPolicy(TCspDirective::ScriptSrc));
		self::assertFalse($csp->hasPolicy(TCspDirective::ConnectSrc), 'Only the restricting directive is amended.');

		$raw = new THttpHeaderCsp();
		$raw->setPolicies("default-src 'self'");
		self::assertFalse($module->amendCspHeader($raw));
	}

	public function testAmendCspPoliciesFindsTheHeadersManagers()
	{
		$manager = new THttpHeadersManager();
		$csp = new THttpHeaderCsp();
		$csp->setPolicies([TCspDirective::DefaultSrc => "'self'"]);
		$manager->addHeader($csp);
		$manager->addHeader(new THttpHeaderCsp());
		$this->_app->setModule('headers-' . uniqid(), $manager);

		$module = $this->module();
		self::assertSame(1, $module->amendCspPolicies(), 'The empty header restricts nothing.');
		self::assertSame("'self' https://*.googletagmanager.com", $csp->getPolicy(TCspDirective::ScriptSrc));
		self::assertSame(0, $module->amendCspPolicies());

		$csp->setPolicies([TCspDirective::DefaultSrc => "'self'"]);
		$quiet = $this->module();
		$quiet->setAmendCsp(false);
		$quiet->setAttachPageBehavior(false);
		$quiet->attachPageServiceHandler($this->_app, null);
		self::assertFalse($csp->hasPolicy(TCspDirective::ScriptSrc), 'AmendCsp=false leaves the policy alone.');
		$module->setAttachPageBehavior(false);
		$module->attachPageServiceHandler($this->_app, null);
		self::assertTrue($csp->hasPolicy(TCspDirective::ScriptSrc), 'Hooking the application amends the policy.');
	}

	// =========================================================================
	// Page behavior
	// =========================================================================

	public function testHookingTheApplicationAttachesThePageBehavior()
	{
		$module = $this->probe();
		$module->setAttachPageBehavior(true);
		self::assertNull((new TPage())->asa(GAnalyticsModule::PAGE_BEHAVIOR_NAME));
		$module->attachPageServiceHandler($this->_app, null);
		self::assertInstanceOf(GAnalyticsPageBehavior::class, $module->getPageBehavior());
		$page = new TPage();
		self::assertSame($module, $page->getGAnalytics());
		$module->registerPageScripts($page);
		$page->trackEvent('from_page');
		self::assertSame([['event', 'from_page']], $module->getQueuedCalls());

		$module->detachPageBehavior();
		self::assertNull((new TPage())->asa(GAnalyticsModule::PAGE_BEHAVIOR_NAME));
		$module->setAttachPageBehavior(false);
		$module->attachPageServiceHandler($this->_app, null);
		self::assertNull($module->getPageBehavior(), 'AttachPageBehavior=false attaches nothing.');
	}

	public function testMeasurementIdAcceptsGoogleTagIds()
	{
		$module = new GAnalyticsModule();
		foreach (['G-ABC123XYZ9', 'AW-123456789', 'DC-1234567', 'GT-ABCDEFG', 'UA-12345678-1', ' G-TRIMMED1 '] as $id) {
			$module->setMeasurementId($id);
			self::assertSame(trim($id), $module->getMeasurementId(), $id);
		}
	}

	/** @return array<string, array{0: string}> */
	public static function invalidMeasurementIds(): array
	{
		return [
			'lowercase' => ['g-abc123'],
			'no prefix' => ['-ABC123'],
			'no dash' => ['GABC123'],
			'long prefix' => ['ABCD-123'],
			'trailing dash' => ['G-ABC-'],
			'double dash' => ['G-ABC--123'],
			'quote' => ["G-ABC'123"],
			'script' => ['G-1</script>'],
			'space' => ['G-ABC 123'],
			'too long' => ['G-' . str_repeat('A', 39)],
		];
	}

	/** @dataProvider invalidMeasurementIds */
	public function testMeasurementIdRefusesInvalidValues(string $id)
	{
		$module = new GAnalyticsModule();
		$this->expectException(TInvalidDataValueException::class);
		$module->setMeasurementId($id);
	}

	public function testMeasurementIdEmptyValueUnsetsIt()
	{
		$module = $this->module();
		self::assertSame(self::ID, $module->getMeasurementId());
		$module->setMeasurementId('');
		self::assertNull($module->getMeasurementId());
		$module->setMeasurementId(self::ID);
		$module->setMeasurementId(null);
		self::assertNull($module->getMeasurementId());
	}

	public function testMeasurementIdFallsBackToTheApplicationParameter()
	{
		$module = new GAnalyticsModule();
		self::assertNull($module->getMeasurementId());

		$this->_app->getParameters()->add(GAnalyticsModule::MEASUREMENT_ID_PARAMETER, 'G-FROMPARAM1');
		self::assertSame('G-FROMPARAM1', $module->getMeasurementId());

		$module->setMeasurementId(self::ID);
		self::assertSame(self::ID, $module->getMeasurementId(), 'The module property wins over the parameter.');

		$module->setMeasurementId(null);
		$module->setMeasurementIdParameter('OtherParameter');
		self::assertSame('OtherParameter', $module->getMeasurementIdParameter());
		self::assertNull($module->getMeasurementId(), 'The renamed parameter is not set.');
		$this->_app->getParameters()->add('OtherParameter', 'G-OTHERPARAM');
		self::assertSame('G-OTHERPARAM', $module->getMeasurementId());

		$this->_app->getParameters()->add('OtherParameter', '');
		self::assertNull($module->getMeasurementId(), 'An empty parameter is no Measurement ID.');
	}

	public function testMeasurementIdParameterIsNotCached()
	{
		$module = new GAnalyticsModule();
		$this->_app->getParameters()->add(GAnalyticsModule::MEASUREMENT_ID_PARAMETER, 'G-FIRST12345');
		self::assertSame('G-FIRST12345', $module->getMeasurementId());
		$this->_app->getParameters()->add(GAnalyticsModule::MEASUREMENT_ID_PARAMETER, 'G-SECOND1234');
		self::assertSame('G-SECOND1234', $module->getMeasurementId());
	}

	public function testMeasurementIdParameterWithAnInvalidValueIsRefused()
	{
		$module = new GAnalyticsModule();
		$this->_app->getParameters()->add(GAnalyticsModule::MEASUREMENT_ID_PARAMETER, 'not an id');
		$this->expectException(TInvalidDataValueException::class);
		$module->getMeasurementId();
	}

	public function testMeasurementIdParameterEmptyRestoresTheDefault()
	{
		$module = new GAnalyticsModule();
		$module->setMeasurementIdParameter('Custom');
		self::assertSame('Custom', $module->getMeasurementIdParameter());
		$module->setMeasurementIdParameter('');
		self::assertSame(GAnalyticsModule::MEASUREMENT_ID_PARAMETER, $module->getMeasurementIdParameter());
	}

	public function testBooleanPropertiesCoerceConfigurationStrings()
	{
		$module = new GAnalyticsModule();
		$module->setEnabled('false');
		self::assertFalse($module->getEnabled());
		$module->setEnabled('true');
		self::assertTrue($module->getEnabled());
		$module->setDebugMode('true');
		self::assertTrue($module->getDebugMode());
		$module->setDebugMode(0);
		self::assertFalse($module->getDebugMode());
		$module->setSendPageView('false');
		self::assertFalse($module->getSendPageView());
		$module->setSendPageView(1);
		self::assertTrue($module->getSendPageView());
	}

	public function testOptionsAcceptArraysJsonAndEmpty()
	{
		$module = new GAnalyticsModule();
		$module->setConfigOptions(['user_id' => 'u1', 'anonymize_ip' => true]);
		self::assertSame(['user_id' => 'u1', 'anonymize_ip' => true], $module->getConfigOptions());
		$module->setConfigOptions('{"cookie_domain": "example.com", "cookie_expires": 3600}');
		self::assertSame(['cookie_domain' => 'example.com', 'cookie_expires' => 3600], $module->getConfigOptions());
		$module->setConfigOptions('');
		self::assertSame([], $module->getConfigOptions());
		$module->setConfigOptions(null);
		self::assertSame([], $module->getConfigOptions());

		$module->setConsentDefaults(' {"ad_storage": "denied", "analytics_storage": "granted", "wait_for_update": 500} ');
		self::assertSame(['ad_storage' => 'denied', 'analytics_storage' => 'granted', 'wait_for_update' => 500], $module->getConsentDefaults());
		$module->setConsentDefaults(['ad_storage' => 'granted']);
		self::assertSame(['ad_storage' => 'granted'], $module->getConsentDefaults());
		$module->setConsentDefaults('  ');
		self::assertSame([], $module->getConsentDefaults());
	}

	/** @return array<string, array{0: mixed}> */
	public static function invalidOptions(): array
	{
		return [
			'malformed json' => ['{"a": }'],
			'json scalar' => ['"text"'],
			'plain text' => ['user_id=u1'],
			'integer' => [42],
			'object' => [new \stdClass()],
		];
	}

	/** @dataProvider invalidOptions */
	public function testConfigOptionsRefuseNonMaps(mixed $value)
	{
		$module = new GAnalyticsModule();
		$this->expectException(TInvalidDataValueException::class);
		$module->setConfigOptions($value);
	}

	/** @dataProvider invalidOptions */
	public function testConsentDefaultsRefuseNonMaps(mixed $value)
	{
		$module = new GAnalyticsModule();
		$this->expectException(TInvalidDataValueException::class);
		$module->setConsentDefaults($value);
	}

	public function testEffectiveConfigOptionsMergeTheFlags()
	{
		$module = new GAnalyticsModule();
		$module->setConfigOptions(['user_id' => 'u1']);
		self::assertSame(['user_id' => 'u1'], $module->getEffectiveConfigOptions());
		$module->setDebugMode(true);
		self::assertSame(['user_id' => 'u1', 'debug_mode' => true], $module->getEffectiveConfigOptions());
		$module->setSendPageView(false);
		self::assertSame(['user_id' => 'u1', 'debug_mode' => true, 'send_page_view' => false], $module->getEffectiveConfigOptions());
		$module->setConfigOptions(['debug_mode' => false, 'send_page_view' => true]);
		self::assertSame(['debug_mode' => true, 'send_page_view' => false], $module->getEffectiveConfigOptions(), 'The flags win over the options.');
		self::assertSame(['debug_mode' => false, 'send_page_view' => true], $module->getConfigOptions(), 'The options themselves are unchanged.');
	}

	public function testTagUrlAcceptsAbsoluteHttpUrlsAndRestoresTheDefault()
	{
		$module = new GAnalyticsModule();
		$module->setTagUrl('https://metrics.example.com/gtag/js');
		self::assertSame('https://metrics.example.com/gtag/js', $module->getTagUrl());
		$module->setTagUrl('http://localhost:8080/gtag/js?l=dataLayer');
		self::assertSame('http://localhost:8080/gtag/js?l=dataLayer', $module->getTagUrl());
		$module->setTagUrl('');
		self::assertSame(GAnalyticsModule::DEFAULT_TAG_URL, $module->getTagUrl());
		$module->setTagUrl('https://metrics.example.com/gtag/js');
		$module->setTagUrl(null);
		self::assertSame(GAnalyticsModule::DEFAULT_TAG_URL, $module->getTagUrl());
	}

	/** @return array<string, array{0: string}> */
	public static function invalidTagUrls(): array
	{
		return [
			'relative' => ['/gtag/js'],
			'scheme-relative' => ['//www.googletagmanager.com/gtag/js'],
			'javascript' => ['javascript:alert(1)'],
			'ftp' => ['ftp://example.com/gtag/js'],
			'no host' => ['https://'],
			'fragment' => ['https://example.com/gtag/js#x'],
			'space' => ['https://example.com/gtag js'],
		];
	}

	/** @dataProvider invalidTagUrls */
	public function testTagUrlRefusesOtherValues(string $url)
	{
		$module = new GAnalyticsModule();
		$this->expectException(TInvalidDataValueException::class);
		$module->setTagUrl($url);
	}

	// =========================================================================
	// Script
	// =========================================================================

	public function testTagScriptUrlCarriesTheEncodedMeasurementId()
	{
		$module = $this->module();
		self::assertSame('https://www.googletagmanager.com/gtag/js?id=G-TEST1234AB', $module->getTagScriptUrl());
		$module->setTagUrl('https://metrics.example.com/gtag/js?l=dl');
		self::assertSame('https://metrics.example.com/gtag/js?l=dl&id=G-TEST1234AB', $module->getTagScriptUrl(), 'An existing query is extended.');
	}

	public function testTagScriptIsTheGoogleSnippet()
	{
		$module = $this->module();
		self::assertSame(
			"window.dataLayer = window.dataLayer || [];\n"
			. "function gtag(){dataLayer.push(arguments);}\n"
			. "gtag('js', new Date());\n"
			. "gtag('config', \"G-TEST1234AB\");",
			$module->getTagScript()
		);
	}

	public function testTagScriptEncodesConsentAndConfigOptions()
	{
		$module = $this->module();
		$module->setConsentDefaults(['ad_storage' => 'denied', 'wait_for_update' => 500]);
		$module->setDebugMode(true);
		$module->setSendPageView(false);
		$module->setConfigOptions(['user_id' => "u'1</script>", 'cookie_expires' => 3600, 'allow_google_signals' => false]);
		$script = $module->getTagScript();
		$lines = explode("\n", $script);
		self::assertSame('window.dataLayer = window.dataLayer || [];', $lines[0]);
		self::assertSame('function gtag(){dataLayer.push(arguments);}', $lines[1]);
		self::assertSame("gtag('consent', 'default', {'ad_storage':\"denied\",'wait_for_update':500});", $lines[2]);
		self::assertSame("gtag('js', new Date());", $lines[3]);
		self::assertSame("gtag('config', \"G-TEST1234AB\", {'user_id':\"u\\u00271\\u003C\\/script\\u003E\",'cookie_expires':3600,'allow_google_signals':false,'debug_mode':true,'send_page_view':false});", $lines[4]);
		self::assertCount(5, $lines);
		self::assertStringNotContainsString('</script>', $script, 'A value cannot close the script element.');
	}

	// =========================================================================
	// Page registration
	// =========================================================================

	public function testRegisterPageScriptsRegistersTheHeadScripts()
	{
		$module = $this->module();
		$page = new TPage();
		self::assertTrue($module->registerPageScripts($page));
		$cs = $page->getClientScript();
		self::assertTrue($cs->isHeadScriptFileRegistered(GAnalyticsModule::SCRIPT_KEY));
		self::assertTrue($cs->isHeadScriptRegistered(GAnalyticsModule::SCRIPT_KEY));
		self::assertSame([$module->getTagScriptUrl()], $cs->getScriptUrls(), 'The async script file is the tag URL.');
		self::assertTrue($page->hasEventHandler('onPreRenderComplete'), 'The form fallback is armed.');
	}

	public function testRegisterPageScriptsSkipsADisabledModule()
	{
		$module = $this->module();
		$module->setEnabled(false);
		$page = new TPage();
		self::assertFalse($module->registerPageScripts($page));
		self::assertFalse($page->getClientScript()->isHeadScriptFileRegistered(GAnalyticsModule::SCRIPT_KEY));
		self::assertFalse($page->hasEventHandler('onPreRenderComplete'));
	}

	public function testRegisterPageScriptsSkipsWithoutAMeasurementId()
	{
		$module = new GAnalyticsModule();
		$page = new TPage();
		self::assertFalse($module->registerPageScripts($page));
		self::assertFalse($page->getClientScript()->isHeadScriptRegistered(GAnalyticsModule::SCRIPT_KEY));
	}

	public function testRegisterPageScriptsUsesTheApplicationParameter()
	{
		$module = new GAnalyticsModule();
		$this->_app->getParameters()->add(GAnalyticsModule::MEASUREMENT_ID_PARAMETER, 'G-FROMPARAM1');
		$page = new TPage();
		self::assertTrue($module->registerPageScripts($page));
		self::assertSame(['https://www.googletagmanager.com/gtag/js?id=G-FROMPARAM1'], $page->getClientScript()->getScriptUrls());
	}

	public function testPreRegisterScriptEventCarriesThePageAndCanStopTheRegistration()
	{
		$module = $this->module();
		$page = new TPage();
		$seen = [];
		$module->attachEventHandler('onPreRegisterScript', function ($sender, $param) use (&$seen) {
			$seen[] = [$sender, $param];
		});
		self::assertTrue($module->registerPageScripts($page));
		self::assertCount(1, $seen);
		self::assertSame($module, $seen[0][0]);
		self::assertInstanceOf(TEventParameter::class, $seen[0][1]);
		self::assertSame($page, $seen[0][1]->getParameter());

		$module->attachEventHandler('onPreRegisterScript', function ($sender, TEventParameter $param) {
			$param->stopImmediatePropagation();
		});
		$other = new TPage();
		self::assertFalse($module->registerPageScripts($other));
		self::assertFalse($other->getClientScript()->isHeadScriptRegistered(GAnalyticsModule::SCRIPT_KEY));
		self::assertFalse($other->hasEventHandler('onPreRenderComplete'));
	}

	public function testPreRunPageHandlerRegistersOnlyPages()
	{
		$module = $this->module();
		$page = new TPage();
		$module->preRunPageHandler(new TPageService(), $page);
		self::assertTrue($page->getClientScript()->isHeadScriptRegistered(GAnalyticsModule::SCRIPT_KEY));
		$module->preRunPageHandler(new TPageService(), null);
		$module->preRunPageHandler(new TPageService(), new \stdClass());
		self::assertTrue(true, 'A parameter that is not a page is ignored.');
	}

	public function testFormFallbackRegistersOnAPageWithoutAHead()
	{
		$module = $this->module();
		$page = new TPage();
		$module->registerPageScripts($page);
		$module->preRenderCompleteHandler($page, null);
		$cs = $page->getClientScript();
		self::assertTrue($cs->isScriptFileRegistered(GAnalyticsModule::SCRIPT_KEY));
		self::assertTrue($cs->isBeginScriptRegistered(GAnalyticsModule::SCRIPT_KEY));
		self::assertSame([$module->getTagScriptUrl()], $cs->getScriptUrls(), 'The head and form entries are one URL.');
	}

	public function testFormFallbackLeavesAPageWithAHeadAlone()
	{
		$module = $this->module();
		$page = new HeadedPage();
		$page->attachHead();
		$module->registerPageScripts($page);
		$module->preRenderCompleteHandler($page, null);
		$cs = $page->getClientScript();
		self::assertTrue($cs->isHeadScriptRegistered(GAnalyticsModule::SCRIPT_KEY));
		self::assertFalse($cs->isScriptFileRegistered(GAnalyticsModule::SCRIPT_KEY));
		self::assertFalse($cs->isBeginScriptRegistered(GAnalyticsModule::SCRIPT_KEY));
	}

	public function testFormFallbackLeavesACallbackAlone()
	{
		$module = $this->module();
		$page = new CallbackPage();
		$module->registerPageScripts($page);
		$module->preRenderCompleteHandler($page, null);
		$cs = $page->getClientScript();
		self::assertFalse($cs->isScriptFileRegistered(GAnalyticsModule::SCRIPT_KEY));
		self::assertFalse($cs->isBeginScriptRegistered(GAnalyticsModule::SCRIPT_KEY));
		$module->preRenderCompleteHandler(new \stdClass(), null);
		self::assertTrue(true, 'A sender that is not a page is ignored.');
	}

	// =========================================================================
	// Lifecycle
	// =========================================================================

	public function testInitHooksThePageServiceWhenTheApplicationInitializes()
	{
		$service = new TPageService();
		$this->_app->setService($service);
		$module = $this->module();
		$module->init(null);
		self::assertFalse($service->hasEventHandler('onPreRunPage'), 'Nothing is hooked before onInitComplete.');

		$this->_app->onInitComplete();
		self::assertTrue($service->hasEventHandler('onPreRunPage'));

		$page = new TPage();
		$service->onPreRunPage($page);
		self::assertTrue($page->getClientScript()->isHeadScriptRegistered(GAnalyticsModule::SCRIPT_KEY));
		$this->_app->detachEventHandler('onInitComplete', [$module, 'attachPageServiceHandler']);
	}

	public function testInitHooksThePageServiceAtOnceWhenTheApplicationIsInitialized()
	{
		$app = new InitializedTestApplication(__DIR__ . '/app', false);
		$app->markInitialized();
		$service = new TPageService();
		$app->setService($service);
		$module = $this->module();
		$module->init(null);
		self::assertTrue($service->hasEventHandler('onPreRunPage'), 'A lazily loaded module hooks the running service at once.');
		self::assertFalse($app->hasEventHandler('onInitComplete'));
	}

	public function testInitLeavesAServiceThatIsNotAPageServiceAlone()
	{
		$service = new OtherService();
		$this->_app->setService($service);
		$module = $this->module();
		$module->init(null);
		$this->_app->onInitComplete();
		self::assertFalse($service->hasEventHandler('onPreRunPage'));
		$this->_app->detachEventHandler('onInitComplete', [$module, 'attachPageServiceHandler']);

		$this->_app->setService(null);
		$module->attachPageServiceHandler($this->_app, null);
		self::assertTrue(true, 'No service is tolerated.');
	}

	public function testMeasurementIdWithoutAnApplicationHasNoParameterToRead()
	{
		Prado::setApplication(null);
		try {
			$module = new GAnalyticsModule();
			self::assertNull($module->getApplication());
			self::assertNull($module->getMeasurementId());
		} finally {
			Prado::setApplication($this->_app);
		}
	}
}
