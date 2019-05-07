<?php

namespace Grasmash\ComposerConverter\Tests;

/**
 *
 */
abstract class CommandTestBase extends TestBase
{
  /**
   * @var \Grasmash\ComposerConverter\Tests\Application
   */
    protected $application;

  /**
   * @var \Grasmash\ComposerConverter\Tests\TestableComposerizeDrupalCommand
   */
    protected $command;

  /**
   * @var \Symfony\Component\Console\Tester\CommandTester
   */
    protected $commandTester;

  /**
   * {@inheritdoc}
   *
   * @see https://symfony.com/doc/current/console.html#testing-commands
   */
    public function setUp()
    {
        parent::setUp();
        $this->application = new Application();
    }
}
