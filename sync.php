<?php
// ----------------------------------------------------------------
// sync.php 2.0.6
// Synchronization tool for destination site, syncing database and files from source
// Version: 2.0.6
// Usage: Access via http://localhost/aiklepios/sync/dest/sync.php
// Changes: 
// - Added directory deletion for DROP_DEST_FILES_NOT_IN_SOURCE (2.0.5)
// - Modified to request files one directory at a time to avoid API timeout (2.0.6)
// ----------------------------------------------------------------

require __DIR__ . '/config.php';
include '../../logger.php';
uli(0, 'a'); // Replace mode
ule("^Exit after error"); // Indicate exit on error

$g_bULIgnoreWarning = true;

// Initialize GUI variables early to prevent undefined errors
$errors = [];
$actions = [];
$tables = defined('SYNC_TABLES') ? SYNC_TABLES : ['Users'];
$directories = defined('SYNC_DIRECTORIES') ? SYNC_DIRECTORIES : ['test'];
$summary = array_fill_keys($tables, [
    'source_records' => 0,
    'dest_records' => 0,
    'records_added' => 0,
    'records_modified' => 0,
    'records_deleted' => 0,
    'structure_status' => []
]);
$progress = [
    'total_tables' => count($tables),
    'tables_processed' => 0,
    'total_files' => 0,
    'files_processed' => 0,
    'total_actions' => 0,
    'total_files_processed' => 0,
    'current_phase' => 'Initializing'
];

if (VERBOSE_LOGGING >= 2) {
    ul("Config file loaded: " . realpath(__DIR__ . '/config.php'));
    ul("Config version: " . (defined('CONFIG_VERSION') ? CONFIG_VERSION : 'undefined'));
    ul("EXCLUDE_DIRECTORIES: " . json_encode(defined('EXCLUDE_DIRECTORIES') ? EXCLUDE_DIRECTORIES : ['sync']));
    ul("SYNC_DIRECTORIES: " . json_encode($directories));
}

try {
    $destPdo = new PDO(
        "mysql:host=" . DEST_DB_HOST . ";dbname=" . DEST_DB_NAME,
        DEST_DB_USER,
        DEST_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    if (VERBOSE_LOGGING >= 2) {
        ul("Destination PDO initialized for database: " . DEST_DB_NAME . " on host: " . DEST_DB_HOST);
    }
} catch (PDOException $e) {
    $error = "Destination PDO initialization failed: " . $e->getMessage();
    ul($error);
    $errors[] = $error;
    $progress['current_phase'] = 'Error in database connection';
    include 'render_gui.php';
    die();
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

if (SOURCE_SITE !== 'source' || TARGET_SITE !== 'dest') {
    $error = "Configuration error: SOURCE_SITE must be 'source' and TARGET_SITE must be 'dest'.";
    ul($error);
    $errors[] = $error;
    $progress['current_phase'] = 'Error in configuration';
    include 'render_gui.php';
    die();
}
if (VERBOSE_LOGGING >= 2) {
    ul("Configuration validated: SOURCE_SITE='source', TARGET_SITE='dest'");
}

function sendToSource($action, $data, $retries = 3, $timeout = 1800) {
    global $errors, $progress, $actions;
    $payload = json_encode(['action' => $action, 'data' => $data]);
    if (VERBOSE_LOGGING >= 1) {
        ul("Sending API request to source: Action=$action, Data=" . json_encode($data));
    }
    
    $attempt = 1;
    while ($attempt <= $retries) {
        $ch = curl_init(SOURCE_API_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $headers = [];
        if (VERBOSE_LOGGING >= 2) {
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$headers) {
                $headers[] = $header;
                return strlen($header);
            });
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);
        
        if (VERBOSE_LOGGING >= 2) {
            ul("API request headers: " . implode("", $headers));
            ul("Effective URL after redirects: $effectiveUrl");
        }
        
        if ($curlError) {
            $errorMsg = "cURL error in API request (attempt $attempt/$retries): $curlError (errno: $curlErrno), Action=$action, Data=" . json_encode($data);
            ul($errorMsg);
            $errors[] = $errorMsg;
            if ($attempt < $retries && $curlErrno === 28) { // Retry on timeout
                $attempt++;
                sleep(2 * $attempt); // Exponential backoff
                continue;
            }
            $progress['current_phase'] = 'Error in API request';
            return ['error' => $errorMsg];
        }
        
        if ($httpCode !== 200) {
            $errorMsg = "Source API error (attempt $attempt/$retries): HTTP Code=$httpCode, Response=$response, Action=$action, Data=" . json_encode($data);
            ul($errorMsg);
            $errors[] = $errorMsg;
            if ($attempt < $retries && in_array($httpCode, [502, 503, 504])) { // Retry on server errors
                $attempt++;
                sleep(2 * $attempt);
                continue;
            }
            $progress['current_phase'] = 'Error in API response';
            return ['error' => $errorMsg];
        }
        
        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMsg = "Source API returned invalid JSON (attempt $attempt/$retries): Response=$response, JSON Error=" . json_last_error_msg() . ", Action=$action, Data=" . json_encode($data);
            ul($errorMsg);
            $errors[] = $errorMsg;
            if ($attempt < $retries) {
                $attempt++;
                sleep(2 * $attempt);
                continue;
            }
            $progress['current_phase'] = 'Error in JSON decoding';
            return ['error' => $errorMsg];
        }
        
        if (isset($decodedResponse['error'])) {
            $errorMsg = "Source API returned error (attempt $attempt/$retries): " . $decodedResponse['error'] . ", Action=$action, Data=" . json_encode($data);
            ul($errorMsg);
            $errors[] = $errorMsg;
            $progress['current_phase'] = 'Error in API response';
            return ['error' => $errorMsg];
        }
        
        if (VERBOSE_LOGGING >= 1) {
            ul("Received API response for action $action: Data=" . json_encode($decodedResponse['data'] ?? []));
        }
        return $decodedResponse;
    }
}

function getSourceData($action, $data = []) {
    $response = sendToSource($action, $data);
    if (isset($response['error'])) {
        ul("getSourceData failed for action $action: " . $response['error']);
        return ['error' => $response['error']];
    }
    $data = $response['data'] ?? [];
    if (VERBOSE_LOGGING >= 1) {
        ul("Retrieved source data for action: $action, Data: " . json_encode($data));
    }
    return $data;
}

