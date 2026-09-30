<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsControlBehavior;
use belisoful\GAnalytics\GAnalyticsModule;
use belisoful\GAnalytics\GAnalyticsReport;
use PHPUnit\Framework\TestCase;
use Prado\Caching\TCache;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TApplication;
use Prado\TComponent;
use Prado\Web\Services\TPageService;
use Prado\Web\UI\JuiControls\TJuiAutoComplete;
use Prado\Web\UI\TPage;
use Prado\Web\UI\WebControls\TDataGrid;
use Prado\Web\UI\WebControls\THyperLink;
use Prado\Web\UI\WebControls\TMultiView;
use Prado\Web\UI\WebControls\TPager;
use Prado\Web\UI\WebControls\TTabPanel;
use Prado\Web\UI\WebControls\TTabView;
use Prado\Web\UI\WebControls\TView;
use Prado\Web\UI\WebControls\TWizard;
use Prado\Web\UI\WebControls\TWizardStep;

/** An in-memory application cache. */
class MemoryCache extends TCache
{
	/** @var array<string, array{0: mixed, 1: int}> key => value and expiry */
	public array $store = [];

	protected function getValue($key)
	{
		return $this->store[$key][0] ?? false;
	}

	protected function setValue($key, $value, $expire)
	{
		$this->store[$key] = [$value, $expire];
		return true;
	}

	protected function addValue($key, $value, $expire)
	{
		return $this->setValue($key, $value, $expire);
	}

	protected function deleteValue($key)
	{
		unset($this->store[$key]);
		return true;
	}

	public function flush()
	{
		$this->store = [];
	}

	public static function getIsAvailable(): bool
	{
		return true;
	}
}

class GAnalyticsTrackingTest extends TestCase
{
	private TApplication $_app;

	private ?\Prado\IService $_previousService = null;

	/** @var ProbeGAnalyticsModule[] */
	private array $_modules = [];

	/** @var array<string, mixed> the application properties the test isolates, by name */
	private array $_snapshot = [];

	/** @var array<string, mixed> the $_SERVER entries the test replaced */
	private array $_server = [];

	protected function setUp(): void
	{
		$this->_app = Prado::getApplication();
		$this->_previousService = $this->_app->getService();
		$this->_app->setService(new TPageService());
		foreach (['_modules' => [], '_cache' => null] as $name => $isolated) {
			$property = new \ReflectionProperty(TApplication::class, $name);
			$property->setAccessible(true);
			$this->_snapshot[$name] = $property->getValue($this->_app);
			$property->setValue($this->_app, $isolated);
		}
		foreach (['HTTP_HOST' => 'example.com', 'SERVER_PORT' => '443', 'HTTPS' => 'on'] as $key => $value) {
			$this->_server[$key] = $_SERVER[$key] ?? null;
			$_SERVER[$key] = $value;
		}
	}

	protected function tearDown(): void
	{
		foreach ($this->_modules as $module) {
			$module->detachControlBehaviors();
		}
		// The application's modules and cache are shared by every test class; the snapshot restores them.
		foreach ($this->_snapshot as $name => $value) {
			$property = new \ReflectionProperty(TApplication::class, $name);
			$property->setAccessible(true);
			$property->setValue($this->_app, $value);
		}
		$this->_app->setService($this->_previousService);
		foreach ($this->_server as $key => $value) {
			if ($value === null) {
				unset($_SERVER[$key]);
			} else {
				$_SERVER[$key] = $value;
			}
		}
		TComponent::detachClassBehavior(GAnalyticsModule::PAGE_BEHAVIOR_NAME, TPage::class);
	}

	private function module(): ProbeGAnalyticsModule
	{
		$module = new ProbeGAnalyticsModule();
		$module->setAttachPageBehavior(false);
		$module->setAmendCsp(false);
		$module->setMeasurementId('G-TEST1234AB');
		$this->_modules[] = $module;
		return $module;
	}

	private function register(string $id, \Prado\IModule $module): void
	{
		$module->setID($id);
		$this->_app->setModule($id, $module);
	}

