<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\IGAnalyticsCredentials;
use Prado\TModule;

/** A module that is credentials, for resolving the module's Credentials by id. */
class FakeCredentialsModule extends TModule implements IGAnalyticsCredentials
{
	public string $token = 'module-token';

	public function getAccessToken(): string
	{
		return $this->token;
	}
}
