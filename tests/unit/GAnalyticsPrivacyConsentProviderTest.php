<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsModule;
use belisoful\GAnalytics\GAnalyticsPrivacyConsentProvider;
use PHPUnit\Framework\TestCase;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TApplication;
use Prado\TComponent;
use Prado\TEventParameter;
use Prado\TModule;
use Prado\Web\Services\TPageService;
use Prado\Web\UI\TPage;

class GAnalyticsPrivacyConsentProviderTest extends TestCase
{
	private TApplication $_app;

	private ?\Prado\IService $_previousService = null;

	/** @var string[] the module ids the test registered */
	private array $_ids = [];

	protected function setUp(): void
	{
		$this->_app = Prado::getApplication();
		$this->_previousService = $this->_app->getService();
		$this->_app->setService(new TPageService());
	}

	protected function tearDown(): void
	{
		// The application never forgets a module; the ones this test added are removed so the
		// provider's discovery of "the first" module sees only its own test's modules.
		$property = new \ReflectionProperty(TApplication::class, '_modules');
		$property->setAccessible(true);
		$modules = $property->getValue($this->_app);
		foreach ($this->_ids as $id) {
			unset($modules[$id]);
		}
		$property->setValue($this->_app, $modules);
		$this->_app->setService($this->_previousService);
		TComponent::detachClassBehavior(GAnalyticsModule::PAGE_BEHAVIOR_NAME, TPage::class);
	}

	private function register(string $id, \Prado\IModule $module): void
	{
		$module->setID($id);
		$this->_app->setModule($id, $module);
		$this->_ids[] = $id;
	}

	private function analytics(): ProbeGAnalyticsModule
	{
		$module = new ProbeGAnalyticsModule();
		$module->setAttachPageBehavior(false);
		$module->setAmendCsp(false);
		$module->setMeasurementId('G-TEST1234AB');
		return $module;
	}

	public function testMapping()
	{
		$provider = new GAnalyticsPrivacyConsentProvider();
		$this->assertSame([
			'analytics_storage' => 'granted',
			'ad_storage' => 'denied',
			'ad_user_data' => 'denied',
			'ad_personalization' => 'denied',
			'security_storage' => 'granted',
		], $provider->mapConsent(['necessary' => 'granted', 'analytics' => 'granted', 'marketing' => 'denied', 'functional' => 'undecided']));
		$this->assertSame(['security_storage' => 'granted'], $provider->mapConsent([]), 'undecided and missing categories are left to the defaults');
	}

	public function testConsentStateReadsTheConsentModule()
	{
		$consent = new FakePrivacyConsentModule(['analytics' => 'denied', 'functional' => 'granted', 'personalization' => 'undecided']);
		$this->register('privacy-consent-a', $consent);
		$provider = new GAnalyticsPrivacyConsentProvider();
		$this->assertSame($consent, $provider->getConsentModule(), 'found by its surface');
		$this->assertSame(['analytics_storage' => 'denied', 'functionality_storage' => 'granted', 'security_storage' => 'granted'], $provider->getConsentState());
	}

	public function testConsentModuleById()
	{
		$this->register('privacy-consent-b', new FakePrivacyConsentModule([]));
		$this->register('not-consent', new FakeCredentialsModule());
		$provider = (new GAnalyticsPrivacyConsentProvider())->setConsentModule(' privacy-consent-b ');
		$this->assertInstanceOf(FakePrivacyConsentModule::class, $provider->getConsentModule());
		$provider->setConsentModule('not-consent');
		try {
			$provider->getConsentModule();
			$this->fail('not a consent module');
		} catch (TConfigurationException $e) {
			$this->assertSame('ganalytics_module_invalid', $e->getErrorCode());
		}
		$provider->setConsentModule('missing');
		$this->expectException(TConfigurationException::class);
		$provider->getConsentModule();
	}

	public function testWithoutAConsentModule()
	{
		$this->expectException(TConfigurationException::class);
		(new GAnalyticsPrivacyConsentProvider())->getConsentModule();
	}

	public function testSetConsentStateWritesBackAsApiChoices()
	{
		$consent = new FakePrivacyConsentModule([]);
		$this->register('privacy-consent-c', $consent);
		$provider = new GAnalyticsPrivacyConsentProvider();
		$provider->setConsentState(['analytics_storage' => 'granted', 'ad_storage' => 'granted', 'ad_user_data' => 'denied', 'security_storage' => 'granted']);
		$this->assertSame([[['analytics' => true, 'marketing' => false], 'api']], $consent->sets);
		$provider->setConsentState(['security_storage' => 'granted']);
		$this->assertCount(1, $consent->sets, 'nothing mapped, nothing written');
	}

	public function testAChangeIsForwardedToGoogleWithoutLoopingBack()
	{
		// An initialized application: the provider attaches at once, as a lazily loaded module does.
		$app = new InitializedPrivacyApplication(__DIR__ . '/app', false);
		$app->markInitialized();
		try {
			$consent = new FakePrivacyConsentModule([]);
			$app->setModule('privacy-consent-d', $consent);
			$analytics = $this->analytics();
			$provider = new GAnalyticsPrivacyConsentProvider();
			$analytics->setConsentProvider($provider);
			$app->setModule('ga-d', $analytics);
			$provider->init(null);
			$this->assertTrue($consent->hasEventHandler('onConsentChanged'));
			$this->forwardAndWriteBack($consent, $analytics);
		} finally {
			Prado::setApplication($this->_app);
		}
	}

