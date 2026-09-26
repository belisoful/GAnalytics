<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsMeasurementProtocol;
use belisoful\GAnalytics\GAnalyticsModule;

/** A module with an in-memory deferred-call store and a recording Measurement Protocol client. */
class ProbeGAnalyticsModule extends GAnalyticsModule
{
	/** @var ?\ArrayAccess The deferred-call store; null models an application without a session. */
	public ?\ArrayAccess $store;

	/** @var RecordingMeasurementProtocol The client {@see createMeasurementProtocol()} returns. */
	public RecordingMeasurementProtocol $protocol;

	public function __construct()
	{
		$this->store = new \ArrayObject();
		$this->protocol = new RecordingMeasurementProtocol();
		parent::__construct();
	}

	/** @return array<int, array<int, mixed>> The calls deferred to the store. */
	public function deferred(): array
	{
		return $this->store !== null && isset($this->store[static::SESSION_KEY]) ? $this->store[static::SESSION_KEY] : [];
	}

	protected function getDeferredStore(): ?\ArrayAccess
	{
		return $this->store;
	}

	protected function createMeasurementProtocol(): GAnalyticsMeasurementProtocol
	{
		return $this->protocol;
	}
}
