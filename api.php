<?php
require __DIR__ . '/api_config.php';
include '../../logger.php';

uli(1, 'a'); // Append mode for multiple API calls
ule("^Exit after error");

set_time_limit(0);

ul("Config file loaded: " . realpath(__DIR__ . '/config.php'));
ul("Config version: " . (defined('CONFIG_VERSION') ? CONFIG_VERSION : 'undefined'));

function pGetPDO()
{
    static $sourcePdo = null;

    if (null == $sourcePdo) {
        try {
            $sourcePdo = new PDO(
                "mysql:host=" . SOURCE_DB_HOST . ";dbname=" . SOURCE_DB_NAME,
                SOURCE_DB_USER,
                SOURCE_DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            if (VERBOSE_LOGGING >= 2) {
                ul("Source PDO initialized for database: " . SOURCE_DB_NAME . " on host: " . SOURCE_DB_HOST);
            }
        } catch (PDOException $e) {
            ul("Source PDO initialization failed: " . $e->getMessage());
            die(json_encode(['error' => "Source DB connection failed: " . $e->getMessage()]));
        }
    }

    return $sourcePdo;
}

function customFileHash($filePath) {
    if (!file_exists($filePath)) {
        ul("customFileHash: File not found: $filePath");
        return '';
    }
    $content = file_get_contents($filePath, false, null, 0, 1024 * 1024);
    if ($content === false) {
        ul("customFileHash: Failed to read file: $filePath");
        return '';
    }
    $filtered = '';
    for ($i = 0, $len = strlen($content); $i < $len; $i++) {
        $ascii = ord($content[$i]);
        if ($ascii >= 32) {
            $filtered .= $content[$i];
        }
    }
    $hash = md5($filtered);
    if (VERBOSE_LOGGING >= 2) {
        ul("Computed custom hash for $filePath: Hash=$hash, Filtered Length=" . strlen($filtered) . " bytes");
    }
    return $hash;
}

if (isset($argv) && is_array($argv)) {
    // Log argv for debugging
    ul("CLI arguments: " . implode(', ', $argv));
    // Check if list_files_background is in argv
    if (in_array('list_files_background', $argv)) {
        $action = 'list_files_background';
        $data = [];
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            ul("Invalid JSON input: " . json_last_error_msg());
            die(json_encode(['error' => "Invalid JSON input: " . json_last_error_msg()]));
        }
        $action = $input['action'] ?? '';
        $data = $input['data'] ?? [];
    }
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        ul("Invalid JSON input: " . json_last_error_msg());
        die(json_encode(['error' => "Invalid JSON input: " . json_last_error_msg()]));
    }
    $action = $input['action'] ?? '';
    $data = $input['data'] ?? [];
}

if (VERBOSE_LOGGING >= 1) {
    ul("Received API request: Action=$action, Data=" . json_encode($data));
}

$response = ['data' => []];