	private function forwardAndWriteBack(FakePrivacyConsentModule $consent, ProbeGAnalyticsModule $analytics): void
	{
		$consent->setConsent(['analytics' => true, 'marketing' => false], 'banner');
		$this->assertSame([['consent', 'update', [
			'analytics_storage' => 'granted',
			'ad_storage' => 'denied',
			'ad_user_data' => 'denied',
			'ad_personalization' => 'denied',
			'security_storage' => 'granted',
		]]], $analytics->deferred(), 'without a page armed, the update waits for the next page');
		$this->assertCount(1, $consent->sets, 'updateConsent did not write the change back');
		$analytics->updateConsent(['analytics_storage' => 'denied']);
		$this->assertCount(2, $consent->sets, 'an application call is written back');
		$this->assertSame([['analytics' => false], 'api'], $consent->sets[1]);
	}

	public function testAnalyticsModuleResolutionAndNoAnalytics()
	{
		$consent = new FakePrivacyConsentModule([]);
		$this->register('privacy-consent-e', $consent);
		$provider = new GAnalyticsPrivacyConsentProvider();
		$this->assertNull($provider->getAnalyticsModule());
		$provider->forwardConsentChange($consent, new FakeConsentChange(['analytics' => 'granted']));
		$analytics = $this->analytics();
		$this->register('ga-e', $analytics);
		$this->assertSame($analytics, $provider->setAnalyticsModule(' ga-e ')->getAnalyticsModule());
		$provider->setAnalyticsModule('privacy-consent-e');
		$this->assertNull($provider->getAnalyticsModule(), 'not a GAnalyticsModule');
	}

	public function testInitWaitsForTheApplicationAndUpdateOnChange()
	{
		$consent = new FakePrivacyConsentModule([]);
		$this->register('privacy-consent-f', $consent);
		$provider = (new GAnalyticsPrivacyConsentProvider())->setUpdateOnChange('false');
		$this->assertFalse($provider->getUpdateOnChange());
		$provider->init(null);
		$this->assertFalse($consent->hasEventHandler('onConsentChanged'));

		$app = new TApplication(__DIR__ . '/app', false);
		try {
			$waiting = new GAnalyticsPrivacyConsentProvider();
			$waiting->init(null);
			$this->assertTrue($app->hasEventHandler('onInitComplete'));
			$this->assertContains([$waiting, 'attachConsentHandler'], $app->getEventHandlers('onInitComplete')->toArray());
		} finally {
			Prado::setApplication($this->_app);
		}
	}

	public function testCategoryMap()
	{
		$provider = new GAnalyticsPrivacyConsentProvider();
		$this->assertSame(GAnalyticsPrivacyConsentProvider::CATEGORY_MAP, $provider->getCategoryMap());
		$provider->setCategoryMap('{"stats": "analytics_storage", "ads": ["ad_storage", "ad_user_data"]}');
		$this->assertSame(['stats' => ['analytics_storage'], 'ads' => ['ad_storage', 'ad_user_data']], $provider->getCategoryMap());
		$this->assertSame(['analytics_storage' => 'granted', 'security_storage' => 'granted'], $provider->mapConsent(['stats' => 'granted', 'analytics' => 'granted']));
		$provider->setCategoryMap(['video' => 'functionality_storage']);
		$this->assertSame(['video' => ['functionality_storage']], $provider->getCategoryMap());
		$provider->setCategoryMap('');
		$this->assertSame(GAnalyticsPrivacyConsentProvider::CATEGORY_MAP, $provider->getCategoryMap());
		try {
			$provider->setCategoryMap('not json');
			$this->fail('refused');
		} catch (TInvalidDataValueException $e) {
			$this->assertSame('ganalytics_category_map_invalid', $e->getErrorCode());
		}
		$this->expectException(TInvalidDataValueException::class);
		$provider->setCategoryMap(['stats' => 'cookie_storage']);
	}
}

/**
 * An application whose initialized state the test sets.
 */
class InitializedPrivacyApplication extends TApplication
{
	public function markInitialized(): void
	{
		$this->setStateFlag(static::STATE_INITIALIZED);
	}
}

/**
 * A consent module with the surface of belisoful/prado-privacy's TConsentModule: getConsent(),
 * setConsent() and onConsentChanged. setConsent() grants or denies and raises the event.
 */
class FakePrivacyConsentModule extends TModule
{
	/** @var array<int, array{0: array, 1: string}> the setConsent() calls */
	public array $sets = [];

	public function __construct(private array $consent)
	{
		parent::__construct();
	}

	public function getConsent(): array
	{
		return $this->consent;
	}

	public function setConsent(array $categories, string $source = 'api'): void
	{
		$this->sets[] = [$categories, $source];
		foreach ($categories as $category => $granted) {
			$this->consent[$category] = $granted ? 'granted' : 'denied';
		}
		$this->onConsentChanged(new FakeConsentChange($this->consent));
	}

	public function onConsentChanged($param)
	{
		return $this->raiseEvent('onConsentChanged', $this, $param);
	}
}

/**
 * The part of TConsentChangedEventParameter the provider reads.
 */
class FakeConsentChange extends TEventParameter
{
	public function __construct(private array $current)
	{
		parent::__construct($current);
	}

	public function getCurrent(): array
	{
		return $this->current;
	}
}
