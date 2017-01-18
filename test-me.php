<?php

$a = 1;
$workingDirectory = getcwd();

fwrite(STDOUT, 'I am running PHP ' . PHP_VERSION . ' on ' . PHP_OS . '.' . PHP_EOL);
fwrite(STDERR, 'Writing to error output works as well.');

echo 'Current working directory: ' . $workingDirectory . PHP_EOL;

echo 'XDebug and breakpoints work as well!';
