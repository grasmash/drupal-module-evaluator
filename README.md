<!--
[![Build Status](https://travis-ci.org/grasmash/composerize-drupal.svg?branch=master)](https://travis-ci.org/grasmash/composerize-drupal) [![Coverage Status](https://coveralls.io/repos/github/grasmash/composerize-drupal/badge.svg?branch=master)](https://coveralls.io/github/grasmash/composerize-drupal?branch=master) [![Packagist](https://img.shields.io/packagist/v/grasmash/composerize-drupal.svg)](https://packagist.org/packages/grasmash/composerize-drupal)
-->
# Overview
This script scans Drupal.org to gather information about modules and report status for health evaluation. Follow the instructions below to run the script. The output example will provide the data and scoring example tells you how to interpret it for the module health evaluation.

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
Each module will be scored based on a number of objective criteria relating to:
- Code Quality
- Compliance
- Customer Responsiveness
- Ownership
- Release Cadence
- Security

The scoring itself is tracked in a spreadsheet where each module has a tab to be filled out, and a report tab which is generated automatically. See this document:
https://docs.google.com/spreadsheets/d/1iySyGQGs_G59O3hkBxRCX6fPXlVTnnXAyffCrbTVXqw/edit#gid=1023805792

The data compiled in the example above is meant to contribute data automatically for you to use to evaluate the following elements in the Module Health Score:

1. Code Quality
- Module meets drupal coding standards with zero warnings or errors (PHP cs)
- Module has less than X deprecation issues (PHPStan)

2. Customer Responsiveness
- Community major flagged issues exist
- Community critical flagged issues exist

3. Ownership
- Modules should have public-facing documentation page on docs.acquia.com which links to the d.o corresponding module page.

4. Security
- Module with >1k installs must be marked stable and opt in to security team coverage

More elements may be automated later, if you'd like to contribute to this project by automating other data points we are collecting in the spreadsheet, we encourage forking the project to add them.
