<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsMeasurementProtocol;
use PHPUnit\Framework\TestCase;

/** The real transport against a local PHP web server: nothing leaves the machine. */
class GAnalyticsHttpTransportTest extends TestCase
{
	private static ?LocalHttpServer $server = null;

	public static function setUpBeforeClass(): void
	{
		self::$server = LocalHttpServer::start();
	}

	public static function tearDownAfterClass(): void
	{
		self::$server?->stop();
		self::$server = null;
	}

	public function testPostSendsHeadersAndBodyAndReadsTheStatus()
	{
		$probe = new TransportProbe();
		[$status, $body] = $probe->send('POST', self::$server->url('/collect?status=201'), ['Content-Type: application/json', 'X-Test: yes'], '{"a":1}');
		self::assertSame(201, $status);
		$echo = \json_decode((string) $body, true);
		self::assertSame('POST', $echo['method']);
		self::assertSame('/collect?status=201', $echo['uri']);
		self::assertSame('application/json', $echo['headers']['CONTENT_TYPE']);
		self::assertSame('yes', $echo['headers']['HTTP_X_TEST']);
		self::assertSame('{"a":1}', $echo['body']);
	}

	public function testGetWithoutABodyAndAnErrorStatus()
	{
		$probe = new TransportProbe();
		[$status, $body] = $probe->send('GET', self::$server->url('/thing?status=404&body=missing'), ['Accept: application/json'], null);
		self::assertSame(404, $status, 'ignore_errors keeps the body of an error response.');
		self::assertSame('missing', $body);
		[$status, $body] = $probe->send('GET', self::$server->url('/thing?status=200'), [], null);
		self::assertSame(200, $status);
		self::assertSame('GET', \json_decode((string) $body, true)['method']);
		self::assertSame('', \json_decode((string) $body, true)['body']);
	}

	public function testAConnectionFailureIsStatusZero()
	{
		$probe = new TransportProbe();
		[$status, $body] = $probe->send('GET', 'http://127.0.0.1:' . LocalHttpServer::freePort() . '/', [], null, 1.0);
		self::assertSame(0, $status);
		self::assertNull($body);
	}

	public function testTheMeasurementProtocolPostsThroughTheTransport()
	{
		$client = new GAnalyticsMeasurementProtocol();
		$client->setMeasurementId('G-LOCAL12345');
		$client->setApiSecret('s3cret');
		$client->setEndpoint(self::$server->url('/mp/collect'));
		self::assertTrue($client->send('1.2', [['name' => 'local_test']]));
		$echo = \json_decode((string) $client->getLastResponse(), true);
		self::assertSame('POST', $echo['method']);
		self::assertSame('/mp/collect?measurement_id=G-LOCAL12345&api_secret=s3cret', $echo['uri']);
		self::assertSame('application/json', $echo['headers']['CONTENT_TYPE']);
		self::assertSame('1.2', \json_decode($echo['body'], true)['client_id']);
	}
}
