<?php
// db/migrate.php — tiny numbered-SQL-file migration runner. Usage: php db/migrate.php
if (php_sapi_name() !== 'cli') {
    die("Run this from the CLI: php db/migrate.php\n");
}

require_once __DIR__ . '/../src/core/config.php';

$conn->query("CREATE TABLE IF NOT EXISTS schema_migrations (
    filename VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

// The live DB predates this migration tool. If nothing has been tracked yet
// and the core schema already exists, the baseline describes reality rather
// than something to execute — record it as applied instead of running it.
$trackingEmpty = $conn->query("SELECT COUNT(*) c FROM schema_migrations")->fetch_assoc()['c'] == 0;
$usersExists = $conn->query("SHOW TABLES LIKE 'users'")->num_rows > 0;
if ($trackingEmpty && $usersExists) {
    $conn->query("INSERT INTO schema_migrations (filename) VALUES ('000_schema_baseline.sql')");
    echo "Existing schema detected — marked 000_schema_baseline.sql as applied without running it.\n";
}

$applied = [];
$res = $conn->query("SELECT filename FROM schema_migrations");
while ($row = $res->fetch_assoc()) { $applied[$row['filename']] = true; }

$files = glob(__DIR__ . '/migrations/*.sql');
sort($files);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) continue;

    echo "Applying $name...\n";
    $sql = file_get_contents($file);

    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) { $result->free(); }
        } while ($conn->more_results() && $conn->next_result());
    }
    if ($conn->errno) {
        die("Migration failed ($name): {$conn->error}\n");
    }

    $stmt = $conn->prepare("INSERT INTO schema_migrations (filename) VALUES (?)");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    echo "Applied $name\n";
    $ran++;
}

echo $ran > 0 ? "Done — $ran migration(s) applied.\n" : "Nothing to do — schema is up to date.\n";
