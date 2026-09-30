<?php

namespace belisoful\GAnalytics\Test\Live;

/** The Measurement Protocol against Google's validation endpoint and collection endpoint. */
class MeasurementProtocolLiveTest extends LiveTestCase
{
	public function testTheValidationEndpointAcceptsAWellFormedEvent()
	{
		$module = $this->measurementModule();
		$mp = $module->getMeasurementProtocol();
		$mp->setDebug(true);
		self::assertTrue($mp->send($mp->newClientId(), [['name' => 'live_test', 'params' => ['source' => 'phpunit', 'engagement_time_msec' => 1]]]));
		$response = \json_decode((string) $mp->getLastResponse(), true);
		self::assertIsArray($response);
		self::assertSame([], $response['validationMessages'] ?? null, 'Google reports no validation message for the event.');
	}

	public function testTheValidationEndpointReportsABadEvent()
	{
		$module = $this->measurementModule();
		$mp = $module->getMeasurementProtocol();
		$mp->setDebug(true);
		$mp->send($mp->newClientId(), [['name' => 'live_test', 'params' => ['too_many_params' => \str_repeat('x', 101)]]]);
		$response = \json_decode((string) $mp->getLastResponse(), true);
		self::assertNotEmpty($response['validationMessages'] ?? [], 'A 101-character value exceeds the parameter limit.');
	}

	public function testTheCollectionEndpointAcceptsAnEvent()
	{
		$module = $this->measurementModule();
		self::assertTrue($module->sendEvent('live_test', ['source' => 'phpunit', 'engagement_time_msec' => 1], $module->getMeasurementProtocol()->newClientId()));
	}
}
