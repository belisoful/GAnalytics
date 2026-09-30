<?php

/**
 * The control tracking end-to-end application: click tracking, tabs, views, a wizard, paging
 * and the realtime counter, with the module configured from protected/application.xml.
 */

require(__DIR__ . '/../../../vendor/autoload.php');

// An installed package's class map is registered by Composer (extra.prado.class-map); inside this
// repository the package is the root project, so the application registers it, and templates use
// the short names as they do in a consumer.
\Prado\Prado::registerClassMap(json_decode((string) file_get_contents(__DIR__ . '/../../../config/classMap.json'), true));

$application = new \Prado\TApplication(__DIR__ . '/protected', false);
$application->run();