	// =========================================================================
	// Clicks
	// =========================================================================

	public function testClickScript()
	{
		$module = $this->module();
		$script = $module->getClickScript();
		self::assertStringStartsWith('(function(w,d){if(w.pradoGAnalyticsSend){return;}var l="dataLayer",m=false;', $script);
		self::assertStringContainsString("w.gtag('event',n,p)", $script);
		self::assertStringContainsString('w.pradoGAnalyticsSendTabs=function(t)', $script);
		self::assertStringNotContainsString('data-ga-event', $script, 'no listener without TrackClicks');

		$module->setTrackClicks('true');
		self::assertTrue($module->getTrackClicks());
		$script = $module->getClickScript();
		self::assertStringContainsString("getAttribute('data-ga-event')", $script);
		self::assertStringContainsString("['click','submit','change'].forEach", $script);
		self::assertStringEndsWith('})(window,document);', $script);

		$module->setMeasurementId('');
		$module->setContainerId('GTM-ABCD12');
		$module->setDataLayerName('siteLayer');
		self::assertStringContainsString('var l="siteLayer",m=true;', $module->getClickScript(), 'a container alone pushes to the data layer');
	}

	public function testClickAttributes()
	{
		$module = $this->module();
		self::assertSame(['data-ga-event' => 'file_download'], $module->getClickAttributes('file_download'));
		self::assertSame(
			['data-ga-event' => 'sign_up', 'data-ga-params' => '{"method":"form","url":"/a/b","name":"Zoë"}', 'data-ga-on' => 'submit'],
			$module->getClickAttributes('sign_up', ['method' => 'form', 'url' => '/a/b', 'name' => 'Zoë'], 'submit')
		);
		try {
			$module->getClickAttributes('bad name');
			self::fail('bad name');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('ganalytics_event_name_invalid', $e->getErrorCode());
		}
		$this->expectException(TInvalidDataValueException::class);
		$module->getClickAttributes('ok', [], 'hover');
	}

	public function testSetClickEventReplacesTheAttributes()
	{
		$module = $this->module();
		$link = new THyperLink();
		$module->setClickEvent($link, 'select_content', ['content_type' => 'pdf'], 'change');
		self::assertSame(['data-ga-event' => 'select_content', 'data-ga-params' => '{"content_type":"pdf"}', 'data-ga-on' => 'change'], $link->getAttributes()->toArray());
		$module->setClickEvent($link, 'file_download');
		self::assertSame(['data-ga-event' => 'file_download'], $link->getAttributes()->toArray(), 'stale params and trigger are removed');
	}

	public function testFullPagesGetTheClickScript()
	{
		$module = $this->module();
		$page = new HeadedPage();
		$page->attachHead();
		$module->registerPageScripts($page);
		$module->preRenderCompleteHandler($page, null);
		self::assertFalse($page->getClientScript()->isHeadScriptRegistered(GAnalyticsModule::CLICK_SCRIPT_KEY), 'off by default');

		$module->setTrackClicks(true);
		$module->preRenderCompleteHandler($page, null);
		self::assertTrue($page->getClientScript()->isHeadScriptRegistered(GAnalyticsModule::CLICK_SCRIPT_KEY));

		$bare = new TPage();
		$module->registerClickScript($bare);
		self::assertTrue($bare->getClientScript()->isBeginScriptRegistered(GAnalyticsModule::CLICK_SCRIPT_KEY), 'no THead: the form');

		$callback = new CallbackPage();
		$module->registerPageScripts($callback);
		$module->preRenderCompleteHandler($callback, null);
		self::assertFalse($callback->getClientScript()->isBeginScriptRegistered(GAnalyticsModule::CLICK_SCRIPT_KEY), 'a callback page already has it');
	}

	// =========================================================================
	// Virtual page views
	// =========================================================================

