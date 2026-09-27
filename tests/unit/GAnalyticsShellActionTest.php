<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsAccessTokenCredentials;
use belisoful\GAnalytics\GAnalyticsModule;
use belisoful\GAnalytics\GAnalyticsShellAction;
use PHPUnit\Framework\TestCase;
use Prado\IO\TTextWriter;
use Prado\Prado;
use Prado\Shell\TShellAction;
use Prado\Shell\TShellWriter;
use Prado\TApplicationMode;
use Prado\TComponent;
use Prado\Web\UI\TPage;

class GAnalyticsShellActionTest extends TestCase
{
	private TTextWriter $_out;

	protected function tearDown(): void
	{
		TComponent::detachClassBehavior(GAnalyticsModule::PAGE_BEHAVIOR_NAME, TPage::class);
	}

	private function action(?ProbeGAnalyticsModule $module): GAnalyticsShellAction
	{
		$this->_out = new TTextWriter();
		$writer = new TShellWriter($this->_out);
		$writer->setColorSupported(false);
		$action = new GAnalyticsShellAction();
		$action->setWriter($writer);
		$action->setModule($module);
		return $action;
	}

	private function module(): ProbeGAnalyticsModule
	{
		$module = new ProbeGAnalyticsModule();
		$module->setAttachPageBehavior(false);
		$module->setAmendCsp(false);
		$module->setMeasurementId('G-TEST1234AB');
		$module->setApiSecret('s3cret');
		$module->setPropertyId('123');
		$module->setCredentials(new GAnalyticsAccessTokenCredentials('tok'));
		return $module;
	}

	private function printed(): string
	{
		return $this->_out->flush();
	}

	public function testContract()
	{
		$action = new GAnalyticsShellAction();
		self::assertInstanceOf(TShellAction::class, $action);
		self::assertSame('ganalytics', $action->getAction());
		self::assertSame('status', $action->isValidAction(['ganalytics']));
		self::assertSame('send', $action->isValidAction(['ganalytics/send', 'login']));
		self::assertNull($action->isValidAction(['ganalytics/send']), 'send needs an event name.');
		self::assertSame('report', $action->isValidAction(['ganalytics/report', 'activeUsers']));
		self::assertSame('realtime', $action->isValidAction(['ganalytics/realtime']));
		self::assertSame('properties', $action->isValidAction(['ganalytics/properties']));
		self::assertNull($action->isValidAction(['ganalytics/nope']));
		self::assertNull($action->isValidAction(['other']));
		self::assertSame(['clientid', 'userid'], $action->options('send'));
		self::assertSame(['clientid', 'userid'], $action->options('validate'));
		self::assertSame(['limit', 'property'], $action->options('report'));
		self::assertSame(['limit', 'property'], $action->options('realtime'));
		self::assertSame(['property'], $action->options('properties'));
		self::assertSame([], $action->options('status'));
		self::assertSame(['c' => 'clientid', 'u' => 'userid', 'l' => 'limit', 'p' => 'property'], $action->optionAliases());
	}

	public function testOptionsCoerce()
	{
		$action = new GAnalyticsShellAction();
		$action->setClientId(' 1.2 ');
		$action->setUserId(' u ');
		$action->setLimit('5');
		$action->setProperty(' 99 ');
		self::assertSame('1.2', $action->getClientId());
		self::assertSame('u', $action->getUserId());
		self::assertSame(5, $action->getLimit());
		self::assertSame('99', $action->getProperty());
		$action->setClientId('');
		$action->setUserId(null);
		$action->setLimit('');
		$action->setProperty('');
		self::assertNull($action->getClientId());
		self::assertNull($action->getUserId());
		self::assertNull($action->getLimit());
		self::assertNull($action->getProperty());
		$action->setLimit(-3);
		self::assertSame(1, $action->getLimit());
	}

