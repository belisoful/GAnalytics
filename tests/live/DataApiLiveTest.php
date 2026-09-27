<?php

namespace belisoful\GAnalytics\Test\Live;

use belisoful\GAnalytics\GAnalyticsAdminApi;
use belisoful\GAnalytics\GAnalyticsDataApi;
use belisoful\GAnalytics\GAnalyticsReport;

/** The Data API and Admin API against a real property with a service account. */
class DataApiLiveTest extends LiveTestCase
{
	private function dataApi(): GAnalyticsDataApi
	{
		$api = new GAnalyticsDataApi($this->credentials());
		$api->setPropertyId($this->env('GA4_PROPERTY_ID'));
		return $api;
	}

	public function testAnAccessTokenIsObtained()
	{
		$token = $this->credentials()->getAccessToken();
		self::assertNotSame('', $token);
	}

	public function testRunReport()
	{
		$report = $this->dataApi()->runReport(GAnalyticsDataApi::reportRequest(['activeUsers', 'screenPageViews'], ['date'], '7daysAgo', 'today', ['limit' => 10]));
		self::assertInstanceOf(GAnalyticsReport::class, $report);
		self::assertSame(['date'], $report->getDimensionHeaders());
		self::assertSame(['activeUsers' => 'TYPE_INTEGER', 'screenPageViews' => 'TYPE_INTEGER'], $report->getMetricHeaders());
		foreach ($report as $row) {
			self::assertIsInt($row['activeUsers']);
		}
	}

	public function testRunRealtimeReport()
	{
		$report = $this->dataApi()->runRealtimeReport(GAnalyticsDataApi::realtimeRequest(['activeUsers']));
		self::assertSame(['activeUsers' => 'TYPE_INTEGER'], $report->getMetricHeaders());
	}

	public function testMetadataListsTheStandardDimensions()
	{
		$metadata = $this->dataApi()->getMetadata();
		$names = array_column($metadata['dimensions'] ?? [], 'apiName');
		self::assertContains('pagePath', $names);
	}

	public function testTheAdminApiSeesTheProperty()
	{
		$admin = new GAnalyticsAdminApi($this->credentials());
		$property = $admin->getProperty($this->env('GA4_PROPERTY_ID'));
		self::assertSame(GAnalyticsAdminApi::resourceName('properties', $this->env('GA4_PROPERTY_ID')), $property['name'] ?? null);
		$streams = $admin->listDataStreams($this->env('GA4_PROPERTY_ID'));
		self::assertNotEmpty($streams);
	}
}