	public function testVirtualPageViewOnAFullPage()
	{
		$module = $this->module();
		$request = $this->_app->getRequest();
		self::assertSame($request->getBaseUrl() . $request->getRequestUri(), $module->getPageLocation());
		self::assertStringStartsWith('https://', $module->getPageLocation());
		$page = new TPage();
		$page->setTitle('Checkout');
		$module->registerPageScripts($page);
		$module->trackVirtualPageView('Steps:Payment', 'Payment');
		$expected = ['page_location' => $module->getPageLocation() . '#Steps:Payment', 'page_title' => 'Checkout | Payment'];
		self::assertSame($expected, $module->getVirtualPage());
		self::assertSame($expected, $module->getEffectiveConfigOptions($page), 'the page view itself carries the view');
		self::assertSame([], $module->getQueuedCalls(), 'no second page_view');
	}

	public function testVirtualPageViewOnACallbackOrWithoutAPage()
	{
		$module = $this->module();
		$module->trackVirtualPageView('Views:Two', 'Two');
		self::assertSame([['event', 'page_view', ['page_location' => $module->getPageLocation() . '#Views:Two', 'page_title' => 'Two']]], $module->deferred(), 'no page: the next one');

		$callback = new CallbackPage();
		$module->registerPageScripts($callback);
		$module->trackVirtualPageView('Views:Three', \str_repeat('x', 120));
		self::assertNull($module->getVirtualPage());
		$call = $module->getQueuedCalls()[0];
		self::assertSame(['event', 'page_view'], [$call[0], $call[1]]);
		self::assertSame(100, \mb_strlen($call[2]['page_title']));
	}

	public function testTabTracking()
	{
		$module = $this->module();
		$page = new TPage();
		$page->setTitle('Account');
		$panel = new TTabPanel();
		$panel->setID('Tabs');
		$page->getControls()->add($panel);
		$profile = new TTabView();
		$profile->setID('Profile');
		$profile->setCaption('Your profile');
		$billing = new TTabView();
		$billing->setID('Billing');
		$panel->getControls()->add($profile);
		$panel->getControls()->add($billing);

		self::assertSame(2, $module->registerTabTracking($panel));
		$cs = $page->getClientScript();
		self::assertTrue($cs->isBeginScriptRegistered(GAnalyticsModule::CLICK_SCRIPT_KEY));
		self::assertTrue($cs->isEndScriptRegistered(GAnalyticsModule::CLICK_SCRIPT_KEY . ':' . $panel->getClientID()));
		$location = $module->getPageLocation();
		$script = $this->endScript($page, GAnalyticsModule::CLICK_SCRIPT_KEY . ':' . $panel->getClientID());
		self::assertStringStartsWith('pradoGAnalyticsSendTabs([', $script);
		self::assertStringContainsString(\json_encode($location . '#Tabs:Profile', JSON_UNESCAPED_SLASHES), \stripslashes($script));
		self::assertStringContainsString('Account | Your profile', $script);
		self::assertStringContainsString('Account | Billing', $script, 'no caption: the ID');
		self::assertStringContainsString($profile->getClientID() . '_0', $script);
	}

	public function testVirtualPageTitles()
	{
		$module = $this->module();
		self::assertSame('Payment', $module->getVirtualPageTitle(null, 'Payment'));
		$page = new TPage();
		self::assertSame('Payment', $module->getVirtualPageTitle($page, 'Payment'), 'no page title');
		$page->setTitle(' Shop ');
		self::assertSame('Shop | Payment', $module->getVirtualPageTitle($page, 'Payment'));
	}

	/** Reads a registered end script. */
	private function endScript(TPage $page, string $key): string
	{
		$property = new \ReflectionProperty($page->getClientScript(), '_endScripts');
		$property->setAccessible(true);
		return (string) $property->getValue($page->getClientScript())[$key];
	}

	// =========================================================================
	// Control tracking
	// =========================================================================

