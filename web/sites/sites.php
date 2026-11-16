<?php

declare(strict_types=1);

// Enable the isolated test site only in the dedicated local test processes.
// Ordinary web and Drush processes continue to use their existing site discovery.
if (getenv('LABDOO_E2E_SITE') === '1') {
  $sites['18411.127.0.0.1'] = 'labdoo_e2e';
  $sites['18411.localhost'] = 'labdoo_e2e';
}
