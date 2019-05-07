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
$ ./bin/evaluate evaluate acquia_connector --dev-version=8.x-1.x-dev

  -------------------------------- ---------------------------------
  Name                             acquia_connector
  Title                            Acquia Connector
  Branch                           8.x-1.x-dev
  Downloads                        1054770
  Security Advisory Coverage       covered
  Starred                          10
  Usage                            9371
  Recommended version              8.x-1.16
  Is stable                        1
  Total issues                     33
  Priority Critical Issues         2
  Priority Major Issues            3
  Priority Normal Issues           27
  Priority Minor Issues            1
  Priority Bug Issues              19
  Category Feature Issues          4
  Category Support Issues          3
  Category Task Issues             7
  Category Plan Issues             0
  Status RTBC Issues               3
  Last "Closed/fixed" issue date   Thu, 28 Feb 2019 15:28:27 +0000
  Total releases                   19
  Last release date                Wed, 06 Feb 2019 18:09:19 +0000
  Days since last release          88
  Deprecation errors               0
  Deprecation file errors          36
  PHPCS errors                     639
  PHPCS warnings                   81
  Composer validation status       warnings
  Scored points                    15
  Total points                     45
 -------------------------------- ---------------------------------
```

```
./bin/evaluate evaluate-multiple ./acquia.yml --format=csv > acquia-modules.csv
```

## Scoring
