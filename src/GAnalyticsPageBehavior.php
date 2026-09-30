<?php

/**
 * GAnalyticsPageBehavior class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\Util\TClassBehavior;
use Prado\Web\UI\TPage;

/**
 * GAnalyticsPageBehavior class.
 *
 * A class behavior that {@see GAnalyticsModule} attaches to {@see TPage}, so page code reaches
 * the module through the page. The methods forward to the module with the same arguments, the
 * page being supplied by the behavior mechanism:
 *
 * ```php
 * $this->trackEvent('sign_up', ['method' => 'form']);          // on the rendered page
 * $this->trackEvent('login', ['method' => 'form'], true);       // on the next page, after a redirect
 * $this->trackEcommerce('add_to_cart', ['items' => [['item_id' => 'SKU-1']]]);
 * $this->updateConsent(['analytics_storage' => 'granted']);
 * $this->setUserProperties(['plan' => 'pro']);
 * $this->gtag('event', 'tutorial_begin');
 * $this->getGAnalytics()->getMeasurementId();
 * ```
 *
 * The behavior is attached under {@see GAnalyticsModule::PAGE_BEHAVIOR_NAME} when the module's
 * {@see GAnalyticsModule::getAttachPageBehavior() AttachPageBehavior} is true (the default).
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GAnalyticsPageBehavior extends TClassBehavior
{
	/** @var GAnalyticsModule The module the behavior forwards to. */
	private GAnalyticsModule $_module;

	/**
	 * @param GAnalyticsModule $module The module the behavior forwards to.
	 */
	public function __construct(GAnalyticsModule $module)
	{
		$this->_module = $module;
		parent::__construct();
	}

	/**
	 * @param TPage $page The page the method is called on.
	 * @return GAnalyticsModule The module.
	 */
	public function getGAnalytics($page): GAnalyticsModule
	{
		return $this->_module;
	}

	/**
	 * Queues a `gtag('event', …)` call; see {@see GAnalyticsModule::trackEvent()}.
	 * @param TPage $page The page the method is called on.
	 * @param string $name The event name.
	 * @param array<string, mixed> $params The event parameters.
	 * @param bool $deferred Whether the event is delivered on the next page instead of this one.
	 */
	public function trackEvent($page, string $name, array $params = [], bool $deferred = false): void
	{
		$this->_module->trackEvent($name, $params, $deferred);
	}

	/**
	 * Queues a `gtag('consent', 'update', …)` call; see {@see GAnalyticsModule::updateConsent()}.
	 * @param TPage $page The page the method is called on.
	 * @param array<string, mixed> $params The consent parameters.
	 * @param bool $deferred Whether the call is delivered on the next page instead of this one.
	 */
	public function updateConsent($page, array $params, bool $deferred = false): void
	{
		$this->_module->updateConsent($params, $deferred);
	}

	/**
	 * Queues a `gtag('set', 'user_properties', …)` call; see {@see GAnalyticsModule::setUserProperties()}.
	 * @param TPage $page The page the method is called on.
	 * @param array<string, mixed> $properties The user properties.
	 * @param bool $deferred Whether the call is delivered on the next page instead of this one.
	 */
	public function setUserProperties($page, array $properties, bool $deferred = false): void
	{
		$this->_module->setUserProperties($properties, $deferred);
	}

	/**
	 * Queues a GA4 ecommerce event; see {@see GAnalyticsModule::trackEcommerce()}.
	 * @param TPage $page The page the method is called on.
	 * @param string $event The ecommerce event, such as `purchase`.
	 * @param array<string, mixed> $params The event parameters; `items` holds {@see GAnalyticsItem}s or arrays.
	 * @param bool $deferred Whether the event is delivered on the next page instead of this one.
	 */
	public function trackEcommerce($page, string $event, array $params, bool $deferred = false): void
	{
		$this->_module->trackEcommerce($event, $params, $deferred);
	}

	/**
	 * Queues any `gtag(…)` call; see {@see GAnalyticsModule::gtag()}.
	 * @param TPage $page The page the method is called on.
	 * @param mixed ...$args The gtag arguments.
	 */
	public function gtag($page, mixed ...$args): void
	{
		$this->_module->gtag(...$args);
	}
}
