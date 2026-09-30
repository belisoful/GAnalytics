<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\IGAnalyticsConsentStore;
use Prado\TModule;

/** A module that is a consent store, recording the states it is given. */
class FakeConsentModule extends TModule implements IGAnalyticsConsentStore
{
	/** @var array<string, string> */
	public array $state = [];

	/** @var array<int, array<string, string>> */
	public array $updates = [];

	public function getConsentState(): array
	{
		return $this->state;
	}

	public function setConsentState(array $state): void
	{
		$this->updates[] = $state;
		$this->state = \array_merge($this->state, $state);
	}
}
