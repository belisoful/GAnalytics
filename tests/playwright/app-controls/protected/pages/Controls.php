<?php

use Prado\Web\UI\TPage;

/** The control tracking page: a view switch by callback, a paged grid, and a click event set in code. */
class Controls extends TPage
{
	public function onLoad($param)
	{
		parent::onLoad($param);
		$this->getGAnalytics()->setClickEvent($this->coded, 'generate_lead', ['source' => 'code']);
		if (!$this->getIsPostBack()) {
			$this->bindOrders();
		}
	}

	public function nextView($sender, $param)
	{
		$this->Views->setActiveViewIndex(1);
	}

	public function changePage($sender, $param)
	{
		$this->Orders->setCurrentPageIndex($param->getNewPageIndex());
		$this->bindOrders();
	}

	protected function bindOrders(): void
	{
		$this->Orders->setDataSource([['order' => 'A-1'], ['order' => 'A-2'], ['order' => 'A-3'], ['order' => 'A-4']]);
		$this->Orders->dataBind();
	}
}
