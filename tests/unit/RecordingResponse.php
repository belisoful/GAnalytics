<?php

namespace belisoful\GAnalytics\Test\Unit;

use Prado\Web\THttpResponse;

/** A response that records the cookies it is asked to send, since the CLI cannot send headers. */
class RecordingResponse extends THttpResponse
{
	/** @var \Prado\Web\THttpCookie[] */
	public array $sent = [];

	public function addCookie($cookie)
	{
		$this->sent[] = $cookie;
	}
}
