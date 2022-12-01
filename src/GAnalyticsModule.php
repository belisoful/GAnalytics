<?php
/**
 * GAnalyticsModule class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\Util\TPluginModule;

/**
 * GAnalyticsModule class.
 *
 * Adds Google Analytics gtag4 to PRADO for tracking users.  Once added into your
 * application configuration, it automatically injects gtag4 code with your
 * specified MeasurementId.
 *
 * Here is an example code that goes into the app configuration <modules>:
 * <code>
 *		<module id="belisoful/GAnalyticsModule" MeasurementId="G-EGXXXXXXXX" />
 * </code>
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.0.1
 */

class GAnalyticsModule extends TPluginModule
{
	/**
	 * The Application Parameter containing the API Key
	 */
	public const MEASUREMENT_ID_PARAMETER = 'GoogleAnalyticsMeasurementId';
	
	/**
	 * @var stirng the Measurement Id
	 */
	private $_measurementId;
	
	/**
	 * @var string the Application Parameter for the Google Maps API Key
	 */
	private $_measurementIdParameter = self::MEASUREMENT_ID_PARAMETER;
	
	/**
	 * initializes Google Analytics Module.
	 * @param array|\Prado\Xml\TXmlElement $config module configuration
	 */
	public function init($config)
	{
		parent::init($config);
		
		$this->getApplication()->attachEventHandler('onInitComplete', [$this, 'attachPageServicePageHandlers']);
	}
	
	/**
	 * This is raised from the Application::onInitComplete event.  If
	 * the service is a TPageService, then it installs initPageHandler on
	 * the TPageService::onPreRunPage event.
	 * @param object $sender the object raising the event
	 * @param null $param
	 */
	public function attachPageServicePageHandlers($sender, $param)
	{
		$service = $this->getService();
		if ($service->isa('Prado\Web\Services\TPageService')) {
			$service->attachEventHandler('onPreRunPage', [$this, 'initPageHandler']);
		}
	}
	
	/**
	 * This adds the google analytics async script and gtag to the THead.
	 * @param TPageService $sender the object raising the event
	 * @param TPage $param the page about to be run
	 * @param mixed $page
	 */
	public function initPageHandler($sender, $page)
	{
		$cs = $page->getClientScript();
		
		$cs->registerHeadScriptFile('gtag', 'https://www.googletagmanager.com/gtag/js?id=' . $this->getMeasurementId(), true);
		$cs->registerHeadScript(
			'gtag',
			"	window.dataLayer = window.dataLayer || [];
	function gtag(){dataLayer.push(arguments);}
	gtag('js', new Date());
	gtag('config', '" . $this->getMeasurementId() . "');"
		);
	}
	
	/**
	 * This returns the API Key set in the module or from the Application Parameter.
	 * @return string  the API Key
	 */
	public function getMeasurementId()
	{
		if (!$this->_measurementId) {
			$this->_measurementId = $this->getApplication()->getParameter()->itemAt($this->_measurementIdParameter);
		}
		return $this->_measurementId;
	}
	
	/**
	 * @param $key string sets the API Key in the module properties
	 * @param mixed $measurementId
	 */
	public function setMeasurementId($measurementId)
	{
		$this->_measurementId = $measurementId;
	}
	
	/**
	 * @return string the API Key Application Parameter
	 */
	public function getMeasurementIdParameter()
	{
		return $this->_measurementIdParameter;
	}
	
	/**
	 * @param $parameter string the API Key Application Parameter
	 */
	public function setMeasurementIdParameter($parameter)
	{
		$this->_measurementIdParameter = $parameter;
	}
}
