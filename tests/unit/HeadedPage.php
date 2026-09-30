<?php

namespace belisoful\GAnalytics\Test\Unit;

use Prado\Web\UI\TPage;
use Prado\Web\UI\WebControls\THead;

/** A page whose THead is attached by the test without rendering. */
class HeadedPage extends TPage
{
	public function attachHead(): THead
	{
		$head = new THead();
		$this->setHead($head);
		return $head;
	}
}
