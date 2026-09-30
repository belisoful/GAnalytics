<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsAccessTokenCredentials;
use belisoful\GAnalytics\GAnalyticsApiException;
use belisoful\GAnalytics\GAnalyticsModule;
use belisoful\GAnalytics\GAnalyticsPersonalDataProvider;
use belisoful\Privacy\Retention\IProcessingActivityProvider;
use belisoful\Privacy\Retention\TProcessingActivity;
use belisoful\Privacy\Rights\IPersonalDataProvider;
use belisoful\Privacy\Rights\TDataSubject;
use PHPUnit\Framework\TestCase;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TApplication;
use Prado\TComponent;
use Prado\Web\THttpCookie;
use Prado\Web\UI\TPage;

class GAnalyticsPersonalDataProviderTest extends TestCase
{
	private TApplication $_app;

	/** @var string[] the module ids the test registered */
	private array $_ids = [];

	private ?\Prado\Security\IUser $_previousUser = null;

	private string $_previousKey = '';

	protected function setUp(): void
	{
		$this->_app = Prado::getApplication();
		$this->_previousUser = $this->_app->getUser();
		$this->_previousKey = (string) $this->_app->getSecurityManager()->getValidationKey();
		$this->_app->getSecurityManager()->setValidationKey('validation-key');
	}

	protected function tearDown(): void
	{
		// The application never forgets a module; the ones this test added are removed so the
		// provider's discovery of "the first" module sees only its own test's modules.
		$property = new \ReflectionProperty(TApplication::class, '_modules');
		$property->setAccessible(true);
		$modules = $property->getValue($this->_app);
		foreach ($this->_ids as $id) {
			unset($modules[$id]);
		}
		$property->setValue($this->_app, $modules);
		$this->_app->setUser($this->_previousUser ?? new FakeUser());
		$this->_app->getSecurityManager()->setValidationKey($this->_previousKey);
		TComponent::detachClassBehavior(GAnalyticsModule::PAGE_BEHAVIOR_NAME, TPage::class);
	}

	private function register(string $id, \Prado\IModule $module): void
	{
		$module->setID($id);
		$this->_app->setModule($id, $module);
		$this->_ids[] = $id;
	}

	private function analytics(string $id = 'analytics-pd'): ProbeGAnalyticsModule
	{
		$module = new ProbeGAnalyticsModule();
		$module->setAttachPageBehavior(false);
		$module->setAmendCsp(false);
		$module->setMeasurementId('G-TEST1234AB');
		$module->setPropertyId('123');
		$module->setCredentials(new GAnalyticsAccessTokenCredentials('tok'));
		$module->setUserIdFromUser(true);
		$this->register($id, $module);
		return $module;
	}

	public function testContract()
	{
		$provider = new GAnalyticsPersonalDataProvider();
		self::assertInstanceOf(IPersonalDataProvider::class, $provider);
		self::assertInstanceOf(IProcessingActivityProvider::class, $provider);
		self::assertSame('google-analytics', $provider->getPersonalDataName());
		$provider->setPersonalDataName(' ga4 ');
		self::assertSame('ga4', $provider->getPersonalDataName());
		$provider->setPersonalDataName('');
		self::assertSame('google-analytics', $provider->getPersonalDataName());
		self::assertFalse($provider->getEraseUserProvidedData());
		$provider->setEraseUserProvidedData('true');
		self::assertTrue($provider->getEraseUserProvidedData());
		self::assertFalse($provider->rectifyPersonalData(new TDataSubject('alice'), ['email' => 'a@b.c']), 'Google data is deleted, never corrected');
	}

