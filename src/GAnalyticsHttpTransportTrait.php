<?php

/**
 * GAnalyticsHttpTransportTrait trait file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/GAnalytics
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace belisoful\GAnalytics;

/**
 * GAnalyticsHttpTransportTrait trait.
 *
 * The one HTTP transport of the extension: a `file_get_contents()` over an `http` stream context,
 * kept in one protected method so a subclass or a test replaces it. Every Google request of the
 * extension (Measurement Protocol, token exchange, Data API, Admin API) goes through
 * {@see transport()}.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
trait GAnalyticsHttpTransportTrait
{
	/**
	 * Sends one HTTP request and returns the status and the response body. A transport failure
	 * (no connection, a timeout) is status 0 with a null body.
	 * @param string $method The HTTP method.
	 * @param string $url The request URL.
	 * @param string[] $headers The request header lines, such as `Content-Type: application/json`.
	 * @param ?string $body The request body, or null for none.
	 * @param float $timeout The timeout in seconds.
	 * @return array{0: int, 1: ?string} The HTTP status code and the response body.
	 */
	protected function transport(string $method, string $url, array $headers, ?string $body, float $timeout): array
	{
		$options = [
			'method' => $method,
			'header' => implode("\r\n", $headers) . "\r\n",
			'timeout' => $timeout,
			'ignore_errors' => true,
		];
		if ($body !== null) {
			$options['content'] = $body;
		}
		$response = @file_get_contents($url, false, stream_context_create(['http' => $options]));
		$status = 0;
		// PHP defines $http_response_header only when the http wrapper received a response.
		$responseHeaders = get_defined_vars()['http_response_header'] ?? [];
		foreach ($responseHeaders as $header) {
			if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $match)) {
				$status = (int) $match[1];
			}
		}
		return [$status, $response === false ? null : $response];
	}
}
