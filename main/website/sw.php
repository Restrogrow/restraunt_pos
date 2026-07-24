<?php
header('Service-Worker-Allowed: /');
header('Content-Type: application/javascript');
readfile(__DIR__ . '/sw.js');
