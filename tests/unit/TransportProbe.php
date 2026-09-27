<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsHttpTransportTrait;

/** Exposes the real HTTP transport of the extension. */
class TransportProbe
{
	use GAnalyticsHttpTransportTrait;

	/** @return array{0: int, 1: ?string} */
	public function send(string $method, string $url, array $headers, ?string $body, float $timeout = 5.0): array
	{
		return $this->transport($method, $url, $headers, $body, $timeout);
	}
}