function applyToTarget($action, $data, $actionDescription) {
    global $actions, $destPdo, $progress, $errors;
    $actions[] = $actionDescription;
    $progress['total_actions']++;
    if (VERBOSE_LOGGING >= 1 || VERBOSE_GUI >= 1) {
        ul("Action planned: $actionDescription" . (VERIFY_MODE ? " [Verify Mode]" : ""));
    }
    
    if (VERIFY_MODE) {
        return true;
    }

    try {
        switch ($action) {
            case 'create_table':
                $table = $data['table'];
                $createSql = $data['create_sql'];
                $destTables = $destPdo->query("SHOW TABLES LIKE '$table'")->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($destTables)) {
                    if (VERBOSE_LOGGING >= 2) {
                        ul("Table $table already exists, skipping creation");
                    }
                    return true;
                }
                $destPdo->exec($createSql);
                if (VERBOSE_LOGGING >= 2) {
                    ul("Created table $table with SQL: $createSql");
                }
                break;
            case 'alter_table':
                $table = $data['table'];
                $alterSql = $data['alter_sql'];
                $destPdo->exec($alterSql);
                if (VERBOSE_LOGGING >= 2) {
                    ul("Altered table $table with SQL: $alterSql");
                }
                break;
            case 'add_record':
                $table = $data['table'];
                $record = $data['record'];
                $columns = array_keys($record);
                $placeholders = array_fill(0, count($columns), '?');
                $sql = "INSERT INTO `$table` (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
                $stmt = $destPdo->prepare($sql);
                $stmt->execute(array_values($record));
                if (VERBOSE_LOGGING >= 2) {
                    $recordDetails = json_encode($record);
                    ul("Added record to destination table $table: Number=" . ($record['Number'] ?? 'N/A') . ", Data=$recordDetails");
                }
                break;
            case 'update_record':
                $table = $data['table'];
                $record = $data['record'];
                $number = $record['Number'];
                unset($record['Number']);
                $updates = array_map(fn($k) => "$k = ?", array_keys($record));
                $sql = "UPDATE `$table` SET " . implode(',', $updates) . " WHERE Number = ?";
                $stmt = $destPdo->prepare($sql);
                $stmt->execute(array_merge(array_values($record), [$number]));
                if (VERBOSE_LOGGING >= 2) {
                    $recordDetails = json_encode($record);
                    ul("Updated record in destination table $table: Number=$number, Data=$recordDetails");
                }
                break;
            case 'delete_record':
                $table = $data['table'];
                $number = $data['number'];
                $stmt = $destPdo->prepare("DELETE FROM `$table` WHERE Number = ?");
                $stmt->execute([$number]);
                if (VERBOSE_LOGGING >= 2) {
                    ul("Deleted record from destination table $table: Number=$number");
                }
                break;
            case 'update_auto_increment':
                $table = $data['table'];
                $autoIncrement = (int) $data['auto_increment'];
                $sql = "ALTER TABLE `$table` AUTO_INCREMENT = $autoIncrement";
                $destPdo->exec($sql);
                if (VERBOSE_LOGGING >= 2) {
                    ul("Updated AUTO_INCREMENT for destination table $table: New Value=$autoIncrement");
                }
                break;
            case 'update_file':
				$relativePath = ltrim($data['path'] ?? '', '/');
				if (!$relativePath || strpos($relativePath, '..') !== false) {
					ul("Error: Invalid relative path for update_file: $relativePath");
					$errors[] = "Invalid relative path for update_file: $relativePath";
					return false;
				}
				$path = DEST_ROOT_DIR . '/' . $relativePath;
				$normalizedPath = str_replace('\\', '/', $path);
				if ($path !== $normalizedPath && VERBOSE_LOGGING >= 2) {
					ul("Destination path normalized: Original=$path, Normalized=$normalizedPath");
				}
				$content = base64_decode($data['content'] ?? '');
				if ($content === false) {
					ul("Error: Failed to decode content for $relativePath");
					$errors[] = "Failed to decode content for $relativePath";
					return false;
				}
				$dir = dirname($normalizedPath);
				if (!is_dir($dir)) {
					if (!mkdir($dir, 0755, true)) {
						ul("Error: Failed to create directory: $dir");
						$errors[] = "Failed to create directory: $dir";
						return false;
					}
					if (VERBOSE_LOGGING >= 2) {
						ul("Created directory: $dir");
					}
				}
				// Check if file exists and attempt to set permissions
				if (file_exists($normalizedPath)) {
					if (!is_writable($normalizedPath)) {
						// Attempt to make the file writable
						if (!chmod($normalizedPath, 0644)) {
							ul("Error: Failed to set write permissions for file: $normalizedPath");
							$errors[] = "Failed to set write permissions for file: $normalizedPath";
							return false;
						}
						if (VERBOSE_LOGGING >= 2) {
							ul("Set write permissions for file: $normalizedPath");
						}
					}
				}
				if (false === file_put_contents($normalizedPath, $content)) {
					ul("Error: Failed to write file: $normalizedPath");
					$errors[] = "Failed to write file: $normalizedPath";
					return false;
				}
				$newHash = customFileHash($normalizedPath);
				if (VERBOSE_LOGGING >= 2) {
					ul("Updated file $normalizedPath: New Hash=$newHash, Size=" . strlen($content) . " bytes");
				}
				break;
        }
        return true;
    } catch (Exception $e) {
        $error = "Unexpected error: " . $e->getMessage() . " in " . $e->getFile() . "@" . $e->getLine();
        ul($error);
        $errors[] = $error;
        return false;
    }
}

function applyTextReplacements($text) {
    $replacements = defined('TEXT_REPLACEMENTS') ? TEXT_REPLACEMENTS : ['source' => 'dest'];
    $replaced = str_replace(array_keys($replacements), array_values($replacements), $text);
    if (VERBOSE_LOGGING >= 2 || VERBOSE_GUI >= 2) {
        ul("Applied text replacements to: '$text' ? '$replaced'");
    }
    return $replaced;
}

