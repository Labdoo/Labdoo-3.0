<?php

/**
 * @file
 * Check MySQL integer display widths do not cause false schema mismatches.
 *
 * Run with: php -d xdebug.mode=off tests/php/labdoo-common-schema-regression.php.
 */

require dirname(__DIR__, 2) . '/web/modules/custom/labdoo_common/labdoo_common.install';

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$check(
  labdoo_common_normalize_mysql_column_type('INT(10) UNSIGNED') === 'int unsigned',
  'INT display width must be ignored.',
);
$check(
  labdoo_common_normalize_mysql_column_type('bigint(20) unsigned') === 'bigint unsigned',
  'BIGINT display width must be ignored.',
);
$check(
  labdoo_common_normalize_mysql_column_type('tinyint(4)') === 'tinyint',
  'TINYINT display width must be ignored.',
);
$check(
  labdoo_common_normalize_mysql_column_type('varchar(32)') === 'varchar(32)',
  'String lengths must remain significant.',
);

echo "Labdoo common schema regression: 4 assertions passed.\n";
