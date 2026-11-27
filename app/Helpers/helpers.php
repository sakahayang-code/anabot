<?php
declare(strict_types=1);

/**
 * AnaBot Global Helper Functions
 * 
 * Safe helper functions for configuration, escaping, and formatting.
 */

if (!function_exists('app_version')) {
    function app_version(): string
    {
        return '1.0.0-dev';
    }
}
