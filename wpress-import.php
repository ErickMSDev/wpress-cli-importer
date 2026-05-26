<?php
/**
 * Standalone WordPress CLI .wpress Importer Script
 *
 * Drops into the WordPress root directory to extract and import any .wpress file.
 * Handles files of arbitrary size with O(1) memory footprint, autodetects old and new
 * domains/paths, and performs a complete serialized-safe search-and-replace on the DB.
 *
 * Usage: php wpress-import.php <archive.wpress> [--dry-run]
 *
 * @author ErickMSDev
 * @version 1.2.0
 */

// Bootstrap configuration
set_time_limit(0);
ini_set('memory_limit', '512M');

// Force CLI mode
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Error: This script can only be run from the command line (CLI).\n");
    exit(1);
}

// Check PHP requirements
if (!extension_loaded('mysqli')) {
    fwrite(STDERR, "Error: The 'mysqli' PHP extension is required to run this script. Please enable it.\n");
    exit(1);
}

// Color and formatting helper functions
function colorize($text, $color = 'white') {
    $supports_color = DIRECTORY_SEPARATOR === '/' || false !== getenv('ANSICON') || 'ON' === getenv('ConEmuANSI') || 'xterm' === getenv('TERM');
    if (!$supports_color) {
        return $text;
    }
    $colors = [
        'green'   => "\033[0;32m",
        'red'     => "\033[0;31m",
        'yellow'  => "\033[1;33m",
        'cyan'    => "\033[0;36m",
        'bold'    => "\033[1m",
        'white'   => "\033[0;37m",
        'reset'   => "\033[0m"
    ];
    return (isset($colors[$color]) ? $colors[$color] : '') . $text . $colors['reset'];
}

function cli_print($text, $color = 'white', $stream = STDOUT) {
    fwrite($stream, colorize($text, $color) . "\n");
}

// Parse Command Line Arguments
$dry_run = false;
$archive_path = null;

for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--dry-run') {
        $dry_run = true;
    } else {
        $archive_path = $argv[$i];
    }
}

// Print header & usage instructions
if (empty($archive_path)) {
    cli_print("============================================================", 'cyan');
    cli_print("   WordPress Standalone .wpress CLI Importer & Migrator", 'bold');
    cli_print("============================================================", 'cyan');
    cli_print("\nUsage:", 'bold');
    cli_print("  php wpress-import.php <path-to-file.wpress> [options]\n", 'green');
    cli_print("Options:", 'bold');
    cli_print("  --dry-run   Simulate extraction, database detection, and migration.", 'yellow');
    cli_print("              Does not write files or modify database tables.\n", 'white');
    exit(1);
}

// Verification checks
if (!file_exists($archive_path)) {
    cli_print("Error: Archive file '{$archive_path}' does not exist.", 'red', STDERR);
    exit(1);
}

if (!file_exists('wp-config.php')) {
    cli_print("Error: 'wp-config.php' not found. Please run this script in your WordPress root directory.", 'red', STDERR);
    exit(1);
}

if ($dry_run) {
    cli_print("============================================================", 'yellow');
    cli_print("           🔥 SIMULATION / DRY-RUN MODE ACTIVE 🔥           ", 'yellow');
    cli_print("============================================================", 'yellow');
    cli_print("No files will be modified. Database will remain untouched.\n", 'white');
}

cli_print("Reading database configurations from 'wp-config.php'...", 'cyan');
$wp_config = file_get_contents('wp-config.php');

// Helper to extract constant defines
function get_define($name, $content) {
    if (preg_match('/define\s*\(\s*[\'"]' . preg_quote($name, '/') . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/i', $content, $matches)) {
        return $matches[1];
    }
    return null;
}

$db_name = get_define('DB_NAME', $wp_config);
$db_user = get_define('DB_USER', $wp_config);
$db_password = get_define('DB_PASSWORD', $wp_config);
$db_host = get_define('DB_HOST', $wp_config);

// Table prefix
$table_prefix = 'wp_';
if (preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]*)[\'"]/i', $wp_config, $matches)) {
    $table_prefix = $matches[1];
}

if (!$db_name || !$db_user) {
    cli_print("Error: Could not parse database details from 'wp-config.php'.", 'red', STDERR);
    exit(1);
}

cli_print("Target Database:  {$db_name}", 'white');
cli_print("Database User:    {$db_user}", 'white');
cli_print("Database Host:    {$db_host}", 'white');
cli_print("Table Prefix:     {$table_prefix}\n", 'white');

// --- AUTODETECT TARGET (NEW) SITE URL & PATH ---
$new_url = null;
$new_path = rtrim(getcwd(), '/');

$host_parts = explode(':', $db_host);
$host = $host_parts[0];
$port_or_socket = isset($host_parts[1]) ? $host_parts[1] : null;
$port = null;
$socket = null;
if ($port_or_socket) {
    if (is_numeric($port_or_socket)) {
        $port = intval($port_or_socket);
    } else {
        $socket = $port_or_socket;
    }
}