	public function testIdentifiersFromEverySource()
	{
		$module = $this->analytics();
		$provider = new GAnalyticsPersonalDataProvider();
		$provider->setEraseUserProvidedData(true);
		$subject = new TDataSubject('alice', 'First.Last@Gmail.com', [
			GAnalyticsPersonalDataProvider::IDENTIFIER_USER_ID => 'crm-7',
			GAnalyticsPersonalDataProvider::IDENTIFIER_CLIENT_ID => '1.2, 3.4 1.2',
			GAnalyticsPersonalDataProvider::IDENTIFIER_APP_INSTANCE_ID => 'app-9',
		]);
		$this->_app->setUser(new FakeUser('alice', false));
		$cookies = $this->_app->getRequest()->getCookies();
		$cookie = new THttpCookie('_ga', 'GA1.1.3.4');
		$cookies->add($cookie);
		try {
			self::assertSame([
				['userId', $module->getUserIdForName('alice')],
				['userId', 'crm-7'],
				['clientId', '1.2'],
				['clientId', '3.4'],
				['appInstanceId', 'app-9'],
				['userProvidedData', 'firstlast@gmail.com'],
			], $provider->getIdentifiers($subject), 'every source, without duplicates; the cookie repeats 3.4');

			$cookie->setValue('GA1.1.5.6');
			self::assertContains(['clientId', '5.6'], $provider->getIdentifiers($subject), "the requester's own _ga cookie");
			$this->_app->setUser(new FakeUser('bob', false));
			self::assertNotContains(['clientId', '5.6'], $provider->getIdentifiers($subject), "another user's cookie is not the subject's");
		} finally {
			$cookies->remove($cookie);
		}
		$this->_app->setUser(new FakeUser('alice', false));
		self::assertNotContains(['clientId', '5.6'], $provider->getIdentifiers($subject), 'no cookie, no client id');
	}

	public function testIdentifiersFollowTheModuleSettings()
	{
		$module = $this->analytics();
		$module->setUserIdFromUser(false);
		$provider = new GAnalyticsPersonalDataProvider();
		self::assertSame([], $provider->getIdentifiers(new TDataSubject('alice', 'a@example.com')), 'no derived id, no email without EraseUserProvidedData');
		$module->setUserIdFromUser(true);
		self::assertSame([], $provider->getIdentifiers(new TDataSubject('')), 'an unnamed subject has no derived id');
		$provider->setEraseUserProvidedData(true);
		self::assertSame([], $provider->getIdentifiers(new TDataSubject('', ' ')), 'an email that normalizes to nothing is skipped');
	}

	public function testEraseSubmitsEveryIdentifier()
	{
		$module = $this->analytics();
		$module->adminApi->answer(['deletionRequestTime' => '2026-09-29T00:00:00Z']);
		$provider = new GAnalyticsPersonalDataProvider();
		$result = $provider->erasePersonalData(new TDataSubject('alice', null, [GAnalyticsPersonalDataProvider::IDENTIFIER_CLIENT_ID => '1.2']));
		self::assertSame(2, $result->getErased());
		self::assertSame([], $result->getErrors());
		self::assertCount(2, $module->adminApi->requests);
		self::assertSame(['userId' => $module->getUserIdForName('alice')], \json_decode((string) $module->adminApi->requests[0]['body'], true));
		self::assertSame(['clientId' => '1.2'], $module->adminApi->lastBody());
		self::assertStringEndsWith('/v1alpha/properties/123:submitUserDeletion', $module->adminApi->requests[0]['url']);
	}

	public function testErasureWithoutIdentifiersErasesNothing()
	{
		$module = $this->analytics();
		$module->setUserIdFromUser(false);
		$result = (new GAnalyticsPersonalDataProvider())->erasePersonalData(new TDataSubject('alice'));
		self::assertSame(['erased' => 0, 'retained' => [], 'errors' => []], $result->toArray());
		self::assertSame([], $module->adminApi->requests);
	}

	public function testPartialRefusalIsReportedWithoutPersonalData()
	{
		$module = $this->analytics();
		$module->adminApi->answers = [[200, '{}'], [403, \json_encode(['error' => ['message' => 'no']])]];
		$result = (new GAnalyticsPersonalDataProvider())->erasePersonalData(new TDataSubject('alice', null, [GAnalyticsPersonalDataProvider::IDENTIFIER_CLIENT_ID => '1.2']));
		self::assertSame(1, $result->getErased());
		self::assertSame(['Google refused the clientId deletion with status 403.'], $result->getErrors());
		self::assertStringNotContainsString('1.2', \implode(' ', $result->getErrors()));
	}

	public function testRefusalOfEveryRequestThrows()
	{
		$module = $this->analytics();
		$module->adminApi->answer(['error' => ['message' => 'no']], 403);
		try {
			(new GAnalyticsPersonalDataProvider())->erasePersonalData(new TDataSubject('alice'));
			self::fail('every request refused');
		} catch (GAnalyticsApiException $e) {
			self::assertSame(403, $e->getStatusCode());
		}
	}

	public function testEraseNeedsAProperty()
	{
		$module = $this->analytics();
		$module->setPropertyId('');
		$this->expectException(TConfigurationException::class);
		(new GAnalyticsPersonalDataProvider())->erasePersonalData(new TDataSubject('alice'));
	}

