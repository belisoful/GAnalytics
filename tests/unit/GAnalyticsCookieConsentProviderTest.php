<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsCookieConsentProvider;
use belisoful\GAnalytics\IGAnalyticsConsentStore;
use PHPUnit\Framework\TestCase;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TApplication;
use Prado\Util\Clock\TMockClock;
use Prado\Web\THttpCookie;
use Prado\Web\THttpCookieSameSite;

class GAnalyticsCookieConsentProviderTest extends TestCase
{
	private TApplication $_app;

	private RecordingResponse $_response;

	private ?\Prado\Web\THttpResponse $_previousResponse = null;

	/** @var THttpCookie[] */
	private array $_added = [];

	protected function setUp(): void
	{
		$this->_app = Prado::getApplication();
		$this->_previousResponse = $this->_app->getResponse();
		$this->_response = new RecordingResponse();
		$this->_app->setResponse($this->_response);
	}

	protected function tearDown(): void
	{
		foreach ($this->_added as $cookie) {
			$this->_app->getRequest()->getCookies()->remove($cookie);
		}
		$this->_app->setResponse($this->_previousResponse);
	}

	private function requestCookie(string $name, string $value): void
	{
		$cookie = new THttpCookie($name, $value);
		$this->_app->getRequest()->getCookies()->add($cookie);
		$this->_added[] = $cookie;
	}

	private function provider(): GAnalyticsCookieConsentProvider
	{
		$provider = new GAnalyticsCookieConsentProvider();
		$provider->setClock((new TMockClock())->setTime(1_700_000_000));
		return $provider;
	}

	public function testDefaults()
	{
		$provider = new GAnalyticsCookieConsentProvider();
		self::assertInstanceOf(IGAnalyticsConsentStore::class, $provider);
		self::assertSame('ganalytics_consent', $provider->getCookieName());
		self::assertSame(365, $provider->getExpires());
		self::assertSame([], $provider->getConsentState(), 'No cookie, no state.');
	}

	public function testReadsTheRequestCookie()
	{
		$this->requestCookie('ganalytics_consent', '{"analytics_storage":"granted","ad_storage":"DENIED","bogus":"granted","ad_user_data":"maybe"}');
		$provider = $this->provider();
		self::assertSame(['analytics_storage' => 'granted', 'ad_storage' => 'denied'], $provider->getConsentState(), 'Unknown types and values are dropped.');
		self::assertSame($provider->getConsentState(), $provider->getConsentState(), 'Read once.');
	}

	public function testAMalformedCookieIsEmpty()
	{
		$this->requestCookie('ganalytics_consent', 'not json');
		self::assertSame([], $this->provider()->getConsentState());
	}

	public function testSetConsentStateMergesAndWritesTheCookie()
	{
		$this->requestCookie('ganalytics_consent', '{"ad_storage":"denied"}');
		$provider = $this->provider();
		$provider->setExpires(30);
		$provider->setConsentState(['analytics_storage' => 'granted']);
		self::assertSame(['ad_storage' => 'denied', 'analytics_storage' => 'granted'], $provider->getConsentState());
		self::assertCount(1, $this->_response->sent);
		$cookie = $this->_response->sent[0];
		self::assertSame('ganalytics_consent', $cookie->getName());
		self::assertSame(['ad_storage' => 'denied', 'analytics_storage' => 'granted'], json_decode($cookie->getValue(), true));
		self::assertSame('/', $cookie->getPath());
		self::assertSame(1_700_000_000 + 30 * 86400, $cookie->getExpire());
		self::assertTrue($cookie->getHttpOnly());
		self::assertSame(THttpCookieSameSite::Lax, $cookie->getSameSite());
		self::assertFalse($cookie->getSecure(), 'The CLI request is not secure.');

		$provider->setConsentState(['ad_storage' => 'granted']);
		self::assertSame(['ad_storage' => 'granted', 'analytics_storage' => 'granted'], $provider->getConsentState());
		self::assertCount(2, $this->_response->sent);
	}

	public function testSetConsentStateRefusesUnknownTypesAndValues()
	{
		$provider = $this->provider();
		try {
			$provider->setConsentState(['bogus' => 'granted']);
			self::fail('Expected an exception');
		} catch (TInvalidDataValueException $e) {
			self::assertCount(0, $this->_response->sent);
		}
		$this->expectException(TInvalidDataValueException::class);
		$provider->setConsentState(['ad_storage' => 'yes']);
	}

	public function testNormalizeState()
	{
		self::assertSame(['ad_storage' => 'granted'], GAnalyticsCookieConsentProvider::normalizeState(['ad_storage' => ' Granted ', 'x' => 'granted', 'analytics_storage' => 1]));
		self::assertSame([], GAnalyticsCookieConsentProvider::normalizeState('string'));
		self::assertSame([], GAnalyticsCookieConsentProvider::normalizeState(null));
	}

	public function testCookieNameAndExpires()
	{
		$provider = $this->provider();
		$provider->setCookieName(' site_consent ');
		self::assertSame('site_consent', $provider->getCookieName());
		$this->requestCookie('site_consent', '{"ad_storage":"granted"}');
		self::assertSame(['ad_storage' => 'granted'], $provider->getConsentState());
		$provider->setCookieName('');
		self::assertSame('ganalytics_consent', $provider->getCookieName());
		self::assertSame([], $provider->getConsentState(), 'Renaming re-reads the request.');
		try {
			$provider->setCookieName('bad name;');
			self::fail('Expected an exception');
		} catch (TInvalidDataValueException $e) {
			self::assertTrue(true);
		}
		$provider->setExpires('7');
		self::assertSame(7, $provider->getExpires());
		$this->expectException(TInvalidDataValueException::class);
		$provider->setExpires(0);
	}
}
