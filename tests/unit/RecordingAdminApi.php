<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsAdminApi;

/** An Admin API client whose transport records requests and answers from a queue. */
class RecordingAdminApi extends GAnalyticsAdminApi
{
	use RecordingTransportTrait;
}
