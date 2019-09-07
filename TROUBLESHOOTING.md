Known issues:
* When a -v flag is added to the command, the output of subprocesses (phpcs, drupal-check) is printed to screen and NOT captured by the getOutput() later, so all code statistics are NULL.
