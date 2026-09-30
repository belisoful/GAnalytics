<?php

/**
 * GAnalyticsReportDataSource class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/belisoful/GAnalytics/blob/main/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\TPropertyValue;
use Prado\Web\UI\WebControls\TDataSourceControl;

/**
 * GAnalyticsReportDataSource class.
 *
 * GAnalyticsReportDataSource is a data source control for a Google Analytics report. A data
 * control binds to it by `DataSourceID`, and each row is an array keyed by dimension and metric
 * name ({@see GAnalyticsReport::getRows()}):
 *
 * ```xml
 * <com:belisoful.GAnalytics.GAnalyticsReportDataSource ID="TopPages" Metrics="screenPageViews, activeUsers"
 *     Dimensions="pagePath" StartDate="7daysAgo" OrderBy="-screenPageViews" Limit="10" />
 * <com:TDataGrid DataSourceID="TopPages" AutoGenerateColumns="true" />
 * ```
 *
 * | Property | Default | Meaning |
 * |---|---|---|
 * | `Metrics`, `Dimensions` | none | The names, comma-separated; at least one metric |
 * | `StartDate`, `EndDate` | `28daysAgo`, `today` | The date range: `YYYY-MM-DD`, `NdaysAgo`, `yesterday` or `today` |
 * | `OrderBy` | none | Names, comma-separated; a `-` prefix sorts descending. A name of `Metrics` sorts by the metric, any other by the dimension |
 * | `Limit` | 0 (Google's default) | The most rows |
 * | `Realtime` | false | A realtime report over the last 30 minutes; the dates are ignored |
 * | `RequestOptions` | none | Further request fields, such as `dimensionFilter`, as an array or a JSON object |
 * | `CacheExpire` | 3600 | The seconds a response is shared through the application cache ({@see GAnalyticsModule::runCachedReport()}); 0 runs every request |
 * | `AnalyticsModule` | the first `GAnalyticsModule` | The module id whose property and credentials run the report |
 *
 * The properties live in the view state. A change raises `OnDataSourceViewChanged`, so a bound
 * control binds again. A refused request throws {@see GAnalyticsApiException}.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GAnalyticsReportDataSource extends TDataSourceControl
{
	/** The name of the one view. */
	public const VIEW_NAME = 'default';

	/** The default seconds a response is shared. */
	public const DEFAULT_CACHE_EXPIRE = 3600;

	/** @var ?GAnalyticsReportDataSourceView The view, created on first use. */
	private ?GAnalyticsReportDataSourceView $_view = null;

	/**
	 * @param string $viewName The view name: empty or {@see VIEW_NAME}.
	 * @return ?GAnalyticsReportDataSourceView The view; null for another name.
	 */
	public function getView($viewName)
	{
		if ($viewName !== '' && $viewName !== static::VIEW_NAME) {
			return null;
		}
		return $this->_view ??= new GAnalyticsReportDataSourceView($this, static::VIEW_NAME);
	}

	/**
	 * @return string[] The view names: {@see VIEW_NAME}.
	 */
	public function getViewNames()
	{
		return [static::VIEW_NAME];
	}

	/**
	 * Runs the report, shared through the application cache for {@see getCacheExpire() CacheExpire} seconds.
	 * @throws TConfigurationException When no analytics module is found, or its property or credentials are unset.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return GAnalyticsReport The report.
	 */
	public function getReport(): GAnalyticsReport
	{
		$module = GAnalyticsModule::findModule($this->getAnalyticsModule());
		if ($module === null) {
			throw new TConfigurationException('ganalytics_module_invalid', $this->getAnalyticsModule() ?: '(any)', GAnalyticsModule::class);
		}
		return $module->runCachedReport($this->getReportRequest(), $this->getRealtime(), $this->getCacheExpire());
	}

	/**
	 * Builds the Data API request from the properties.
	 * @throws TConfigurationException When no metric is set.
	 * @return array<string, mixed> The `RunReportRequest` or `RunRealtimeReportRequest` body.
	 */
	public function getReportRequest(): array
	{
		$metrics = $this->getMetrics();
		if (\count($metrics) === 0) {
			throw new TConfigurationException('ganalytics_report_metrics_required', $this->getID());
		}
		$extra = [];
		if ($this->getLimit() > 0) {
			$extra['limit'] = $this->getLimit();
		}
		$orderBys = [];
		foreach ($this->getOrderBy() as $name) {
			$desc = \str_starts_with($name, '-');
			$name = \ltrim($name, '-');
			$orderBys[] = \in_array($name, $metrics, true)
				? ['metric' => ['metricName' => $name], 'desc' => $desc]
				: ['dimension' => ['dimensionName' => $name], 'desc' => $desc];
		}
		if (\count($orderBys) > 0) {
			$extra['orderBys'] = $orderBys;
		}
		$extra = \array_merge($extra, $this->getRequestOptions());
		return $this->getRealtime()
			? GAnalyticsDataApi::realtimeRequest($metrics, $this->getDimensions(), $extra)
			: GAnalyticsDataApi::reportRequest($metrics, $this->getDimensions(), $this->getStartDate(), $this->getEndDate(), $extra);
	}

	/**
	 * Raises `OnDataSourceViewChanged` on the view, so bound controls bind again.
	 */
	protected function changed(): void
	{
		$this->_view?->onDataSourceViewChanged(null);
	}

	/**
	 * @param string $name The view state key.
	 * @param mixed $value The value.
	 * @param mixed $default The default.
	 */
	protected function setOption(string $name, mixed $value, mixed $default): void
	{
		if ($this->getViewState($name, $default) !== $value) {
			$this->setViewState($name, $value, $default);
			$this->changed();
		}
	}

	/**
	 * @param mixed $value Names as an array or a comma-separated string.
	 * @return string[] The trimmed, non-empty names.
	 */
	protected static function ensureNames(mixed $value): array
	{
		$names = \is_array($value) ? $value : \explode(',', (string) TPropertyValue::ensureString($value));
		return \array_values(\array_filter(\array_map(fn ($name) => \trim((string) $name), $names), fn ($name) => $name !== ''));
	}

	/**
	 * @return string[] The metric names.
	 */
	public function getMetrics(): array
	{
		return $this->getViewState('Metrics', []);
	}

	/**
	 * @param mixed $value The metric names, as an array or a comma-separated string.
	 */
	public function setMetrics($value): void
	{
		$this->setOption('Metrics', static::ensureNames($value), []);
	}

	/**
	 * @return string[] The dimension names.
	 */
	public function getDimensions(): array
	{
		return $this->getViewState('Dimensions', []);
	}

	/**
	 * @param mixed $value The dimension names, as an array or a comma-separated string.
	 */
	public function setDimensions($value): void
	{
		$this->setOption('Dimensions', static::ensureNames($value), []);
	}

	/**
	 * @return string The start date. Defaults to `28daysAgo`.
	 */
	public function getStartDate(): string
	{
		return $this->getViewState('StartDate', '28daysAgo');
	}

	/**
	 * @param mixed $value The start date: `YYYY-MM-DD`, `NdaysAgo`, `yesterday` or `today`; empty restores `28daysAgo`.
	 */
	public function setStartDate($value): void
	{
		$value = \trim((string) TPropertyValue::ensureString($value));
		$this->setOption('StartDate', $value === '' ? '28daysAgo' : $value, '28daysAgo');
	}

	/**
	 * @return string The end date. Defaults to `today`.
	 */
	public function getEndDate(): string
	{
		return $this->getViewState('EndDate', 'today');
	}

	/**
	 * @param mixed $value The end date, in the start date's forms; empty restores `today`.
	 */
	public function setEndDate($value): void
	{
		$value = \trim((string) TPropertyValue::ensureString($value));
		$this->setOption('EndDate', $value === '' ? 'today' : $value, 'today');
	}

	/**
	 * @return string[] The sort names; a `-` prefix sorts descending.
	 */
	public function getOrderBy(): array
	{
		return $this->getViewState('OrderBy', []);
	}

	/**
	 * @param mixed $value The sort names, as an array or a comma-separated string; a `-` prefix sorts descending.
	 */
	public function setOrderBy($value): void
	{
		$this->setOption('OrderBy', static::ensureNames($value), []);
	}

	/**
	 * @return int The most rows; 0 for Google's default.
	 */
	public function getLimit(): int
	{
		return $this->getViewState('Limit', 0);
	}

	/**
	 * @param mixed $value The most rows; 0 or less for Google's default.
	 */
	public function setLimit($value): void
	{
		$this->setOption('Limit', \max(0, TPropertyValue::ensureInteger($value)), 0);
	}

	/**
	 * @return bool Whether the report is a realtime report. Defaults to false.
	 */
	public function getRealtime(): bool
	{
		return $this->getViewState('Realtime', false);
	}

	/**
	 * @param mixed $value Whether the report is a realtime report over the last 30 minutes.
	 */
	public function setRealtime($value): void
	{
		$this->setOption('Realtime', TPropertyValue::ensureBoolean($value), false);
	}

	/**
	 * @return array<string, mixed> Further request fields.
	 */
	public function getRequestOptions(): array
	{
		return $this->getViewState('RequestOptions', []);
	}

	/**
	 * @param mixed $value Further request fields, as an array or a JSON object; empty for none.
	 * @throws TInvalidDataValueException When the value is not a map.
	 */
	public function setRequestOptions($value): void
	{
		if ($value === null || $value === '' || $value === []) {
			$this->setOption('RequestOptions', [], []);
			return;
		}
		$options = \is_array($value) ? $value : \json_decode((string) $value, true);
		if (!\is_array($options)) {
			throw new TInvalidDataValueException('ganalytics_options_invalid', 'RequestOptions', (string) $value);
		}
		$this->setOption('RequestOptions', $options, []);
	}

	/**
	 * @return int The seconds a response is shared. Defaults to {@see DEFAULT_CACHE_EXPIRE}.
	 */
	public function getCacheExpire(): int
	{
		return $this->getViewState('CacheExpire', static::DEFAULT_CACHE_EXPIRE);
	}

	/**
	 * @param mixed $value The seconds a response is shared through the application cache; 0 or less runs every request.
	 */
	public function setCacheExpire($value): void
	{
		$this->setViewState('CacheExpire', \max(0, TPropertyValue::ensureInteger($value)), static::DEFAULT_CACHE_EXPIRE);
	}

	/**
	 * @return string The id of the analytics module; empty for the first `GAnalyticsModule`.
	 */
	public function getAnalyticsModule(): string
	{
		return $this->getViewState('AnalyticsModule', '');
	}

	/**
	 * @param mixed $value The id of the analytics module; empty for the first `GAnalyticsModule`.
	 */
	public function setAnalyticsModule($value): void
	{
		$this->setOption('AnalyticsModule', \trim((string) TPropertyValue::ensureString($value)), '');
	}
}
