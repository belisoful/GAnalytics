<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsReport;
use PHPUnit\Framework\TestCase;

class GAnalyticsReportTest extends TestCase
{
	/** @return array<string, mixed> */
	public static function response(): array
	{
		return [
			'kind' => 'analyticsData#runReport',
			'dimensionHeaders' => [['name' => 'country'], ['name' => 'pagePath']],
			'metricHeaders' => [
				['name' => 'activeUsers', 'type' => 'TYPE_INTEGER'],
				['name' => 'averageSessionDuration', 'type' => 'TYPE_SECONDS'],
				['name' => 'customLabel', 'type' => 'TYPE_STRING'],
			],
			'rows' => [
				['dimensionValues' => [['value' => 'US'], ['value' => '/Home']], 'metricValues' => [['value' => '12'], ['value' => '34.5'], ['value' => 'a']]],
				['dimensionValues' => [['value' => 'DE'], ['value' => '/About']], 'metricValues' => [['value' => '3'], ['value' => '0'], ['value' => 'b']]],
			],
			'totals' => [['dimensionValues' => [['value' => 'RESERVED_TOTAL'], ['value' => 'RESERVED_TOTAL']], 'metricValues' => [['value' => '15'], ['value' => '34.5'], ['value' => '']]]],
			'maximums' => [['metricValues' => [['value' => '12'], ['value' => '34.5'], ['value' => 'b']]]],
			'minimums' => [['metricValues' => [['value' => '3'], ['value' => '0'], ['value' => 'a']]]],
			'rowCount' => 40,
			'metadata' => ['currencyCode' => 'USD', 'timeZone' => 'America/Los_Angeles'],
			'propertyQuota' => ['tokensPerDay' => ['consumed' => 1, 'remaining' => 24999]],
		];
	}

	public function testHeadersRowsAndTypes()
	{
		$report = new GAnalyticsReport(self::response());
		self::assertSame('analyticsData#runReport', $report->getKind());
		self::assertSame(['country', 'pagePath'], $report->getDimensionHeaders());
		self::assertSame(['activeUsers' => 'TYPE_INTEGER', 'averageSessionDuration' => 'TYPE_SECONDS', 'customLabel' => 'TYPE_STRING'], $report->getMetricHeaders());
		self::assertSame(['country', 'pagePath', 'activeUsers', 'averageSessionDuration', 'customLabel'], $report->getColumns());
		self::assertSame([
			['country' => 'US', 'pagePath' => '/Home', 'activeUsers' => 12, 'averageSessionDuration' => 34.5, 'customLabel' => 'a'],
			['country' => 'DE', 'pagePath' => '/About', 'activeUsers' => 3, 'averageSessionDuration' => 0.0, 'customLabel' => 'b'],
		], $report->getRows());
		self::assertSame($report->getRows(), $report->toArray());
		self::assertSame($report->getRows(), $report->getRows(), 'Rows are built once.');
		self::assertCount(2, $report);
		self::assertSame(['US', 'DE'], \array_column(\iterator_to_array($report), 'country'));
		self::assertSame(40, $report->getRowCount());
		self::assertSame(['currencyCode' => 'USD', 'timeZone' => 'America/Los_Angeles'], $report->getMetadata());
		self::assertSame(['tokensPerDay' => ['consumed' => 1, 'remaining' => 24999]], $report->getPropertyQuota());
		self::assertSame(self::response(), $report->getResponse());
	}

	public function testAggregateRows()
	{
		$report = new GAnalyticsReport(self::response());
		self::assertSame([['country' => 'RESERVED_TOTAL', 'pagePath' => 'RESERVED_TOTAL', 'activeUsers' => 15, 'averageSessionDuration' => 34.5, 'customLabel' => '']], $report->getTotals());
		self::assertSame([['activeUsers' => 12, 'averageSessionDuration' => 34.5, 'customLabel' => 'b']], $report->getMaximums());
		self::assertSame([['activeUsers' => 3, 'averageSessionDuration' => 0.0, 'customLabel' => 'a']], $report->getMinimums());
	}

	public function testEmptyAndPartialResponses()
	{
		$report = new GAnalyticsReport([]);
		self::assertSame([], $report->getRows());
		self::assertSame(0, $report->getRowCount(), 'Without rowCount the row count is the number of rows.');
		self::assertSame('', $report->getKind());
		self::assertSame([], $report->getMetadata());
		self::assertSame([], $report->getTotals());
		self::assertCount(0, $report);

		$report = new GAnalyticsReport(['metricHeaders' => [['name' => 'activeUsers']], 'rows' => [['metricValues' => [['value' => '7'], ['value' => '8']]], ['dimensionValues' => [['value' => 'x']]]]]);
		self::assertSame([['activeUsers' => '7', 'metric1' => '8'], ['dimension0' => 'x']], $report->getRows(), 'A header-less type is a string; extra columns get positional names.');
	}

	/** @return array<string, array{0: mixed, 1: string, 2: mixed}> */
	public static function metricCasts(): array
	{
		return [
			'integer' => ['12', 'TYPE_INTEGER', 12],
			'float' => ['1.5', 'TYPE_FLOAT', 1.5],
			'currency' => ['9.99', 'TYPE_CURRENCY', 9.99],
			'milliseconds' => ['250', 'TYPE_MILLISECONDS', 250.0],
			'string' => ['abc', 'TYPE_STRING', 'abc'],
			'unknown type' => ['12', 'TYPE_OTHER', '12'],
			'null' => [null, 'TYPE_INTEGER', null],
		];
	}

	/** @dataProvider metricCasts */
	public function testCastMetric(mixed $value, string $type, mixed $expected)
	{
		self::assertSame($expected, GAnalyticsReport::castMetric($value, $type));
	}
}