if (empty($tables)) {
    $tables = getSourceData('tables');
    if (isset($tables['error'])) {
        $errors[] = "Failed to retrieve table list: " . $tables['error'];
    } else {
        $progress['total_tables'] = count($tables);
        $summary = array_fill_keys($tables, [
            'source_records' => 0,
            'dest_records' => 0,
            'records_added' => 0,
            'records_modified' => 0,
            'records_deleted' => 0,
            'structure_status' => []
        ]);
    }
}
if (VERBOSE_LOGGING >= 1 || VERBOSE_GUI >= 1) {
    ul("Tables to sync: " . (empty($tables) ? "None" : implode(', ', $tables)));
}

$tablesToCreate = [];
$tablesToAlter = [];

if (defined('DB_SYNC') && DB_SYNC) {
    $progress['current_phase'] = 'Processing tables';
    foreach ($tables as $table) {
        if (VERBOSE_LOGGING >= 1 || VERBOSE_GUI >= 1) {
            ul("Processing table: $table");
        }

        $destTables = $destPdo->query("SHOW TABLES LIKE '$table'")->fetchAll(PDO::FETCH_COLUMN);

        $sourceStructure = getSourceData('table_structure', ['table' => $table]);
        if (isset($sourceStructure['error'])) {
            $errors[] = "Failed to get structure for table $table: " . $sourceStructure['error'];
            $progress['tables_processed']++;
            continue;
        }
        $createSql = $sourceStructure['create_table'];
        $sourceColumns = $sourceStructure['columns'];

        if (empty($destTables)) {
            applyToTarget(
                'create_table',
                ['table' => $table, 'create_sql' => $createSql],
                "Create table $table"
            );
            $summary[$table]['structure_status'][] = "Table does not exist (will be created)";
            if (VERIFY_MODE) {
                $tablesToCreate[] = $table;
                if (VERBOSE_LOGGING >= 2) {
                    ul("Skipping record sync for table $table in verify mode (table does not exist)");
                }
                $sourceRecords = getSourceData('records', ['table' => $table]);
                if (isset($sourceRecords['error'])) {
                    $errors[] = "Failed to get records for table $table: " . $sourceRecords['error'];
                    $progress['tables_processed']++;
                    continue;
                }
                $summary[$table]['source_records'] = count($sourceRecords);
                $summary[$table]['records_added'] = count($sourceRecords);
                if (VERBOSE_LOGGING >= 1 || VERBOSE_GUI >= 1) {
                    ul("Summary for table $table: Source Records={$summary[$table]['source_records']}, Dest Records=0, Records Added={$summary[$table]['records_added']}, Structure: Table does not exist");
                }
                $progress['tables_processed']++;
                continue;
            }
        }

        // Compare and align table structure
        $destStmt = $destPdo->query("SHOW COLUMNS FROM `$table`");
        $destColumns = $destStmt->fetchAll(PDO::FETCH_ASSOC);
        $destColumnMap = [];
        foreach ($destColumns as $col) {
            $destColumnMap[$col['Field']] = [
                'Type' => $col['Type'],
                'Null' => $col['Null'],
                'Key' => $col['Key'],
                'Default' => $col['Default'],
                'Extra' => $col['Extra']
            ];
        }

        $alterStatements = [];
        $columnsToAdd = [];
        $columnsToModify = [];
        $columnsToDrop = [];
        foreach ($sourceColumns as $colName => $colDef) {
            if (!isset($destColumnMap[$colName])) {
                $alterStatements[] = "ADD COLUMN `$colName` {$colDef['Type']}" . 
                    ($colDef['Null'] === 'NO' ? ' NOT NULL' : '') . 
                    ($colDef['Default'] !== null ? " DEFAULT '{$colDef['Default']}'" : '') . 
                    ($colDef['Extra'] ? " {$colDef['Extra']}" : '');
                if (VERBOSE_LOGGING >= 2) {
                    ul("Planned addition of column $colName to table $table: {$colDef['Type']}");
                }
                $columnsToAdd[] = "$colName ({$colDef['Type']})";
            } elseif (
                $destColumnMap[$colName]['Type'] !== $colDef['Type'] ||
                $destColumnMap[$colName]['Null'] !== $colDef['Null'] ||
                $destColumnMap[$colName]['Default'] !== $colDef['Default'] ||
                $destColumnMap[$colName]['Extra'] !== $colDef['Extra']
            ) {
                $alterStatements[] = "MODIFY COLUMN `$colName` {$colDef['Type']}" . 
                    ($colDef['Null'] === 'NO' ? ' NOT NULL' : '') . 
                    ($colDef['Default'] !== null ? " DEFAULT '{$colDef['Default']}'" : '') . 
                    ($colDef['Extra'] ? " {$colDef['Extra']}" : '');
                if (VERBOSE_LOGGING >= 2) {
                    ul("Planned modification of column $colName in table $table: {$colDef['Type']}");
                }
                $columnsToModify[] = "$colName (to {$colDef['Type']}" . 
                    ($colDef['Null'] === 'NO' ? ', NOT NULL' : '') . 
                    ($colDef['Default'] !== null ? ", DEFAULT '{$colDef['Default']}'" : '') . 
                    ($colDef['Extra'] ? ", {$colDef['Extra']}" : '') . ")";
            }
        }

        foreach ($destColumnMap as $colName => $colDef) {
            if (!isset($sourceColumns[$colName])) {
                if (DROP_DEST_COLUMNS_NOT_IN_SOURCE) {
                    $alterStatements[] = "DROP COLUMN `$colName`";
                    if (VERBOSE_LOGGING >= 2) {
                        ul("Planned removal of column $colName from table $table (not in source)");
                    }
                    $columnsToDrop[] = $colName;
                } else {
                    if (VERBOSE_LOGGING >= 2) {
                        ul("Preserved column $colName in table $table (not in source, DROP_DEST_COLUMNS_NOT_IN_SOURCE=false)");
                    }
                    $summary[$table]['structure_status'][] = "Preserved column: $colName";
                }
            }
        }

        if (!empty($columnsToAdd)) {
            $summary[$table]['structure_status'][] = "Columns to add: " . implode(', ', $columnsToAdd);
        }
        if (!empty($columnsToModify)) {
            $summary[$table]['structure_status'][] = "Columns to modify: " . implode(', ', $columnsToModify);
        }
        if (!empty($columnsToDrop)) {
            $summary[$table]['structure_status'][] = "Columns to drop: " . implode(', ', $columnsToDrop);
        }

        if (!empty($alterStatements)) {
            $alterSql = "ALTER TABLE `$table` " . implode(', ', $alterStatements);
            $actionText = "Alter table $table structure";
            if (!empty($columnsToDrop)) {
                $actionText = "Drop column " . implode(', ', $columnsToDrop) . " from table $table";
            }
            if (!applyToTarget('alter_table', ['table' => $table, 'alter_sql' => $alterSql], $actionText)) {
                $progress['tables_processed']++;
                continue;
            }
            $tablesToAlter[] = $table;
        } else {
            $summary[$table]['structure_status'][] = "Structure aligned";
        }

        // Batch process records
        $sourceRecords = getSourceData('records', ['table' => $table]);
        if (isset($sourceRecords['error'])) {
            $errors[] = "Failed to get records for table $table: " . $sourceRecords['error'];
            $progress['tables_processed']++;
            continue;
        }
        $summary[$table]['source_records'] = count($sourceRecords);
        if (VERBOSE_LOGGING >= 2) {
            ul("Retrieved {$summary[$table]['source_records']} records from source table: $table");
        }

        $selectColumns = array_keys($sourceColumns);
        $selectColumns = array_map(fn($col) => "`$col`", $selectColumns);
        $selectQuery = !empty($alterStatements) && DROP_DEST_COLUMNS_NOT_IN_SOURCE
            ? "SELECT " . implode(', ', $selectColumns) . " FROM `$table`"
            : "SELECT * FROM `$table`";
        $destRecords = $destPdo->query($selectQuery)->fetchAll(PDO::FETCH_ASSOC);
        $summary[$table]['dest_records'] = count($destRecords);
        if (VERBOSE_LOGGING >= 2) {
            ul("Retrieved {$summary[$table]['dest_records']} records from destination table: $table");
        }

        // Process source records in batches
        $batchSize = defined('MAX_BATCH_SIZE') ? MAX_BATCH_SIZE : 100;
        $sourceBatches = array_chunk($sourceRecords, $batchSize);
        foreach ($sourceBatches as $batchIndex => $sourceBatch) {
            if (VERBOSE_LOGGING >= 2) {
                ul("Processing batch " . ($batchIndex + 1) . " of " . count($sourceBatches) . " for table $table");
            }
            foreach ($sourceBatch as $sourceRecord) {
                $number = $sourceRecord['Number'] ?? null;
                if (!$number) {
                    ul("Skipping source record in table $table with missing Number");
                    $errors[] = "Skipped source record in table $table with missing Number";
                    continue;
                }

                $dest_use = array_filter($destRecords, fn($r) => $r['Number'] == $number);
                $destRecord = reset($dest_use);

                if (!$destRecord) {
                    $recordDetails = json_encode($sourceRecord);
                    if (!applyToTarget(
                        'add_record',
                        ['table' => $table, 'record' => $sourceRecord],
                        "Add record Number $number to table $table"
                    )) {
                        continue;
                    }
                    $summary[$table]['records_added']++;
                    if (VERBOSE_LOGGING >= 2 && !VERIFY_MODE) {
                        ul("Planned add of record Number $number to destination table $table: $recordDetails");
                    }
                } else {
                    $filteredDestRecord = array_intersect_key($destRecord, $sourceRecord);
                    if ($filteredDestRecord != $sourceRecord) {
                        $recordDetails = json_encode($sourceRecord);
                        $diff = array_diff_assoc($sourceRecord, $filteredDestRecord);
                        if (!applyToTarget(
                            'update_record',
                            ['table' => $table, 'record' => $sourceRecord],
                            "Update record Number $number in table $table"
                        )) {
                            continue;
                        }
                        $summary[$table]['records_modified']++;
                        if (VERBOSE_LOGGING >= 2 && !VERIFY_MODE) {
                            ul("Planned update of record Number $number in destination table $table: $recordDetails, Changes: " . json_encode($diff));
                        }
                    } else {
                        if (VERBOSE_LOGGING >= 2) {
                            $recordDetails = json_encode($sourceRecord);
                            ul("Skipped update of record Number $number in destination table $table: Records match, Data: $recordDetails");
                        }
                        if (VERBOSE_GUI >= 2) {
                            $actions[] = "Skip update of record # $number in table $table: Records match";
                        }
                    }
                }
            }
        }

        // Process destination records for deletion
        foreach ($destRecords as $destRecord) {
            $number = $destRecord['Number'] ?? null;
            if (!$number) {
                ul("Skipping destination record in table $table with missing Number");
                $errors[] = "Skipped destination record in table $table with missing Number";
                continue;
            }

            $sourceRecord = array_filter($sourceRecords, fn($r) => $r['Number'] == $number);
            if (empty($sourceRecord)) {
                $recordDetails = json_encode($destRecord);
                if (!applyToTarget(
                    'delete_record',
                    ['table' => $table, 'number' => $number],
                    "Delete record Number $number from table $table"
                )) {
                    continue;
                }
                $summary[$table]['records_deleted']++;
                if (VERBOSE_LOGGING >= 2 && !VERIFY_MODE) {
                    ul("Planned deletion of record Number $number from destination table $table: $recordDetails");
                }
            }
        }

        $sourceAutoInc = getSourceData('auto_increment', ['table' => $table]);
        if (isset($sourceAutoInc['error'])) {
            $errors[] = "Failed to get AUTO_INCREMENT for table $table: " . $sourceAutoInc['error'];
            $progress['tables_processed']++;
            continue;
        }
        $destStmt = $destPdo->query("SHOW TABLE STATUS LIKE '$table'");
        $destStatus = $destStmt->fetch(PDO::FETCH_ASSOC);
        $destAutoInc = $destStatus['Auto_increment'] ?? 1;
        if (VERBOSE_LOGGING >= 2) {
            ul("Compared AUTO_INCREMENT for table $table: Source=$sourceAutoInc, Destination=$destAutoInc");
        }

        if ($sourceAutoInc != $destAutoInc) {
            if (!applyToTarget(
                'update_auto_increment',
                ['table' => $table, 'auto_increment' => $sourceAutoInc],
                "Update AUTO_INCREMENT to $sourceAutoInc for table $table"
            )) {
                $progress['tables_processed']++;
                continue;
            }
            $summary[$table]['structure_status'][] = "AUTO_INCREMENT updated to $sourceAutoInc (was: $destAutoInc)";
            if (VERBOSE_LOGGING >= 2 && !VERIFY_MODE) {
                ul("Planned update of AUTO_INCREMENT to $sourceAutoInc for destination table $table");
            }
        }

        if (VERBOSE_LOGGING >= 1 || VERBOSE_GUI >= 1) {
            ul("Summary for table $table: Source Records={$summary[$table]['source_records']}, Dest Records={$summary[$table]['dest_records']}, Records Added={$summary[$table]['records_added']}, Records Modified={$summary[$table]['records_modified']}, Records Deleted={$summary[$table]['records_deleted']}, Structure: " . implode('; ', $summary[$table]['structure_status']));
        }
        $progress['tables_processed']++;
    }
} else {
    if (VERBOSE_LOGGING >= 1 || VERBOSE_GUI >= 1) {
        ul("Database synchronization skipped: DB_SYNC is false");
    }
    $progress['tables_processed'] = $progress['total_tables'];
}

