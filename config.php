<?php

$host = new Aerys\Host();
$host->expose("*", 8080);

// connect middleware

$public = Aerys\root(__DIR__ . "/public");
$host->use($public);

$router = new Aerys\Router();
$host->use($router);

// process pre files

$format = true;
$comment = true;

Pre\process(__DIR__ . "/routes.pre", __DIR__ . "/routes.php", $format, $comment);
Pre\process(__DIR__ . "/functions.pre", __DIR__ . "/functions.php", $format, $comment);

require_once __DIR__ . "/functions.php";

// add routes

$builder = require_once __DIR__ . "/routes.php";
$builder($router);
