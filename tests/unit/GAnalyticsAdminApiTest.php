<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsAccessTokenCredentials;
use belisoful\GAnalytics\GAnalyticsAdminApi;
use PHPUnit\Framework\TestCase;

class GAnalyticsAdminApiTest extends TestCase
{
	private function api(): RecordingAdminApi
	{
		return new RecordingAdminApi(new GAnalyticsAccessTokenCredentials('tok-2'));
	}

	public function testDefaults()
	{
		self::assertSame('https://analyticsadmin.googleapis.com/v1beta', (new GAnalyticsAdminApi())->getBaseUrl());
		self::assertSame(GAnalyticsAdminApi::DEFAULT_BASE_URL, GAnalyticsAdminApi::getDefaultBaseUrl());
	}

	public function testResourceNames()
	{
		self::assertSame('properties/123', GAnalyticsAdminApi::resourceName('properties', '123'));
		self::assertSame('properties/123', GAnalyticsAdminApi::resourceName('properties', ' properties/123 '));
		self::assertSame('accounts/9', GAnalyticsAdminApi::resourceName('accounts', '9'));
	}

	public function testListingsFollowPagesAndUseTheRightPaths()
	{
		$api = $this->api();
		$api->answers = [
			[200, \json_encode(['accountSummaries' => [['account' => 'accounts/1']], 'nextPageToken' => 'n'])],
			[200, \json_encode(['accountSummaries' => [['account' => 'accounts/2']]])],
		];
		self::assertSame([['account' => 'accounts/1'], ['account' => 'accounts/2']], $api->listAccountSummaries());
		self::assertStringEndsWith('/accountSummaries?pageSize=200', $api->requests[0]['url']);
		self::assertStringEndsWith('/accountSummaries?pageSize=200&pageToken=n', $api->requests[1]['url']);
		self::assertContains('Authorization: Bearer tok-2', $api->requests[0]['headers']);

		$api->answer(['properties' => [['name' => 'properties/5']]]);
		self::assertSame([['name' => 'properties/5']], $api->listProperties('1'));
		self::assertStringEndsWith('/properties?' . \http_build_query(['filter' => 'parent:accounts/1', 'pageSize' => 200]), $api->requests[2]['url']);

		$api->answer(['name' => 'properties/5', 'displayName' => 'Site']);
		self::assertSame('Site', $api->getProperty('5')['displayName']);
		self::assertStringEndsWith('/properties/5', $api->requests[3]['url']);

		$api->answer(['dataStreams' => [['name' => 'properties/5/dataStreams/7', 'type' => 'WEB_DATA_STREAM', 'webStreamData' => ['measurementId' => 'G-ABC']]]]);
		$streams = $api->listDataStreams('properties/5');
		self::assertSame('G-ABC', $streams[0]['webStreamData']['measurementId']);
		self::assertStringEndsWith('/properties/5/dataStreams?pageSize=200', $api->requests[4]['url']);

		$api->answer(['name' => 'properties/5/dataStreams/7']);
		self::assertSame('properties/5/dataStreams/7', $api->getDataStream('properties/5/dataStreams/7')['name']);

		$api->answer(['measurementProtocolSecrets' => [['displayName' => 'server', 'secretValue' => 's3']]]);
		self::assertSame('s3', $api->listMeasurementProtocolSecrets('properties/5/dataStreams/7')[0]['secretValue']);
		self::assertStringEndsWith('/properties/5/dataStreams/7/measurementProtocolSecrets', $api->requests[6]['url']);

		$api->answer(['displayName' => 'cron', 'secretValue' => 'new']);
		self::assertSame('new', $api->createMeasurementProtocolSecret('properties/5/dataStreams/7', 'cron')['secretValue']);
		self::assertSame('POST', $api->requests[7]['method']);
		self::assertSame(['displayName' => 'cron'], $api->lastBody());
	}
}
