<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsAdminApi;
use belisoful\GAnalytics\GAnalyticsDataApi;
use belisoful\GAnalytics\GAnalyticsMeasurementProtocol;
use belisoful\GAnalytics\GAnalyticsModule;

/** A module with an in-memory deferred-call store and recording Google clients. */
class ProbeGAnalyticsModule extends GAnalyticsModule
{
	/** @var ?\ArrayAccess The deferred-call store; null models an application without a session. */
	public ?\ArrayAccess $store;

	/** @var RecordingMeasurementProtocol The client {@see createMeasurementProtocol()} returns. */
	public RecordingMeasurementProtocol $protocol;

	/** @var RecordingDataApi The client {@see createDataApi()} returns. */
	public RecordingDataApi $dataApi;

	/** @var RecordingAdminApi The client {@see createAdminApi()} returns. */
	public RecordingAdminApi $adminApi;

	public function __construct()
	{
		$this->store = new \ArrayObject();
		$this->protocol = new RecordingMeasurementProtocol();
		$this->dataApi = new RecordingDataApi();
		$this->adminApi = new RecordingAdminApi();
		parent::__construct();
	}

	/** @return array<int, array<int, mixed>> The calls deferred to the store. */
	public function deferred(): array
	{
		return $this->store !== null && isset($this->store[static::SESSION_KEY]) ? $this->store[static::SESSION_KEY] : [];
	}

	/** @var bool[] The $forWrite flags of the store lookups, in order. */
	public array $storeLookups = [];

	protected function getDeferredStore(bool $forWrite = false): ?\ArrayAccess
	{
		$this->storeLookups[] = $forWrite;
		return $this->store;
	}

	protected function createMeasurementProtocol(): GAnalyticsMeasurementProtocol
	{
		return $this->protocol;
	}

	protected function createDataApi(): GAnalyticsDataApi
	{
		return $this->dataApi;
	}

	protected function createAdminApi(): GAnalyticsAdminApi
	{
		return $this->adminApi;
	}
}
