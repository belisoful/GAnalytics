<?php

namespace belisoful\GAnalytics\Test\Unit;

use belisoful\GAnalytics\GAnalyticsMeasurementProtocol;

/** A Measurement Protocol client whose transport records each request and answers with a canned status and body. */
class RecordingMeasurementProtocol extends GAnalyticsMeasurementProtocol
{
	/** @var array<int, array{url: string, body: string}> The requests posted, oldest first. */
	public array $posts = [];

	/** @var int The HTTP status every request is answered with. */
	public int $status = 204;

	/** @var ?string The body every request is answered with. */
	public ?string $response = '';

	protected function post(string $url, string $body): array
	{
		$this->posts[] = ['url' => $url, 'body' => $body];
		return [$this->status, $this->response];
	}

	/** @return array<string, mixed> The decoded body of the last request. */
	public function lastPayload(): array
	{
		return json_decode($this->posts[count($this->posts) - 1]['body'], true);
	}
}
