#!/usr/bin/env php
<?php

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/commands/translate.php';

use Symfony\Component\Console\Application;

$application = new Application();

$application->add(new Translate());

$application->run();
