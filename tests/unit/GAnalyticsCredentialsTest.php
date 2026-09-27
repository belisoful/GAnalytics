<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsAccessTokenCredentials;
use belisoful\GAnalytics\GAnalyticsApiException;
use belisoful\GAnalytics\GAnalyticsServiceAccountCredentials;
use belisoful\GAnalytics\IGAnalyticsCredentials;
use PHPUnit\Framework\TestCase;
use Prado\Caching\TMemoryCache;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\Util\Clock\TMockClock;

class GAnalyticsCredentialsTest extends TestCase
{
	/** @var array<string, string> A freshly generated service account key: private key and public key. */
	private static array $pair = [];

	public static function setUpBeforeClass(): void
	{
		$resource = \openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		\openssl_pkey_export($resource, $private);
		self::$pair = ['private' => $private, 'public' => \openssl_pkey_get_details($resource)['key']];
	}

	protected function tearDown(): void
	{
		$app = Prado::getApplication();
		if ($app->getCache() !== null) {
			$app->getCache()->flush();
		}
	}

	/** @return array<string, string> */
	private function key(): array
	{
		return ['type' => 'service_account', 'client_email' => 'bot@project.iam.gserviceaccount.com', 'private_key' => self::$pair['private'], 'token_uri' => 'https://oauth2.googleapis.com/token'];
	}

	private function credentials(): RecordingServiceAccountCredentials
	{
		$credentials = new RecordingServiceAccountCredentials();
		$credentials->setKey($this->key());
		$credentials->setClock((new TMockClock())->setTime(1_700_000_000));
		$credentials->answer(['access_token' => 'ya29.first', 'expires_in' => 3599, 'token_type' => 'Bearer']);
		return $credentials;
	}

	public function testAccessTokenCredentials()
	{
		$credentials = new GAnalyticsAccessTokenCredentials(' abc ');
		self::assertInstanceOf(IGAnalyticsCredentials::class, $credentials);
		$credentials->setAccessToken('0');
		self::assertSame('0', $credentials->getAccessToken(), "The token '0' is a value, not an empty one.");
		$credentials->setAccessToken(' abc ');
		self::assertSame('abc', $credentials->getAccessToken());
		$credentials->setAccessToken('');
		$this->expectException(TConfigurationException::class);
		$credentials->getAccessToken();
	}

	public function testDefaults()
	{
		$credentials = new GAnalyticsServiceAccountCredentials();
		$credentials->clearToken();
		self::assertNull($credentials->getKeyFile());
		self::assertSame([GAnalyticsServiceAccountCredentials::SCOPE_READONLY], $credentials->getScopes());
		self::assertSame(GAnalyticsServiceAccountCredentials::DEFAULT_TOKEN_URI, $credentials->getTokenUri());
		self::assertSame(10.0, $credentials->getTimeout());
		$this->expectException(TConfigurationException::class);
		$credentials->getKey();
	}

	public function testAssertionIsASignedJwtWithTheClaims()
	{
		$credentials = $this->credentials();
		$credentials->setScopes([GAnalyticsServiceAccountCredentials::SCOPE_READONLY, GAnalyticsServiceAccountCredentials::SCOPE_EDIT]);
		$jwt = $credentials->createAssertion(1_700_000_000);
		[$header, $claims, $signature] = \explode('.', $jwt);
		self::assertSame(['alg' => 'RS256', 'typ' => 'JWT'], \json_decode(GAnalyticsServiceAccountCredentials::base64UrlDecode($header), true));
		self::assertSame([
			'iss' => 'bot@project.iam.gserviceaccount.com',
			'scope' => GAnalyticsServiceAccountCredentials::SCOPE_READONLY . ' ' . GAnalyticsServiceAccountCredentials::SCOPE_EDIT,
			'aud' => 'https://oauth2.googleapis.com/token',
			'iat' => 1_700_000_000,
			'exp' => 1_700_003_600,
		], \json_decode(GAnalyticsServiceAccountCredentials::base64UrlDecode($claims), true));
		self::assertSame(1, \openssl_verify($header . '.' . $claims, GAnalyticsServiceAccountCredentials::base64UrlDecode($signature), self::$pair['public'], OPENSSL_ALGO_SHA256), 'The signature verifies with the public key.');
		self::assertStringNotContainsString('=', $jwt);
		self::assertStringNotContainsString('+', $jwt);
	}

