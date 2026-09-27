<?php

namespace belisoful\GAnalytics\Test\Unit;

/** Replaces the HTTP transport with a recorder that answers from a queue of canned responses. */
trait RecordingTransportTrait
{
	/** @var array<int, array{method: string, url: string, headers: string[], body: ?string, timeout: float}> The requests, oldest first. */
	public array $requests = [];

	/** @var array<int, array{0: int, 1: ?string}> The answers, consumed in order; the last one repeats. */
	public array $answers = [[200, '{}']];

	protected function transport(string $method, string $url, array $headers, ?string $body, float $timeout): array
	{
		$this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];
		$answer = count($this->answers) > 1 ? array_shift($this->answers) : $this->answers[0];
		return $answer;
	}

	/** @return array<string, mixed> The decoded body of the last request. */
	public function lastBody(): array
	{
		return json_decode((string) $this->requests[count($this->requests) - 1]['body'], true) ?? [];
	}

	/** @param array<string, mixed> $response A JSON answer with status 200. */
	public function answer(array $response, int $status = 200): void
	{
		$this->answers = [[$status, json_encode($response)]];
	}
}