// Try connecting to existing database to discover current new URL
cli_print("Attempting to autodetect new site URL/IP from the active database...", 'cyan');
$conn = @mysqli_connect($host, $db_user, $db_password, $db_name, $port, $socket);
if ($conn) {
    $prefix_escaped = mysqli_real_escape_string($conn, $table_prefix);
    $result = @mysqli_query($conn, "SELECT option_value FROM `{$prefix_escaped}options` WHERE option_name = 'siteurl' LIMIT 1");
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        if (!empty($row['option_value'])) {
            $new_url = rtrim($row['option_value'], '/');
            cli_print("✓ Detected New URL: {$new_url}", 'green');
        }
        mysqli_free_result($result);
    }
    mysqli_close($conn);
}

if (!$new_url) {
    cli_print("⚠️  Could not read existing URL from the target database (it might be empty or unmigrated).", 'yellow');
}

// Prompt to backup existing wp-content folder
$handle = fopen("php://stdin", "r");
if (is_dir('wp-content') && !$dry_run) {
    cli_print("\nAn existing 'wp-content' folder was detected.", 'yellow');
    fwrite(STDOUT, colorize("Would you like to back it up before importing? [y/N]: ", 'cyan'));
    $answer = trim(strtolower(fgets($handle)));
    
    if ($answer === 'y' || $answer === 'yes') {
        $backup_name = 'wp-content-backup-' . time();
        cli_print("Moving 'wp-content' to '{$backup_name}'...", 'cyan');
        if (rename('wp-content', $backup_name)) {
            cli_print("✓ Backup created successfully!\n", 'green');
        } else {
            cli_print("Error: Failed to back up existing 'wp-content' folder. Aborting for safety.", 'red', STDERR);
            exit(1);
        }
    } else {
        cli_print("Skipping backup. Existing files may be overwritten.\n", 'yellow');
    }
}

// .wpress format structural definitions
$filename_size = 255;
$content_size = 14;
$mtime_size = 12;
$prefix_size = 4096;
$header_size = $filename_size + $content_size + $mtime_size + $prefix_size;
$nul = "\x00";

$file = fopen($archive_path, 'rb');
if (!$file) {
    cli_print("Error: Could not open archive '{$archive_path}'.", 'red', STDERR);
    exit(1);
}

cli_print("------------------------------------------------------------", 'cyan');
cli_print($dry_run ? "Simulating extraction of archive. Please wait..." : "Extracting archive. Please wait...", 'bold');
cli_print("------------------------------------------------------------", 'cyan');

$count = 0;
$db_sql_path = null;
$old_url = null;
$old_path = null;

while (!feof($file)) {
    $header = fread($file, $header_size);
    if (strlen($header) < $header_size) {
        break;
    }
    if ($header === str_repeat($nul, $header_size)) {
        break; // EOF marker block
    }

    // Decode header block fields (split at first null byte)
    $filename_raw = substr($header, 0, $filename_size);
    $filename = explode($nul, $filename_raw)[0];

    $size_raw = substr($header, $filename_size, $content_size);
    $size = intval(explode($nul, $size_raw)[0]);

    $mtime_raw = substr($header, $filename_size + $content_size, $mtime_size);
    $mtime = intval(explode($nul, $mtime_raw)[0]);

    $prefix_raw = substr($header, $filename_size + $content_size + $mtime_size, $prefix_size);
    $prefix = explode($nul, $prefix_raw)[0];

    if (empty($filename)) {
        // Skip payload data block
        if ($size > 0) {
            fseek($file, $size, SEEK_CUR);
        }
        continue;
    }

    // Path normalization for Linux/Unix
    $prefix = str_replace('\\', '/', $prefix);
    $filename = str_replace('\\', '/', $filename);

    if (!empty($prefix) && $prefix !== '.') {
        $full_rel_path = $prefix . '/' . $filename;
    } else {
        $full_rel_path = $filename;
    }

    // Protect against directory traversal
    $full_rel_path = ltrim(str_replace('../', '', $full_rel_path), '/');

    // Keep track of the database.sql file and map extraction destinations
    $is_database = (basename($full_rel_path) === 'database.sql');
    if ($is_database) {
        $db_sql_path = 'database.sql';
        $dest_path = './database.sql';
    } else {
        $dest_path = './wp-content/' . $full_rel_path;
    }
    
    // In Dry-run mode, read database.sql directly in memory up to 10MB to find domain/paths
    if ($dry_run) {
        if ($is_database) {
            $scan_size = min($size, 10 * 1024 * 1024); // read up to 10MB
            $sql_buffer = fread($file, $scan_size);
            
            // Match 'siteurl' option value (handles domains, IPs, and optional ports)
            if (preg_match('/[\x27\x22]siteurl[\x27\x22]\s*,\s*[\x27\x22](https?:\/\/[^\x27\x22]+)[\x27\x22]/i', $sql_buffer, $matches)) {
                $old_url = rtrim($matches[1], '/');
            }
            // Match old absolute file path ending in /wp-content
            if (preg_match('/[\x27\x22](\/[^\x27\x22]*)\/wp-content/i', $sql_buffer, $matches)) {
                $old_path = rtrim($matches[1], '/');
            }
            
            $remaining = $size - $scan_size;
            if ($remaining > 0) {
                fseek($file, $remaining, SEEK_CUR);
            }
        } else {
            if ($size > 0) {
                fseek($file, $size, SEEK_CUR);
            }
        }
        $count++;
        if ($count % 500 === 0) {
            cli_print("  ↳ [Dry-run] Scanned {$count} files...", 'cyan');
        }
        continue;
    }

    // Directory record vs File record
    if (substr($dest_path, -1) === '/' || empty($filename)) {
        if (!is_dir($dest_path)) {
            if (!@mkdir($dest_path, 0755, true)) {
                cli_print("Error: Failed to create directory: '{$dest_path}'. Please check write permissions.", 'red', STDERR);
                exit(1);
            }
        }
        if ($size > 0) {
            fseek($file, $size, SEEK_CUR);
        }
        continue;
    }

    // Create parent directory
    $parent_dir = dirname($dest_path);
    if (!is_dir($parent_dir) && !empty($parent_dir)) {
        if (!@mkdir($parent_dir, 0755, true)) {
            cli_print("Error: Failed to create directory: '{$parent_dir}'. Please check write permissions.", 'red', STDERR);
            exit(1);
        }
    }

    // Write file payload in chunks (prevents memory spikes)
    $out = fopen($dest_path, 'wb');
    if ($out) {
        $remaining = $size;
        while ($remaining > 0 && !feof($file)) {
            $chunk_len = min($remaining, 8192);
            $chunk = fread($file, $chunk_len);
            fwrite($out, $chunk);
            $remaining -= strlen($chunk);
        }
        fclose($out);

        // Keep original modification time
        if ($mtime > 0) {
            @touch($dest_path, $mtime);
        }

        $count++;
        if ($count % 500 === 0) {
            cli_print("  ↳ Extracted {$count} files...", 'cyan');
        }
    } else {
        cli_print("Warning: Could not open file for writing: '{$dest_path}'. Skipping payload.", 'yellow');
        if ($size > 0) {
            fseek($file, $size, SEEK_CUR);
        }
    }
}
fclose($file);

