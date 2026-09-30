<?php

/**
 * GAnalyticsDataApi class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\TPropertyValue;

/**
 * GAnalyticsDataApi class.
 *
 * The Google Analytics Data API v1beta for one GA4 property ({@see setPropertyId() PropertyId}):
 * reports ({@see runReport()}, {@see batchRunReports()}, {@see runPivotReport()},
 * {@see batchRunPivotReports()}), realtime ({@see runRealtimeReport()}), the property's
 * dimensions and metrics ({@see getMetadata()}) and {@see checkCompatibility()}. Every other RPC
 * of the API is reachable through {@see call()}. Requests are the API's JSON shapes;
 * {@see reportRequest()} and {@see realtimeRequest()} build the common ones from metric and
 * dimension names. Reports come back as {@see GAnalyticsReport}.
 *
 * Realtime data is pull-only: the API has no push channel. Poll it on a schedule (PRADO's
 * `TCronModule` running {@see GAnalyticsModule::pollRealtime()}) and publish the result through
 * the application's own channel, such as `belisoful/prado-webhooks` or `belisoful/prado-websocket`.
 *
 * ```php
 * $api = new GAnalyticsDataApi($credentials);
 * $api->setPropertyId('123456789');
 * $report = $api->runReport(GAnalyticsDataApi::reportRequest(['activeUsers'], ['country'], '7daysAgo', 'today'));
 * foreach ($report as $row) {
 *     echo $row['country'], ': ', $row['activeUsers'], "\n";
 * }
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GAnalyticsDataApi extends GAnalyticsApiClient
{
	/** The Data API v1beta base URL. */
	public const DEFAULT_BASE_URL = 'https://analyticsdata.googleapis.com/v1beta';

	/** @var ?string The numeric GA4 property id. */
	private ?string $_propertyId = null;

	/**
	 * @return string {@see DEFAULT_BASE_URL}.
	 */
	public static function getDefaultBaseUrl(): string
	{
		return static::DEFAULT_BASE_URL;
	}

	/**
	 * Runs a report.
	 * @param array<string, mixed> $request The `RunReportRequest` body; see {@see reportRequest()}.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return GAnalyticsReport The report.
	 */
	public function runReport(array $request): GAnalyticsReport
	{
		return new GAnalyticsReport($this->call('runReport', $request));
	}

	/**
	 * Runs a realtime report over the last 30 minutes (60 with the 360 edition).
	 * @param array<string, mixed> $request The `RunRealtimeReportRequest` body; see {@see realtimeRequest()}.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return GAnalyticsReport The report.
	 */
	public function runRealtimeReport(array $request): GAnalyticsReport
	{
		return new GAnalyticsReport($this->call('runRealtimeReport', $request));
	}

	/**
	 * Runs up to five reports in one request.
	 * @param array<int, array<string, mixed>> $requests The `RunReportRequest` bodies.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return GAnalyticsReport[] The reports, in request order.
	 */
	public function batchRunReports(array $requests): array
	{
		$response = $this->call('batchRunReports', ['requests' => \array_values($requests)]);
		return \array_map(fn ($report) => new GAnalyticsReport((array) $report), (array) ($response['reports'] ?? []));
	}

	/**
	 * Runs a pivot report.
	 * @param array<string, mixed> $request The `RunPivotReportRequest` body.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return array<string, mixed> The `RunPivotReportResponse`.
	 */
	public function runPivotReport(array $request): array
	{
		return $this->call('runPivotReport', $request);
	}

	/**
	 * Runs up to five pivot reports in one request.
	 * @param array<int, array<string, mixed>> $requests The `RunPivotReportRequest` bodies.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return array<int, array<string, mixed>> The `RunPivotReportResponse`s, in request order.
	 */
	public function batchRunPivotReports(array $requests): array
	{
		$response = $this->call('batchRunPivotReports', ['requests' => \array_values($requests)]);
		return \array_values((array) ($response['pivotReports'] ?? []));
	}

	/**
	 * Returns the dimensions and metrics available to the property, custom ones included.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return array<string, mixed> The `Metadata`: `dimensions` and `metrics`.
	 */
	public function getMetadata(): array
	{
		return $this->request('GET', $this->getPropertyPath() . '/metadata');
	}

	/**
	 * Checks which dimensions and metrics can be added to a report request.
	 * @param array<string, mixed> $request The `CheckCompatibilityRequest` body.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return array<string, mixed> The `CheckCompatibilityResponse`.
	 */
	public function checkCompatibility(array $request): array
	{
		return $this->call('checkCompatibility', $request);
	}

	/**
	 * Calls a property RPC: `POST properties/{id}:{rpc}`.
	 * @param string $rpc The RPC name, such as `runReport`.
	 * @param array<string, mixed> $body The request body.
	 * @throws TConfigurationException When no property id or credentials are set.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return array<string, mixed> The decoded response.
	 */
	public function call(string $rpc, array $body = []): array
	{
		return $this->request('POST', $this->getPropertyPath() . ':' . $rpc, $body);
	}

	/**
	 * Builds a `RunReportRequest` from metric and dimension names and a date range.
	 * @param string[] $metrics The metric names, such as `activeUsers`.
	 * @param string[] $dimensions The dimension names, such as `pagePath`.
	 * @param string $startDate The start date: `YYYY-MM-DD`, `NdaysAgo`, `yesterday` or `today`.
	 * @param string $endDate The end date, in the same forms.
	 * @param array<string, mixed> $extra Further request fields (`limit`, `offset`, `orderBys`, `dimensionFilter`, `metricAggregations`, …).
	 * @return array<string, mixed> The request body.
	 */
	public static function reportRequest(array $metrics, array $dimensions = [], string $startDate = '28daysAgo', string $endDate = 'today', array $extra = []): array
	{
		$request = ['dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]]];
		$request += static::namedRequest($metrics, $dimensions);
		return \array_merge($request, $extra);
	}

	/**
	 * Builds a `RunRealtimeReportRequest` from metric and dimension names.
	 * @param string[] $metrics The metric names, such as `activeUsers`.
	 * @param string[] $dimensions The dimension names, such as `country`.
	 * @param array<string, mixed> $extra Further request fields (`limit`, `minuteRanges`, `orderBys`, …).
	 * @return array<string, mixed> The request body.
	 */
	public static function realtimeRequest(array $metrics, array $dimensions = [], array $extra = []): array
	{
		return \array_merge(static::namedRequest($metrics, $dimensions), $extra);
	}

	/**
	 * Builds the `metrics` and `dimensions` lists of a request from names.
	 * @param string[] $metrics The metric names.
	 * @param string[] $dimensions The dimension names.
	 * @return array<string, mixed> The two lists; `dimensions` is omitted when empty.
	 */
	protected static function namedRequest(array $metrics, array $dimensions): array
	{
		$request = ['metrics' => \array_map(fn ($name) => ['name' => (string) $name], \array_values($metrics))];
		if (\count($dimensions) > 0) {
			$request['dimensions'] = \array_map(fn ($name) => ['name' => (string) $name], \array_values($dimensions));
		}
		return $request;
	}

	/**
	 * @throws TConfigurationException When no property id is set.
	 * @return string The property resource path, `properties/{id}`.
	 */
	public function getPropertyPath(): string
	{
		if ($this->_propertyId === null) {
			throw new TConfigurationException('ganalytics_property_unconfigured');
		}
		return 'properties/' . $this->_propertyId;
	}

	/**
	 * @return ?string The numeric GA4 property id.
	 */
	public function getPropertyId(): ?string
	{
		return $this->_propertyId;
	}

	/**
	 * Sets the GA4 property id, the number shown in the Analytics admin (`123456789`); the
	 * resource form `properties/123456789` is accepted too.
	 * @param mixed $value The property id; empty for none.
	 * @throws TInvalidDataValueException When the value is not a property id.
	 */
	public function setPropertyId($value)
	{
		$this->_propertyId = static::normalizePropertyId($value);
	}

	/**
	 * Normalizes a property id: `123456789` or `properties/123456789` to `123456789`; empty to null.
	 * @param mixed $value The property id.
	 * @throws TInvalidDataValueException When the value is not a property id.
	 * @return ?string The numeric id, or null for an empty value.
	 */
	public static function normalizePropertyId($value): ?string
	{
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
		if ($value === null) {
			return null;
		}
		$id = \trim((string) TPropertyValue::ensureString($value));
		if (\str_starts_with($id, 'properties/')) {
			$id = \substr($id, \strlen('properties/'));
		}
		if (!\preg_match('/^\d{1,20}$/', $id)) {
			throw new TInvalidDataValueException('ganalytics_property_invalid', $id);
		}
		return $id;
	}
}
