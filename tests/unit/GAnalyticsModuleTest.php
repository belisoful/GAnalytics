<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsModule;
use PHPUnit\Framework\TestCase;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TApplication;
use Prado\TEventParameter;
use Prado\Util\TPluginModule;
use Prado\Web\Services\TPageService;
use Prado\TService;
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

/** A page that reports a callback request, to prove the form fallback leaves callbacks alone. */
class CallbackPage extends TPage
{
	public function getIsCallback()
	{
		return true;
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

	protected function setUp(): void
	{
		$this->_app = Prado::getApplication();
		$this->_previousService = $this->_app->getService();
		// A page resolves its client script manager class through the running page service.
		$this->_app->setService(new TPageService());
		$this->_app->getParameters()->remove(GAnalyticsModule::MEASUREMENT_ID_PARAMETER);
	}

	protected function tearDown(): void
	{
		$this->_app->getParameters()->remove(GAnalyticsModule::MEASUREMENT_ID_PARAMETER);
		$this->_app->getParameters()->remove('OtherParameter');
		$this->_app->setService($this->_previousService);
		if (Prado::getApplication() !== $this->_app) {
			Prado::setApplication($this->_app);
		}
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
