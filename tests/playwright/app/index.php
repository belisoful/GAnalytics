<?php

/**
 * The gtag.js end-to-end application: a PRADO application with the module configured from
 * protected/application.xml, served by PHP's built-in server for the Playwright specs.
 */

require(__DIR__ . '/../../../vendor/autoload.php');

$application = new \Prado\TApplication(__DIR__ . '/protected', false);
$application->run();