	public function testExport()
	{
		$module = $this->analytics();
		$provider = new GAnalyticsPersonalDataProvider();
		$provider->setEraseUserProvidedData(true);
		$data = $provider->exportPersonalData(new TDataSubject('alice', 'a@example.com', [GAnalyticsPersonalDataProvider::IDENTIFIER_CLIENT_ID => '1.2']));
		self::assertSame('properties/123', $data['property']);
		self::assertSame([
			['kind' => 'userId', 'id' => $module->getUserIdForName('alice')],
			['kind' => 'clientId', 'id' => '1.2'],
		], $data['identifiers'], 'the email is the subject\'s own data, not a Google identifier');
		self::assertStringContainsString('no per-user export API', $data['note']);

		$module->setPropertyId('');
		self::assertNull($provider->exportPersonalData(new TDataSubject('alice'))['property']);
		$module->setUserIdFromUser(false);
		self::assertSame([], $provider->exportPersonalData(new TDataSubject('alice', 'a@example.com')), 'nothing held, nothing exported');
	}

	public function testAnalyticsModuleById()
	{
		$first = $this->analytics('analytics-pd-a');
		$second = $this->analytics('analytics-pd-b');
		$this->register('not-analytics', new FakeCredentialsModule());
		$provider = new GAnalyticsPersonalDataProvider();
		self::assertSame($first, $provider->getAnalyticsModule(), 'the first one');
		$provider->setAnalyticsModule(' analytics-pd-b ');
		self::assertSame($second, $provider->getAnalyticsModule());
		$provider->setAnalyticsModule('not-analytics');
		self::assertNull($provider->getAnalyticsModule());
		try {
			$provider->erasePersonalData(new TDataSubject('alice'));
			self::fail('not an analytics module');
		} catch (TConfigurationException $e) {
			self::assertSame('ganalytics_module_invalid', $e->getErrorCode());
		}
	}

	public function testWithoutAnAnalyticsModule()
	{
		$provider = new GAnalyticsPersonalDataProvider();
		self::assertNull($provider->getAnalyticsModule());
		$this->expectException(TConfigurationException::class);
		$provider->exportPersonalData(new TDataSubject('alice'));
	}

	public function testProcessingActivity()
	{
		$provider = new GAnalyticsPersonalDataProvider();
		$activities = $provider->getProcessingActivities();
		self::assertCount(1, $activities);
		$activity = $activities[0];
		self::assertInstanceOf(TProcessingActivity::class, $activity);
		self::assertSame('Google Analytics', $activity->getName());
		self::assertSame('consent', $activity->getLegalBasis());
		self::assertSame(['website visitors'], $activity->getSubjectCategories(), 'no analytics module, no user ids');
		self::assertContains('Google Ireland Limited and Google LLC (Google Analytics, processor)', $activity->getRecipients());
		self::assertNotSame('', $activity->getRetentionPeriod());

		$this->analytics();
		$activity = $provider->getProcessingActivities()[0];
		self::assertSame(['website visitors', 'registered users'], $activity->getSubjectCategories());
		self::assertContains('pseudonymous user id (HMAC of the user name)', $activity->getDataCategories());

		$provider->setActivity('{"Purpose": "Audience measurement", "RetentionPolicy": "ga4"}');
		self::assertSame(['Purpose' => 'Audience measurement', 'RetentionPolicy' => 'ga4'], $provider->getActivity());
		$activity = $provider->getProcessingActivities()[0];
		self::assertSame('Audience measurement', $activity->getPurpose());
		self::assertSame('ga4', $activity->getRetentionPolicy());
		self::assertSame('Google Analytics', $activity->getName(), 'the other defaults stay');

		$provider->setActivity(['LegalBasis' => 'LegitimateInterests']);
		self::assertSame('legitimate_interests', $provider->getProcessingActivities()[0]->getLegalBasis());
		$provider->setActivity('');
		self::assertSame([], $provider->getActivity());
	}

	public function testActivityRefusesBadValues()
	{
		$provider = new GAnalyticsPersonalDataProvider();
		try {
			$provider->setActivity('not json');
			self::fail('not a map');
		} catch (TInvalidDataValueException $e) {
			self::assertSame('ganalytics_options_invalid', $e->getErrorCode());
		}
		$provider->setActivity(['LegalBasis' => 'whim']);
		$this->expectException(TInvalidDataValueException::class);
		$provider->getProcessingActivities();
	}
}
