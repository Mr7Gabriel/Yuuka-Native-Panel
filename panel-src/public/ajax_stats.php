<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();

$summary = SystemService::summary();
jsonResponse(array_merge(['ok' => true], $summary));
