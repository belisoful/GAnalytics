<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsMeasurementProtocol;
use PHPUnit\Framework\TestCase;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Util\Clock\TMockClock;

class GAnalyticsMeasurementProtocolTest extends TestCase
{
	private function client(): RecordingMeasurementProtocol
	{
		$client = new RecordingMeasurementProtocol();
		$client->setMeasurementId('G-TEST1234AB');
		$client->setApiSecret('s3cret');
		$client->setClock((new TMockClock())->setMicrotime(1_700_000_000.25));
		return $client;
	}

	public function testDefaults()
	{
		$client = new GAnalyticsMeasurementProtocol();
		self::assertNull($client->getMeasurementId());
		self::assertNull($client->getApiSecret());
		self::assertSame('https://www.google-analytics.com/mp/collect', $client->getEndpoint());
		self::assertSame('https://www.google-analytics.com/debug/mp/collect', $client->getDebugEndpoint());
		self::assertFalse($client->getDebug());
		self::assertSame(2.0, $client->getTimeout());
		self::assertNull($client->getLastResponse());
	}

	public function testSendPostsThePayload()
	{
		$client = $this->client();
		self::assertTrue($client->send('123.456', [['name' => 'purchase', 'params' => ['value' => 9.99, 'currency' => 'USD']], ['name' => 'login']], 'u-1', ['non_personalized_ads' => true]));
		self::assertCount(1, $client->posts);
		self::assertSame('https://www.google-analytics.com/mp/collect?measurement_id=G-TEST1234AB&api_secret=s3cret', $client->posts[0]['url']);
		self::assertSame([
			'non_personalized_ads' => true,
			'client_id' => '123.456',
			'user_id' => 'u-1',
			'timestamp_micros' => 1_700_000_000_250_000,
			'events' => [
				['name' => 'purchase', 'params' => ['value' => 9.99, 'currency' => 'USD']],
				['name' => 'login'],
			],
		], $client->lastPayload());
		self::assertSame('', $client->getLastResponse());
	}

	public function testSendOmitsAnEmptyUserId()
	{
		$client = $this->client();
		$client->send('123.456', [['name' => 'login']], '');
		self::assertArrayNotHasKey('user_id', $client->lastPayload());
		$client->send('123.456', [['name' => 'login']]);
		self::assertArrayNotHasKey('user_id', $client->lastPayload());
	}

	public function testSendReportsARefusedRequest()
	{
		$client = $this->client();
		$client->status = 500;
		$client->response = 'boom';
		self::assertFalse($client->send('123.456', [['name' => 'login']]));
		self::assertSame('boom', $client->getLastResponse());
		$client->status = 0;
		$client->response = null;
		self::assertFalse($client->send('123.456', [['name' => 'login']]), 'A transport failure is status 0.');
		self::assertNull($client->getLastResponse());
	}

	public function testDebugUsesTheValidationEndpoint()
	{
		$client = $this->client();
		$client->setDebug('true');
		$client->status = 200;
		$client->response = '{"validationMessages":[{"fieldPath":"events","description":"bad","validationCode":"VALUE_INVALID"}]}';
		self::assertTrue($client->send('123.456', [['name' => 'login']]));
		self::assertStringStartsWith('https://www.google-analytics.com/debug/mp/collect?', $client->posts[0]['url']);
	}

	public function testSendRequiresTheConfiguration()
	{
		$client = new RecordingMeasurementProtocol();
		$client->setMeasurementId('G-TEST1234AB');
		$this->expectException(TConfigurationException::class);
		$client->send('123.456', [['name' => 'login']]);
	}

	public function testSendRefusesAnEmptyClientId()
	{
		$this->expectException(TInvalidDataValueException::class);
		$this->client()->send(' ', [['name' => 'login']]);
	}

	public function testSendRefusesNoEvents()
	{
		$this->expectException(TInvalidDataValueException::class);
		$this->client()->send('1.2', []);
	}

	public function testSendRefusesMoreThanTwentyFiveEvents()
	{
		$this->expectException(TInvalidDataValueException::class);
		$this->client()->send('1.2', array_fill(0, 26, ['name' => 'login']));
	}

