<?php

declare(strict_types=1);

// auto_prepend_file fuer den Test-Server (php -S): zeigt ESSE_PRIVATE_PATH auf das
// von tests/integration/bootstrap.php geschriebene Test-config.php, ohne die echte
// lokale Installation (config/, local.php) anzufassen.

require_once __DIR__ . '/bootstrap.php';

defined('ESSE_PRIVATE_PATH') || define('ESSE_PRIVATE_PATH', TEST_CONFIG_DIR);