	public function testTrackControlsProperty()
	{
		$module = $this->module();
		self::assertSame([], $module->getTrackControls());
		$module->setTrackControls(' Wizards, paging ,,wizards');
		self::assertSame(['wizards', 'paging'], $module->getTrackControls());
		$module->setTrackControls(['all']);
		self::assertSame(\array_keys(GAnalyticsModule::CONTROL_TRACKING), $module->getTrackControls());
		$module->setTrackControls('');
		self::assertSame([], $module->getTrackControls());
		$this->expectException(TInvalidDataValueException::class);
		$module->setTrackControls('wizards, grids');
	}

	public function testControlBehaviorsAttachToTheClasses()
	{
		$module = $this->module();
		$module->setTrackControls('all');
		$behaviors = $module->attachControlBehaviors();
		self::assertSame(\array_keys(GAnalyticsModule::CONTROL_TRACKING), \array_keys($behaviors));
		self::assertSame($behaviors, $module->attachControlBehaviors(), 'once');
		self::assertInstanceOf(GAnalyticsControlBehavior::class, (new TWizard())->asa(GAnalyticsModule::CONTROL_BEHAVIOR_NAME . '-wizards'));
		self::assertNotNull((new TPager())->asa(GAnalyticsModule::CONTROL_BEHAVIOR_NAME . '-paging'));
		self::assertNotNull((new TDataGrid())->asa(GAnalyticsModule::CONTROL_BEHAVIOR_NAME . '-paging'));
		self::assertNotNull((new TJuiAutoComplete())->asa(GAnalyticsModule::CONTROL_BEHAVIOR_NAME . '-searches'));
		self::assertSame(5, $module->detachControlBehaviors());
		self::assertNull((new TWizard())->asa(GAnalyticsModule::CONTROL_BEHAVIOR_NAME . '-wizards'));
		self::assertSame(0, $module->detachControlBehaviors());
	}

	public function testTheHookAttachesTheBehaviors()
	{
		$module = $this->module();
		$module->setTrackControls('views');
		$module->attachPageServiceHandler($this->_app, null);
		self::assertNotNull((new TMultiView())->asa(GAnalyticsModule::CONTROL_BEHAVIOR_NAME . '-views'));
		self::assertNull((new TWizard())->asa(GAnalyticsModule::CONTROL_BEHAVIOR_NAME . '-wizards'));
	}

	public function testBehaviorKinds()
	{
		$module = $this->module();
		$behavior = new GAnalyticsControlBehavior($module, 'tabs');
		self::assertSame($module, $behavior->getModule());
		self::assertSame('tabs', $behavior->getKind());
		self::assertSame(['onPreRender' => 'tabPanelPreRender'], $behavior->events());
		self::assertSame(['onActiveStepChanged', 'onCompleteButtonClick', 'onCancelButtonClick'], \array_keys((new GAnalyticsControlBehavior($module, 'wizards'))->events()));
		self::assertSame(['onActiveViewChanged' => 'viewChanged'], (new GAnalyticsControlBehavior($module, 'views'))->events());
		self::assertSame(['onPageIndexChanged' => 'pageChanged'], (new GAnalyticsControlBehavior($module, 'paging'))->events());
		self::assertSame(['onSuggestionSelected' => 'suggestionSelected'], (new GAnalyticsControlBehavior($module, 'searches'))->events());
		$this->expectException(TInvalidDataValueException::class);
		new GAnalyticsControlBehavior($module, 'grids');
	}

	private function wizard(TPage $page): TWizard
	{
		$wizard = new TWizard();
		$wizard->setID('Checkout');
		$page->getControls()->add($wizard);
		foreach (['Address' => 'Your address', 'Payment' => ''] as $id => $title) {
			$step = new TWizardStep();
			$step->setID($id);
			$step->setTitle($title);
			$wizard->getWizardSteps()->add($step);
		}
		return $wizard;
	}

