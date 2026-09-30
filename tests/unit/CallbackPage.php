<?php

namespace belisoful\GAnalytics\Test\Unit;

use Prado\Web\UI\ActiveControls\TCallbackClientScript;
use Prado\Web\UI\TPage;

/** A page that reports a callback request, with one callback client the test can inspect. */
class CallbackPage extends TPage
{
	public TCallbackClientScript $client;

	public function __construct()
	{
		$this->client = new TCallbackClientScript();
		parent::__construct();
	}

	public function getIsCallback()
	{
		return true;
	}

	public function getCallbackClient()
	{
		return $this->client;
	}
}
