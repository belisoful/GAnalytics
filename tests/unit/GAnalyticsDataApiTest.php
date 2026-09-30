<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsAccessTokenCredentials;
use belisoful\GAnalytics\GAnalyticsApiClient;
use belisoful\GAnalytics\GAnalyticsApiException;
use belisoful\GAnalytics\GAnalyticsDataApi;
use belisoful\GAnalytics\GAnalyticsReport;
use PHPUnit\Framework\TestCase;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;

class GAnalyticsDataApiTest extends TestCase
{
	private function api(): RecordingDataApi
	{
		$api = new RecordingDataApi(new GAnalyticsAccessTokenCredentials('tok-1'));
		$api->setPropertyId('123456789');
		return $api;
	}

	public function testDefaults()
	{
		$api = new GAnalyticsDataApi();
		self::assertInstanceOf(GAnalyticsApiClient::class, $api);
		self::assertSame('https://analyticsdata.googleapis.com/v1beta', $api->getBaseUrl());
		self::assertSame(GAnalyticsDataApi::DEFAULT_BASE_URL, GAnalyticsDataApi::getDefaultBaseUrl());
		self::assertNull($api->getCredentials());
		self::assertNull($api->getPropertyId());
		self::assertSame(10.0, $api->getTimeout());
		self::assertNull($api->getLastResponse());
	}

	public function testRunReportPostsTheRequestWithTheBearerToken()
	{
		$api = $this->api();
		$api->answer(GAnalyticsReportTest::response());
		$request = GAnalyticsDataApi::reportRequest(['activeUsers', 'averageSessionDuration', 'customLabel'], ['country', 'pagePath'], '7daysAgo', 'yesterday', ['limit' => 10]);
		$report = $api->runReport($request);
		self::assertInstanceOf(GAnalyticsReport::class, $report);
		self::assertCount(2, $report);
		self::assertCount(1, $api->requests);
		$sent = $api->requests[0];
		self::assertSame('POST', $sent['method']);
		self::assertSame('https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport', $sent['url']);
		self::assertContains('Authorization: Bearer tok-1', $sent['headers']);
		self::assertContains('Content-Type: application/json', $sent['headers']);
		self::assertSame(10.0, $sent['timeout']);
		self::assertSame([
			'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'yesterday']],
			'metrics' => [['name' => 'activeUsers'], ['name' => 'averageSessionDuration'], ['name' => 'customLabel']],
			'dimensions' => [['name' => 'country'], ['name' => 'pagePath']],
			'limit' => 10,
		], $api->lastBody());
		self::assertSame(GAnalyticsReportTest::response(), $api->getLastResponse());
	}

	public function testRequestBuilders()
	{
		self::assertSame(
			['dateRanges' => [['startDate' => '28daysAgo', 'endDate' => 'today']], 'metrics' => [['name' => 'sessions']]],
			GAnalyticsDataApi::reportRequest(['sessions']),
			'No dimensions key without dimensions.'
		);
		self::assertSame(
			['metrics' => [['name' => 'activeUsers']], 'dimensions' => [['name' => 'country']], 'limit' => 5],
			GAnalyticsDataApi::realtimeRequest(['activeUsers'], ['country'], ['limit' => 5])
		);
		self::assertSame(['metrics' => [['name' => 'activeUsers']]], GAnalyticsDataApi::realtimeRequest(['activeUsers']));
	}

	public function testRealtimePivotBatchMetadataAndCompatibility()
	{
		$api = $this->api();
		$api->answer(['metricHeaders' => [['name' => 'activeUsers', 'type' => 'TYPE_INTEGER']], 'rows' => [['metricValues' => [['value' => '5']]]]]);
		$report = $api->runRealtimeReport(GAnalyticsDataApi::realtimeRequest(['activeUsers']));
		self::assertSame([['activeUsers' => 5]], $report->getRows());
		self::assertStringEndsWith('properties/123456789:runRealtimeReport', $api->requests[0]['url']);

		$api->answer(['reports' => [['rows' => []], GAnalyticsReportTest::response()]]);
		$reports = $api->batchRunReports([GAnalyticsDataApi::reportRequest(['a']), GAnalyticsDataApi::reportRequest(['b'])]);
		self::assertCount(2, $reports);
		self::assertInstanceOf(GAnalyticsReport::class, $reports[1]);
		self::assertCount(2, $reports[1]);
		self::assertStringEndsWith(':batchRunReports', $api->requests[1]['url']);
		self::assertCount(2, $api->lastBody()['requests']);

		$api->answer(['pivotHeaders' => []]);
		self::assertSame(['pivotHeaders' => []], $api->runPivotReport(['pivots' => []]));
		self::assertStringEndsWith(':runPivotReport', $api->requests[2]['url']);

		$api->answer(['pivotReports' => [['a' => 1], ['b' => 2]]]);
		self::assertSame([['a' => 1], ['b' => 2]], $api->batchRunPivotReports([[], []]));
		self::assertStringEndsWith(':batchRunPivotReports', $api->requests[3]['url']);

		$api->answer(['dimensions' => [['apiName' => 'country']], 'metrics' => []]);
		self::assertSame(['dimensions' => [['apiName' => 'country']], 'metrics' => []], $api->getMetadata());
		self::assertSame('GET', $api->requests[4]['method']);
		self::assertStringEndsWith('properties/123456789/metadata', $api->requests[4]['url']);
		self::assertNull($api->requests[4]['body']);
		self::assertNotContains('Content-Type: application/json', $api->requests[4]['headers']);

		$api->answer(['dimensionCompatibilities' => []]);
		self::assertSame(['dimensionCompatibilities' => []], $api->checkCompatibility(['dimensions' => [['name' => 'country']]]));
		self::assertStringEndsWith(':checkCompatibility', $api->requests[5]['url']);
	}

