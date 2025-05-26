# SyncTools
Synchronization Tool Project  that synchronize database tables and files between a source site and a destination site
The tool runs on a web server environment using PHP and MySQL.
Key Features
Database Synchronization: Synchronizes table structures, records, and AUTO_INCREMENT values for specified tables (e.g., Users).
File Synchronization: Adds, updates, or deletes files in DEST_ROOT_DIR based on source file hashes, supporting directories like test.
Directory Deletion: Deletes destination directories not present in the source if empty or containing only non-source files, enabled by DROP_DEST_FILES_NOT_IN_SOURCE.
GUI Output: Displays actions in a web interface, e.g., Delete files, Delete directories if not in source.

Logging: Writes detailed logs for debugging.
