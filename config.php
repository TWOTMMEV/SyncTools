<?php
// ----------------------------------------------------------------
// config.php 1.0
// Configuration settings for the dest site of the sync tool
//
// usage:
// Included by sync.php to define constants for source operations
// ----------------------------------------------------------------

// ----------------------------------------------------------------
// Constants
// ----------------------------------------------------------------

define('CONFIG_VERSION', 'v1.0');										// Configuration version

define('VERIFY_MODE', true);											// Verify mode (true = preview only, false = execute)

define('VERBOSE_LOGGING', 2);											// Verbosity level: 0=minimal, 1=API details, 2=full debug
define('VERBOSE_GUI', 1);												// Verbosity GUI level: 0=minimal, 1=API details, 2=full debug

	// Files batch sync

define('FILES_BATCH_SYNC', true);										// File batch sync switch
define('MAX_BATCH_SIZE', 2000);											// Maximum batch size for records and files

	// Files sync

define('FILES_SYNC', false);											// File sync switch

define('DEST_ROOT_DIR', '../..');										// Root directory for file sync (destination root)

/* NO large directories: timeout issues
define('SYNC_DIRECTORIES', [											// Directories to sync (updated to valid paths or empty for all)
	'AI',
	'Composer',
	'Msgs',
	'out',
	'vendor',
	'ComLog']
	);
*/
define('SYNC_DIRECTORIES', [											// Directories to sync (updated to valid paths or empty for all)
	'test']
	);

define('EXCLUDE_DIRECTORIES', [											// Directories to exclude from sync (relative to DEST_ROOT_DIR)
	'sync']
	);

define('DROP_DEST_FILES_NOT_IN_SOURCE', true);							// Drop files on destination not present in source


	// DB sync

define('DB_SYNC', false);												// DB sync switch

define('SYNC_TABLES', [													// Tables to sync
	'table1']
	);
/*
define('SYNC_TABLES', [													// Tables to sync
	'Users',
	'Tickets',
	'StatsLog',
	'EmailsPP',
	'System']
	);
*/

define('DROP_DEST_COLUMNS_NOT_IN_SOURCE', true);						// Drop columns on destination not present in source (true = drop, false = preserve)



define('SOURCE_SITE', 'source');										// The site being copied (read-only)
define('TARGET_SITE', 'dest');											// The site being aligned to source
define('TEXT_REPLACEMENTS', [											// Text replacement rules for action log
    'table1' => 'table1',
    'Number' => '#'
]);


define('SOURCE_API_URL', 'https://aiklepios.com/sync/source/api.php');	// Source API endpoint (use HTTPS to avoid 301 redirects)

	// Destination database credentials

define('DEST_DB_HOST', 'localhost');
define('DEST_DB_NAME', 'db1o3h4mpxgjae');
define('DEST_DB_USER', 'aiklepios');
define('DEST_DB_PASS', '*');
// ----------------------------------------------------------------
?>