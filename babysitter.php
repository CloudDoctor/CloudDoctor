#!/usr/bin/php
<?php

require_once(__DIR__ . "/bootstrap.php");

use CloudDoctor\CloudDoctor;

$dr = new CloudDoctor();
$dr->assertFromFile(
    "cloud-definition.yml",
    "cloud-definition.override.yml"
);
$dr->deploy();