	public function testBase64Url()
	{
		$bytes = "\xfb\xff\x00ab";
		self::assertSame('-_8AYWI', GAnalyticsServiceAccountCredentials::base64UrlEncode($bytes));
		self::assertSame($bytes, GAnalyticsServiceAccountCredentials::base64UrlDecode('-_8AYWI'));
		self::assertSame('', GAnalyticsServiceAccountCredentials::base64UrlDecode(''));
	}

	public function testAccessTokenIsExchangedAndReused()
	{
		$credentials = $this->credentials();
		self::assertSame('ya29.first', $credentials->getAccessToken());
		self::assertCount(1, $credentials->requests);
		$sent = $credentials->requests[0];
		self::assertSame('POST', $sent['method']);
		self::assertSame('https://oauth2.googleapis.com/token', $sent['url']);
		self::assertContains('Content-Type: application/x-www-form-urlencoded', $sent['headers']);
		\parse_str((string) $sent['body'], $form);
		self::assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);
		self::assertSame(3, \count(\explode('.', $form['assertion'])));

		$credentials->answer(['access_token' => 'ya29.second', 'expires_in' => 3599]);
		self::assertSame('ya29.first', $credentials->getAccessToken(), 'The token is reused until it nears expiry.');
		self::assertCount(1, $credentials->requests);

		$credentials->getClock()->setTime(1_700_000_000 + 3599 - 59);
		self::assertSame('ya29.second', $credentials->getAccessToken(), 'Inside the refresh margin a new token is exchanged.');
		self::assertCount(2, $credentials->requests);

