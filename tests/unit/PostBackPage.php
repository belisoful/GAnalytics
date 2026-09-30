<?php

namespace belisoful\GAnalytics\Test\Unit;

use Prado\Web\UI\TPage;

/** A page that reports a postback, for the validation tracking. */
class PostBackPage extends TPage
{
	public function getIsPostBack()
	{
		return true;
	}
}