	public function testStatusPrintsTheConfiguration()
	{
		$module = $this->module();
		$module->setContainerId('GTM-ABC1234');
		$module->setTrackLogins(true);
		$action = $this->action($module);
		self::assertTrue($action->actionStatus(['ganalytics/status']));
		$out = $this->printed();
		self::assertStringContainsString('G-TEST1234AB (module property)', $out);
		self::assertStringContainsString('GTM-ABC1234', $out);
		self::assertStringContainsString('API secret', $out);
		self::assertStringContainsString('set', $out);
		self::assertStringContainsString('Property          123', $out);
		self::assertStringContainsString(GAnalyticsAccessTokenCredentials::class, $out);
		self::assertStringContainsString('logins', $out);
		self::assertStringContainsString("gtag('config', \"G-TEST1234AB\");", $out);
		self::assertStringContainsString('gtm.start', $out);
	}

	public function testStatusShowsTheOtherSideOfEverySetting()
	{
		$module = $this->module();
		$module->setEnabled(false);
		$module->setAdditionalMeasurementIds('AW-123456789');
		$module->setEnabledModes('Normal');
		$module->setUserId('customer-1');
		$module->setAttachPageBehavior(true);
		$module->setAmendCsp(true);
		$module->setApiSecret(null);
		$module->setTrackExceptions(true);
		$action = $this->action($module);
		$action->actionStatus([]);
		$out = $this->printed();
		self::assertStringContainsString('no (Enabled=false, mode', $out);
		self::assertStringContainsString('AW-123456789', $out);
		self::assertStringContainsString('Enabled modes     Normal', $out);
		self::assertStringContainsString('User id           customer-1', $out);
		self::assertStringContainsString('exceptions', $out);
		self::assertStringContainsString('attached', $out);
		self::assertStringContainsString('Amend CSP         yes', $out);
		self::assertStringContainsString('API secret        -', $out);

		$module->setUserIdFromUser(true);
		$module->setUserId(null);
		$action = $this->action($module);
		$action->actionStatus([]);
		self::assertStringContainsString('from the application user', $this->printed());

		$module->setEnabled(true);
		$app = Prado::getApplication();
		$mode = (string) $app->getMode();
		$app->setMode(TApplicationMode::Debug);
		try {
			$action = $this->action($module);
			$action->actionStatus([]);
			self::assertStringContainsString('no (Enabled=true, mode Debug)', $this->printed(), 'Inactive because of the mode.');
		} finally {
			$app->setMode($mode);
		}
	}

	public function testStatusDescribesAParameterIdAndAMissingOne()
	{
		$app = Prado::getApplication();
		$module = $this->module();
		$module->setMeasurementId(null);
		$action = $this->action($module);
		$action->actionStatus([]);
		self::assertStringContainsString('parameter GoogleAnalyticsMeasurementId is unset', $this->printed());

		$app->getParameters()->add(GAnalyticsModule::MEASUREMENT_ID_PARAMETER, 'G-FROMPARAM1');
		try {
			$action = $this->action($module);
			$action->actionStatus([]);
			self::assertStringContainsString('G-FROMPARAM1 (parameter GoogleAnalyticsMeasurementId)', $this->printed());
			$app->getParameters()->add(GAnalyticsModule::MEASUREMENT_ID_PARAMETER, 'bad id');
			$action = $this->action($module);
			$action->actionStatus([]);
			self::assertStringContainsString('invalid:', $this->printed());
		} finally {
			$app->getParameters()->remove(GAnalyticsModule::MEASUREMENT_ID_PARAMETER);
		}
	}

	public function testWithoutAModuleEveryCommandPrintsAnError()
	{
		$action = $this->action(null);
		self::assertNull($action->getModule());
		self::assertTrue($action->actionStatus([]));
		self::assertTrue($action->actionSend(['ganalytics/send', 'x']));
		self::assertTrue($action->actionValidate(['ganalytics/validate', 'x']));
		self::assertTrue($action->actionReport(['ganalytics/report', 'activeUsers']));
		self::assertTrue($action->actionRealtime(['ganalytics/realtime']));
		self::assertTrue($action->actionProperties(['ganalytics/properties']));
		self::assertStringContainsString('is not configured', $this->printed());
	}

