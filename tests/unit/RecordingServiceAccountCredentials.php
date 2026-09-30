<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsServiceAccountCredentials;

/** Service account credentials whose token exchange is recorded and answered from a queue. */
class RecordingServiceAccountCredentials extends GAnalyticsServiceAccountCredentials
{
	use RecordingTransportTrait;
}
