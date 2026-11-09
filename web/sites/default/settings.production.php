<?php

/**
 * @file
 * Production performance and private file settings for Labdoo V3.
 *
 * Include this file at the end of the production settings.php, after local
 * overrides. The private directory must exist outside the web root and be
 * writable by the web server before enabling it.
 */

$settings['state_cache'] = TRUE;
$settings['file_private_path'] = getenv('LABDOO_PRIVATE_FILES_PATH') ?: '/mnt/data/labdoo-private';
