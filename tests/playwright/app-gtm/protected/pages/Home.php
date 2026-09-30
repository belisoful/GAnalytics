<?php

use Prado\Web\UI\TPage;

/** The Tag Manager home page: a callback event becomes a data layer push. */
class Home extends TPage
{
	public function trackClick($sender, $param)
	{
		$this->trackEvent('add_to_cart', ['value' => 9.99]);
		$this->status->setText('tracked');
	}
}