	public function testModuleIsResolvedFromTheApplication()
	{
		$app = Prado::getApplication();
		$module = $this->module();
		$app->setModule('ganalytics-' . \uniqid(), $module);
		$this->_out = new TTextWriter();
		$writer = new TShellWriter($this->_out);
		$action = new GAnalyticsShellAction();
		$action->setWriter($writer);
		self::assertSame($module, $action->getModule());
	}

	public function testSendAndValidatePostEvents()
	{
		$module = $this->module();
		$action = $this->action($module);
		$action->setClientId('111.222');
		$action->setUserId('u-9');
		self::assertTrue($action->actionSend(['ganalytics/send', 'purchase', '{"value": 9.99}']));
		$payload = $module->protocol->lastPayload();
		self::assertSame('111.222', $payload['client_id']);
		self::assertSame('u-9', $payload['user_id']);
		self::assertSame([['name' => 'purchase', 'params' => ['value' => 9.99]]], $payload['events']);
		self::assertStringStartsWith('https://www.google-analytics.com/mp/collect?', $module->protocol->posts[0]['url']);
		self::assertStringContainsString('Sent purchase for client 111.222: accepted', $this->printed());

		$module->protocol->status = 200;
		$module->protocol->response = '{"validationMessages":[]}';
		$action = $this->action($module);
		self::assertTrue($action->actionValidate(['ganalytics/validate', 'login']));
		self::assertStringStartsWith('https://www.google-analytics.com/debug/mp/collect?', $module->protocol->posts[1]['url']);
		$out = $this->printed();
		self::assertMatchesRegularExpression('/client \d+\.\d+: accepted/', $out, 'A client id is generated.');
		self::assertStringContainsString('No validation messages', $out);

		$module->protocol->response = '{"validationMessages":[{"description":"bad"}]}';
		$action = $this->action($module);
		$action->actionValidate(['ganalytics/validate', 'login', '{}']);
		self::assertStringContainsString('"description":"bad"', $this->printed());

		$module->protocol->status = 500;
		$module->protocol->response = 'server error';
		$action = $this->action($module);
		$action->actionSend(['ganalytics/send', 'login']);
		$out = $this->printed();
		self::assertStringContainsString('refused', $out);
		self::assertStringContainsString('server error', $out);
	}

	public function testSendReportsBadInput()
	{
		$module = $this->module();
		$action = $this->action($module);
		$action->actionSend(['ganalytics/send', 'login', 'not json']);
		self::assertStringContainsString('must be a JSON object', $this->printed());
		self::assertCount(0, $module->protocol->posts);

		$action = $this->action($module);
		$action->actionSend(['ganalytics/send', 'login', '  ']);
		self::assertStringContainsString('accepted', $this->printed(), 'Blank params are no params.');
		self::assertArrayNotHasKey('params', $module->protocol->lastPayload()['events'][0]);

		$action = $this->action($module);
		$action->actionSend(['ganalytics/send', 'bad-name']);
		self::assertStringContainsString('not valid', $this->printed());

		$module->setApiSecret(null);
		$action = $this->action($module);
		$action->actionSend(['ganalytics/send', 'login']);
		self::assertStringContainsString('ApiSecret', $this->printed());
	}

	public function testReportPrintsATable()
	{
		$module = $this->module();
		$module->dataApi->answer(GAnalyticsReportTest::response());
		$action = $this->action($module);
		$action->setLimit(2);
		self::assertTrue($action->actionReport(['ganalytics/report', 'activeUsers,averageSessionDuration,customLabel', 'country, pagePath', '7daysAgo', 'yesterday']));
		$out = $this->printed();
		self::assertStringContainsString('Report 7daysAgo to yesterday (40 rows)', $out);
		self::assertStringContainsString('country  pagePath  activeUsers', $out);
		self::assertStringContainsString('US       /Home     12', $out);
		self::assertStringContainsString('DE       /About    3', $out);
		$body = $module->dataApi->lastBody();
		self::assertSame(2, $body['limit']);
		self::assertSame([['startDate' => '7daysAgo', 'endDate' => 'yesterday']], $body['dateRanges']);
		self::assertStringEndsWith('properties/123:runReport', $module->dataApi->requests[0]['url']);
	}

