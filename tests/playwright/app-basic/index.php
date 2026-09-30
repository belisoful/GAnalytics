<?php

/**
 * The basic consent mode end-to-end application: no Google tag until the visitor grants
 * analytics or ad storage, under a nonce Content Security Policy without 'unsafe-eval'.
 */

require(__DIR__ . '/../../../vendor/autoload.php');

$application = new \Prado\TApplication(__DIR__ . '/protected', false);
$application->run();
