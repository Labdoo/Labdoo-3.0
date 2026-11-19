<?php
/**
 * Shim to define missing PDO constants when pdo_mysql is not loaded.
 * We use a dummy class to mock the PDO constants if they are missing.
 */
if (!class_exists('PDO', false)) {
    class PDO {
        const MYSQL_ATTR_SSL_CA = 1007;
        const MYSQL_ATTR_USE_BUFFERED_QUERY = 1000;
        const MYSQL_ATTR_INIT_COMMAND = 1002;
        const MYSQL_ATTR_SSL_VERIFY_SERVER_CERT = 1014;
        public static function getAvailableDrivers() { return []; }
    }
} else {
    // If PDO exists but constants don't (unlikely if it's the real PDO)
    $reflect = new ReflectionClass('PDO');
    if (!$reflect->hasConstant('MYSQL_ATTR_SSL_CA')) {
        // We can't add constants to an existing class in PHP.
        // This environment has PDO (from pdo.so) but not pdo_mysql.
    }
}
