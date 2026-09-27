<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsDataApi;

/** A Data API client whose transport records requests and answers from a queue. */
class RecordingDataApi extends GAnalyticsDataApi
{
	use RecordingTransportTrait;
}
