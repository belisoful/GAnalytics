<?php

/**
 * Common settings for all unit tests of the PRADO Google Analytics extension.
 *
 * Registers the extension's error message file so exception codes resolve, autoloads the
 * framework and the extension via Composer's PSR-4 map, and constructs (without running) a
 * TApplication on the test application directory so modules have an application to attach to.
 */

require_once(__DIR__ . '/../../vendor/autoload.php');

// Lets a test construct another TApplication (Prado::setApplication refuses a second one otherwise).
define('PRADO_TEST_RUN', true);

\Prado\Exceptions\TException::addMessageFile(__DIR__ . '/../../config/errorMessages.txt');

$application = new \Prado\TApplication(__DIR__ . '/../unit/app', false);

// The default response module starts an output buffer when it initializes; create it here and
// close that buffer, so phpunit's own output is not captured and no test is flagged for it.
$level = ob_get_level();
$application->getResponse();
while (ob_get_level() > $level) {
	ob_end_flush();
}
