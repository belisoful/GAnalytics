<?php

/**
 * The router of the unit tests' local HTTP server: answers with the status the `status` query
 * parameter asks for (200 by default) and a JSON echo of the method, headers and body, or with
 * the raw `body` query parameter when one is given.
 */
$status = (int) ($_GET['status'] ?? 200);
http_response_code($status);
if (isset($_GET['body'])) {
	header('Content-Type: text/plain');
	echo $_GET['body'];
	return true;
}
header('Content-Type: application/json');
$headers = [];
foreach ($_SERVER as $key => $value) {
	if (str_starts_with($key, 'HTTP_') || in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
		$headers[$key] = $value;
	}
}
echo json_encode([
	'method' => $_SERVER['REQUEST_METHOD'],
	'uri' => $_SERVER['REQUEST_URI'],
	'headers' => $headers,
	'body' => file_get_contents('php://input'),
]);
return true;
