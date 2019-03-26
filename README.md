<!--
[![Build Status](https://travis-ci.org/grasmash/composerize-drupal.svg?branch=master)](https://travis-ci.org/grasmash/composerize-drupal) [![Coverage Status](https://coveralls.io/repos/github/grasmash/composerize-drupal/badge.svg?branch=master)](https://coveralls.io/github/grasmash/composerize-drupal?branch=master) [![Packagist](https://img.shields.io/packagist/v/grasmash/composerize-drupal.svg)](https://packagist.org/packages/grasmash/composerize-drupal)
-->

# Module Evaluator

Install:
```
git clone git@github.com:grasmash/drupal-module-evaluator.git
cd drupal-module-evaluator
composer install
```

Example usage:
```
$ ./bin/evaluate acquia_connector --dev-version=8.x-1.x-dev

Acquia Connector (acquia_connector)
Downloads:  991665
SA Coverage:  covered
Starred:  10
Usage:  9337
Issue statistics for 8.x-1.x-dev
  total issues:  68
  By priority:
    # critical:  5 (7.35%)
    # major:     13 (19.12%)
    # normal:    48 (70.59%)
    # minor:     2 (2.94%)
  By category:
    # bug:       45 (66.18%)
    # feature:   6 (8.82%)
    # support:   4 (5.88%)
    # task:      13 (19.12%)
    # plan:      0 (0.00%)
  Last "Closed (fixed)":  Thu, 28 Feb 2019 15:28:27 +0000
# releases:      19
last release:    Wed, 06 Feb 2019 18:09:19 +0000
Code Analysis for 8.x-1.16:
  Deprecation errors: 0
  Deprecation file errors: 36
  Coding standards errors: 640
  Coding standards warnings: 81
```

## Scoring