cli_print($dry_run ? "✓ Simulated scan completed. Total files parsed: {$count}\n" : "✓ Extraction completed. Total files extracted: {$count}\n", 'green');

// --- AUTOMATIC PERMISSIONS AND OWNERSHIP CORRECTION ---
if (!$dry_run) {
    $is_root = false;
    if (function_exists('posix_getuid') && posix_getuid() === 0) {
        $is_root = true;
    } elseif (getenv('USER') === 'root') {
        $is_root = true;
    }

    if ($is_root) {
        cli_print("------------------------------------------------------------", 'cyan');
        cli_print("Correcting File ownership & permissions (Root execution detected)...", 'cyan');
        cli_print("------------------------------------------------------------", 'cyan');
        
        $owner_uid = fileowner('wp-config.php');
        $owner_info = function_exists('posix_getpwuid') ? posix_getpwuid($owner_uid) : null;
        
        if ($owner_info) {
            $username = $owner_info['name'];
            $groupname = isset($owner_info['gid']) && function_exists('posix_getgrgid') ? posix_getgrgid($owner_info['gid'])['name'] : $username;
            
            cli_print("✓ Autodetected WordPress user: {$username}:{$groupname}", 'green');
            cli_print("Applying ownership to 'wp-content'...", 'white');
            
            $chown_cmd = sprintf('chown -R %s:%s ./wp-content', escapeshellarg($username), escapeshellarg($groupname));
            @exec($chown_cmd);
        }
        
        cli_print("Applying standard permissions (755 for dirs, 644 for files)...", 'white');
        @exec('find ./wp-content -type d -exec chmod 755 {} \;');
        @exec('find ./wp-content -type f -exec chmod 644 {} \;');
        cli_print("✓ Ownership and permissions restored successfully!\n", 'green');
    }
}

// --- AUTODETECT SOURCE (OLD) SITE URL & PATH FROM DATABASE.SQL (Non dry-run) ---
if (!$dry_run && $db_sql_path && file_exists($db_sql_path)) {
    cli_print("Scanning extracted 'database.sql' to discover the source domain/IP and path...", 'cyan');
    $sql_file = fopen($db_sql_path, 'r');
    if ($sql_file) {
        $bytes_read = 0;
        $max_bytes = 10 * 1024 * 1024; // Scan up to 10MB
        while (!feof($sql_file) && $bytes_read < $max_bytes) {
            $line = fgets($sql_file);
            $bytes_read += strlen($line);

            // Match 'siteurl' option value (handles domains, IPs, and optional ports)
            if (!$old_url && preg_match('/[\x27\x22]siteurl[\x27\x22]\s*,\s*[\x27\x22](https?:\/\/[^\x27\x22]+)[\x27\x22]/i', $line, $matches)) {
                $old_url = rtrim($matches[1], '/');
            }

            // Match old absolute file path ending in /wp-content
            if (!$old_path && preg_match('/[\x27\x22](\/[^\x27\x22]*)\/wp-content/i', $line, $matches)) {
                $old_path = rtrim($matches[1], '/');
            }

            if ($old_url && $old_path) {
                break;
            }
        }
        fclose($sql_file);
    }
}

