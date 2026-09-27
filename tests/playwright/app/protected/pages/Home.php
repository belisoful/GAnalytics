<?php

use Prado\Web\UI\TPage;

/** The gtag.js home page: a callback that tracks an event and one that grants consent, through the page behavior. */
class Home extends TPage
{
	public function trackClick($sender, $param)
	{
		$this->trackEvent('add_to_cart', ['value' => 9.99, 'currency' => 'USD']);
		$this->status->setText('tracked');
	}

	public function grantConsent($sender, $param)
	{
		$this->updateConsent(['analytics_storage' => 'granted']);
		$this->status->setText('consented');
	}
}
