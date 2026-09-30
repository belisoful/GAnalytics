<?php

use Prado\Web\UI\TPage;

/** The basic consent mode page: an event before consent, and consent granted by callback or postback. */
class Home extends TPage
{
	public function trackClick($sender, $param)
	{
		$this->trackEvent('early_click');
		$this->status->setText('tracked');
	}

	public function grantConsent($sender, $param)
	{
		$this->updateConsent(['analytics_storage' => 'granted']);
		$this->status->setText('consented');
	}
}
