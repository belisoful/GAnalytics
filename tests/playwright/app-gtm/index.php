<?php

/**
 * The Tag Manager end-to-end application: the module with a container id and no Measurement ID.
 */

require(__DIR__ . '/../../../vendor/autoload.php');

$application = new \Prado\TApplication(__DIR__ . '/protected', false);
$application->run();
