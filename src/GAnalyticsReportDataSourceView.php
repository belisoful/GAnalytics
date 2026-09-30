<?php

/**
 * GAnalyticsReportDataSourceView class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/belisoful/GAnalytics/blob/main/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\Collections\TList;
use Prado\Web\UI\WebControls\TDataSourceView;

/**
 * GAnalyticsReportDataSourceView class.
 *
 * The view of a {@see GAnalyticsReportDataSource}: {@see select()} runs the report and returns its
 * rows. The view is read-only.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GAnalyticsReportDataSourceView extends TDataSourceView
{
	/**
	 * Runs the data source's report.
	 * @param mixed $parameters The select parameters; the report takes none.
	 * @throws \Prado\Exceptions\TConfigurationException When the report cannot be configured.
	 * @throws GAnalyticsApiException When the API refuses the request.
	 * @return TList The rows, each an array keyed by dimension and metric name.
	 */
	public function select($parameters)
	{
		$source = $this->getDataSource();
		\assert($source instanceof GAnalyticsReportDataSource);
		return new TList($source->getReport()->getRows());
	}
}
