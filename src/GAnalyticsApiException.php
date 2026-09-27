<?php

/**
 * GAnalyticsApiException class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace belisoful\GAnalytics;

use Prado\Exceptions\TException;

/**
 * GAnalyticsApiException class.
 *
 * A Google API request that was not accepted: the HTTP status is not 2xx, or the response is not
 * JSON. {@see getStatusCode()} is the status (0 for a transport failure) and {@see getResponse()}
 * the decoded error body, whose `error.message` is the message text when Google sent one.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class GAnalyticsApiException extends TException
{
	/** @var int The HTTP status code; 0 for a transport failure. */
	private int $_statusCode;

	/** @var array<string, mixed> The decoded response body; empty when there was none. */
	private array $_response;

	/**
	 * @param int $statusCode The HTTP status code; 0 for a transport failure.
	 * @param array<string, mixed> $response The decoded response body.
	 * @param ?string $detail The message detail; defaults to the response's `error.message`, `error.status`, or the status.
	 */
	public function __construct(int $statusCode, array $response = [], ?string $detail = null)
	{
		$this->_statusCode = $statusCode;
		$this->_response = $response;
		$detail ??= $response['error']['message'] ?? $response['error']['status'] ?? ($statusCode === 0 ? 'no response' : 'HTTP ' . $statusCode);
		parent::__construct('ganalytics_api_error', (string) $statusCode, (string) $detail);
	}

	/**
	 * @return int The HTTP status code; 0 for a transport failure.
	 */
	public function getStatusCode(): int
	{
		return $this->_statusCode;
	}

	/**
	 * @return array<string, mixed> The decoded response body; empty when there was none.
	 */
	public function getResponse(): array
	{
		return $this->_response;
	}
}
