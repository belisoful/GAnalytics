<?php

/**
 * GAnalyticsRealtimeCounter class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/belisoful/GAnalytics/blob/main/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TException;
use Prado\Prado;
use Prado\TPropertyValue;
use Prado\Util\Log\TLogger;
use Prado\Web\THttpUtility;
use Prado\Web\UI\ActiveControls\TTimeTriggeredCallback;

/**
 * GAnalyticsRealtimeCounter class.
 *
 * GAnalyticsRealtimeCounter shows one realtime metric, such as the active users of the last
 * 30 minutes, and refreshes it by callback every {@see getInterval() Interval} seconds:
 *
 * ```xml
 * <com:GAnalyticsRealtimeCounter Metric="activeUsers" Format="{0} people on the site now" Interval="60" />
 * ```
 *
 * | Property | Default | Meaning |
 * |---|---|---|
 * | `Metric` | `activeUsers` | The realtime metric |
 * | `Format` | `{0}` | The text; `{0}` is the value |
 * | `Interval` | 60 | The seconds between refreshes |
 * | `CacheExpire` | 60 | The seconds a value is shared through the application cache, so every visitor's counter costs one API request per period |
 * | `ErrorText` | empty | The text while the value cannot be read (logged as a warning) |
 * | `CssClass` | empty | The CSS class of the `<span>` |
 * | `AnalyticsModule` | the first `GAnalyticsModule` | The module id whose property and credentials read the value |
 *
 * The value is the metric's total over the realtime window; no rows is 0. The counter renders a
 * `<span>` with the ID `<client ID>_value`, and each callback replaces its content.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GAnalyticsRealtimeCounter extends TTimeTriggeredCallback
{
	/** The default seconds between refreshes, and the default seconds a value is shared. */
	public const DEFAULT_INTERVAL = 60;

	/**
	 * Reads the metric's value, shared through the application cache for {@see getCacheExpire() CacheExpire} seconds.
	 * @return null|float|int The value; null when it cannot be read (logged).
	 */
	public function getValue(): null|float|int
	{
		$metric = $this->getMetric();
		try {
			$module = GAnalyticsModule::findModule($this->getAnalyticsModule());
			if ($module === null) {
				throw new TConfigurationException('ganalytics_module_invalid', $this->getAnalyticsModule() ?: '(any)', GAnalyticsModule::class);
			}
			$report = $module->runCachedReport(GAnalyticsDataApi::realtimeRequest([$metric]), true, $this->getCacheExpire());
		} catch (TException $e) {
			Prado::log('The realtime counter ' . $this->getID() . ' could not read ' . $metric . ': ' . $e->getMessage(), TLogger::WARNING, static::class);
			return null;
		}
		$total = 0;
		foreach ($report->getRows() as $row) {
			$total += $row[$metric] ?? 0;
		}
		return $total;
	}

	/**
	 * @return string The text: the {@see getFormat() Format} with the value, or the {@see getErrorText() ErrorText}.
	 */
	public function getDisplayText(): string
	{
		$value = $this->getValue();
		return $value === null ? $this->getErrorText() : \str_replace('{0}', (string) $value, $this->getFormat());
	}

	/**
	 * @return string The ID of the `<span>` holding the text.
	 */
	public function getValueClientID(): string
	{
		return $this->getClientID() . '_value';
	}

	/**
	 * Renders the `<span>` with the text, then the timer.
	 * @param \Prado\Web\UI\THtmlWriter $writer The writer.
	 */
	public function render($writer)
	{
		$writer->addAttribute('id', $this->getValueClientID());
		if (($class = $this->getCssClass()) !== '') {
			$writer->addAttribute('class', $class);
		}
		$writer->renderBeginTag('span');
		$writer->write(THttpUtility::htmlEncode($this->getDisplayText()));
		$writer->renderEndTag();
		parent::render($writer);
	}

	/**
	 * Raises `OnCallback`, then replaces the `<span>` content with the current text.
	 * @param \Prado\Web\UI\ActiveControls\TCallbackEventParameter $param The event parameter.
	 */
	public function onCallback($param)
	{
		parent::onCallback($param);
		$this->getPage()->getCallbackClient()->update($this->getValueClientID(), THttpUtility::htmlEncode($this->getDisplayText()));
	}

	/**
	 * @return float The seconds between refreshes. Defaults to {@see DEFAULT_INTERVAL}.
	 */
	public function getInterval()
	{
		return $this->getViewState('Interval', static::DEFAULT_INTERVAL);
	}

	/**
	 * @param mixed $value The seconds between refreshes; a positive number.
	 * @throws TConfigurationException When the value is not positive.
	 */
	public function setInterval($value)
	{
		$interval = TPropertyValue::ensureFloat($value);
		if ($interval <= 0) {
			throw new TConfigurationException('callback_interval_be_positive', $this->getID());
		}
		$this->setViewState('Interval', $interval, static::DEFAULT_INTERVAL);
	}

	/**
	 * @return bool Whether the refreshes start when the page loads. Defaults to true.
	 */
	public function getStartTimerOnLoad()
	{
		return $this->getViewState('StartTimerOnLoad', true);
	}

	/**
	 * @param mixed $value Whether the refreshes start when the page loads.
	 */
	public function setStartTimerOnLoad($value)
	{
		$this->setViewState('StartTimerOnLoad', TPropertyValue::ensureBoolean($value), true);
	}

	/**
	 * @return string The realtime metric. Defaults to `activeUsers`.
	 */
	public function getMetric(): string
	{
		return $this->getViewState('Metric', 'activeUsers');
	}

	/**
	 * @param mixed $value The realtime metric; empty restores `activeUsers`.
	 */
	public function setMetric($value): void
	{
		$value = \trim((string) TPropertyValue::ensureString($value));
		$this->setViewState('Metric', $value === '' ? 'activeUsers' : $value, 'activeUsers');
	}

	/**
	 * @return string The text; `{0}` is the value. Defaults to `{0}`.
	 */
	public function getFormat(): string
	{
		return $this->getViewState('Format', '{0}');
	}

	/**
	 * @param mixed $value The text; `{0}` is the value. Empty restores `{0}`.
	 */
	public function setFormat($value): void
	{
		$value = (string) TPropertyValue::ensureString($value);
		$this->setViewState('Format', $value === '' ? '{0}' : $value, '{0}');
	}

	/**
	 * @return string The text while the value cannot be read. Defaults to empty.
	 */
	public function getErrorText(): string
	{
		return $this->getViewState('ErrorText', '');
	}

	/**
	 * @param mixed $value The text while the value cannot be read.
	 */
	public function setErrorText($value): void
	{
		$this->setViewState('ErrorText', (string) TPropertyValue::ensureString($value), '');
	}

	/**
	 * @return int The seconds a value is shared. Defaults to {@see DEFAULT_INTERVAL}.
	 */
	public function getCacheExpire(): int
	{
		return $this->getViewState('CacheExpire', static::DEFAULT_INTERVAL);
	}

	/**
	 * @param mixed $value The seconds a value is shared through the application cache; 0 or less reads every refresh.
	 */
	public function setCacheExpire($value): void
	{
		$this->setViewState('CacheExpire', \max(0, TPropertyValue::ensureInteger($value)), static::DEFAULT_INTERVAL);
	}

	/**
	 * @return string The CSS class of the `<span>`.
	 */
	public function getCssClass(): string
	{
		return $this->getViewState('CssClass', '');
	}

	/**
	 * @param mixed $value The CSS class of the `<span>`.
	 */
	public function setCssClass($value): void
	{
		$this->setViewState('CssClass', \trim((string) TPropertyValue::ensureString($value)), '');
	}

	/**
	 * @return string The id of the analytics module; empty for the first `GAnalyticsModule`.
	 */
	public function getAnalyticsModule(): string
	{
		return $this->getViewState('AnalyticsModule', '');
	}

	/**
	 * @param mixed $value The id of the analytics module; empty for the first `GAnalyticsModule`.
	 */
	public function setAnalyticsModule($value): void
	{
		$this->setViewState('AnalyticsModule', \trim((string) TPropertyValue::ensureString($value)), '');
	}
}
