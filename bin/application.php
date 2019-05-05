<?php
require __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Console\Application;
use Consolidation\AnnotatedCommand\AnnotatedCommandFactory;
use Consolidation\OutputFormatters\FormatterManager;
use Grasmash\Evaluator\EvaluateCommand;

$application = new Application('evaluator', '1.0.0');
$command = new EvaluateCommand($application);
$commandFactory = new AnnotatedCommandFactory();
$commandFactory->setIncludeAllPublicMethods(true);
$commandFactory->commandProcessor()->setFormatterManager(new FormatterManager());
$commandList = $commandFactory->createCommandsFromClass($command);
foreach ($commandList as $command) {
    $application->add($command);
}
$application->setDefaultCommand($command->getName());
$application->run();
