<?php
require __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Console\Application;

use Grasmash\Evaluator\EvaluateCommand;

$application = new Application('evaluator', '1.0.0');
$command = new EvaluateCommand();

$application->add($command);

$application->setDefaultCommand($command->getName(), true);
$application->run();
