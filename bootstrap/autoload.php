<?php
declare(strict_types=1);

/**
 * AnaBot Standalone Zero-Dependency PSR-4 Autoloader
 * 
 * Provides seamless out-of-the-box autoloading on pure shared hosting
 * without requiring Composer CLI.
 */

// If Composer autoloader exists, utilize it
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
} else {
    // Pure PHP Native PSR-4 Autoloader
    spl_autoload_register(function (string $class): void {
        $prefix = 'AnaBot\\';
        $baseDir = dirname(__DIR__) . '/app/';
        
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        
        if (file_exists($file)) {
            require_once $file;
        }
    });

    // Load global helpers
    $helpersFile = dirname(__DIR__) . '/app/Helpers/helpers.php';
    if (file_exists($helpersFile)) {
        require_once $helpersFile;
    }
}