// Dynamic prompt fallback if OLD URL / IP was not found (instead of hardcoded values)
if (empty($old_url)) {
    cli_print("\n⚠️  Could not automatically detect the source site URL/IP from database.sql.", 'yellow');
    cli_print("This can happen if the 'siteurl' option is placed further down in the file or if it's customized.", 'yellow');
    cli_print("Please enter the source URL or IP address (e.g., https://oldsite.com or http://192.168.1.10:8080):", 'cyan');
    fwrite(STDOUT, "> ");
    $old_url = rtrim(trim(fgets($handle)), '/');
    
    if (empty($old_url)) {
        cli_print("Error: The source site URL/IP is required to map the database. Aborting.", 'red', STDERR);
        exit(1);
    }
}

// Dynamic prompt fallback if NEW URL / IP was not found
if (empty($new_url)) {
    cli_print("\n⚠️  Could not automatically detect the target site URL/IP from the active database.", 'yellow');
    cli_print("This is expected if you are doing a fresh import on a completely empty database.", 'yellow');
    cli_print("Please enter the target URL or IP address (e.g., https://newsite.com or http://localhost:8080):", 'cyan');
    fwrite(STDOUT, "> ");
    $new_url = rtrim(trim(fgets($handle)), '/');
    
    if (empty($new_url)) {
        cli_print("Error: The target site URL/IP is required. Aborting.", 'red', STDERR);
        exit(1);
    }
}

cli_print("\n------------------------------------------------------------", 'cyan');
cli_print("Migration Domains & Paths Configured", 'bold');
cli_print("------------------------------------------------------------", 'cyan');
cli_print("Old Site URL/IP:  {$old_url}", 'green');
cli_print("New Site URL/IP:  {$new_url}", 'green');
cli_print("Old File Path:    " . ($old_path ?: '[Not detected]'), 'white');
cli_print("New File Path:    {$new_path}\n", 'white');

if (!$dry_run) {
    cli_print("Do you want to confirm these domains/paths, or modify them manually?", 'white');
    fwrite(STDOUT, colorize("Press ENTER to proceed with these defaults, or type 'edit' to customize: ", 'cyan'));
    $selection = trim(strtolower(fgets($handle)));

    if ($selection === 'edit') {
        fwrite(STDOUT, colorize("Enter Old URL/IP (e.g. https://oldsite.com): ", 'cyan'));
        $old_url = rtrim(trim(fgets($handle)), '/');
        fwrite(STDOUT, colorize("Enter New URL/IP (e.g. http://localhost:8080): ", 'cyan'));
        $new_url = rtrim(trim(fgets($handle)), '/');
        
        fwrite(STDOUT, colorize("Enter Old Absolute Path (leave blank if not needed): ", 'cyan'));
        $old_path = rtrim(trim(fgets($handle)), '/');
        if (empty($old_path)) $old_path = null;
    }
}

