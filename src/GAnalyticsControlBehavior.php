<?php

/**
 * GAnalyticsControlBehavior class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/belisoful/GAnalytics/blob/main/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\Exceptions\TInvalidDataValueException;
use Prado\Util\TClassBehavior;
use Prado\Web\UI\JuiControls\TJuiAutoComplete;
use Prado\Web\UI\WebControls\TMultiView;
use Prado\Web\UI\WebControls\TPager;
use Prado\Web\UI\WebControls\TTabPanel;
use Prado\Web\UI\WebControls\TWizard;

/**
 * GAnalyticsControlBehavior class.
 *
 * GAnalyticsControlBehavior reports the events of PRADO controls to Google Analytics. The module
 * attaches one per {@see GAnalyticsModule::setTrackControls() TrackControls} name to the control
 * classes of that name, so every control of those classes created afterwards reports:
 *
 * | Name | Control event | Google Analytics |
 * |---|---|---|
 * | `wizards` | `TWizard::onActiveStepChanged` on a postback | `wizard_step` with `wizard`, `step_index` (from 1), `step_name`, `step_count` |
 * | `wizards` | `TWizard::onCompleteButtonClick` | `wizard_complete` with `wizard`, `step_count` |
 * | `wizards` | `TWizard::onCancelButtonClick` | `wizard_cancel` with `wizard`, `step_index`, `step_name` |
 * | `views` | `TMultiView::onActiveViewChanged` on a postback | a virtual page view, `#<multiview ID>:<view ID>` ({@see GAnalyticsModule::trackVirtualPageView()}) |
 * | `tabs` | `TTabPanel::onPreRender` | tab switches in the browser as virtual page views ({@see GAnalyticsModule::registerTabTracking()}) |
 * | `paging` | `TDataGrid::onPageIndexChanged`, `TPager::onPageIndexChanged` | `view_item_list` with `item_list_id`, `item_list_name`, `page` (from 1) |
 * | `searches` | `TJuiAutoComplete::onSuggestionSelected` | `search` with `search_term` |
 *
 * The first activation of a view or a wizard step, when a page is first requested, is not an
 * event. A wizard with a `FinishDestinationUrl` or `CancelDestinationUrl` redirects, so its
 * `wizard_complete` or `wizard_cancel` is deferred to the next page. `item_list_name` and
 * `step_name` are the grid's caption and the step's title when set, the control ID otherwise.
 * Parameter values are cut to {@see GAnalyticsModule::PARAM_MAX_LENGTH} characters.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GAnalyticsControlBehavior extends TClassBehavior
{
	/** The control events of each tracking name, and their handlers. */
	public const CONTROL_EVENTS = [
		'wizards' => ['onActiveStepChanged' => 'wizardStepChanged', 'onCompleteButtonClick' => 'wizardCompleted', 'onCancelButtonClick' => 'wizardCancelled'],
		'views' => ['onActiveViewChanged' => 'viewChanged'],
		'tabs' => ['onPreRender' => 'tabPanelPreRender'],
		'paging' => ['onPageIndexChanged' => 'pageChanged'],
		'searches' => ['onSuggestionSelected' => 'suggestionSelected'],
	];

	/** @var GAnalyticsModule The module the events go through. */
	private GAnalyticsModule $_module;

	/** @var string The {@see GAnalyticsModule::CONTROL_TRACKING} name. */
	private string $_kind;

	/**
	 * @param GAnalyticsModule $module The module the events go through.
	 * @param string $kind The {@see GAnalyticsModule::CONTROL_TRACKING} name.
	 * @throws TInvalidDataValueException When the name is not a tracking name.
	 */
	public function __construct(GAnalyticsModule $module, string $kind)
	{
		if (!isset(GAnalyticsModule::CONTROL_TRACKING[$kind])) {
			throw new TInvalidDataValueException('ganalytics_track_controls_invalid', $kind, \implode(', ', \array_keys(GAnalyticsModule::CONTROL_TRACKING)));
		}
		$this->_module = $module;
		$this->_kind = $kind;
		parent::__construct();
	}

	/**
	 * @return array<string, string> The control events of the tracking name, and their handlers.
	 */
	public function events()
	{
		return static::CONTROL_EVENTS[$this->_kind];
	}

	/**
	 * @return GAnalyticsModule The module the events go through.
	 */
	public function getModule(): GAnalyticsModule
	{
		return $this->_module;
	}

	/**
	 * @return string The {@see GAnalyticsModule::CONTROL_TRACKING} name.
	 */
	public function getKind(): string
	{
		return $this->_kind;
	}

	/**
	 * Queues `wizard_step` for a step change on a postback.
	 * @param TWizard $sender The wizard.
	 * @param mixed $param The event parameter.
	 */
	public function wizardStepChanged($sender, $param): void
	{
		if (!$sender->getPage()->getIsPostBack()) {
			return;
		}
		$this->_module->trackEvent('wizard_step', [
			'wizard' => $this->cut($sender->getID()),
			'step_index' => $sender->getActiveStepIndex() + 1,
			'step_name' => $this->stepName($sender),
			'step_count' => $sender->getWizardSteps()->getCount(),
		]);
	}

	/**
	 * Queues `wizard_complete`, deferred when the wizard redirects.
	 * @param TWizard $sender The wizard.
	 * @param mixed $param The event parameter.
	 */
	public function wizardCompleted($sender, $param): void
	{
		$this->_module->trackEvent('wizard_complete', [
			'wizard' => $this->cut($sender->getID()),
			'step_count' => $sender->getWizardSteps()->getCount(),
		], $sender->getFinishDestinationUrl() !== '');
	}

	/**
	 * Queues `wizard_cancel`, deferred when the wizard redirects.
	 * @param TWizard $sender The wizard.
	 * @param mixed $param The event parameter.
	 */
	public function wizardCancelled($sender, $param): void
	{
		$this->_module->trackEvent('wizard_cancel', [
			'wizard' => $this->cut($sender->getID()),
			'step_index' => $sender->getActiveStepIndex() + 1,
			'step_name' => $this->stepName($sender),
		], $sender->getCancelDestinationUrl() !== '');
	}

	/**
	 * Reports a view change on a postback as a virtual page view.
	 * @param TMultiView $sender The multi view.
	 * @param mixed $param The event parameter.
	 */
	public function viewChanged($sender, $param): void
	{
		$view = $sender->getActiveView();
		if ($view === null || !$sender->getPage()->getIsPostBack()) {
			return;
		}
		$this->_module->trackVirtualPageView($sender->getID() . ':' . $view->getID(), (string) $view->getID());
	}

	/**
	 * Registers the tab tracking of a tab panel on a full page.
	 * @param TTabPanel $sender The tab panel.
	 * @param mixed $param The event parameter.
	 */
	public function tabPanelPreRender($sender, $param): void
	{
		if (!$sender->getPage()->getIsCallback()) {
			$this->_module->registerTabTracking($sender);
		}
	}

	/**
	 * Queues `view_item_list` for a page change of a data grid or a pager.
	 * @param \Prado\Web\UI\WebControls\TDataGrid|TPager $sender The grid or the pager.
	 * @param \Prado\Web\UI\WebControls\TDataGridPageChangedEventParameter|\Prado\Web\UI\WebControls\TPagerPageChangedEventParameter $param The new page.
	 */
	public function pageChanged($sender, $param): void
	{
		$caption = $sender instanceof TPager ? '' : \trim((string) $sender->getCaption());
		$this->_module->trackEvent('view_item_list', [
			'item_list_id' => $this->cut($sender->getID()),
			'item_list_name' => $this->cut($caption === '' ? $sender->getID() : $caption),
			'page' => (int) $param->getNewPageIndex() + 1,
		]);
	}

	/**
	 * Queues `search` with the selected suggestion's text.
	 * @param TJuiAutoComplete $sender The auto-complete text box.
	 * @param mixed $param The event parameter.
	 */
	public function suggestionSelected($sender, $param): void
	{
		$term = \trim((string) $sender->getText());
		if ($term !== '') {
			$this->_module->trackEvent('search', ['search_term' => $this->cut($term)]);
		}
	}

	/**
	 * @param TWizard $wizard The wizard.
	 * @return string The active step's title, or its ID.
	 */
	protected function stepName(TWizard $wizard): string
	{
		$step = $wizard->getActiveStep();
		$title = $step === null ? '' : \trim((string) $step->getTitle());
		return $this->cut($title !== '' || $step === null ? $title : $step->getID());
	}

	/**
	 * @param mixed $value A parameter value.
	 * @return string The value, cut to {@see GAnalyticsModule::PARAM_MAX_LENGTH} characters.
	 */
	protected function cut(mixed $value): string
	{
		return \mb_substr((string) $value, 0, GAnalyticsModule::PARAM_MAX_LENGTH);
	}
}