	public function testReportDefaultsPropertyOverrideEmptyRowsAndErrors()
	{
		$module = $this->module();
		$module->dataApi->answer(['rowCount' => 0]);
		$action = $this->action($module);
		$action->setProperty('456');
		$action->actionReport(['ganalytics/report', 'sessions']);
		self::assertStringContainsString('(no rows)', $this->printed());
		self::assertStringEndsWith('properties/456:runReport', $module->dataApi->requests[0]['url']);
		self::assertSame([['startDate' => '28daysAgo', 'endDate' => 'today']], $module->dataApi->lastBody()['dateRanges']);

		$module->dataApi->answer(['error' => ['message' => 'quota exceeded']], 429);
		$action = $this->action($module);
		$action->actionReport(['ganalytics/report', 'sessions']);
		self::assertStringContainsString('quota exceeded', $this->printed());
	}

	public function testRealtimeUsesTheModuleDefaults()
	{
		$module = $this->module();
		$module->setRealtimeMetrics('activeUsers, screenPageViews');
		$module->setRealtimeDimensions('country');
		$module->dataApi->answer(['metricHeaders' => [['name' => 'activeUsers', 'type' => 'TYPE_INTEGER'], ['name' => 'screenPageViews', 'type' => 'TYPE_INTEGER']], 'dimensionHeaders' => [['name' => 'country']], 'rows' => [['dimensionValues' => [['value' => 'US']], 'metricValues' => [['value' => '3'], ['value' => '9']]]]]);
		$action = $this->action($module);
		self::assertTrue($action->actionRealtime(['ganalytics/realtime']));
		self::assertStringContainsString('Realtime report (1 rows)', $this->printed());
		self::assertSame(['metrics' => [['name' => 'activeUsers'], ['name' => 'screenPageViews']], 'dimensions' => [['name' => 'country']]], $module->dataApi->lastBody());

		$action = $this->action($module);
		$action->setLimit(1);
		$action->actionRealtime(['ganalytics/realtime', 'eventCount', 'eventName']);
		self::assertSame(['metrics' => [['name' => 'eventCount']], 'dimensions' => [['name' => 'eventName']], 'limit' => 1], $module->dataApi->lastBody());
	}

	public function testPropertiesListsAccountsPropertiesAndStreams()
	{
		$module = $this->module();
		$module->adminApi->answers = [
			[200, \json_encode(['accountSummaries' => [['account' => 'accounts/1', 'displayName' => 'Acme', 'propertySummaries' => [['property' => 'properties/123', 'displayName' => 'Site']]]]])],
			[200, \json_encode(['dataStreams' => [['name' => 'properties/123/dataStreams/7', 'type' => 'WEB_DATA_STREAM', 'webStreamData' => ['measurementId' => 'G-ABC']]]])],
		];
		$action = $this->action($module);
		self::assertTrue($action->actionProperties(['ganalytics/properties']));
		$out = $this->printed();
		self::assertStringContainsString('accounts/1  Acme', $out);
		self::assertStringContainsString('properties/123  Site', $out);
		self::assertStringContainsString('properties/123/dataStreams/7  WEB_DATA_STREAM  G-ABC', $out);

		$module->adminApi->answer(['dataStreams' => [['name' => 'properties/9/dataStreams/1', 'type' => 'IOS_APP_DATA_STREAM']]]);
		$action = $this->action($module);
		$action->setProperty('9');
		$action->actionProperties(['ganalytics/properties']);
		$out = $this->printed();
		self::assertStringContainsString('properties/9', $out);
		self::assertStringContainsString('IOS_APP_DATA_STREAM', $out);
		self::assertStringEndsWith('/properties/9/dataStreams?pageSize=200', $module->adminApi->requests[\count($module->adminApi->requests) - 1]['url']);

		$module->adminApi->answer(['error' => ['message' => 'nope']], 403);
		$action = $this->action($module);
		$action->actionProperties(['ganalytics/properties']);
		self::assertStringContainsString('nope', $this->printed());
	}
}
