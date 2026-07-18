<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
Rbac::require('nodejs.view');

jsonResponse(['ok' => true, 'data' => NodeService::combinedStatus()]);