switch ($action) {
    case 'tables':
        $tables = defined('SYNC_TABLES') ? SYNC_TABLES : ['table1'];
        if (empty($tables)) {
            $stmt = pGetPDO()->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        $response['data'] = $tables;
        if (VERBOSE_LOGGING >= 2) {
            ul("Returning tables: " . implode(', ', $tables));
        }
        break;

    case 'table_structure':
        $table = $data['table'] ?? '';
        if (!$table) {
            ul("Missing table parameter for table_structure");
            die(json_encode(['error' => "Missing table parameter"]));
        }
        $stmt = pGetPDO()->query("SHOW CREATE TABLE `$table`");
        $createTable = $stmt->fetch(PDO::FETCH_ASSOC)['Create Table'] ?? '';
        if (!$createTable) {
            ul("Table $table not found");
            die(json_encode(['error' => "Table $table not found"]));
        }
        $stmt = pGetPDO()->query("SHOW COLUMNS FROM `$table`");
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $columns[$col['Field']] = [
                'Type' => $col['Type'],
                'Null' => $col['Null'],
                'Key' => $col['Key'],
                'Default' => $col['Default'],
                'Extra' => $col['Extra']
            ];
        }
        $response['data'] = [
            'create_table' => $createTable,
            'columns' => $columns
        ];
        if (VERBOSE_LOGGING >= 2) {
            ul("Returning structure for table $table: Columns=" . json_encode(array_keys($columns)));
        }
        break;

    case 'records':
        $table = $data['table'] ?? '';
        if (!$table) {
            ul("Missing table parameter for records");
            die(json_encode(['error' => "Missing table parameter"]));
        }
        $stmt = pGetPDO()->query("SELECT * FROM `$table`");
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response['data'] = $records;
        if (VERBOSE_LOGGING >= 2) {
            ul("Returning " . count($records) . " records for table $table");
        }
        break;

    case 'auto_increment':
        $table = $data['table'] ?? '';
        if (!$table) {
            ul("Missing table parameter for auto_increment");
            die(json_encode(['error' => "Missing table parameter"]));
        }
        $stmt = pGetPDO()->query("SHOW TABLE STATUS LIKE '$table'");
        $status = $stmt->fetch(PDO::FETCH_ASSOC);
        $autoIncrement = $status['Auto_increment'] ?? 1;
        $response['data'] = $autoIncrement;
        if (VERBOSE_LOGGING >= 2) {
            ul("Returning AUTO_INCREMENT for table $table: $autoIncrement");
        }
        break;

    case 'list_files':
        $directories = $data['directories'] ?? null;
        $excludeDirs = $data['exclude_dirs'] ?? [];
        if ($directories === null) {
            ul("Error: No directories provided for list_files");
            die(json_encode(['error' => "No directories provided for list_files"]));
        }
        if (VERBOSE_LOGGING >= 2) {
            ul("Directories to sync: " . json_encode($directories));
            ul("Excluded directories: " . json_encode($excludeDirs));
        }
        $resolvedSourceRootDir = realpath(SOURCE_ROOT_DIR);
        if ($resolvedSourceRootDir === false) {
            ul("Invalid SOURCE_ROOT_DIR: " . SOURCE_ROOT_DIR);
            die(json_encode(['error' => "Invalid SOURCE_ROOT_DIR"]));
        }
        $excludeDirs = array_map(function($dir) use ($resolvedSourceRootDir) {
            $path = realpath($resolvedSourceRootDir . '/' . ltrim($dir, '/'));
            if ($path === false) {
                ul("Warning: Excluded directory does not exist: $dir");
                return null;
            }
            return $path;
        }, $excludeDirs);
        $excludeDirs = array_filter($excludeDirs);
        $files = [];
        $directories = empty($directories) ? [''] : $directories;
        foreach ($directories as $dir) {
            $path = $resolvedSourceRootDir . '/' . ltrim($dir, '/');
            if (!is_dir($path)) {
                ul("Directory not found: $path");
                continue;
            }
            if (VERBOSE_LOGGING >= 2) {
                ul("Recursively scanning source directory: $path");
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $file) {
                if ($file->isDir()) continue;
                $filePath = realpath($file->getPathname());
                $normalizedFilePath = str_replace('\\', '/', $filePath);
                $relativePath = ltrim(substr($normalizedFilePath, strlen($resolvedSourceRootDir) + 1), '/');
                $fileDir = realpath(dirname($filePath));
                $normalizedFileDir = str_replace('\\', '/', $fileDir);
                $isExcluded = false;
                foreach ($excludeDirs as $excludeDir) {
                    if ($excludeDir && strpos($normalizedFileDir . '/', $excludeDir . '/') === 0) {
                        $isExcluded = true;
                        if (VERBOSE_LOGGING >= 2) {
                            ul("File $relativePath excluded: Directory $normalizedFileDir starts with excluded $excludeDir");
                        }
                        break;
                    }
                }
                if ($isExcluded) continue;
                $hash = customFileHash($filePath);
                $files[] = [
                    'path' => $relativePath,
                    'hash' => $hash
                ];
                if (VERBOSE_LOGGING >= 2) {
                    ul("Listed file $relativePath: Hash=$hash");
                }
            }
        }
        $response['data'] = $files;
        if (VERBOSE_LOGGING >= 2) {
            ul("Returning " . count($files) . " files from source (including subdirectories)");
        }
        break;

    case 'get_file':
        $relativePath = $data['path'] ?? '';
        if (!$relativePath || strpos($relativePath, '..') !== false || strpos($relativePath, '/') === 0) {
            ul("Invalid file path: $relativePath");
            die(json_encode(['error' => "Invalid file path"]));
        }
        $filePath = realpath(SOURCE_ROOT_DIR . '/' . $relativePath);
        if ($filePath === false || !file_exists($filePath)) {
            ul("File not found: $relativePath");
            die(json_encode(['error' => "File not found: $relativePath"]));
        }
        $content = file_get_contents($filePath);
        if ($content === false) {
            ul("Failed to read file: $relativePath");
            die(json_encode(['error' => "Failed to read file: $relativePath"]));
        }
        $response['data'] = [
            'content' => base64_encode($content),
            'hash' => customFileHash($filePath)
        ];
        if (VERBOSE_LOGGING >= 2) {
            ul("Returning file $relativePath: Size=" . strlen($content) . " bytes");
        }
        break;

    case 'start_file_listing':
		$listingFile = 'listing.txt';
		$listFile = 'list.txt';

		if (REMOVE_LISTFILE || !file_exists($listFile)) {
			// Create listing.txt to signal operation start
			clearstatcache();
			if (false === file_put_contents($listingFile, "Listing in progress\n")) {
				$error = error_get_last();
				ul("Failed to create $listingFile: " . ($error['message'] ?? 'Unknown error'));
				die(json_encode(['error' => "Failed to create $listingFile"]));
			}
			if (VERBOSE_LOGGING >= 2) {
				ul("Created $listingFile");
			}
			clearstatcache();
			if (file_exists($listFile)) {
				if (!is_writable($listFile)) {
					ul("Existing $listFile is not writable");
				}
				if (!unlink($listFile)) {
					$error = error_get_last();
					ul("Failed to delete existing $listFile: " . ($error['message'] ?? 'Unknown error'));
					die(json_encode(['error' => "Failed to delete existing $listFile"]));
				}
				if (VERBOSE_LOGGING >= 2) {
					ul("Deleted existing $listFile");
				}
			}

			// Create list.txt
			if (false === file_put_contents($listFile, '')) {
				$error = error_get_last();
				ul("Failed to create $listFile: " . ($error['message'] ?? 'Unknown error'));
				die(json_encode(['error' => "Failed to create $listFile"]));
			}
			if (VERBOSE_LOGGING >= 2) {
				ul("Created $listFile");
			}

			// Launch background instance of api.php
			$phpBinary = PHP_BINARY ?: 'php';
			$apiPath = __FILE__;
			$command = "$phpBinary " . escapeshellarg($apiPath) . " list_files_background > /dev/null 2>&1 &";
			exec($command, $output, $returnVar);
			if ($returnVar !== 0) {
				ul("Failed to launch background listing process: Return code $returnVar, Command: $command");
				die(json_encode(['error' => 'Failed to launch background listing process']));
			}
			if (VERBOSE_LOGGING >= 1) {
				ul("Launched background listing process: $command");
			}
		} else {
			if (VERBOSE_LOGGING >= 2) {
				ul("Keep existing $listFile");
			}
			clearstatcache();
			if (file_exists($listingFile)) {
				if (!unlink($listingFile)) {
					ul("Failed to remove $listingFile");
				} else {
					if (VERBOSE_LOGGING >= 1) {
						ul("Removed $listingFile");
					}
				}
			}		
		}
		
        echo json_encode(['data' => 'Listing started']);
        exit;

    case 'check_listing_status':
        $listingFile = 'listing.txt';
        clearstatcache();
        $exists = file_exists($listingFile);
        if (VERBOSE_LOGGING >= 2) {
            ul("Checked listing status: $listingFile " . ($exists ? "exists" : "does not exist"));
        }
        echo json_encode(['data' => ['listing_exists' => $exists]]);
        exit;

    case 'list_files_background':
        $sourceRoot = realpath(SOURCE_ROOT_DIR);
        $syncDir = $sourceRoot . '/sync';
        $listFile = 'list.txt';
        $listingFile = 'listing.txt';

        if (VERBOSE_LOGGING >= 1) {
            ul("Bckgrnd_Starting background file listing for $sourceRoot");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $filePath = $file->getRealPath();
            $relativePath = str_replace($sourceRoot . '/', '', $filePath);

            // Skip files in root and sync directory
            if (dirname($relativePath) === '.' || strpos($relativePath, 'sync/') === 0) {
                if (VERBOSE_LOGGING >= 2) {
                    ul("Bckgrnd_Skipped file: $relativePath (in root or sync directory)");
                }
                continue;
            }

            // Check if api.php exists before adding file to list.txt
            clearstatcache();
            if (!file_exists(__FILE__)) {
                ul("Bckgrnd_Aborted due to missing api.php");
                exit;
            }

            $size = $file->getSize();
            $hash = customFileHash($filePath);
            $line = "Name: $relativePath, Length: $size, Hash: $hash\n";
            if (false === file_put_contents($listFile, $line, FILE_APPEND)) {
                ul("Bckgrnd_Failed to write to $listFile: $line");
            }
            if (VERBOSE_LOGGING >= 2) {
                ul("Bckgrnd_Listed file: $relativePath, Length: $size, Hash: $hash");
            }
        }

        // Remove listing.txt to signal completion
        clearstatcache();
        if (file_exists($listingFile)) {
            if (!unlink($listingFile)) {
                ul("Bckgrnd_Failed to remove $listingFile");
            } else {
                if (VERBOSE_LOGGING >= 1) {
                    ul("Bckgrnd_Removed $listingFile");
                }
            }
        }

        if (VERBOSE_LOGGING >= 1) {
            ul("Bckgrnd_Background file listing completed");
        }
        exit;

    case 'get_file_list_batch':
        $listFile = 'list.txt';
        $batchSize = (int) ($data['batch_size'] ?? 0);
        $readPosition = (int) ($data['read_position'] ?? 0);

        if ($batchSize <= 0) {
            ul("Invalid batch_size for get_file_list_batch: $batchSize");
            die(json_encode(['error' => "Invalid batch_size: $batchSize"]));
        }
        if ($readPosition < 0) {
            ul("Invalid read_position for get_file_list_batch: $readPosition");
            die(json_encode(['error' => "Invalid read_position: $readPosition"]));
        }

        clearstatcache();
        if (!file_exists($listFile)) {
            ul("list.txt not found for get_file_list_batch");
            die(json_encode(['error' => "list.txt not found"]));
        }
        if (!is_readable($listFile)) {
            ul("list.txt is not readable for get_file_list_batch");
            die(json_encode(['error' => "list.txt is not readable"]));
        }

        $files = [];
        $handle = fopen($listFile, 'r');
        if ($handle === false) {
            ul("Failed to open list.txt for reading");
            die(json_encode(['error' => "Failed to open list.txt"]));
        }

        if ($readPosition > 0) {
            if (fseek($handle, $readPosition) === -1) {
                ul("Failed to seek to read_position $readPosition in list.txt");
                fclose($handle);
                die(json_encode(['error' => "Failed to seek to read_position $readPosition"]));
            }
        }

        while (count($files) < $batchSize && ($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            // Parse line: Name: path, Length: size, Hash: hash
            $matches = [];
            if (preg_match('/Name: (.+?), Length: (\d+), Hash: ([a-f0-9]+)/', $line, $matches)) {
                $files[] = [
                    'path' => $matches[1],
                    'length' => (int) $matches[2],
                    'hash' => $matches[3]
                ];
            } else {
                ul("Invalid line format in list.txt: $line");
            }
        }
        $newReadPosition = ftell($handle);
        fclose($handle);

        if (VERBOSE_LOGGING >= 2) {
            ul("Returning batch of " . count($files) . " files from list.txt, read_position=$readPosition, batch_size=$batchSize, new_read_position=$newReadPosition");
        }
        $response['data'] = [
            'files' => $files,
            'read_position' => $newReadPosition
        ];
        break;

    default:
        ul("Unknown action: $action");
        die(json_encode(['error' => "Unknown action: $action"]));
}

header('Content-Type: application/json');
echo json_encode($response);
?>