	public function testAnErrorResponseThrowsWithGooglesMessage()
	{
		$api = $this->api();
		$api->answer(['error' => ['code' => 403, 'message' => 'The caller does not have permission', 'status' => 'PERMISSION_DENIED']], 403);
		try {
			$api->runReport(GAnalyticsDataApi::reportRequest(['activeUsers']));
			self::fail('Expected an exception');
		} catch (GAnalyticsApiException $e) {
			self::assertSame(403, $e->getStatusCode());
			self::assertSame('PERMISSION_DENIED', $e->getResponse()['error']['status']);
			self::assertStringContainsString('403', $e->getMessage());
			self::assertStringContainsString('The caller does not have permission', $e->getMessage());
		}
	}

	public function testATransportFailureAndABadBodyThrow()
	{
		$api = $this->api();
		$api->answers = [[0, null]];
		try {
			$api->getMetadata();
			self::fail('Expected an exception');
		} catch (GAnalyticsApiException $e) {
			self::assertSame(0, $e->getStatusCode());
			self::assertSame([], $e->getResponse());
			self::assertStringContainsString('no response', $e->getMessage());
		}
		$api->answers = [[200, 'not json']];
		try {
			$api->getMetadata();
			self::fail('Expected an exception');
		} catch (GAnalyticsApiException $e) {
			self::assertSame(200, $e->getStatusCode());
			self::assertStringContainsString('not JSON', $e->getMessage());
		}
		$api->answers = [[500, 'oops']];
		try {
			$api->getMetadata();
			self::fail('Expected an exception');
		} catch (GAnalyticsApiException $e) {
			self::assertSame(500, $e->getStatusCode());
			self::assertStringContainsString('HTTP 500', $e->getMessage());
		}
		$api->answers = [[204, '']];
		self::assertSame([], $api->getMetadata(), 'An empty 2xx body is an empty response.');
	}

	public function testRequestAllFollowsPageTokens()
	{
		$api = $this->api();
		$api->answers = [
			[200, \json_encode(['items' => [['n' => 1], ['n' => 2]], 'nextPageToken' => 'p2'])],
			[200, \json_encode(['items' => [['n' => 3]]])],
		];
		self::assertSame([['n' => 1], ['n' => 2], ['n' => 3]], $api->requestAll('things', 'items', ['pageSize' => 2]));
		self::assertCount(2, $api->requests);
		self::assertStringEndsWith('/things?pageSize=2', $api->requests[0]['url']);
		self::assertStringEndsWith('/things?pageSize=2&pageToken=p2', $api->requests[1]['url']);
	}

	public function testCredentialsAndPropertyAreRequired()
	{
		$api = new RecordingDataApi();
		$api->setPropertyId('1');
		try {
			$api->getMetadata();
			self::fail('Expected an exception');
		} catch (TConfigurationException $e) {
			self::assertSame([], $api->requests);
		}
		$api->setCredentials(new GAnalyticsAccessTokenCredentials('t'));
		$api->setPropertyId(null);
		$this->expectException(TConfigurationException::class);
		$api->getMetadata();
	}

	public function testPropertyIdForms()
	{
		$api = new GAnalyticsDataApi();
		$api->setPropertyId(' properties/42 ');
		self::assertSame('42', $api->getPropertyId());
		self::assertSame('properties/42', $api->getPropertyPath());
		$api->setPropertyId(123);
		self::assertSame('123', $api->getPropertyId());
		$api->setPropertyId('');
		self::assertNull($api->getPropertyId());
		self::assertNull(GAnalyticsDataApi::normalizePropertyId(null));
		$this->expectException(TInvalidDataValueException::class);
		$api->setPropertyId('G-ABC');
	}

	public function testBaseUrlAndTimeout()
	{
		$api = new GAnalyticsDataApi();
		$api->setBaseUrl('http://localhost:9999/data/v1beta/');
		self::assertSame('http://localhost:9999/data/v1beta', $api->getBaseUrl());
		$api->setBaseUrl('');
		self::assertSame(GAnalyticsDataApi::DEFAULT_BASE_URL, $api->getBaseUrl());
		$api->setTimeout('3');
		self::assertSame(3.0, $api->getTimeout());
		foreach (['ftp://x/y', 'https://x/y?z', 'https://x/y#z', '/relative'] as $bad) {
			try {
				$api->setBaseUrl($bad);
				self::fail("Expected $bad to be refused");
			} catch (TInvalidDataValueException $e) {
				self::assertTrue(true);
			}
		}
		$this->expectException(TInvalidDataValueException::class);
		$api->setTimeout(-1);
	}

	public function testAnUnencodableBodyIsRefused()
	{
		$api = $this->api();
		$this->expectException(TInvalidDataValueException::class);
		$api->call('runReport', ['bytes' => "\xB1\x31"]);
	}

	public function testExceptionDefaultsWithoutAResponse()
	{
		$e = new GAnalyticsApiException(404);
		self::assertSame(404, $e->getStatusCode());
		self::assertSame([], $e->getResponse());
		self::assertStringContainsString('HTTP 404', $e->getMessage());
		$e = new GAnalyticsApiException(400, ['error' => ['status' => 'INVALID_ARGUMENT']]);
		self::assertStringContainsString('INVALID_ARGUMENT', $e->getMessage());
		$e = new GAnalyticsApiException(400, [], 'custom detail');
		self::assertStringContainsString('custom detail', $e->getMessage());
	}
}
