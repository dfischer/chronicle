<?php
use ParagonIE\Chronicle\Chronicle;
use ParagonIE\ConstantTime\Binary;

if (!defined('CHRONICLE_APP_ROOT')) {
    define('CHRONICLE_APP_ROOT', __DIR__);
}
require_once CHRONICLE_APP_ROOT . '/vendor/autoload.php';

if (!\class_exists(Chronicle::class)) {
    \spl_autoload_register(function ($class) {
        // Project-specific namespace prefix
        $prefix = 'ParagonIE\\Chronicle';
        // Base directory for the namespace prefix
        $base_dir = __DIR__ . DIRECTORY_SEPARATOR . 'src/Chronicle';
        // Does the class use the namespace prefix?
        $len = \strlen($prefix);
        if (\strncmp($prefix, $class, $len) !== 0) {
            // no, move to the next registered autoloader
            return;
        }
        // Get the relative class name
        $relative_class = \substr($class, $len);
        // Replace the namespace prefix with the base directory, replace namespace
        // separators with directory separators in the relative class name, append
        // with .php
        $file = $base_dir.
            \str_replace(
                ['\\', '_'],
                DIRECTORY_SEPARATOR,
                $relative_class
            ).'.php';
        // If the file exists, require it
        if (\file_exists($file)) {
            require $file;
        }
    }, false, true);
}

if (!function_exists('prompt')) {
    /**
     * @param $text
     * @return mixed
     */
    function prompt(string $text = ''): string
    {
        static $fp = null;
        if ($fp === null) {
            $fp = \fopen('php://stdin', 'r');
        }
        echo $text, ': ';
        return Binary::safeSubstr(\fgets($fp), 0, -1);
    }
}
