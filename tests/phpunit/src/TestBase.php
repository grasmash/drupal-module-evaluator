<?php

namespace Grasmash\ComposerConverter\Tests;

use Symfony\Component\Filesystem\Filesystem;

/**
 *
 */
abstract class TestBase extends \PHPUnit_Framework_TestCase
{

  /**
   * @var \Symfony\Component\Filesystem\Filesystem
   */
    protected $fs;

  /**
   * @var string
   */
    protected $composerizeDrupalPath;

  /**
   * @var string
   */
    protected $sandbox;

  /**
   * @var \Grasmash\ComposerConverter\Tests\SandboxManager
   */
    protected $sandboxManager;

  /**
   * {@inheritdoc}
   *
   * @see https://symfony.com/doc/current/console.html#testing-commands
   */
    public function setUp()
    {
        parent::setUp();
        $this->fs = new Filesystem();
        $this->composerizeDrupalPath = dirname(dirname(dirname(__DIR__)));
        $this->sandboxManager = new SandboxManager();
    }
}
