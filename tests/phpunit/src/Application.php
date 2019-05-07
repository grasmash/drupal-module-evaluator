<?php

use Composer\Console\Application;

namespace Grasmash\ComposerConverter\Tests;

/**
 *
 */
class Application extends Application
{

  /**
   *
   */
    public function setIo($io)
    {
        $this->io = $io;
    }
}