	public function testWizardEvents()
	{
		$module = $this->module();
		$behavior = new GAnalyticsControlBehavior($module, 'wizards');
		$page = new PostBackPage();
		$module->registerPageScripts($page);
		$wizard = $this->wizard($page);
		$wizard->setActiveStepIndex(0);

		$behavior->wizardStepChanged($wizard, null);
		self::assertSame(['event', 'wizard_step', ['wizard' => 'Checkout', 'step_index' => 1, 'step_name' => 'Your address', 'step_count' => 2]], $module->getQueuedCalls()[0]);
		$wizard->setActiveStepIndex(1);
		$behavior->wizardStepChanged($wizard, null);
		self::assertSame('Payment', $module->getQueuedCalls()[1][2]['step_name'], 'no title: the ID');

		$behavior->wizardCompleted($wizard, null);
		self::assertSame(['event', 'wizard_complete', ['wizard' => 'Checkout', 'step_count' => 2]], $module->getQueuedCalls()[2]);
		$behavior->wizardCancelled($wizard, null);
		self::assertSame(['event', 'wizard_cancel', ['wizard' => 'Checkout', 'step_index' => 2, 'step_name' => 'Payment']], $module->getQueuedCalls()[3]);

		$wizard->setFinishDestinationUrl('/done');
		$wizard->setCancelDestinationUrl('/cart');
		$behavior->wizardCompleted($wizard, null);
		$behavior->wizardCancelled($wizard, null);
		self::assertSame(['wizard_complete', 'wizard_cancel'], \array_map(fn ($call) => $call[1], $module->deferred()), 'a redirect defers them');
		self::assertCount(4, $module->getQueuedCalls());

		$first = new TPage();
		$behavior->wizardStepChanged($this->wizard($first), null);
		self::assertCount(4, $module->getQueuedCalls(), 'the first request activates the first step: no event');
	}

	public function testWizardWithoutSteps()
	{
		$module = $this->module();
		$page = new PostBackPage();
		$module->registerPageScripts($page);
		$wizard = new TWizard();
		$wizard->setID('Empty');
		$page->getControls()->add($wizard);
		(new GAnalyticsControlBehavior($module, 'wizards'))->wizardCancelled($wizard, null);
		self::assertSame('', $module->getQueuedCalls()[0][2]['step_name']);
	}

	public function testViewChanges()
	{
		$module = $this->module();
		$behavior = new GAnalyticsControlBehavior($module, 'views');
		$page = new PostBackPage();
		$module->registerPageScripts($page);
		$views = new TMultiView();
		$views->setID('Steps');
		$page->getControls()->add($views);
		$behavior->viewChanged($views, null);
		self::assertNull($module->getVirtualPage(), 'no active view');
		foreach (['One', 'Two'] as $id) {
			$view = new TView();
			$view->setID($id);
			$views->getControls()->add($view);
		}
		$views->setActiveViewIndex(1);
		$behavior->viewChanged($views, null);
		self::assertStringEndsWith('#Steps:Two', $module->getVirtualPage()['page_location']);

		$first = new TPage();
		$other = new ProbeGAnalyticsModule();
		$other->setMeasurementId('G-TEST1234AB');
		$other->registerPageScripts($first);
		$first->getControls()->add($views);
		(new GAnalyticsControlBehavior($other, 'views'))->viewChanged($views, null);
		self::assertNull($other->getVirtualPage(), 'the first request: no event');
	}

	public function testTabPanelsAreTrackedOnFullPages()
	{
		$module = $this->module();
		$behavior = new GAnalyticsControlBehavior($module, 'tabs');
		foreach ([new TPage(), new CallbackPage()] as $page) {
			$panel = new TTabPanel();
			$page->getControls()->add($panel);
			$behavior->tabPanelPreRender($panel, null);
			self::assertSame(!$page->getIsCallback(), $page->getClientScript()->isEndScriptRegistered(GAnalyticsModule::CLICK_SCRIPT_KEY . ':' . $panel->getClientID()));
		}
	}

