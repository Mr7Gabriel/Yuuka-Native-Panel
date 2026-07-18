<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

redirect(Auth::check() ? '/dashboard.php' : '/login.php');
