<?php
// render_gui.php
// Renders the GUI for sync.php, used by exitAfterError to display errors
?>
<!DOCTYPE html>
<html>
<head>
    <title>Sync Tool</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        #config, #status, #actions, #summary, #errors { border: 1px solid #ccc; padding: 10px; margin-bottom: 20px; }
        #actions, #errors { height: 400px; overflow-y: scroll; }
        #actions li, #errors li { margin: 5px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        button { padding: 10px; margin: 10px 0; }
    </style>
</head>
<body>
    <h1>Sync Tool</h1>
    <p>Mode: <?php echo defined('VERIFY_MODE') && VERIFY_MODE ? 'Verify (Preview)' : 'Execute'; ?></p>
    <p>Source: Source (read-only) | Target: Dest (updated)</p>
    <h2>Status</h2>
    <div id="status">
        <table>
            <tr>
                <th>Metric</th>
                <th>Value</th>
            </tr>
            <tr>
                <td>Current Phase</td>
                <td><?php echo isset($progress['current_phase']) ? htmlspecialchars($progress['current_phase']) : 'Not started'; ?></td>
            </tr>
            <tr>
                <td>Tables Processed</td>
                <td><?php echo isset($progress['tables_processed'], $progress['total_tables']) ? $progress['tables_processed'] . '/' . $progress['total_tables'] : '0/0'; ?></td>
            </tr>
            //Code to insert
            <tr>
                <td>Files Processed</td>
                <td><?php echo isset($progress['files_processed'], $progress['total_files']) ? $progress['files_processed'] . '/' . $progress['total_files'] : '0/0'; ?></td>
            </tr>
            <tr>
                <td>Batch Files Processed</td>
                <td><?php echo isset($progress['total_files_processed']) ? htmlspecialchars($progress['total_files_processed']) : '0'; ?></td>
            </tr>
            <tr>
                <td>Total Actions</td>
                <td><?php echo isset($progress['total_actions']) ? $progress['total_actions'] : '0'; ?></td>
            </tr>
        </table>
    </div>
    <h2>Configuration</h2>
    <div id="config">
                <table>
            <tr>
                <th>Option</th>
                <th>Value</th>
            </tr>
            <tr>
                <td>Sync Tables</td>
                <td><?php echo isset($tables) && !empty($tables) ? htmlspecialchars(implode(', ', $tables)) : 'None'; ?></td>
            </tr>
            <tr>
                <td>Sync Directories</td>
                <td><?php echo isset($directories) && !empty($directories) ? htmlspecialchars(implode(', ', $directories)) : (isset($resolvedDestRootDir) ? htmlspecialchars($resolvedDestRootDir) : (defined('DEST_ROOT_DIR') ? htmlspecialchars(DEST_ROOT_DIR) : 'None')); ?></td>
            </tr>
            <tr>
                <td>Exclude Directories</td>
                <td><?php echo defined('EXCLUDE_DIRECTORIES') && is_array(EXCLUDE_DIRECTORIES) && !empty(EXCLUDE_DIRECTORIES) ? htmlspecialchars(implode(', ', EXCLUDE_DIRECTORIES)) : 'sync'; ?></td>
            </tr>
            <tr>
                <td>Verify Mode</td>
                <td><?php echo defined('VERIFY_MODE') && VERIFY_MODE ? 'True' : 'False'; ?></td>
            </tr>
            <tr>
                <td>Destination Root Directory</td>
                <td><?php echo defined('DEST_ROOT_DIR') ? htmlspecialchars(DEST_ROOT_DIR) : 'Not set'; ?></td>
            </tr>
            <tr>
                <td>Drop Columns Not in Source</td>
                <td><?php echo defined('DROP_DEST_COLUMNS_NOT_IN_SOURCE') && DROP_DEST_COLUMNS_NOT_IN_SOURCE ? 'True' : 'False'; ?></td>
            </tr>
            <tr>
                <td>Drop Files Not in Source</td>
                <td><?php echo defined('DROP_DEST_FILES_NOT_IN_SOURCE') && DROP_DEST_FILES_NOT_IN_SOURCE ? 'True' : 'False'; ?></td>
            </tr>
            <tr>
                <td>Verbose Logging Level</td>
                <td><?php echo defined('VERBOSE_LOGGING') ? htmlspecialchars(VERBOSE_LOGGING) : '0'; ?></td>
            </tr>
            <tr>
                <td>Verbose GUI Level</td>
                <td><?php echo defined('VERBOSE_GUI') ? htmlspecialchars(VERBOSE_GUI) : '0'; ?></td>
            </tr>
            <tr>
                <td>Database Synchronization</td>
                <td><?php echo defined('DB_SYNC') ? (DB_SYNC ? 'Enabled' : 'Disabled') : 'Not set'; ?></td>
            </tr>
            <tr>
                <td>File Synchronization</td>
                <td><?php echo defined('FILES_SYNC') ? (FILES_SYNC ? 'Enabled' : 'Disabled') : 'Not set'; ?></td>
            </tr>
            <tr>
                <td>File Batch Synchronization</td>
                <td><?php echo defined('FILES_BATCH_SYNC') ? (FILES_BATCH_SYNC ? 'Enabled' : 'Disabled') : 'Not set'; ?></td>
            </tr>
        </table>
    </div>
    <h2>Sync Summary</h2>
    <div id="summary">
        <table>
            <tr>
                <th>Table</th>
                <th>Source Records</th>
                <th>Dest Records</th>
                <th>Records Added</th>
                <th>Records Modified</th>
                <th>Records Deleted</th>
                <th>Structure Status</th>
            </tr>
            <?php if (isset($summary) && is_array($summary) && !empty($summary)): ?>
                <?php foreach ($summary as $table => $data): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(applyTextReplacements($table)); ?></td>
                        <td><?php echo $data['source_records'] ?? '0'; ?></td>
                        <td><?php echo $data['dest_records'] ?? '0'; ?></td>
                        <td><?php echo $data['records_added'] ?? '0'; ?></td>
                        <td><?php echo $data['records_modified'] ?? '0'; ?></td>
                        <td><?php echo $data['records_deleted'] ?? '0'; ?></td>
                        <td><?php echo htmlspecialchars(implode('; ', $data['structure_status'] ?? [])); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7">No summary available</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>
    <?php if (!empty($errors)): ?>
        <h2>Errors</h2>
        <div id="errors">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li style="color: #ff0000;"><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <h2>Actions</h2>
    <div id="actions">
        <ul>
            <?php if (isset($actions) && !empty($actions)): ?>
                <?php foreach ($actions as $action): ?>
                    <li style="<?php echo strpos($action, 'Skip') !== false ? 'color: #8c8c8c;' : ''; ?>">
                        <?php echo htmlspecialchars(applyTextReplacements($action)); ?>
                    </li>
                <?php endforeach; ?>
            <?php else: ?>
                <li>No actions planned</li>
            <?php endif; ?>
        </ul>
    </div>
    <form method="post">
        <button type="submit" name="sync">Run Sync (<?php echo defined('VERIFY_MODE') && VERIFY_MODE ? 'Preview' : 'Execute'; ?>)</button>
    </form>
</body>
</html>