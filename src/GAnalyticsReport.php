<?php

/**
 * GAnalyticsReport class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\TComponent;

/**
 * GAnalyticsReport class.
 *
 * A Data API report response (`runReport`, `runRealtimeReport`) as PHP data. {@see getRows()}
 * returns one associative array per row keyed by the dimension and metric names, with metric
 * values cast to `int` or `float` by their reported type, so a report binds to a PRADO data
 * control as it is:
 *
 * ```php
 * $report = $module->runReport(['activeUsers', 'screenPageViews'], ['pagePath']);
 * $this->Grid->setDataSource($report->getRows());   // [['pagePath' => '/Home', 'activeUsers' => 12, 'screenPageViews' => 40], …]
 * $this->Grid->dataBind();
 * ```
 *
 * The report is countable and iterable over its rows. {@see getTotals()}, {@see getMaximums()}
 * and {@see getMinimums()} hold the aggregate rows a request asked for; {@see getResponse()} is the
 * raw response.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GAnalyticsReport extends TComponent implements \IteratorAggregate, \Countable
{
	/** Metric types cast to `int`. */
	public const INTEGER_TYPES = ['TYPE_INTEGER'];

	/** Metric types cast to `float`. */
	public const FLOAT_TYPES = ['TYPE_FLOAT', 'TYPE_SECONDS', 'TYPE_MILLISECONDS', 'TYPE_MINUTES', 'TYPE_HOURS', 'TYPE_STANDARD', 'TYPE_CURRENCY', 'TYPE_FEET', 'TYPE_MILES', 'TYPE_METERS', 'TYPE_KILOMETERS'];

	/** @var array<string, mixed> The raw response. */
	private array $_response;

	/** @var string[] The dimension names, in column order. */
	private array $_dimensionHeaders = [];

	/** @var array<string, string> The metric names, in column order, to their types. */
	private array $_metricHeaders = [];

	/** @var ?array<int, array<string, mixed>> The rows, built on first use. */
	private ?array $_rows = null;

	/**
	 * @param array<string, mixed> $response The decoded Data API response.
	 */
	public function __construct(array $response)
	{
		$this->_response = $response;
		foreach ((array) ($response['dimensionHeaders'] ?? []) as $header) {
			$this->_dimensionHeaders[] = (string) ($header['name'] ?? '');
		}
		foreach ((array) ($response['metricHeaders'] ?? []) as $header) {
			$this->_metricHeaders[(string) ($header['name'] ?? '')] = (string) ($header['type'] ?? 'TYPE_STRING');
		}
		parent::__construct();
	}

	/**
	 * @return array<string, mixed> The raw response.
	 */
	public function getResponse(): array
	{
		return $this->_response;
	}

	/**
	 * @return string[] The dimension names, in column order.
	 */
	public function getDimensionHeaders(): array
	{
		return $this->_dimensionHeaders;
	}

	/**
	 * @return array<string, string> The metric names, in column order, to their Data API types.
	 */
	public function getMetricHeaders(): array
	{
		return $this->_metricHeaders;
	}

	/**
	 * @return string[] The column names: the dimensions, then the metrics.
	 */
	public function getColumns(): array
	{
		return \array_merge($this->_dimensionHeaders, \array_keys($this->_metricHeaders));
	}

	/**
	 * Returns the rows as associative arrays keyed by column name, metric values cast by type.
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function getRows(): array
	{
		return $this->_rows ??= $this->convertRows((array) ($this->_response['rows'] ?? []));
	}

	/**
	 * @return array<int, array<string, mixed>> The rows; the same as {@see getRows()}, for data binding.
	 */
	public function toArray(): array
	{
		return $this->getRows();
	}

	/**
	 * @return array<int, array<string, mixed>> The `totals` rows the request asked for, converted like {@see getRows()}.
	 */
	public function getTotals(): array
	{
		return $this->convertRows((array) ($this->_response['totals'] ?? []));
	}

	/**
	 * @return array<int, array<string, mixed>> The `maximums` rows the request asked for.
	 */
	public function getMaximums(): array
	{
		return $this->convertRows((array) ($this->_response['maximums'] ?? []));
	}

	/**
	 * @return array<int, array<string, mixed>> The `minimums` rows the request asked for.
	 */
	public function getMinimums(): array
	{
		return $this->convertRows((array) ($this->_response['minimums'] ?? []));
	}

	/**
	 * @return int The total number of rows the query matched, before `limit` and `offset`; the row count when the response has no `rowCount`.
	 */
	public function getRowCount(): int
	{
		return (int) ($this->_response['rowCount'] ?? \count($this->getRows()));
	}

	/**
	 * @return array<string, mixed> The response `metadata` (currency, time zone, sampling, …).
	 */
	public function getMetadata(): array
	{
		return (array) ($this->_response['metadata'] ?? []);
	}

	/**
	 * @return array<string, mixed> The `propertyQuota` of the response, when the request asked for it.
	 */
	public function getPropertyQuota(): array
	{
		return (array) ($this->_response['propertyQuota'] ?? []);
	}

	/**
	 * @return string The response `kind`, such as `analyticsData#runReport`.
	 */
	public function getKind(): string
	{
		return (string) ($this->_response['kind'] ?? '');
	}

	/**
	 * Converts Data API rows (`dimensionValues`, `metricValues`) to associative arrays.
	 * @param array<int, array<string, mixed>> $rows The raw rows.
	 * @return array<int, array<string, mixed>> The converted rows.
	 */
	protected function convertRows(array $rows): array
	{
		$result = [];
		foreach ($rows as $row) {
			$entry = [];
			foreach ((array) ($row['dimensionValues'] ?? []) as $i => $value) {
				$entry[$this->_dimensionHeaders[$i] ?? 'dimension' . $i] = $value['value'] ?? null;
			}
			$i = 0;
			foreach ((array) ($row['metricValues'] ?? []) as $value) {
				$name = \array_keys($this->_metricHeaders)[$i] ?? 'metric' . $i;
				$entry[$name] = static::castMetric($value['value'] ?? null, $this->_metricHeaders[$name] ?? 'TYPE_STRING');
				$i++;
			}
			$result[] = $entry;
		}
		return $result;
	}

	/**
	 * Casts a metric value by its Data API type.
	 * @param mixed $value The reported value, a string.
	 * @param string $type The metric type, such as `TYPE_INTEGER`.
	 * @return null|float|int|string The value as `int`, `float`, or the string.
	 */
	public static function castMetric(mixed $value, string $type): null|float|int|string
	{
		if ($value === null) {
			return null;
		}
		if (\in_array($type, static::INTEGER_TYPES, true)) {
			return (int) $value;
		}
		if (\in_array($type, static::FLOAT_TYPES, true)) {
			return (float) $value;
		}
		return (string) $value;
	}

	/**
	 * @return int The number of rows in the response.
	 */
	public function count(): int
	{
		return \count($this->getRows());
	}

	/**
	 * @return \ArrayIterator<int, array<string, mixed>> An iterator over the rows.
	 */
	public function getIterator(): \ArrayIterator
	{
		return new \ArrayIterator($this->getRows());
	}
}
