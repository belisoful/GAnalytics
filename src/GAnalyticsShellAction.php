<?php

/**
 * GAnalyticsShellAction class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\Exceptions\TException;
use Prado\Prado;
use Prado\Shell\TShellAction;
use Prado\Shell\TShellWriter;
use Prado\TPropertyValue;

/**
 * GAnalyticsShellAction class.
 *
 * The `prado-cli ganalytics/*` commands, registered by {@see GAnalyticsModule} in a
 * {@see \Prado\Shell\TShellApplication}:
 *
 * | Command | Effect |
 * |---|---|
 * | `ganalytics/status` | Prints the module's effective configuration and the tag script |
 * | `ganalytics/send <event> [params-json]` | Sends an event over the Measurement Protocol |
 * | `ganalytics/validate <event> [params-json]` | Sends the event to the validation endpoint and prints Google's messages |
 * | `ganalytics/report <metrics> [dimensions] [start] [end]` | Runs a Data API report and prints the rows |
 * | `ganalytics/realtime [metrics] [dimensions]` | Runs a realtime report and prints the rows |
 * | `ganalytics/properties` | Lists the accounts and properties the credentials can see, with each web stream's Measurement ID |
 *
 * Metrics and dimensions are comma-separated names. `--clientid=` and `--userid=` set the client
 * and user ids of `send` and `validate`; `--limit=` the row limit of `report` and `realtime`;
 * `--property=` the property of `report`, `realtime` and `properties` for the run.
 *
 * ```sh
 * php prado-cli.php ganalytics/send purchase '{"value": 9.99, "currency": "USD"}' --clientid=123.456
 * php prado-cli.php ganalytics/report activeUsers,screenPageViews pagePath 7daysAgo today --limit=20
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GAnalyticsShellAction extends TShellAction
{
	protected $action = 'ganalytics';
	protected $methods = ['status', 'send', 'validate', 'report', 'realtime', 'properties'];
	protected $parameters = [null, 'event', 'event', 'metrics', null, null];
	protected $optional = [null, 'params-json', 'params-json', 'dimensions start end', 'metrics dimensions', null];
	protected $description = [
		'Google Analytics 4: inspect the tag, send events, and read reports.',
		'Prints the effective configuration and the tag script.',
		'Sends an event over the Measurement Protocol.',
		'Sends an event to the validation endpoint and prints the validation messages.',
		'Runs a Data API report over a date range and prints the rows.',
		'Runs a realtime report and prints the rows.',
		'Lists the accounts, properties and web streams the credentials can see.',
	];

	/** @var null|false|GAnalyticsModule The module: false until resolved, then the module or null when none is configured. */
	private null|false|GAnalyticsModule $_module = false;

	/** @var ?string The client id override of send and validate. */
	private ?string $_clientId = null;

	/** @var ?string The user id override of send and validate. */
	private ?string $_userId = null;

	/** @var ?int The row limit of report and realtime. */
	private ?int $_limit = null;

	/** @var ?string The property override of report, realtime and properties. */
	private ?string $_property = null;

	/**
	 * @param string $methodID The command.
	 * @return string[] The `--option` names the command accepts.
	 */
	public function options($methodID): array
	{
		return match ($methodID) {
			'send', 'validate' => ['clientid', 'userid'],
			'report', 'realtime' => ['limit', 'property'],
			'properties' => ['property'],
			default => [],
		};
	}

	/**
	 * @return array<string, string> The `-alias` to option names.
	 */
	public function optionAliases(): array
	{
		return ['c' => 'clientid', 'u' => 'userid', 'l' => 'limit', 'p' => 'property'];
	}

	/**
	 * Returns the module the commands drive, resolved by type on first use. Without one an error is printed.
	 * @return ?GAnalyticsModule The module, or null when none is configured.
	 */
	public function getModule(): ?GAnalyticsModule
	{
		if ($this->_module === false) {
			$this->_module = null;
			$app = Prado::getApplication();
			foreach (\array_keys($app->getModulesByType(GAnalyticsModule::class)) as $id) {
				$module = $app->getModule($id);   // loads a lazy module; returns a loaded one as is
				\assert($module instanceof GAnalyticsModule);
				$this->_module = $module;
				break;
			}
		}
		if ($this->_module === null) {
			$this->getWriter()->writeError('A ' . GAnalyticsModule::class . ' is not configured in the application.');
		}
		return $this->_module;
	}

	/**
	 * @param ?GAnalyticsModule $module The module the commands drive.
	 */
	public function setModule(?GAnalyticsModule $module): void
	{
		$this->_module = $module;
	}

	/**
	 * Prints the module's effective configuration and the tag script.
	 * @param array $args The command line arguments.
	 * @return bool Whether the command ran.
	 */
	public function actionStatus($args)
	{
		if (($module = $this->getModule()) === null) {
			return true;
		}
		$writer = $this->getWriter();
		$writer->writeLine();
		$writer->writeLine('Google Analytics', [TShellWriter::BOLD]);
		try {
			$id = $module->getMeasurementId();
			$idText = $id === null
				? '- (parameter ' . $module->getMeasurementIdParameter() . ' is unset)'
				: $id . ' (' . ($module->getApplication()->getParameters()->itemAt($module->getMeasurementIdParameter()) === $id ? 'parameter ' . $module->getMeasurementIdParameter() : 'module property') . ')';
			$valid = true;
		} catch (TException $e) {
			$id = null;
			$idText = 'invalid: ' . $e->getMessage();
			$valid = false;
		}
		$rows = [
			['Module', (string) $module->getID()],
			['Active', $module->getIsActive() ? 'yes' : 'no (Enabled=' . ($module->getEnabled() ? 'true' : 'false') . ', mode ' . $module->getApplication()->getMode() . ')'],
			['Measurement ID', $idText],
			['Additional IDs', \implode(', ', $module->getAdditionalMeasurementIds()) ?: '-'],
			['Container', $module->getContainerId() ?? '-'],
			['Tag URL', $id !== null ? $module->getTagScriptUrl() : $module->getTagUrl()],
			['Data layer', $module->getDataLayerName()],
			['Enabled modes', \implode(', ', $module->getEnabledModes()) ?: 'all'],
			['Config options', \json_encode($valid ? $module->getEffectiveConfigOptions() : [], JSON_UNESCAPED_SLASHES)],
			['Consent defaults', $this->report(fn () => $module->getEffectiveConsentDefaults(), fn (array $defaults) => (string) \json_encode($defaults, JSON_UNESCAPED_SLASHES))],
			['Consent provider', $this->report(fn () => $module->getConsentProvider(), fn (mixed $provider) => $this->describeObject($provider))],
			['User id', $module->getUserIdFromUser() ? 'from the application user' : ($module->getUserId() ?? '-')],
			['Tracking', \implode(', ', \array_keys(\array_filter(['exceptions' => $module->getTrackExceptions(), 'logins' => $module->getTrackLogins(), 'validation errors' => $module->getTrackValidationErrors()]))) ?: '-'],
			['Page behavior', $module->getAttachPageBehavior() ? 'attached' : 'off'],
			['Amend CSP', $module->getAmendCsp() ? 'yes' : 'no'],
			['API secret', $module->getApiSecret() !== null ? 'set' : '-'],
			['Property', $module->getPropertyId() ?? '-'],
			['Credentials', $this->report(fn () => $module->getCredentials(), fn (mixed $credentials) => $this->describeObject($credentials))],
		];
		foreach ($rows as [$label, $value]) {
			$writer->write('  ' . $writer->pad($label, 18));
			$writer->writeLine($value, [TShellWriter::GREEN]);
		}
		if ($valid && $module->getHasTag()) {
			$writer->writeLine();
			$writer->writeLine('Tag script', [TShellWriter::BOLD]);
			$writer->writeLine($this->report(fn () => $module->getTagScript(), fn (string $script) => $script));
		}
		$writer->writeLine();
		return true;
	}

	/**
	 * Sends an event over the Measurement Protocol: `ganalytics/send <event> [params-json]`.
	 * @param array $args The command line arguments.
	 * @return bool Whether the command ran.
	 */
	public function actionSend($args)
	{
		return $this->sendEvent($args, false);
	}

	/**
	 * Sends an event to the validation endpoint and prints the validation messages:
	 * `ganalytics/validate <event> [params-json]`.
	 * @param array $args The command line arguments.
	 * @return bool Whether the command ran.
	 */
	public function actionValidate($args)
	{
		return $this->sendEvent($args, true);
	}

	/**
	 * Sends the event of `send` and `validate`.
	 * @param array $args The command line arguments.
	 * @param bool $validate Whether the validation endpoint is used.
	 * @return bool Whether the command ran.
	 */
	protected function sendEvent(array $args, bool $validate): bool
	{
		if (($module = $this->getModule()) === null) {
			return true;
		}
		$writer = $this->getWriter();
		\array_shift($args);
		$name = (string) \array_shift($args);
		$params = $this->decodeJson(\array_shift($args), 'params');
		if ($params === null) {
			return true;
		}
		try {
			$mp = $module->getMeasurementProtocol();
			$mp->setDebug($validate || $module->getDebugMode());
			$clientId = $this->_clientId ?? $module->getClientId() ?? $mp->newClientId();
			$event = ['name' => $name];
			if (\count($params) > 0) {
				$event['params'] = $params;
			}
			$accepted = $mp->send($clientId, [$event], $this->_userId ?? $module->getEffectiveUserId());
		} catch (TException $e) {
			$writer->writeError($e->getMessage());
			return true;
		}
		$writer->writeLine();
		$writer->write($validate ? 'Validated ' : 'Sent ');
		$writer->write($name, [TShellWriter::BOLD]);
		$writer->write(' for client ');
		$writer->write($clientId, [TShellWriter::BOLD]);
		$writer->writeLine($accepted ? ': accepted' : ': refused', [$accepted ? TShellWriter::GREEN : TShellWriter::RED]);
		$response = $mp->getLastResponse();
		if (\is_string($response) && \trim($response) !== '') {
			$decoded = \json_decode($response, true);
			$messages = \is_array($decoded) ? ($decoded['validationMessages'] ?? null) : null;
			if (\is_array($messages) && \count($messages) === 0) {
				$writer->writeLine('  No validation messages.', [TShellWriter::GREEN]);
			} elseif (\is_array($messages)) {
				foreach ($messages as $message) {
					$writer->writeLine('  ' . \json_encode($message, JSON_UNESCAPED_SLASHES), [TShellWriter::RED]);
				}
			} else {
				$writer->writeLine('  ' . \trim($response));
			}
		}
		$writer->writeLine();
		return true;
	}

	/**
	 * Runs a Data API report: `ganalytics/report <metrics> [dimensions] [start] [end]`.
	 * @param array $args The command line arguments.
	 * @return bool Whether the command ran.
	 */
	public function actionReport($args)
	{
		if (($module = $this->getModule()) === null) {
			return true;
		}
		\array_shift($args);
		$metrics = $this->names(\array_shift($args));
		$dimensions = $this->names(\array_shift($args));
		$start = (string) (\array_shift($args) ?? '28daysAgo');
		$end = (string) (\array_shift($args) ?? 'today');
		$extra = $this->_limit !== null ? ['limit' => $this->_limit] : [];
		return $this->printReport(fn () => $this->api($module)->runReport(GAnalyticsDataApi::reportRequest($metrics, $dimensions, $start, $end, $extra)), "Report {$start} to {$end}");
	}

	/**
	 * Runs a realtime report: `ganalytics/realtime [metrics] [dimensions]`.
	 * @param array $args The command line arguments.
	 * @return bool Whether the command ran.
	 */
	public function actionRealtime($args)
	{
		if (($module = $this->getModule()) === null) {
			return true;
		}
		\array_shift($args);
		$metrics = $this->names(\array_shift($args)) ?: $module->getRealtimeMetrics();
		$dimensions = $this->names(\array_shift($args)) ?: $module->getRealtimeDimensions();
		$extra = $this->_limit !== null ? ['limit' => $this->_limit] : [];
		return $this->printReport(fn () => $this->api($module)->runRealtimeReport(GAnalyticsDataApi::realtimeRequest($metrics, $dimensions, $extra)), 'Realtime report');
	}

	/**
	 * Lists the accounts, properties and web streams the credentials can see: `ganalytics/properties`.
	 * @param array $args The command line arguments.
	 * @return bool Whether the command ran.
	 */
	public function actionProperties($args)
	{
		if (($module = $this->getModule()) === null) {
			return true;
		}
		$writer = $this->getWriter();
		try {
			$admin = $module->getAdminApi();
			$writer->writeLine();
			if ($this->_property !== null) {
				$properties = [['property' => GAnalyticsAdminApi::resourceName('properties', $this->_property), 'displayName' => '']];
			} else {
				$properties = [];
				foreach ($admin->listAccountSummaries() as $account) {
					$writer->write(($account['account'] ?? '') . '  ');
					$writer->writeLine((string) ($account['displayName'] ?? ''), [TShellWriter::BOLD]);
					foreach ((array) ($account['propertySummaries'] ?? []) as $property) {
						$properties[] = $property;
					}
				}
			}
			foreach ($properties as $property) {
				$writer->write('  ' . ($property['property'] ?? '') . '  ');
				$writer->writeLine((string) ($property['displayName'] ?? ''), [TShellWriter::GREEN]);
				foreach ($admin->listDataStreams((string) ($property['property'] ?? '')) as $stream) {
					$measurementId = $stream['webStreamData']['measurementId'] ?? null;
					$writer->writeLine('    ' . ($stream['name'] ?? '') . '  ' . ($stream['type'] ?? '') . ($measurementId !== null ? '  ' . $measurementId : ''));
				}
			}
		} catch (TException $e) {
			$writer->writeError($e->getMessage());
			return true;
		}
		$writer->writeLine();
		return true;
	}

	/**
	 * Returns the module's Data API, on the `--property` override when given.
	 * @param GAnalyticsModule $module The module.
	 * @return GAnalyticsDataApi The client.
	 */
	protected function api(GAnalyticsModule $module): GAnalyticsDataApi
	{
		$api = $module->getDataApi();
		if ($this->_property !== null) {
			$api->setPropertyId($this->_property);
		}
		return $api;
	}

	/**
	 * Runs a report and prints it as a table, or prints the error.
	 * @param callable(): GAnalyticsReport $run Runs the report.
	 * @param string $title The heading.
	 * @return bool Whether the command ran.
	 */
	protected function printReport(callable $run, string $title): bool
	{
		$writer = $this->getWriter();
		try {
			$report = $run();
		} catch (TException $e) {
			$writer->writeError($e->getMessage());
			return true;
		}
		$writer->writeLine();
		$writer->writeLine($title . ' (' . $report->getRowCount() . ' rows)', [TShellWriter::BOLD]);
		$columns = $report->getColumns();
		if (\count($report) === 0) {
			$writer->writeLine('  (no rows)');
		} else {
			$widths = \array_map('strlen', $columns);
			$rows = [];
			foreach ($report as $row) {
				$cells = [];
				foreach ($columns as $i => $column) {
					$cells[] = (string) ($row[$column] ?? '');
					$widths[$i] = \max($widths[$i], \strlen(\end($cells)));
				}
				$rows[] = $cells;
			}
			$writer->writeLine('  ' . \implode('  ', \array_map(fn ($column, $i) => $writer->pad($column, $widths[$i]), $columns, \array_keys($columns))), [TShellWriter::UNDERLINE]);
			foreach ($rows as $cells) {
				$writer->writeLine('  ' . \implode('  ', \array_map(fn ($cell, $i) => $writer->pad($cell, $widths[$i]), $cells, \array_keys($cells))));
			}
		}
		$writer->writeLine();
		return true;
	}

	/**
	 * Decodes a JSON object argument, printing an error for anything else.
	 * @param ?string $json The argument, or null for none.
	 * @param string $what The argument's name, for the error.
	 * @return ?array<string, mixed> The decoded object (empty for none), or null after an error.
	 */
	protected function decodeJson(?string $json, string $what): ?array
	{
		if ($json === null || \trim($json) === '') {
			return [];
		}
		$decoded = \json_decode($json, true);
		if (!\is_array($decoded)) {
			$this->getWriter()->writeError("The {$what} must be a JSON object; got: {$json}");
			return null;
		}
		return $decoded;
	}

	/**
	 * Splits a comma-separated argument into names.
	 * @param ?string $list The argument, or null for none.
	 * @return string[] The names.
	 */
	protected function names(?string $list): array
	{
		return \array_values(\array_filter(\array_map('trim', \explode(',', (string) $list)), fn ($name) => $name !== ''));
	}

	/**
	 * @param mixed $object An object or null.
	 * @return string The object's class, or a dash.
	 */
	protected function describeObject(mixed $object): string
	{
		return \is_object($object) ? $object::class : '-';
	}

	/**
	 * Formats a value for the status report, or the configuration error resolving it raises, so
	 * one bad module id does not take the whole report down.
	 * @param callable(): mixed $resolve Returns the value.
	 * @param callable(mixed): string $format Formats the value.
	 * @return string The formatted value, or the error.
	 */
	protected function report(callable $resolve, callable $format): string
	{
		try {
			return $format($resolve());
		} catch (TException $e) {
			return 'invalid: ' . $e->getMessage();
		}
	}

	/**
	 * @return ?string The `--clientid` override.
	 */
	public function getClientId(): ?string
	{
		return $this->_clientId;
	}

	/**
	 * @param mixed $value The `--clientid` override; empty for none.
	 */
	public function setClientId($value): void
	{
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
		$this->_clientId = ($value === null) ? null : \trim((string) TPropertyValue::ensureString($value));
	}

	/**
	 * @return ?string The `--userid` override.
	 */
	public function getUserId(): ?string
	{
		return $this->_userId;
	}

	/**
	 * @param mixed $value The `--userid` override; empty for none.
	 */
	public function setUserId($value): void
	{
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
		$this->_userId = ($value === null) ? null : \trim((string) TPropertyValue::ensureString($value));
	}

	/**
	 * @return ?int The `--limit` row limit.
	 */
	public function getLimit(): ?int
	{
		return $this->_limit;
	}

	/**
	 * @param mixed $value The `--limit` row limit; empty for the API default.
	 */
	public function setLimit($value): void
	{
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
		$this->_limit = ($value === null) ? null : \max(1, TPropertyValue::ensureInteger($value));
	}

	/**
	 * @return ?string The `--property` override.
	 */
	public function getProperty(): ?string
	{
		return $this->_property;
	}

	/**
	 * @param mixed $value The `--property` override; empty for the module's property.
	 */
	public function setProperty($value): void
	{
		$value = TPropertyValue::ensureNullIf($value, TPropertyValue::FILTER_TRIM_VALUE | TPropertyValue::FILTER_EMPTY);
		$this->_property = ($value === null) ? null : \trim((string) TPropertyValue::ensureString($value));
	}
}
