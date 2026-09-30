<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsAccessTokenCredentials;
use belisoful\GAnalytics\GAnalyticsModule;
use belisoful\GAnalytics\GAnalyticsRealtimeCounter;
use belisoful\GAnalytics\GAnalyticsReportDataSource;
use belisoful\GAnalytics\GAnalyticsReportDataSourceView;
use PHPUnit\Framework\TestCase;
use Prado\Collections\TList;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\TTextWriter;
use Prado\Prado;
use Prado\TApplication;
use Prado\TComponent;
use Prado\Web\Services\TPageService;
use Prado\Web\UI\THtmlWriter;
use Prado\Web\UI\TPage;
use Prado\Web\TAssetManager;
use Prado\Web\UI\WebControls\TDropDownList;

/** An asset manager publishing into a given directory. */
class DirectoryAssetManager extends TAssetManager
{
	public function useDirectory(string $dir): void
	{
		$this->setBasePathDirect($dir);
	}
}

class GAnalyticsReportControlsTest extends TestCase
{
	private TApplication $_app;

	private ?\Prado\IService $_previousService = null;

	/** @var array<string, mixed> the application properties the test isolates, by name */
	private array $_snapshot = [];

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
	}

	protected function tearDown(): void
	{
		// The application's modules and cache are shared by every test class; the snapshot restores them.
		foreach ($this->_snapshot as $name => $value) {
			$property = new \ReflectionProperty(TApplication::class, $name);
			$property->setAccessible(true);
			$property->setValue($this->_app, $value);
		}
		$this->_app->setService($this->_previousService);
		TComponent::detachClassBehavior(GAnalyticsModule::PAGE_BEHAVIOR_NAME, TPage::class);
	}

	private function analytics(string $id = 'ga-report-controls'): ProbeGAnalyticsModule
	{
		$module = new ProbeGAnalyticsModule();
		$module->setAttachPageBehavior(false);
		$module->setAmendCsp(false);
		$module->setMeasurementId('G-TEST1234AB');
		$module->setPropertyId('123');
		$module->setCredentials(new GAnalyticsAccessTokenCredentials('tok'));
		$module->setID($id);
		$this->_app->setModule($id, $module);
		return $module;
	}

	/** @param array<int, array<string, int|string>> $rows pagePath and screenPageViews */
	private function answer(ProbeGAnalyticsModule $module, array $rows): void
	{
		$module->dataApi->answer([
			'dimensionHeaders' => [['name' => 'pagePath']],
			'metricHeaders' => [['name' => 'screenPageViews', 'type' => 'TYPE_INTEGER']],
			'rows' => \array_map(fn ($row) => ['dimensionValues' => [['value' => $row[0]]], 'metricValues' => [['value' => (string) $row[1]]]], $rows),
		]);
	}

	// =========================================================================
	// GAnalyticsReportDataSource
	// =========================================================================

	public function testDataSourceDefaultsAndProperties()
	{
		$source = new GAnalyticsReportDataSource();
		self::assertSame([], $source->getMetrics());
		self::assertSame([], $source->getDimensions());
		self::assertSame('28daysAgo', $source->getStartDate());
		self::assertSame('today', $source->getEndDate());
		self::assertSame([], $source->getOrderBy());
		self::assertSame(0, $source->getLimit());
		self::assertFalse($source->getRealtime());
		self::assertSame([], $source->getRequestOptions());
		self::assertSame(3600, $source->getCacheExpire());
		self::assertSame('', $source->getAnalyticsModule());

		$source->setMetrics(' screenPageViews, activeUsers ,');
		$source->setDimensions(['pagePath', ' ']);
		$source->setStartDate(' 7daysAgo ');
		$source->setEndDate('yesterday');
		$source->setOrderBy('-screenPageViews, pagePath');
		$source->setLimit('-5');
		$source->setRealtime('true');
		$source->setRequestOptions('{"keepEmptyRows": true}');
		$source->setCacheExpire(-1);
		$source->setAnalyticsModule(' ga ');
		self::assertSame(['screenPageViews', 'activeUsers'], $source->getMetrics());
		self::assertSame(['pagePath'], $source->getDimensions());
		self::assertSame('7daysAgo', $source->getStartDate());
		self::assertSame('yesterday', $source->getEndDate());
		self::assertSame(['-screenPageViews', 'pagePath'], $source->getOrderBy());
		self::assertSame(0, $source->getLimit());
		self::assertTrue($source->getRealtime());
		self::assertSame(['keepEmptyRows' => true], $source->getRequestOptions());
		self::assertSame(0, $source->getCacheExpire());
		self::assertSame('ga', $source->getAnalyticsModule());

		$source->setStartDate('');
		$source->setEndDate('');
		$source->setRequestOptions('');
		self::assertSame('28daysAgo', $source->getStartDate());
		self::assertSame('today', $source->getEndDate());
		self::assertSame([], $source->getRequestOptions());
		$source->setRequestOptions(['offset' => 10]);
		self::assertSame(['offset' => 10], $source->getRequestOptions());
		$this->expectException(TInvalidDataValueException::class);
		$source->setRequestOptions('not json');
	}

	public function testDataSourceViews()
	{
		$source = new GAnalyticsReportDataSource();
		$view = $source->getView('');
		self::assertInstanceOf(GAnalyticsReportDataSourceView::class, $view);
		self::assertSame($view, $source->getView('default'));
		self::assertSame('default', $view->getName());
		self::assertSame($source, $view->getDataSource());
		self::assertNull($source->getView('other'));
		self::assertSame(['default'], $source->getViewNames());
	}

	public function testDataSourceRequest()
	{
		$source = new GAnalyticsReportDataSource();
		$source->setID('Top');
		try {
			$source->getReportRequest();
			self::fail('no metric');
		} catch (TConfigurationException $e) {
			self::assertSame('ganalytics_report_metrics_required', $e->getErrorCode());
		}
		$source->setMetrics('screenPageViews');
		self::assertSame(['dateRanges' => [['startDate' => '28daysAgo', 'endDate' => 'today']], 'metrics' => [['name' => 'screenPageViews']]], $source->getReportRequest());

		$source->setDimensions('pagePath');
		$source->setOrderBy('-screenPageViews, pagePath');
		$source->setLimit(10);
		$source->setRequestOptions(['limit' => 5, 'keepEmptyRows' => true]);
		self::assertSame([
			'dateRanges' => [['startDate' => '28daysAgo', 'endDate' => 'today']],
			'metrics' => [['name' => 'screenPageViews']],
			'dimensions' => [['name' => 'pagePath']],
			'limit' => 5,
			'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true], ['dimension' => ['dimensionName' => 'pagePath'], 'desc' => false]],
			'keepEmptyRows' => true,
		], $source->getReportRequest(), 'RequestOptions override the built fields');

		$source->setRealtime(true);
		$source->setRequestOptions('');
		self::assertArrayNotHasKey('dateRanges', $source->getReportRequest());
		self::assertSame(10, $source->getReportRequest()['limit']);
	}

	public function testDataSourceChangesRebind()
	{
		$source = new GAnalyticsReportDataSource();
		$source->setMetrics('screenPageViews');
		$changes = 0;
		$source->getView('')->attachEventHandler('OnDataSourceViewChanged', function () use (&$changes) {
			$changes++;
		});
		$source->setMetrics('screenPageViews');
		self::assertSame(0, $changes, 'the same value changes nothing');
		$source->setMetrics('activeUsers');
		$source->setDimensions('country');
		$source->setLimit(3);
		self::assertSame(3, $changes);
		$source->setCacheExpire(10);
		self::assertSame(3, $changes, 'the cache is not the data');
	}

	public function testDataSourceSelectsTheRows()
	{
		$module = $this->analytics();
		$this->answer($module, [['/', 40], ['/about', 7]]);
		$source = new GAnalyticsReportDataSource();
		$source->setMetrics('screenPageViews');
		$source->setDimensions('pagePath');
		$source->setAnalyticsModule('ga-report-controls');
		$rows = $source->getView('')->select(null);
		self::assertInstanceOf(TList::class, $rows);
		self::assertSame([['pagePath' => '/', 'screenPageViews' => 40], ['pagePath' => '/about', 'screenPageViews' => 7]], $rows->toArray());
		self::assertStringEndsWith('/properties/123:runReport', $module->dataApi->requests[0]['url']);

		$source->setRealtime(true);
		$source->getReport();
		self::assertStringEndsWith(':runRealtimeReport', $module->dataApi->requests[1]['url']);

		$source->setAnalyticsModule('missing');
		$this->expectException(TConfigurationException::class);
		$source->getReport();
	}

	public function testADataControlBindsByDataSourceID()
	{
		$module = $this->analytics();
		$this->answer($module, [['/', 40], ['/about', 7], ['/contact', 2]]);
		$page = new TPage();
		$source = new GAnalyticsReportDataSource();
		$source->setID('Top');
		$source->setMetrics('screenPageViews');
		$source->setDimensions('pagePath');
		$list = new TDropDownList();
		$list->setID('List');
		$list->setDataSourceID('Top');
		$list->setDataTextField('pagePath');
		$list->setDataValueField('screenPageViews');
		$page->getControls()->add($source);
		$page->getControls()->add($list);
		$list->dataBind();
		self::assertSame(3, $list->getItems()->getCount());
		self::assertSame('/about', $list->getItems()->itemAt(1)->getText());
		self::assertSame('7', $list->getItems()->itemAt(1)->getValue());
	}

	// =========================================================================
	// GAnalyticsRealtimeCounter
	// =========================================================================

	public function testCounterDefaultsAndProperties()
	{
		$counter = new GAnalyticsRealtimeCounter();
		self::assertSame(60, $counter->getInterval());
		self::assertTrue($counter->getStartTimerOnLoad());
		self::assertSame('activeUsers', $counter->getMetric());
		self::assertSame('{0}', $counter->getFormat());
		self::assertSame('', $counter->getErrorText());
		self::assertSame(60, $counter->getCacheExpire());
		self::assertSame('', $counter->getCssClass());
		self::assertSame('', $counter->getAnalyticsModule());

		$counter->setInterval('30');
		$counter->setStartTimerOnLoad(false);
		$counter->setMetric(' screenPageViews ');
		$counter->setFormat('{0} now');
		$counter->setErrorText('n/a');
		$counter->setCacheExpire('-3');
		$counter->setCssClass(' live ');
		$counter->setAnalyticsModule(' ga ');
		self::assertSame(30.0, $counter->getInterval());
		self::assertFalse($counter->getStartTimerOnLoad());
		self::assertSame('screenPageViews', $counter->getMetric());
		self::assertSame('{0} now', $counter->getFormat());
		self::assertSame('n/a', $counter->getErrorText());
		self::assertSame(0, $counter->getCacheExpire());
		self::assertSame('live', $counter->getCssClass());
		self::assertSame('ga', $counter->getAnalyticsModule());

		$counter->setMetric('');
		$counter->setFormat('');
		self::assertSame('activeUsers', $counter->getMetric());
		self::assertSame('{0}', $counter->getFormat());
		$this->expectException(TConfigurationException::class);
		$counter->setInterval(0);
	}

	public function testCounterValue()
	{
		$module = $this->analytics();
		$module->dataApi->answer([
			'dimensionHeaders' => [['name' => 'country']],
			'metricHeaders' => [['name' => 'activeUsers', 'type' => 'TYPE_INTEGER']],
			'rows' => [['dimensionValues' => [['value' => 'US']], 'metricValues' => [['value' => '5']]], ['dimensionValues' => [['value' => 'DE']], 'metricValues' => [['value' => '3']]]],
		]);
		$counter = new GAnalyticsRealtimeCounter();
		$counter->setFormat('{0} active');
		self::assertSame(8, $counter->getValue());
		self::assertSame('8 active', $counter->getDisplayText());
		self::assertSame(['metrics' => [['name' => 'activeUsers']]], $module->dataApi->lastBody());
		self::assertStringEndsWith(':runRealtimeReport', $module->dataApi->requests[0]['url']);

		$module->dataApi->answer(['metricHeaders' => [['name' => 'activeUsers', 'type' => 'TYPE_INTEGER']]]);
		self::assertSame(0, $counter->getValue(), 'no rows is 0');

		$module->dataApi->answer(['error' => ['message' => 'quota']], 429);
		$counter->setErrorText('unavailable');
		self::assertNull($counter->getValue());
		self::assertSame('unavailable', $counter->getDisplayText());

		$counter->setAnalyticsModule('missing');
		self::assertNull($counter->getValue(), 'no module is logged, not thrown');
	}

	/** Runs code with an asset manager publishing into a temporary directory, for the timer's script. */
	private function withAssets(callable $run): void
	{
		$dir = \dirname(__DIR__) . DIRECTORY_SEPARATOR . 'unit' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'assets';
		if (!\is_dir($dir)) {
			\mkdir($dir);
		}
		$property = new \ReflectionProperty(TApplication::class, '_assetManager');
		$property->setAccessible(true);
		$previous = $property->getValue($this->_app);
		$assets = new DirectoryAssetManager();
		$assets->useDirectory($dir);
		$assets->setBaseUrl('/assets');
		$assets->init(null);
		try {
			$run();
		} finally {
			$property->setValue($this->_app, $previous);
		}
	}

	public function testCounterRendersAndRefreshes()
	{
		$module = $this->analytics();
		$module->dataApi->answer(['metricHeaders' => [['name' => 'activeUsers', 'type' => 'TYPE_INTEGER']], 'rows' => [['metricValues' => [['value' => '4']]]]]);
		$page = new CallbackPage();
		$counter = new GAnalyticsRealtimeCounter();
		$counter->setID('Live');
		$counter->setFormat('<b>{0}</b>');
		$counter->setCssClass('live');
		$page->getControls()->add($counter);

		$writer = new THtmlWriter(new TTextWriter());
		$this->withAssets(fn () => $counter->render($writer));
		self::assertStringStartsWith('<span id="' . $counter->getClientID() . '_value" class="live">&lt;b&gt;4&lt;/b&gt;</span>', $writer->flush());

		$counter->onCallback(null);
		$actions = $page->client->getClientFunctionsToExecute();
		self::assertCount(1, $actions);
		self::assertStringContainsString($counter->getValueClientID(), \json_encode($actions));
		self::assertStringContainsString('&lt;b&gt;4&lt;/b&gt;', \json_encode($actions, JSON_UNESCAPED_SLASHES));

		$plain = new GAnalyticsRealtimeCounter();
		$page->getControls()->add($plain);
		$writer = new THtmlWriter(new TTextWriter());
		$this->withAssets(fn () => $plain->render($writer));
		self::assertStringStartsWith('<span id="' . $plain->getClientID() . '_value">4</span>', $writer->flush(), 'no class attribute without a CssClass');
	}
}