		$credentials->clearToken();
		$credentials->answer(['access_token' => 'ya29.third']);
		self::assertSame('ya29.third', $credentials->getAccessToken(), 'clearToken() forces an exchange; a missing expires_in means the JWT lifetime.');
		self::assertCount(3, $credentials->requests);
	}

	public function testTheTokenIsSharedThroughTheApplicationCache()
	{
		$app = Prado::getApplication();
		$cache = new TMemoryCache();
		$cache->init(null);
		$app->setCache($cache);
		try {
			$first = $this->credentials();
			self::assertSame('ya29.first', $first->getAccessToken());
			$second = $this->credentials();
			$second->answer(['access_token' => 'ya29.other']);
			self::assertSame('ya29.first', $second->getAccessToken(), 'A second holder of the same account reads the cached token.');
			self::assertCount(0, $second->requests);
			$second->getClock()->setTime(1_700_000_000 + 4000);
			self::assertSame('ya29.other', $second->getAccessToken(), 'An expired cached token is exchanged again.');
			$first->clearToken();
			$third = $this->credentials();
			$third->answer(['access_token' => 'ya29.fresh']);
			$third->getClock()->setTime(1_700_000_000 + 4000);
			self::assertSame('ya29.fresh', $third->getAccessToken(), 'clearToken() also drops the cached token.');

			$dir = \sys_get_temp_dir() . '/ganalytics-' . \uniqid();
			\mkdir($dir);
			$file = $dir . '/key.json';
			\file_put_contents($file, \json_encode($this->key()));
			try {
				$byFile = new RecordingServiceAccountCredentials();
				$byFile->setKeyFile($file);
				$byFile->setClock((new TMockClock())->setTime(1_700_000_000 + 4000));
				self::assertSame('ya29.fresh', $byFile->getAccessToken(), 'The cache key is the account, however the key was set.');
				self::assertCount(0, $byFile->requests);
			} finally {
				\unlink($file);
				\rmdir($dir);
			}
		} finally {
			$cache->flush();
		}
	}

	public function testARefusedExchangeThrows()
	{
		$credentials = $this->credentials();
		$credentials->answer(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400);
		try {
			$credentials->getAccessToken();
			self::fail('Expected an exception');
		} catch (GAnalyticsApiException $e) {
			self::assertSame(400, $e->getStatusCode());
			self::assertStringContainsString('Invalid JWT Signature.', $e->getMessage());
		}
		$credentials->answers = [[0, null]];
		try {
			$credentials->getAccessToken();
			self::fail('Expected an exception');
		} catch (GAnalyticsApiException $e) {
			self::assertSame(0, $e->getStatusCode());
		}
		$credentials->answer(['token_type' => 'Bearer']);
		$this->expectException(GAnalyticsApiException::class);
		$credentials->getAccessToken();
	}

	public function testKeyValidation()
	{
		$credentials = new GAnalyticsServiceAccountCredentials();
		$credentials->setKey(\json_encode($this->key()));
		self::assertSame('bot@project.iam.gserviceaccount.com', $credentials->getKey()['client_email']);
		$credentials->setKey('');
		try {
			$credentials->getKey();
			self::fail('Expected an exception');
		} catch (TConfigurationException $e) {
			self::assertTrue(true);
		}
		foreach (['{"client_email": "x"}', '{"private_key": "x"}', 'nonsense', ['client_email' => 'x', 'private_key' => '']] as $bad) {
			try {
				$credentials->setKey($bad);
				self::fail('Expected an exception');
			} catch (TInvalidDataValueException $e) {
				self::assertTrue(true);
			}
		}
		$credentials->setKey(['client_email' => 'x@y', 'private_key' => 'not a pem key']);
		$this->expectException(TInvalidDataValueException::class);
		$credentials->createAssertion(1);
	}

	public function testKeyFileIsReadOnFirstUse()
	{
		$dir = \sys_get_temp_dir() . '/ganalytics-' . \uniqid();
		\mkdir($dir);
		$file = $dir . '/key.json';
		\file_put_contents($file, \json_encode($this->key()));
		try {
			$credentials = new GAnalyticsServiceAccountCredentials();
			$credentials->setKeyFile($file);
			self::assertSame($file, $credentials->getKeyFile());
			self::assertSame('bot@project.iam.gserviceaccount.com', $credentials->getKey()['client_email']);
			$credentials->setKeyFile('');
			self::assertNull($credentials->getKeyFile());

			$credentials->setKeyFile('relative/key.json');
			self::assertSame(Prado::getApplication()->getBasePath() . DIRECTORY_SEPARATOR . 'relative/key.json', $credentials->getKeyFile(), 'A relative path is under the application.');
			$credentials->setKeyFile('C:\\keys\\service.json');
			self::assertSame('C:\\keys\\service.json', $credentials->getKeyFile(), 'A Windows path is absolute.');
			$app = Prado::getApplication();
			Prado::setApplication(null);
			try {
				$credentials->setKeyFile('relative/key.json');
				self::assertSame('relative/key.json', $credentials->getKeyFile(), 'Without an application a relative path stays relative.');
			} finally {
				Prado::setApplication($app);
			}
			$credentials->setKeyFile('relative/key.json');
			$this->expectException(TConfigurationException::class);
			$credentials->getKey();
		} finally {
			\unlink($file);
			\rmdir($dir);
		}
	}

	public function testScopesTokenUriAndTimeout()
	{
		$credentials = new GAnalyticsServiceAccountCredentials();
		$credentials->setScopes('a b, c,, a');
		self::assertSame(['a', 'b', 'c'], $credentials->getScopes());
		$credentials->setScopes([]);
		self::assertSame([GAnalyticsServiceAccountCredentials::SCOPE_READONLY], $credentials->getScopes());
		$credentials->setKey(['client_email' => 'x@y', 'private_key' => self::$pair['private'], 'token_uri' => 'https://example.com/token']);
		self::assertSame('https://example.com/token', $credentials->getTokenUri());
		$credentials->setKey(['client_email' => 'x@y', 'private_key' => self::$pair['private']]);
		self::assertSame(GAnalyticsServiceAccountCredentials::DEFAULT_TOKEN_URI, $credentials->getTokenUri());
		$credentials->setTimeout(1);
		self::assertSame(1.0, $credentials->getTimeout());
		$this->expectException(TInvalidDataValueException::class);
		$credentials->setTimeout('0');
	}
}