if (defined('FILES_SYNC') && FILES_SYNC) {
    $resolvedDestRootDir = realpath(DEST_ROOT_DIR);
    if ($resolvedDestRootDir === false) {
        $error = "Error: Invalid DEST_ROOT_DIR: " . DEST_ROOT_DIR;
        ul($error);
        $errors[] = $error;
        $progress['current_phase'] = 'Error in configuration';
        include 'render_gui.php';
        die();
    }
    if (VERBOSE_LOGGING >= 2) {
        ul("Resolved DEST_ROOT_DIR: " . $resolvedDestRootDir);
    }

    if (empty($directories)) {
        $directories = [''];
        if (VERBOSE_LOGGING >= 2) {
            ul("SYNC_DIRECTORIES is empty; syncing all directories under DEST_ROOT_DIR recursively");
        }
    } else {
        foreach ($directories as $dir) {
            if (strpos($dir, '..') !== false || $dir === '') {
                $error = "Error: Invalid sync directory: $dir";
                ul($error);
                $errors[] = $error;
                $progress['current_phase'] = 'Error in configuration';
                include 'render_gui.php';
                die();
            }
            $dirPath = realpath($resolvedDestRootDir . '/' . ltrim($dir, '/'));
            if ($dirPath === false || !is_dir($dirPath)) {
                ul("Warning: Sync directory does not exist or is not a directory: $dir");
                $errors[] = "Sync directory does not exist or is not a directory: $dir";
            } else {
                if (VERBOSE_LOGGING >= 2) {
                    ul("Sync directory validated: $dir (resolved: $dirPath), will process recursively");
                }
            }
        }
    }

    $excludeDirs = array_map(function($dir) use ($resolvedDestRootDir) {
        $path = realpath($resolvedDestRootDir . '/' . ltrim($dir, '/'));
        if ($path === false) {
            ul("Warning: Excluded directory does not exist: $dir");
            return null;
        }
        return $path;
    }, defined('EXCLUDE_DIRECTORIES') ? EXCLUDE_DIRECTORIES : ['sync']);
    $excludeDirs = array_filter($excludeDirs);

    if (VERBOSE_LOGGING >= 2) {
        ul("Directories to sync: " . (empty($directories) ? "All (root)" : implode(', ', $directories)));
        ul("Excluded directories: " . (defined('EXCLUDE_DIRECTORIES') && is_array(EXCLUDE_DIRECTORIES) && !empty(EXCLUDE_DIRECTORIES) ? implode(', ', EXCLUDE_DIRECTORIES) : "sync"));
        ul("Resolved excluded directories: " . (empty($excludeDirs) ? "None" : implode(', ', $excludeDirs)));
    }

    $progress['current_phase'] = 'Retrieving source files';
    $sourceFiles = [];
    foreach ($directories as $dir) {
        if (VERBOSE_LOGGING >= 1) {
            ul("Requesting file list for directory: $dir");
        }
        $sourceFilesResponse = sendToSource('list_files', [
            'directories' => [$dir],
            'exclude_dirs' => defined('EXCLUDE_DIRECTORIES') ? EXCLUDE_DIRECTORIES : ['sync']
        ]);
        if (isset($sourceFilesResponse['error'])) {
            $error = "Failed to retrieve source file list for directory $dir: " . $sourceFilesResponse['error'];
            ul($error);
            $errors[] = $error;
            continue;
        }
        $files = $sourceFilesResponse['data'] ?? [];
        if (VERBOSE_LOGGING >= 1) {
            ul("Retrieved " . count($files) . " files for directory $dir");
        }
        if (VERBOSE_LOGGING >= 2) {
            ul("Files for directory $dir: " . json_encode($files));
        }
        foreach ($files as $file) {
            // Ensure no duplicate paths by using path as key
            $sourceFiles[$file['path']] = $file;
        }
    }
    $sourceFiles = array_values($sourceFiles); // Convert back to indexed array
    $progress['total_files'] = count($sourceFiles);
    if (VERBOSE_LOGGING >= 1 || VERBOSE_GUI >= 1) {
        ul("Retrieved {$progress['total_files']} total files from source across all directories");
    }
    if (VERBOSE_LOGGING >= 2) {
        ul("Total source files: " . json_encode($sourceFiles));
    }

    // Validate source files before processing
    foreach ($sourceFiles as $file) {
        $relativePath = $file['path'] ?? '';
        $sourceHash = $file['hash'] ?? '';
        if (!$relativePath || !$sourceHash || strpos($relativePath, '..') !== false || strpos($relativePath, '/') === 0) {
            ul("Warning: Invalid source file entry: " . json_encode($file));
            $errors[] = "Invalid source file entry: " . json_encode($file);
            $sourceFiles = array_filter($sourceFiles, fn($f) => $f['path'] !== $relativePath);
            $progress['total_files'] = count($sourceFiles);
            continue;
        }
    }

    // Collect source directories for directory deletion check
    $sourceDirs = [];
    foreach ($sourceFiles as $file) {
        $dir = dirname($file['path']);
        while ($dir !== '.' && $dir !== '') {
            $sourceDirs[$dir] = true;
            $dir = dirname($dir);
        }
    }
    if (VERBOSE_LOGGING >= 2) {
        ul("Source directories identified: " . implode(', ', array_keys($sourceDirs)));
    }

    $progress['current_phase'] = 'Processing files';
    $batchSize = defined('MAX_BATCH_SIZE') ? MAX_BATCH_SIZE : 100;
    $sourceFileBatches = array_chunk($sourceFiles, $batchSize);
    foreach ($sourceFileBatches as $batchIndex => $sourceBatch) {
        if (VERBOSE_LOGGING >= 2) {
            ul("Processing file batch " . ($batchIndex + 1) . " of " . count($sourceFileBatches));
        }
        foreach ($sourceBatch as $file) {
            $relativePath = $file['path'];
            $sourceHash = $file['hash'];
            $destPath = $resolvedDestRootDir . '/' . $relativePath;
            $normalizedDestPath = str_replace('\\', '/', $destPath);
            if ($destPath !== $normalizedDestPath && VERBOSE_LOGGING >= 2) {
                ul("Destination path normalized: Original=$destPath, Normalized=$normalizedDestPath");
            }
            $destDir = dirname($normalizedDestPath);
            
            $isExcluded = false;
            $fileDir = realpath($destDir) ?: $destDir;
            $normalizedFileDir = str_replace('\\', '/', $fileDir);
            foreach ($excludeDirs as $excludeDir) {
                if ($excludeDir && strpos($normalizedFileDir . '/', $excludeDir . '/') === 0) {
                    $isExcluded = true;
                    if (VERBOSE_LOGGING >= 2) {
                        ul("File $relativePath excluded: Directory $normalizedFileDir starts with excluded $excludeDir");
                    }
                    break;
                }
            }
            if ($isExcluded) {
                if (VERBOSE_LOGGING >= 1 || VERBOSE_GUI >= 1) {
                    ul("Skipped file $relativePath (in excluded directory)");
                }
                if (VERBOSE_GUI >= 2) {
                    $actions[] = "Skip file $relativePath (in excluded directory)";
                }
                $progress['files_processed']++;
                continue;
            }

            if (VERBOSE_LOGGING >= 2) {
                ul("Processing file $relativePath: Source Path=$destPath, Normalized=$normalizedDestPath, Directory=$normalizedFileDir");
            }

            $destHash = '';
            $destSize = 0;
            $destFirstBytes = '';
            $hasBOM = false;
            if (file_exists($normalizedDestPath)) {
                $content = file_get_contents($normalizedDestPath);
                if ($content === false) {
                    ul("Warning: Failed to read destination file $normalizedDestPath");
                    $errors[] = "Failed to read destination file $normalizedDestPath";
                    $progress['files_processed']++;
                    continue;
                }
                $destSize = strlen($content);
                $destFirstBytes = bin2hex(substr($content, 0, 16));
                $hasBOM = strpos($content, "\xEF\xBB\xBF") === 0 ? 'Yes' : 'No';
                $destHash = customFileHash($normalizedDestPath);
            }
            if (VERBOSE_LOGGING >= 2) {
                ul("Compared file $relativePath: Source Hash=$sourceHash, Destination Hash=$destHash, Dest Size=$destSize bytes, Dest First 16 bytes=$destFirstBytes, BOM Detected=$hasBOM");
            }

            if ($sourceHash !== $destHash) {
                $sourceFileResponse = sendToSource('get_file', ['path' => $relativePath]);
                if (isset($sourceFileResponse['error'])) {
                    $error = "Warning: Failed to retrieve source file $relativePath: " . $sourceFileResponse['error'];
                    ul($error);
                    $errors[] = $error;
                    $progress['files_processed']++;
                    continue;
                }
                if (!isset($sourceFileResponse['data']['content'])) {
                    $error = "Warning: Source file $relativePath has no content in API response";
                    ul($error);
                    $errors[] = $error;
                    $progress['files_processed']++;
                    continue;
                }
                $fileContent = base64_decode($sourceFileResponse['data']['content'] ?? '');
                if ($fileContent === false) {
                    $error = "Warning: Failed to decode source file content for $relativePath";
                    ul($error);
                    $errors[] = $error;
                    $progress['files_processed']++;
                    continue;
                }
                $action = file_exists($normalizedDestPath) ? "Update file $relativePath" : "Add file $relativePath";
                if (!applyToTarget(
                    'update_file',
                    ['path' => $relativePath, 'content' => base64_encode($fileContent)],
                    $action
                )) {
                    $progress['files_processed']++;
                    continue;
                }
                if (VERBOSE_LOGGING >= 2 && !VERIFY_MODE) {
                    ul("Planned $action: Source Hash=$sourceHash, Destination Hash=$destHash");
                }
            } else {
                if (VERBOSE_LOGGING >= 2) {
                    ul("Skipped file $relativePath: Hashes match (Source=$sourceHash, Destination=$destHash)");
                }
                if (VERBOSE_GUI >= 2) {
                    $actions[] = "Skip file $relativePath: Hashes match";
                }
            }
            $progress['files_processed']++;
        }
    }

    if (DROP_DEST_FILES_NOT_IN_SOURCE) {
        $progress['current_phase'] = 'Checking destination files and directories';
        $destDirectories = array_map(function($dir) use ($resolvedDestRootDir) {
            $path = realpath($resolvedDestRootDir . '/' . ltrim($dir, '/'));
            return $path !== false ? $path : null;
        }, $directories);
        $destDirectories = array_filter($destDirectories);
        if (empty($destDirectories)) {
            $destDirectories = [$resolvedDestRootDir];
            if (VERBOSE_LOGGING >= 2) {
                ul("No specific sync directories; checking all files and directories under DEST_ROOT_DIR recursively for deletion");
            }
        } else {
            if (VERBOSE_LOGGING >= 2) {
                ul("Checking files and directories in sync directories for deletion: " . implode(', ', $destDirectories));
            }
        }

        foreach ($destDirectories as $dir) {
            if (!is_dir($dir)) {
                ul("Warning: Directory not found: $dir");
                $errors[] = "Directory not found: $dir";
                continue;
            }
            if (VERBOSE_LOGGING >= 2) {
                ul("Recursively scanning directory for deletion: $dir");
            }
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            $destDirsToCheck = []; // Collect directories to check after files
            foreach ($files as $file) {
                $filePath = realpath($file->getPathname());
                if ($filePath === false) {
                    ul("Warning: Unable to resolve path for " . $file->getPathname());
                    $errors[] = "Unable to resolve path for " . $file->getPathname();
                    continue;
                }
                $normalizedFilePath = str_replace('\\', '/', $filePath);
                $relativePath = str_replace('\\', '/', ltrim(substr($normalizedFilePath, strlen($resolvedDestRootDir) + 1), '/'));
                $fileDir = realpath(dirname($filePath)) ?: dirname($filePath);
                $normalizedFileDir = str_replace('\\', '/', $fileDir);

                // Check if the file or directory is excluded
                $isExcluded = false;
                foreach ($excludeDirs as $excludeDir) {
                    if ($excludeDir && strpos($normalizedFileDir . '/', $excludeDir . '/') === 0) {
                        $isExcluded = true;
                        if (VERBOSE_LOGGING >= 2) {
                            ul("File or directory $relativePath excluded: Directory $normalizedFileDir starts with excluded $excludeDir");
                        }
                        break;
                    }
                }
                if ($isExcluded) {
                    if (VERBOSE_LOGGING >= 1 || VERBOSE_GUI >= 1) {
                        ul("Skipped file or directory $relativePath (in excluded directory)");
                    }
                    if (VERBOSE_GUI >= 2) {
                        $actions[] = "Skip file or directory $relativePath (in excluded directory)";
                    }
                    continue;
                }

                if ($file->isDir()) {
                    // Collect directories for later processing
                    $destDirsToCheck[$relativePath] = $normalizedFilePath;
                    continue;
                }

                // Process files
                $isInSource = false;
                foreach ($sourceFiles as $sourceFile) {
                    if ($sourceFile['path'] === $relativePath) {
                        $isInSource = true;
                        break;
                    }
                }
                if (!$isInSource) {
                    $action = "Delete file $relativePath (not in source)";
                    $actions[] = $action;
                    $progress['total_actions']++;
                    if (VERBOSE_LOGGING >= 1 || VERBOSE_GUI >= 1) {
                        ul("Planned $action");
                    }
                    if (!VERIFY_MODE) {
                        if (!unlink($normalizedFilePath)) {
                            ul("Warning: Failed to delete file $relativePath");
                            $errors[] = "Failed to delete file $relativePath";
                            continue;
                        }
                        if (VERBOSE_LOGGING >= 2) {
                            ul("Deleted file $relativePath");
                        }
                    }
                }
            }

            // Process directories in reverse order (deepest first) to ensure empty directories are deleted
            krsort($destDirsToCheck); // Sort to process subdirectories before parents
            foreach ($destDirsToCheck as $relativePath => $normalizedDirPath) {
                $isExcluded = false;
                foreach ($excludeDirs as $excludeDir) {
                    if ($excludeDir && strpos($normalizedDirPath . '/', $excludeDir . '/') === 0) {
                        $isExcluded = true;
                        if (VERBOSE_LOGGING >= 2) {
                            ul("Directory $relativePath excluded: Directory $normalizedDirPath starts with excluded $excludeDir");
                        }
                        break;
                    }
                }
                if ($isExcluded) {
                    if (VERBOSE_LOGGING >= 1 || VERBOSE_GUI >= 1) {
                        ul("Skipped directory $relativePath (in excluded directory)");
                    }
                    if (VERBOSE_GUI >= 2) {
                        $actions[] = "Skip directory $relativePath (in excluded directory)";
                    }
                    continue;
                }

                // Check if directory exists in source
                $isInSource = isset($sourceDirs[$relativePath]);
                if (!$isInSource) {
                    // Verify directory is empty or contains only files that would be deleted
                    $dirIterator = new DirectoryIterator($normalizedDirPath);
                    $hasContents = false;
                    foreach ($dirIterator as $item) {
                        if ($item->isDot()) continue;
                        $itemPath = $item->getPathname();
                        $relativeItemPath = str_replace('\\', '/', ltrim(substr(realpath($itemPath) ?: $itemPath, strlen($resolvedDestRootDir) + 1), '/'));
                        if ($item->isDir()) {
                            // Subdirectory must also be scheduled for deletion or empty
                            if (!isset($destDirsToCheck[$relativeItemPath])) {
                                $hasContents = true;
                                break;
                            }
                        } else {
                            // Check if file is in source
                            $fileInSource = false;
                            foreach ($sourceFiles as $sourceFile) {
                                if ($sourceFile['path'] === $relativeItemPath) {
                                    $fileInSource = true;
                                    break;
                                }
                            }
                            if ($fileInSource) {
                                $hasContents = true;
                                break;
                            }
                        }
                    }

                    if (!$hasContents) {
                        $action = "Delete directory $relativePath (not in source)";
                        $actions[] = $action;
                        $progress['total_actions']++;
                        if (VERBOSE_LOGGING >= 1 || VERBOSE_GUI >= 1) {
                            ul("Planned $action");
                        }
                        if (!VERIFY_MODE) {
                            if (!rmdir($normalizedDirPath)) {
                                ul("Warning: Failed to delete directory $relativePath");
                                $errors[] = "Failed to delete directory $relativePath";
                                continue;
                            }
                            if (VERBOSE_LOGGING >= 2) {
                                ul("Deleted directory $relativePath");
                            }
                        }
                    } else {
                        if (VERBOSE_LOGGING >= 2) {
                            ul("Skipped directory deletion for $relativePath: Contains files or subdirectories not scheduled for deletion");
                        }
                        if (VERBOSE_GUI >= 2) {
                            $actions[] = "Skip directory $relativePath: Contains files or subdirectories not scheduled for deletion";
                        }
                    }
                }
            }
        }
    }
} else {
    if (VERBOSE_LOGGING >= 1 || VERBOSE_GUI >= 1) {
        ul("File synchronization skipped: FILES_SYNC is false");
    }
    $progress['files_processed'] = $progress['total_files'];
}


