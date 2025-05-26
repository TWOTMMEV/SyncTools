<?php
// ----------------------------------------------------------------
// config.php 1.0
// Configuration settings for the source site of the sync tool
//
// usage:
// Included by api.php to define constants for source operations
// ----------------------------------------------------------------

// ----------------------------------------------------------------
// Constants
// ----------------------------------------------------------------
define('VERBOSE_LOGGING', 2);								// 0: minimal, 1: standard, 2: detailed
define('CONFIG_VERSION', 'v1.0');							// Marker to verify this config file

define('REMOVE_LISTFILE', false);							// Remove the files list

define('SOURCE_ROOT_DIR', '../..');							// Root directory for file access

define('SOURCE_DB_HOST', 'localhost');						// Source database host
define('SOURCE_DB_NAME', 'deluolyl_aiklepios');				// Source database name
define('SOURCE_DB_USER', 'deluolyl_aiklepios');				// Source database user
define('SOURCE_DB_PASS', 'wf3enao-iuh=fwerg-357u');		// Source database password
// ----------------------------------------------------------------
?>