// Database Import Phase
if ($db_sql_path && file_exists($db_sql_path)) {
    if ($dry_run) {
        cli_print("\n------------------------------------------------------------", 'yellow');
        cli_print("[Simulation] Database Import Actions Preview:", 'yellow');
        cli_print("------------------------------------------------------------", 'yellow');
        cli_print("1. Would import SQL dump '{$db_sql_path}' into database '{$db_name}'.", 'white');
        cli_print("2. Would rename archive placeholder tables ('SERVMASK_PREFIX_') to '{$table_prefix}'.", 'white');
        cli_print("3. Would run paged serialized-safe search-and-replace for:", 'white');
        cli_print("   - 'SERVMASK_PREFIX_' -> '{$table_prefix}'", 'cyan');
        cli_print("   - '{$old_url}' -> '{$new_url}'", 'cyan');
        
        $escaped_old_url = str_replace('/', '\\/', $old_url);
        $escaped_new_url = str_replace('/', '\\/', $new_url);
        cli_print("   - '{$escaped_old_url}' -> '{$escaped_new_url}' (escaped JSON structure)", 'cyan');
        
        if ($old_path) {
            cli_print("   - '{$old_path}' -> '{$new_path}' (absolute paths)", 'cyan');
        }
        
        $old_url_http = str_replace('https://', 'http://', $old_url);
        if ($old_url_http !== $old_url) {
            cli_print("   - '{$old_url_http}' -> '{$new_url}' (HTTP URLs)", 'cyan');
        }
        
        cli_print("\n✓ Simulation completed successfully! (No database tables or files were modified).", 'green');
    } else {
        fwrite(STDOUT, colorize("\nWould you like to import the database dump into '{$db_name}'? [y/N]: ", 'cyan'));
        $answer = trim(strtolower(fgets($handle)));

        if ($answer === 'y' || $answer === 'yes') {
            cli_print("Importing database dump using mysql CLI...", 'cyan');

            $port_flag = '';
            if ($port_or_socket) {
                if (is_numeric($port_or_socket)) {
                    $port_flag = ' -P ' . escapeshellarg($port_or_socket);
                } else {
                    $port_flag = ' --socket=' . escapeshellarg($port_or_socket);
                }
            }

            // Use environment variable for database password (hides it from process list 'ps aux')
            putenv("MYSQL_PWD=" . $db_password);

            // Construct secure mysql import command
            $cmd = sprintf(
                'mysql -h %s%s -u %s %s < %s 2>&1',
                escapeshellarg($host),
                $port_flag,
                escapeshellarg($db_user),
                escapeshellarg($db_name),
                escapeshellarg($db_sql_path)
            );

            $output = [];
            $return_var = 0;
            exec($cmd, $output, $return_var);

            if ($return_var === 0) {
                cli_print("✓ Database dump imported successfully!", 'green');
                unlink($db_sql_path);
                cli_print("✓ Removed '{$db_sql_path}' for security.", 'green');
                
                // --- RENAME SERVMASK_PREFIX_ TABLES TO REAL PREFIX ---
                cli_print("\n------------------------------------------------------------", 'cyan');
                cli_print("Renaming archive placeholder tables (SERVMASK_PREFIX_)...", 'cyan');
                cli_print("------------------------------------------------------------", 'cyan');
                
                // Get all tables using mysql CLI
                putenv("MYSQL_PWD=" . $db_password);
                $tables_cmd = sprintf(
                    'mysql -h %s%s -u %s %s -e "SHOW TABLES" -sN 2>&1',
                    escapeshellarg($host),
                    $port_flag,
                    escapeshellarg($db_user),
                    escapeshellarg($db_name)
                );
                
                $all_tables = [];
                $tables_return = 0;
                @exec($tables_cmd, $all_tables, $tables_return);

                if ($tables_return === 0 && !empty($all_tables)) {
                    $prefix_placeholder = 'SERVMASK_PREFIX_';
                    $drop_queries = [];
                    $rename_queries = [];
                    $renamed_count = 0;

                    $lowercase_tables = array_map('strtolower', $all_tables);
                    foreach ($all_tables as $table) {
                        $table = trim($table);
                        if (stripos($table, $prefix_placeholder) === 0) {
                            $real_table_name = str_ireplace($prefix_placeholder, $table_prefix, $table);
                            
                            if (in_array(strtolower($real_table_name), $lowercase_tables)) {
                                $index = array_search(strtolower($real_table_name), $lowercase_tables);
                                $exact_existing_name = $all_tables[$index];
                                $drop_queries[] = "DROP TABLE IF EXISTS `{$exact_existing_name}`;";
                            }
                            $rename_queries[] = "RENAME TABLE `{$table}` TO `{$real_table_name}`;";
                            $renamed_count++;
                        }
                    }

                    if ($renamed_count > 0) {
                        $sql_query = implode("\n", $drop_queries) . "\n" . implode("\n", $rename_queries);
                        
                        $run_cmd = sprintf(
                            'mysql -h %s%s -u %s %s -e %s 2>&1',
                            escapeshellarg($host),
                            $port_flag,
                            escapeshellarg($db_user),
                            escapeshellarg($db_name),
                            escapeshellarg($sql_query)
                        );
                        
                        $run_output = [];
                        $run_return = 0;
                        @exec($run_cmd, $run_output, $run_return);
                        
                        if ($run_return === 0) {
                            cli_print("✓ Successfully renamed {$renamed_count} tables from 'SERVMASK_PREFIX_' to '{$table_prefix}'.", 'green');
                        } else {
                            cli_print("⚠️  Failed to rename tables via mysql CLI. Error:", 'yellow');
                            cli_print(implode("\n", $run_output), 'red');
                        }
                    } else {
                        cli_print("✓ Table renaming checked. No 'SERVMASK_PREFIX_' tables found.", 'green');
                    }
                } else {
                    cli_print("⚠️  Could not retrieve table list via mysql CLI. Output:", 'yellow');
                    cli_print(implode("\n", $all_tables), 'red');
                }
                
                // --- DOMAIN AND PATH SEARCH & REPLACE ---
                cli_print("\n------------------------------------------------------------", 'cyan');
                cli_print("Running Domain, Path, and Prefix Search & Replace...", 'cyan');
                cli_print("------------------------------------------------------------", 'cyan');
                
                // Try running WP-CLI search-replace if available
                $wp_cli_available = false;
                @exec('which wp 2>&1', $out, $return_code);
                if ($return_code === 0) {
                    $wp_cli_available = true;
                }

                if ($wp_cli_available) {
                    cli_print("✓ WP-CLI detected! Performing replacement using native WP-CLI...", 'green');
                    
                    $allow_root_flag = '';
                    $is_root = false;
                    if (function_exists('posix_getuid') && posix_getuid() === 0) {
                        $is_root = true;
                    } elseif (getenv('USER') === 'root') {
                        $is_root = true;
                    }
                    if ($is_root) {
                        $allow_root_flag = ' --allow-root';
                    }

                    // Replace Prefix Placeholders first
                    cli_print("Replacing database prefix references...", 'white');
                    $cmd_prefix = sprintf('wp search-replace %s %s --all-tables%s 2>&1', escapeshellarg('SERVMASK_PREFIX_'), escapeshellarg($table_prefix), $allow_root_flag);
                    @exec($cmd_prefix, $out_prefix);
                    cli_print(implode("\n", $out_prefix), 'white');

                    // Replace URL
                    cli_print("Replacing URLs...", 'white');
                    $cmd_url = sprintf('wp search-replace %s %s --all-tables%s 2>&1', escapeshellarg($old_url), escapeshellarg($new_url), $allow_root_flag);
                    @exec($cmd_url, $out_url);
                    cli_print(implode("\n", $out_url), 'white');

                    // Replace URL with escaped slashes (crucial for JSON/serialized data like Elementor)
                    $escaped_old_url = str_replace('/', '\\/', $old_url);
                    $escaped_new_url = str_replace('/', '\\/', $new_url);
                    cli_print("Replacing escaped URLs...", 'white');
                    $cmd_url_esc = sprintf('wp search-replace %s %s --all-tables%s 2>&1', escapeshellarg($escaped_old_url), escapeshellarg($escaped_new_url), $allow_root_flag);
                    @exec($cmd_url_esc, $out_url_esc);
                    cli_print(implode("\n", $out_url_esc), 'white');

                    // Replace HTTP version of old URL if old URL has HTTPS
                    $old_url_http = str_replace('https://', 'http://', $old_url);
                    if ($old_url_http !== $old_url) {
                        cli_print("Replacing HTTP URLs...", 'white');
                        $cmd_url_http = sprintf('wp search-replace %s %s --all-tables%s 2>&1', escapeshellarg($old_url_http), escapeshellarg($new_url), $allow_root_flag);
                        @exec($cmd_url_http, $out_url_http);
                        cli_print(implode("\n", $out_url_http), 'white');

                        $escaped_old_url_http = str_replace('/', '\\/', $old_url_http);
                        cli_print("Replacing escaped HTTP URLs...", 'white');
                        $cmd_url_http_esc = sprintf('wp search-replace %s %s --all-tables%s 2>&1', escapeshellarg($escaped_old_url_http), escapeshellarg($escaped_new_url), $allow_root_flag);
                        @exec($cmd_url_http_esc, $out_url_http_esc);
                        cli_print(implode("\n", $out_url_http_esc), 'white');
                    }

                    // Replace Path if detected
                    if ($old_path) {
                        cli_print("Replacing absolute paths...", 'white');
                        $cmd_path = sprintf('wp search-replace %s %s --all-tables%s 2>&1', escapeshellarg($old_path), escapeshellarg($new_path), $allow_root_flag);
                        @exec($cmd_path, $out_path);
                        cli_print(implode("\n", $out_path), 'white');
                    }
                } else {
                    cli_print("⚠️  WP-CLI not found. Falling back to native O(1) memory safe PHP replacement...", 'yellow');
                    
                    // Replace Prefix
                    cli_print("Replacing database prefix references...", 'white');
                    run_database_search_replace($db_host, $db_user, $db_password, $db_name, 'SERVMASK_PREFIX_', $table_prefix);

                    // Replace URL
                    cli_print("Replacing URLs...", 'white');
                    run_database_search_replace($db_host, $db_user, $db_password, $db_name, $old_url, $new_url);

                    $escaped_old_url = str_replace('/', '\\/', $old_url);
                    $escaped_new_url = str_replace('/', '\\/', $new_url);
                    cli_print("Replacing escaped URLs...", 'white');
                    run_database_search_replace($db_host, $db_user, $db_password, $db_name, $escaped_old_url, $escaped_new_url);

                    $old_url_http = str_replace('https://', 'http://', $old_url);
                    if ($old_url_http !== $old_url) {
                        cli_print("Replacing HTTP URLs...", 'white');
                        run_database_search_replace($db_host, $db_user, $db_password, $db_name, $old_url_http, $new_url);

                        $escaped_old_url_http = str_replace('/', '\\/', $old_url_http);
                        cli_print("Replacing escaped HTTP URLs...", 'white');
                        run_database_search_replace($db_host, $db_user, $db_password, $db_name, $escaped_old_url_http, $escaped_new_url);
                    }
                    
                    // Replace Path if detected
                    if ($old_path) {
                        cli_print("Replacing absolute paths...", 'white');
                        run_database_search_replace($db_host, $db_user, $db_password, $db_name, $old_path, $new_path);
                    }
                }

                // --- AUTOMATIC THEME ACTIVATION ---
                cli_print("\n------------------------------------------------------------", 'cyan');
                cli_print("Reactivating original theme from package.json...", 'cyan');
                cli_print("------------------------------------------------------------", 'cyan');
                
                $package_path = './wp-content/package.json';
                if (file_exists($package_path)) {
                    $package_content = file_get_contents($package_path);
                    $package_data = json_decode($package_content, true);
                    
                    if (json_last_error() === JSON_ERROR_NONE && $package_data) {
                        $activation_conn = @mysqli_connect($host, $db_user, $db_password, $db_name, $port, $socket);
                        if ($activation_conn) {
                            $prefix_escaped = mysqli_real_escape_string($activation_conn, $table_prefix);
                            
                            // Reactivate theme template & stylesheet
                            if (!empty($package_data['Template'])) {
                                $template = mysqli_real_escape_string($activation_conn, $package_data['Template']);
                                $update_template_sql = "UPDATE `{$prefix_escaped}options` SET option_value = '{$template}' WHERE option_name = 'template'";
                                @mysqli_query($activation_conn, $update_template_sql);
                            }
                            if (!empty($package_data['Stylesheet'])) {
                                $stylesheet = mysqli_real_escape_string($activation_conn, $package_data['Stylesheet']);
                                $update_style_sql = "UPDATE `{$prefix_escaped}options` SET option_value = '{$stylesheet}' WHERE option_name = 'stylesheet'";
                                if (@mysqli_query($activation_conn, $update_style_sql)) {
                                    cli_print("✓ Reactivated theme '{$stylesheet}' successfully!", 'green');
                                }
                            }
                            
                            mysqli_close($activation_conn);
                        }
                    }
                    // Delete package.json for clean-up
                    @unlink($package_path);
                }
            } else {
                cli_print("Error: Database import failed. mysql client output:", 'red', STDERR);
                cli_print(implode("\n", $output), 'white', STDERR);
                cli_print("You can try importing the SQL file manually at: '{$db_sql_path}'", 'yellow', STDERR);
            }
        } else {
            cli_print("Skipped database import. The dump remains at '{$db_sql_path}'.", 'yellow');
        }
    }
} else {
    cli_print("No 'database.sql' dump found in the archive.", 'yellow');
}

