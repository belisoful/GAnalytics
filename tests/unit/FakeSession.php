<?php

namespace belisoful\GAnalytics\Test\Unit;

use Prado\Web\THttpSession;

/** A session module over an array, with an explicit started flag, so no PHP session is started under phpunit. */
class FakeSession extends THttpSession
{
	/** @var array<string, mixed> */
	public array $data = [];

	public bool $started = false;

	public int $opens = 0;

	public function open()
	{
		$this->started = true;
		$this->opens++;
	}

	public function getIsStarted()
	{
		return $this->started;
	}

	public function getSessionName()
	{
		return 'FAKESESSID';
	}

	public function offsetExists($offset): bool
	{
		return isset($this->data[$offset]);
	}

	public function offsetGet($offset): mixed
	{
		return $this->data[$offset] ?? null;
	}

	public function offsetSet($offset, $item): void
	{
		$this->data[$offset] = $item;
	}

	public function offsetUnset($offset): void
	{
		unset($this->data[$offset]);
	}
}
