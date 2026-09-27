<?php

use Prado\Web\UI\TPage;

/** A postback that queues a deferred event and redirects, so the event must arrive on the next page. */
class Login extends TPage
{
	public function logIn($sender, $param)
	{
		$this->trackEvent('login', ['method' => 'form'], true);
		$this->getResponse()->redirect($this->getService()->constructUrl('Home'));
	}
}
