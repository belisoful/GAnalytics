<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsModule;
use belisoful\GAnalytics\GAnalyticsPageBehavior;
use PHPUnit\Framework\TestCase;
use Prado\Prado;
use Prado\TComponent;
use Prado\Web\Services\TPageService;
use Prado\Web\UI\TPage;

class GAnalyticsPageBehaviorTest extends TestCase
{
	private ?\Prado\IService $_previousService = null;

	protected function setUp(): void
	{
		$this->_previousService = Prado::getApplication()->getService();
		Prado::getApplication()->setService(new TPageService());
	}

	protected function tearDown(): void
	{
		TComponent::detachClassBehavior(GAnalyticsModule::PAGE_BEHAVIOR_NAME, TPage::class);
		Prado::getApplication()->setService($this->_previousService);
	}

	public function testMethodsForwardToTheModule()
	{
		$module = new ProbeGAnalyticsModule();
		$module->setMeasurementId('G-TEST1234AB');
		$page = new TPage();
		$page->attachBehavior(GAnalyticsModule::PAGE_BEHAVIOR_NAME, new GAnalyticsPageBehavior($module));
		self::assertSame($module, $page->getGAnalytics());

		$module->registerPageScripts($page);
		$page->trackEvent('sign_up', ['method' => 'form']);
		$page->updateConsent(['analytics_storage' => 'granted']);
		$page->setUserProperties(['plan' => 'pro']);
		$page->gtag('event', 'tutorial_begin');
		self::assertSame([
			['event', 'sign_up', ['method' => 'form']],
			['consent', 'update', ['analytics_storage' => 'granted']],
			['set', 'user_properties', ['plan' => 'pro']],
			['event', 'tutorial_begin'],
		], $module->getQueuedCalls());

		$page->trackEvent('login', [], true);
		$page->updateConsent(['ad_storage' => 'denied'], true);
		$page->setUserProperties(['tier' => 1], true);
		self::assertSame([
			['event', 'login'],
			['consent', 'update', ['ad_storage' => 'denied']],
			['set', 'user_properties', ['tier' => 1]],
		], $module->deferred());
	}

	public function testTheModuleAttachesTheBehaviorToEveryPage()
	{
		$module = new ProbeGAnalyticsModule();
		$module->setMeasurementId('G-TEST1234AB');
		$behavior = $module->attachPageBehavior();
		self::assertInstanceOf(GAnalyticsPageBehavior::class, $behavior);
		self::assertSame($behavior, $module->attachPageBehavior(), 'Attaching twice keeps the one behavior.');
		self::assertSame($behavior, $module->getPageBehavior());

		$page = new TPage();
		self::assertSame($behavior, $page->asa(GAnalyticsModule::PAGE_BEHAVIOR_NAME));
		self::assertSame($module, $page->getGAnalytics());

		self::assertTrue($module->detachPageBehavior());
		self::assertFalse($module->detachPageBehavior());
		self::assertNull($module->getPageBehavior());
		self::assertNull((new TPage())->asa(GAnalyticsModule::PAGE_BEHAVIOR_NAME));
	}
}
