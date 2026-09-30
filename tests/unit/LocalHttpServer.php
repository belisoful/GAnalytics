<?php

namespace belisoful\GAnalytics\Test\Unit;

/**
 * PHP's built-in web server on a free loopback port, answering every request from
 * {@see ROUTER} with a JSON echo of the request, so the real HTTP transport is exercised
 * without leaving the machine.
 */
class LocalHttpServer
{
	/** The router script the server runs. */
	public const ROUTER = __DIR__ . '/app/echo-router.php';

	/** @var resource The server process. */
	private $_process;

	/** @var int The port. */
	private int $_port;

	private function __construct($process, int $port)
	{
		$this->_process = $process;
		$this->_port = $port;
	}

	/**
	 * Starts a server and waits until it accepts connections.
	 * @throws \RuntimeException When the server does not come up within a few seconds.
	 */
	public static function start(): self
	{
		$port = self::freePort();
		$process = \proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:' . $port, self::ROUTER],
			[0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
			$pipes
		);
		if (!\is_resource($process)) {
			throw new \RuntimeException('The local HTTP server could not be started.');
		}
		$deadline = \microtime(true) + 10;
		while (\microtime(true) < $deadline) {
			$socket = @\fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
			if (\is_resource($socket)) {
				\fclose($socket);
				return new self($process, $port);
			}
			\usleep(50_000);
		}
		\proc_terminate($process);
		throw new \RuntimeException('The local HTTP server did not accept connections.');
	}

	/** @return string The server's base URL. */
	public function url(string $path = '/'): string
	{
		return 'http://127.0.0.1:' . $this->_port . $path;
	}

	/** @return int A loopback port nothing listens on right now. */
	public static function freePort(): int
	{
		$socket = \stream_socket_server('tcp://127.0.0.1:0');
		$port = (int) \substr(\stream_socket_get_name($socket, false), \strrpos(\stream_socket_get_name($socket, false), ':') + 1);
		\fclose($socket);
		return $port;
	}

	public function stop(): void
	{
		if (\is_resource($this->_process)) {
			\proc_terminate($this->_process);
			\proc_close($this->_process);
		}
	}

	public function __destruct()
	{
		$this->stop();
	}
}