	/** @return array<string, array{0: mixed}> */
	public static function invalidEventNames(): array
	{
		return [
			'digit first' => ['1login'],
			'dash' => ['sign-up'],
			'space' => ['sign up'],
			'too long' => [str_repeat('a', 41)],
			'empty' => [''],
			'not a string' => [42],
			'missing' => [null],
		];
	}

	/** @dataProvider invalidEventNames */
	public function testSendRefusesAnInvalidEventName(mixed $name)
	{
		$this->expectException(TInvalidDataValueException::class);
		$this->client()->send('1.2', [['name' => $name]]);
	}

	public function testSendRefusesAnUnencodablePayload()
	{
		$this->expectException(TInvalidDataValueException::class);
		$this->client()->send('1.2', [['name' => 'login', 'params' => ['bytes' => "\xB1\x31"]]]);
	}

	public function testNewClientIdUsesTheClock()
	{
		$client = $this->client();
		$id = $client->newClientId();
		self::assertMatchesRegularExpression('/^\d{10}\.1700000000$/', $id);
		self::assertNotSame($id, $client->newClientId(), 'The random part differs between calls.');
	}

	public function testClientIdFromCookie()
	{
		self::assertSame('1234567890.1700000000', GAnalyticsMeasurementProtocol::clientIdFromCookie('GA1.1.1234567890.1700000000'));
		self::assertSame('12.34', GAnalyticsMeasurementProtocol::clientIdFromCookie(' GA1.2.12.34 '));
		self::assertNull(GAnalyticsMeasurementProtocol::clientIdFromCookie(null));
		self::assertNull(GAnalyticsMeasurementProtocol::clientIdFromCookie(''));
		self::assertNull(GAnalyticsMeasurementProtocol::clientIdFromCookie('1234567890.1700000000'));
		self::assertNull(GAnalyticsMeasurementProtocol::clientIdFromCookie('GA1.1.abc.def'));
	}

	public function testEndpointsAcceptAbsoluteUrlsAndRestoreDefaults()
	{
		$client = new GAnalyticsMeasurementProtocol();
		$client->setEndpoint('https://region1.google-analytics.com/mp/collect');
		self::assertSame('https://region1.google-analytics.com/mp/collect', $client->getEndpoint());
		$client->setEndpoint('');
		self::assertSame(GAnalyticsMeasurementProtocol::DEFAULT_ENDPOINT, $client->getEndpoint());
		$client->setDebugEndpoint('http://localhost:9000/debug');
		self::assertSame('http://localhost:9000/debug', $client->getDebugEndpoint());
		$client->setDebugEndpoint(null);
		self::assertSame(GAnalyticsMeasurementProtocol::DEFAULT_DEBUG_ENDPOINT, $client->getDebugEndpoint());
	}

	/** @return array<string, array{0: string}> */
	public static function invalidEndpoints(): array
	{
		return [
			'relative' => ['/mp/collect'],
			'ftp' => ['ftp://example.com/mp'],
			'query' => ['https://example.com/mp?x=1'],
			'fragment' => ['https://example.com/mp#x'],
		];
	}

	/** @dataProvider invalidEndpoints */
	public function testEndpointsRefuseOtherValues(string $url)
	{
		$this->expectException(TInvalidDataValueException::class);
		(new GAnalyticsMeasurementProtocol())->setEndpoint($url);
	}

	public function testCredentialsAndTimeout()
	{
		$client = new GAnalyticsMeasurementProtocol();
		$client->setMeasurementId(' G-X ');
		$client->setApiSecret(' abc ');
		$client->setTimeout('4.5');
		self::assertSame('G-X', $client->getMeasurementId());
		self::assertSame('abc', $client->getApiSecret());
		self::assertSame(4.5, $client->getTimeout());
		$client->setMeasurementId('');
		$client->setApiSecret(null);
		self::assertNull($client->getMeasurementId());
		self::assertNull($client->getApiSecret());
		$this->expectException(TInvalidDataValueException::class);
		$client->setTimeout(0);
	}
}
