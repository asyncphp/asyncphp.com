<?php

require __DIR__ . "/vendor/autoload.php";

use League\CommonMark\CommonMarkConverter;

print "Converting markdown..." . PHP_EOL;

$converter = new CommonMarkConverter();

$files = [
    "01-introduction",
    "02-discovering-the-standard-library",
];

$layout = file_get_contents(__DIR__ . "/templates/layouts/sample.html");

foreach ($files as $file) {
    print "Converting {$file}..." . PHP_EOL;
    $markdown = file_get_contents(__DIR__ . "/manuscript/{$file}.md");
    $layout = str_replace("[{$file}]", $converter->convertToHtml($markdown), $layout);
}

file_put_contents(__DIR__ . "/templates/pages/sample.html", $layout);
