<?php

/**
 * GAnalyticsPersonalDataProvider class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/belisoful/GAnalytics/blob/main/LICENSE
 */

namespace belisoful\GAnalytics;

use belisoful\Privacy\Retention\IProcessingActivityProvider;
use belisoful\Privacy\Retention\TLegalBasis;
use belisoful\Privacy\Retention\TProcessingActivity;
use belisoful\Privacy\Rights\IPersonalDataProvider;
use belisoful\Privacy\Rights\TDataSubject;
use belisoful\Privacy\Rights\TErasureResult;
use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\Prado;
use Prado\TModule;
use Prado\TPropertyValue;
use Prado\Util\Log\TLogger;

/**
 * GAnalyticsPersonalDataProvider class.
 *
 * GAnalyticsPersonalDataProvider joins Google Analytics to the data subject rights and the
 * records of processing of `belisoful/prado-privacy`. It is an `IPersonalDataProvider` of
 * `TPrivacyModule` and an `IProcessingActivityProvider` of `TProcessingRegistry`; both discover it
 * as a loaded module.
 *
 * ```xml
 * <module id="belisoful/ganalytics" MeasurementId="G-XXXXXXXXXX" PropertyId="123456789" UserIdFromUser="true">
 *     <credentials class="belisoful\GAnalytics\GAnalyticsServiceAccountCredentials" KeyFile="ga4-key.json"
 *         Scopes="https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/analytics.edit" />
 * </module>
 * <module id="privacy-google" class="belisoful\GAnalytics\GAnalyticsPersonalDataProvider" />
 * ```
 *
 * | Right | Effect |
 * |---|---|
 * | Erasure | One Admin API `submitUserDeletion` request per identifier of the subject; Google deletes the events collected before the request |
 * | Export | The identifiers Google Analytics holds the subject's events under, and the property; GA4 has no per-user export API |
 * | Rectification | None: Google Analytics data is deleted, never corrected |
 *
 * The subject's identifiers:
 *
 * | Source | Kind |
 * |---|---|
 * | The user name, when the analytics module's `UserIdFromUser` is on | `userId`: {@see GAnalyticsModule::getUserIdForName()} |
 * | `TDataSubject` identifier `ga_user_id` | `userId` |
 * | `TDataSubject` identifier `ga_client_id` (a comma- or space-separated list) | `clientId` |
 * | The request's `_ga` cookie, when the subject is the logged-in requester | `clientId` |
 * | `TDataSubject` identifier `ga_app_instance_id` | `appInstanceId` |
 * | The subject's email, with `EraseUserProvidedData` | `userProvidedData` |
 *
 * The analytics module's credentials need the `analytics.edit` scope and the Editor role on the
 * property. A refused request is reported in the {@see TErasureResult} errors with its status; when
 * every request is refused, the last refusal is thrown, so the privacy request is recorded as failed.
 *
 * {@see getProcessingActivities()} declares Google Analytics in the records of processing: consent
 * as the legal basis, Google as the recipient, the transfer to the United States, and the
 * property's data retention setting. `Activity` overrides any field. The defaults describe a
 * typical GA4 setup; the controller reviews them against its own configuration and contracts.
 *
 * The class implements interfaces of `belisoful/prado-privacy`, so it loads only in an application
 * that installed that package; nothing else in this extension references it.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GAnalyticsPersonalDataProvider extends TModule implements IPersonalDataProvider, IProcessingActivityProvider
{
	/** The `TDataSubject` identifier kind holding GA4 user ids. */
	public const IDENTIFIER_USER_ID = 'ga_user_id';

	/** The `TDataSubject` identifier kind holding GA4 client ids. */
	public const IDENTIFIER_CLIENT_ID = 'ga_client_id';

	/** The `TDataSubject` identifier kind holding Firebase app instance ids. */
	public const IDENTIFIER_APP_INSTANCE_ID = 'ga_app_instance_id';

	/** @var string the id of the analytics module */
	private string $_analyticsModule = '';

	/** @var string the provider's name in an export */
	private string $_personalDataName = 'google-analytics';

	/** @var bool whether the subject's email is submitted as user-provided data */
	private bool $_eraseUserProvidedData = false;

	/** @var array<string, mixed> processing activity property => value, over the defaults */
	private array $_activity = [];

	/**
	 * @return string the provider's name: the key of its data in an export
	 */
	public function getPersonalDataName(): string
	{
		return $this->_personalDataName;
	}

	/**
	 * @param mixed $value the provider's name; empty restores `google-analytics`
	 * @return static the provider
	 */
	public function setPersonalDataName($value): static
	{
		$value = \trim(TPropertyValue::ensureString($value));
		$this->_personalDataName = $value === '' ? 'google-analytics' : $value;
		return $this;
	}

	/**
	 * Returns the identifiers Google Analytics holds the subject's events under, and the property.
	 * @param TDataSubject $subject the verified subject
	 * @throws TConfigurationException when no analytics module is found
	 * @return array{property: ?string, identifiers: array<int, array{kind: string, id: string}>, note: string}|array{} the identifiers; empty when there are none
	 */
	public function exportPersonalData(TDataSubject $subject): array
	{
		$module = $this->requireAnalyticsModule();
		$identifiers = [];
		foreach ($this->getIdentifiers($subject) as [$kind, $id]) {
			if ($kind !== 'userProvidedData') {
				$identifiers[] = ['kind' => $kind, 'id' => $id];
			}
		}
		if ($identifiers === []) {
			return [];
		}
		$property = $module->getPropertyId();
		return [
			'property' => $property === null ? null : 'properties/' . $property,
			'identifiers' => $identifiers,
			'note' => 'Google Analytics holds the events collected under these identifiers. GA4 has no per-user export API; the property\'s User Explorer report shows them.',
		];
	}

	/**
	 * Submits a user deletion to Google for every identifier of the subject.
	 * @param TDataSubject $subject the verified subject
	 * @throws TConfigurationException when no analytics module is found, or its property id or credentials are unset
	 * @throws GAnalyticsApiException when Google refuses every request
	 * @return TErasureResult one erased item per accepted request, and an error per refused one
	 */
	public function erasePersonalData(TDataSubject $subject): TErasureResult
	{
		$module = $this->requireAnalyticsModule();
		$result = new TErasureResult();
		$refusal = null;
		foreach ($this->getIdentifiers($subject) as [$kind, $id]) {
			try {
				$module->deleteUserData($id, $kind);
				$result->addErased(1);
			} catch (GAnalyticsApiException $e) {
				// The error names the kind and the status only: the identifier is personal data.
				$result->addError('Google refused the ' . $kind . ' deletion with status ' . $e->getStatusCode() . '.');
				Prado::log('Google refused a ' . $kind . ' deletion: ' . $e->getMessage(), TLogger::WARNING, static::class);
				$refusal = $e;
			}
		}
		if ($refusal !== null && $result->getErased() === 0) {
			throw $refusal;
		}
		return $result;
	}

	/**
	 * Google Analytics data cannot be corrected, only deleted.
	 * @param TDataSubject $subject the verified subject
	 * @param array<string, mixed> $changes field => corrected value
	 * @return bool false
	 */
	public function rectifyPersonalData(TDataSubject $subject, array $changes): bool
	{
		return false;
	}

	/**
	 * Returns the subject's identifiers as Google Analytics user deletion kinds, without duplicates.
	 * @param TDataSubject $subject the subject
	 * @throws TConfigurationException when no analytics module is found
	 * @return array<int, array{0: string, 1: string}> the kind and the identifier, in order
	 */
	public function getIdentifiers(TDataSubject $subject): array
	{
		$module = $this->requireAnalyticsModule();
		$found = [];
		if ($module->getUserIdFromUser() && $subject->getName() !== '') {
			$found[] = ['userId', $module->getUserIdForName($subject->getName())];
		}
		foreach (self::splitIdentifiers($subject->getIdentifier(self::IDENTIFIER_USER_ID)) as $id) {
			$found[] = ['userId', $id];
		}
		foreach (self::splitIdentifiers($subject->getIdentifier(self::IDENTIFIER_CLIENT_ID)) as $id) {
			$found[] = ['clientId', $id];
		}
		if ($subject->getIsCurrentUser() && ($clientId = $module->getClientId()) !== null) {
			$found[] = ['clientId', $clientId];
		}
		foreach (self::splitIdentifiers($subject->getIdentifier(self::IDENTIFIER_APP_INSTANCE_ID)) as $id) {
			$found[] = ['appInstanceId', $id];
		}
		$email = $subject->getEmail();
		if ($this->getEraseUserProvidedData() && $email !== null && ($email = GAnalyticsAdminApi::normalizeUserProvidedData($email)) !== '') {
			$found[] = ['userProvidedData', $email];
		}
		$unique = [];
		foreach ($found as $identifier) {
			$unique[$identifier[0] . "\0" . $identifier[1]] = $identifier;
		}
		return \array_values($unique);
	}

	/**
	 * @param ?string $value a comma- or space-separated list of identifiers
	 * @return string[] the identifiers
	 */
	protected static function splitIdentifiers(?string $value): array
	{
		return $value === null ? [] : \preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
	}

	/**
	 * Declares Google Analytics in the records of processing.
	 * @throws TInvalidDataValueException when `Activity` names an unknown legal basis
	 * @return TProcessingActivity[] the Google Analytics activity
	 */
	public function getProcessingActivities(): array
	{
		return [new TProcessingActivity(\array_merge($this->getDefaultActivity(), $this->_activity))];
	}

	/**
	 * Returns the default Google Analytics activity; a derived user id adds registered users and the pseudonymous id.
	 * @return array<string, mixed> processing activity property => value
	 */
	public function getDefaultActivity(): array
	{
		$userIds = $this->getAnalyticsModule()?->getUserIdFromUser() ?? false;
		$subjects = ['website visitors'];
		$data = [
			'online identifiers (GA4 client id in first-party cookies)',
			'device, browser and operating system',
			'approximate location derived from the IP address',
			'pages viewed, events and traffic sources',
		];
		if ($userIds) {
			$subjects[] = 'registered users';
			$data[] = 'pseudonymous user id (HMAC of the user name)';
		}
		return [
			'Name' => 'Google Analytics',
			'Purpose' => 'Measuring how visitors use the website: pages viewed, events, traffic sources and conversions.',
			'LegalBasis' => TLegalBasis::Consent,
			'LegalBasisNote' => 'Consent to analytics storage (ePrivacy Directive Art. 5(3)); Google Consent Mode carries the choice to the tag.',
			'SubjectCategories' => $subjects,
			'DataCategories' => $data,
			'Recipients' => ['Google Ireland Limited and Google LLC (Google Analytics, processor)'],
			'Transfers' => ['United States: EU-US Data Privacy Framework and the standard contractual clauses of the Google Ads Data Processing Terms'],
			'RetentionPeriod' => 'the GA4 property\'s data retention setting (2 or 14 months for event-level data)',
			'SecurityMeasures' => 'TLS in transit; GA4 does not store IP addresses; user ids are pseudonymized before they reach Google.',
		];
	}

	/**
	 * @return array<string, mixed> processing activity property => value, over {@see getDefaultActivity()}
	 */
	public function getActivity(): array
	{
		return $this->_activity;
	}

	/**
	 * @param mixed $value processing activity property => value, as an array or a JSON object; empty for the defaults
	 * @throws TInvalidDataValueException when the value is not a map
	 * @return static the provider
	 */
	public function setActivity($value): static
	{
		if ($value === null || $value === '' || $value === []) {
			$this->_activity = [];
			return $this;
		}
		$activity = \is_array($value) ? $value : \json_decode((string) $value, true);
		if (!\is_array($activity)) {
			throw new TInvalidDataValueException('ganalytics_options_invalid', 'Activity', (string) $value);
		}
		$this->_activity = $activity;
		return $this;
	}

	/**
	 * @return bool whether the subject's email is submitted for deletion as user-provided data
	 */
	public function getEraseUserProvidedData(): bool
	{
		return $this->_eraseUserProvidedData;
	}

	/**
	 * @param mixed $value whether the subject's email is submitted for deletion as user-provided data; for a site that sends user-provided data to Google
	 * @return static the provider
	 */
	public function setEraseUserProvidedData($value): static
	{
		$this->_eraseUserProvidedData = TPropertyValue::ensureBoolean($value);
		return $this;
	}

	/**
	 * @throws TConfigurationException when no analytics module is found
	 * @return GAnalyticsModule the analytics module
	 */
	protected function requireAnalyticsModule(): GAnalyticsModule
	{
		return $this->getAnalyticsModule() ?? throw new TConfigurationException('ganalytics_module_invalid', $this->_analyticsModule ?: '(any)', GAnalyticsModule::class);
	}

	/**
	 * @return ?GAnalyticsModule the analytics module: `AnalyticsModule`, or the first `GAnalyticsModule`; null when none
	 */
	public function getAnalyticsModule(): ?GAnalyticsModule
	{
		$app = $this->getApplication();
		if ($this->_analyticsModule !== '') {
			$module = $app->getModule($this->_analyticsModule);
			return $module instanceof GAnalyticsModule ? $module : null;
		}
		foreach ($app->getModulesByType(GAnalyticsModule::class) as $module) {
			return $module;
		}
		return null;
	}

	/**
	 * @param mixed $value the id of the analytics module; empty finds the first `GAnalyticsModule`
	 * @return static the provider
	 */
	public function setAnalyticsModule($value): static
	{
		$this->_analyticsModule = \trim(TPropertyValue::ensureString($value));
		return $this;
	}
}
