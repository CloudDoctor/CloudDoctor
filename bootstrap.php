<?php
require_once(__DIR__ . "/vendor/autoload.php");

$environment = array_merge($_SERVER, $_ENV);
ksort($environment);