	public function testPaging()
	{
		$module = $this->module();
		$behavior = new GAnalyticsControlBehavior($module, 'paging');
		$page = new PostBackPage();
		$module->registerPageScripts($page);
		$grid = new TDataGrid();
		$grid->setID('Orders');
		$behavior->pageChanged($grid, new \Prado\Web\UI\WebControls\TDataGridPageChangedEventParameter(null, 2));
		$grid->setCaption('Recent orders');
		$behavior->pageChanged($grid, new \Prado\Web\UI\WebControls\TDataGridPageChangedEventParameter(null, 0));
		$pager = new TPager();
		$pager->setID('Pages');
		$behavior->pageChanged($pager, new \Prado\Web\UI\WebControls\TPagerPageChangedEventParameter(null, 4));
		self::assertSame([
			['event', 'view_item_list', ['item_list_id' => 'Orders', 'item_list_name' => 'Orders', 'page' => 3]],
			['event', 'view_item_list', ['item_list_id' => 'Orders', 'item_list_name' => 'Recent orders', 'page' => 1]],
			['event', 'view_item_list', ['item_list_id' => 'Pages', 'item_list_name' => 'Pages', 'page' => 5]],
		], $module->getQueuedCalls());
	}

	public function testSearches()
	{
		$module = $this->module();
		$behavior = new GAnalyticsControlBehavior($module, 'searches');
		$page = new CallbackPage();
		$module->registerPageScripts($page);
		$box = new TJuiAutoComplete();
		$behavior->suggestionSelected($box, null);
		self::assertSame([], $module->getQueuedCalls(), 'no text, no search');
		$box->setText(' blue mug ');
		$behavior->suggestionSelected($box, null);
		self::assertSame([['event', 'search', ['search_term' => 'blue mug']]], $module->getQueuedCalls());
	}

	// =========================================================================
	// Cached reports and module lookup
	// =========================================================================

	public function testRunCachedReport()
	{
		$module = $this->module();
		$module->setPropertyId('123');
		$module->setCredentials(new \belisoful\GAnalytics\GAnalyticsAccessTokenCredentials('tok'));
		$module->dataApi->answer(['metricHeaders' => [['name' => 'activeUsers', 'type' => 'TYPE_INTEGER']], 'rows' => [['metricValues' => [['value' => '7']]]]]);
		$request = ['metrics' => [['name' => 'activeUsers']]];

		self::assertSame([['activeUsers' => 7]], $module->runCachedReport($request, true, 60)->getRows(), 'no cache module');
		self::assertSame([['activeUsers' => 7]], $module->runCachedReport($request, false)->getRows());
		self::assertCount(2, $module->dataApi->requests);
		self::assertStringEndsWith(':runRealtimeReport', $module->dataApi->requests[0]['url']);
		self::assertStringEndsWith(':runReport', $module->dataApi->requests[1]['url']);

		$cache = new MemoryCache();
		$this->_app->setCache($cache);
		$first = $module->runCachedReport($request, true, 60);
		$again = $module->runCachedReport($request, true, 60);
		self::assertInstanceOf(GAnalyticsReport::class, $again);
		self::assertSame($first->getRows(), $again->getRows());
		self::assertCount(3, $module->dataApi->requests, 'the second one comes from the cache');
		$key = \array_key_first($cache->store);
		self::assertSame(60, $cache->store[$key][1]);
		$module->runCachedReport($request, false, 60);
		self::assertCount(4, $module->dataApi->requests, 'a report and a realtime report are different keys');
		$module->runCachedReport($request, true, 0);
		self::assertCount(5, $module->dataApi->requests, '0 is not cached');
	}

	public function testFindModule()
	{
		self::assertNull(GAnalyticsModule::findModule(), 'none registered');
		$first = $this->module();
		$this->register('ga-find-a', $first);
		$second = $this->module();
		$this->register('ga-find-b', $second);
		$this->register('ga-find-other', new FakeCredentialsModule());
		self::assertSame($first, GAnalyticsModule::findModule());
		self::assertSame($second, GAnalyticsModule::findModule('ga-find-b'));
		self::assertNull(GAnalyticsModule::findModule('ga-find-other'));
		self::assertNull(GAnalyticsModule::findModule('ga-missing'));
	}
}