if (defined('FILES_BATCH_SYNC') && FILES_BATCH_SYNC) {
	$progress['current_phase'] = 'Initiating source file listing';
	ul("Starting file listing operation on source");
	$startResponse = sendToSource('start_file_listing', []);
	if (isset($startResponse['error'])) {
		$error = "Failed to start file listing: " . $startResponse['error'];
		ul($error);
		$errors[] = $error;
		$progress['current_phase'] = 'Error in file listing';
		include 'render_gui.php';
		die();
	}
	ul("File listing operation started on source");

	// Poll every minute until listing.txt is removed
	$listingComplete = false;
	while (!$listingComplete) {
		$statusResponse = sendToSource('check_listing_status', []);
		if (isset($statusResponse['error'])) {
			$error = "Failed to check listing status: " . $statusResponse['error'];
			ul($error);
			$errors[] = $error;
			$progress['current_phase'] = 'Error checking listing status';
			include 'render_gui.php';
			die();
		}
		$listingExists = $statusResponse['data']['listing_exists'] ?? true;
		if ($listingExists) {
			ul("Listing operation in progress, waiting 60 seconds...");
			sleep(60); // Wait 1 minute
		} else {
			$listingComplete = true;
			ul("Listing operation completed on source");
			$progress['current_phase'] = 'Source file listing completed';
		}
	}
	
	
    $batchSize = defined('MAX_BATCH_SIZE') ? MAX_BATCH_SIZE : 100;
    $destRoot = defined('DEST_ROOT_DIR') ? DEST_ROOT_DIR : '/home/deluolyl/aiklepios.com/sync/dest';
    $readPosition = 0;
    $batchNumber = 1;

    if (VERBOSE_LOGGING >= 1) {
        ul("Starting batch synchronization with MAX_BATCH_SIZE=$batchSize");
    }

    while (true) {
        $response = sendToSource('get_file_list_batch', [
            'batch_size' => $batchSize,
            'read_position' => $readPosition
        ]);

        if (isset($response['error'])) {
            $error = "Failed to retrieve batch $batchNumber: " . $response['error'];
            ul($error);
            $errors[] = $error;
            $progress['current_phase'] = 'Batch synchronization failed';
            include 'render_gui.php';
            die();
        }

        $files = $response['data']['files'] ?? [];
        $newReadPosition = $response['data']['read_position'] ?? $readPosition;

        if (empty($files)) {
            break; // No more files to process
        }

        foreach ($files as $file) {
            $relativePath = $file['path'];
            $expectedLength = (int) $file['length'];
            $expectedHash = $file['hash'];
            $destPath = $destRoot . '/' . $relativePath;

            $needsUpdate = false;
            clearstatcache();
            if (!file_exists($destPath)) {
                $needsUpdate = true;
                if (VERBOSE_LOGGING >= 2) {
                    ul("File missing in destination, will fetch: $relativePath");
                }
            } elseif (filesize($destPath) !== $expectedLength) {
                $needsUpdate = true;
                if (VERBOSE_LOGGING >= 2) {
                    ul("File size mismatch, will fetch: $relativePath");
                }
            } else {
                $destHash = customFileHash($destPath);
                if ($destHash !== $expectedHash) {
                    $needsUpdate = true;
                    if (VERBOSE_LOGGING >= 2) {
                        ul("File hash mismatch, will fetch: $relativePath");
                    }
                }
            }

            if ($needsUpdate) {
                // Fetch file from source
                $response = sendToSource('get_file', ['path' => $relativePath]);
                if (isset($response['error'])) {
                    $error = "Failed to fetch file $relativePath: " . $response['error'];
                    ul($error);
                    $errors[] = $error;
                    continue; // Skip to next file
                }

                $fileContent = base64_decode($response['data']['content'] ?? '');
                $fetchedHash = $response['data']['hash'] ?? '';
                if ($fileContent === false || $fetchedHash !== $expectedHash) {
                    $error = "Invalid file content or hash mismatch for $relativePath";
                    ul($error);
                    $errors[] = $error;
                    continue;
                }
 
                // Ensure destination directory exists
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    if (!mkdir($destDir, 0755, true)) {
                        $error = "Failed to create directory: $destDir";
                        ul($error);
                        $errors[] = $error;
                        continue;
                    }
                }

                // Write file to destination
                if (file_put_contents($destPath, $fileContent) === false) {
                    $error = "Failed to write file: $destPath";
                    ul($error);
                    $errors[] = $error;
                    continue;
                }

                $progress['total_actions']++;
                $actions[] = "Updated file: $relativePath";
                if (VERBOSE_LOGGING >= 1) {
                    ul("Updated file: $destPath");
                }
            } else {
                if (VERBOSE_LOGGING >= 2) {
                    ul("File unchanged: $relativePath");
                }
            }

            $progress['total_files_processed']++;
        }

        if (VERBOSE_LOGGING >= 2) {
            ul("Processed batch $batchNumber with " . count($files) . " files, read_position=$readPosition, new_read_position=$newReadPosition, total_files_processed={$progress['total_files_processed']}");
        }

        $batchNumber++;
        $readPosition = $newReadPosition;
        $progress['current_phase'] = "Processed batch $batchNumber";
    }

    if (VERBOSE_LOGGING >= 1) {
        ul("Completed batch synchronization: Processed {$progress['total_files_processed']} files, performed {$progress['total_actions']} actions");
    }
}else {
	if (VERBOSE_LOGGING >= 1 || VERBOSE_GUI >= 1) {
		ul("File batch synchronization skipped: FILES_BATCH_SYNC is false");
	}
}



$progress['current_phase'] = 'Completed';
if (VERBOSE_LOGGING >= 1 || VERBOSE_GUI >= 1) {
    ul("Sync operation completed: Tables Processed={$progress['tables_processed']}/{$progress['total_tables']}, Files Processed={$progress['files_processed']}/{$progress['total_files']}, Actions={$progress['total_actions']}");
}

include 'render_gui.php';
?>