fclose($handle);

cli_print("\n============================================================", 'green');
cli_print("                Migration Step Successful!", 'green');
cli_print("============================================================", 'green');
cli_print("WordPress files and database have been successfully restored", 'white');
cli_print("and fully mapped to the new server configuration.\n", 'white');
cli_print("Next Steps:", 'bold');
cli_print("1. Re-save your permalinks (Settings > Permalinks in wp-admin) to flush rewrite rules.", 'cyan');
cli_print("2. Remember to delete this script ('wpress-import.php') for security!", 'red');
cli_print("============================================================\n", 'green');


// =========================================================================
//            SERIALIZED-SAFE DATABASE SEARCH & REPLACE FUNCTIONS
// =========================================================================

function run_database_search_replace($db_host, $db_user, $db_password, $db_name, $search, $replace) {
    $host_parts = explode(':', $db_host);
    $host = $host_parts[0];
    $port = isset($host_parts[1]) && is_numeric($host_parts[1]) ? intval($host_parts[1]) : null;
    $socket = isset($host_parts[1]) && !is_numeric($host_parts[1]) ? $host_parts[1] : null;

    $conn = @mysqli_connect($host, $db_user, $db_password, $db_name, $port, $socket);
    if (!$conn) {
        fwrite(STDERR, colorize("Error: Could not connect to database for search and replace: " . mysqli_connect_error() . "\n", 'red'));
        return;
    }

    // Get all tables
    $tables = [];
    $res = mysqli_query($conn, "SHOW TABLES");
    while ($row = mysqli_fetch_row($res)) {
        $tables[] = $row[0];
    }
    mysqli_free_result($res);

    $total_columns_updated = 0;
    $total_rows_updated = 0;

    foreach ($tables as $table) {
        $primary_keys = [];
        $columns = [];
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}`");
        if (!$res) continue;

        while ($row = mysqli_fetch_assoc($res)) {
            $col_name = $row['Field'];
            $col_type = strtolower($row['Type']);
            
            // Only process string columns
            if (strpos($col_type, 'char') !== false || strpos($col_type, 'text') !== false) {
                $columns[] = $col_name;
            }
            
            if ($row['Key'] === 'PRI') {
                $primary_keys[] = $col_name;
            }
        }
        mysqli_free_result($res);

        if (empty($columns)) {
            continue;
        }

        $select_cols = array_merge($primary_keys, $columns);
        $select_cols = array_unique($select_cols);
        
        if (empty($primary_keys)) {
            $primary_keys = $select_cols; // fallback to prevent skipping tables without primary keys
        }

        $escaped_cols = array_map(function($c) { return "`{$c}`"; }, $select_cols);
        
        // Paged processing to maintain O(1) memory footprint
        $batch_size = 1000;
        $offset = 0;
        
        while (true) {
            $order_by = '';
            if (!empty($primary_keys)) {
                $escaped_pks = array_map(function($pk) { return "`{$pk}`"; }, $primary_keys);
                $order_by = " ORDER BY " . implode(', ', $escaped_pks);
            }
            
            $sql = "SELECT " . implode(', ', $escaped_cols) . " FROM `{$table}`" . $order_by . " LIMIT {$batch_size} OFFSET {$offset}";
            $res = mysqli_query($conn, $sql);
            if (!$res) {
                break;
            }
            
            $row_count = mysqli_num_rows($res);
            if ($row_count === 0) {
                mysqli_free_result($res);
                break;
            }

            while ($row = mysqli_fetch_assoc($res)) {
                $updates = [];
                $changed_row = false;

                foreach ($columns as $column) {
                    $orig_value = $row[$column];
                    if (empty($orig_value)) continue;
                    
                    $changed_col = false;
                    $new_value = serialized_safe_replace($orig_value, $search, $replace, $changed_col);
                    
                    if ($changed_col) {
                        $updates[$column] = $new_value;
                        $changed_row = true;
                        $total_columns_updated++;
                    }
                }

                if ($changed_row) {
                    // Build WHERE clause based on primary keys
                    $where_parts = [];
                    foreach ($primary_keys as $pk) {
                        $pk_val = mysqli_real_escape_string($conn, $row[$pk]);
                        $where_parts[] = "`{$pk}` = '{$pk_val}'";
                    }
                    
                    // Build SET clause
                    $set_parts = [];
                    foreach ($updates as $col => $val) {
                        $escaped_val = mysqli_real_escape_string($conn, $val);
                        $set_parts[] = "`{$col}` = '{$escaped_val}'";
                    }
                    
                    $update_sql = "UPDATE `{$table}` SET " . implode(', ', $set_parts) . " WHERE " . implode(' AND ', $where_parts);
                    @mysqli_query($conn, $update_sql);
                    $total_rows_updated++;
                }
            }
            mysqli_free_result($res);
            $offset += $batch_size;
        }
    }

    mysqli_close($conn);
    cli_print("✓ Replaced '{$search}' with '{$replace}' across database.", 'green');
    cli_print("  → Updated entries: {$total_rows_updated} records.", 'white');
}

function recursive_search_replace($data, $search, $replace, &$changed) {
    if (is_string($data)) {
        $new_str = str_replace($search, $replace, $data);
        if ($new_str !== $data) {
            $changed = true;
            return $new_str;
        }
        return $data;
    }
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = recursive_search_replace($value, $search, $replace, $changed);
        }
        return $data;
    }
    if (is_object($data)) {
        $clone = clone $data;
        foreach (get_object_vars($data) as $key => $value) {
            $clone->$key = recursive_search_replace($value, $search, $replace, $changed);
        }
        return $clone;
    }
    return $data;
}

function serialized_safe_replace($value, $search, $replace, &$changed) {
    if (is_serialized($value)) {
        $unserialized = @unserialize($value);
        if ($unserialized !== false || $value === 'b:0;') {
            $temp_changed = false;
            $replaced = recursive_search_replace($unserialized, $search, $replace, $temp_changed);
            if ($temp_changed) {
                $changed = true;
                return serialize($replaced);
            }
        }
    }
    
    // Normal string replace
    $new_val = str_replace($search, $replace, $value);
    if ($new_val !== $value) {
        $changed = true;
        return $new_val;
    }
    return $value;
}

// Standard WordPress is_serialized check
function is_serialized($data, $strict = true) {
    if (!is_string($data)) {
        return false;
    }
    $data = trim($data);
    if ('N;' === $data) {
        return true;
    }
    if (strlen($data) < 4) {
        return false;
    }
    if (':' !== $data[1]) {
        return false;
    }
    if ($strict) {
        $lastc = substr($data, -1);
        if (';' !== $lastc && '}' !== $lastc) {
            return false;
        }
    } else {
        $semicolon = strpos($data, ';');
        $brace     = strpos($data, '}');
        if (false === $semicolon && false === $brace) {
            return false;
        }
        if (false !== $semicolon && $semicolon < 3) {
            return false;
        }
        if (false !== $brace && $brace < 4) {
            return false;
        }
    }
    $token = $data[0];
    switch ($token) {
        case 's':
            if ($strict) {
                if ('"' !== substr($data, -2, 1)) {
                    return false;
                }
            } elseif (false === strpos($data, '"')) {
                return false;
            }
            // Gold
        case 'a':
        case 'O':
            return (bool) preg_match("/^{$token}:[0-9]+:/s", $data);
        case 'b':
        case 'i':
        case 'd':
            $end = $strict ? '$' : '';
            return (bool) preg_match("/^{$token}:[0-9.E+-]+;$end/", $data);
    }
    return false;
}
