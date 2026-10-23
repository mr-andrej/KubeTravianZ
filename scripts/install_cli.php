<?php

#################################################################################
##  KubeTravianZ - headless CLI installer                                      ##
## --------------------------------------------------------------------------- ##
##  Idempotent database bootstrap for containers and Kubernetes init Jobs.     ##
##  Replaces the browser wizard (install/) in environments where config.php    ##
##  is rendered ahead of time (ConfigMap) and no human clicks through forms.   ##
##                                                                             ##
##  Usage:                                                                     ##
##    php scripts/install_cli.php                                              ##
##                                                                             ##
##  Environment:                                                               ##
##    TZ_DB_WAIT_SECONDS   max seconds to wait for MariaDB (default 120)       ##
##    TZ_ADMIN_NAME        admin username  (default: admin)                    ##
##    TZ_ADMIN_EMAIL       admin email     (default: admin@example.com)        ##
##    TZ_ADMIN_PASSWORD    admin password  (required for admin creation)       ##
##    TZ_ADMIN_TRIBE       1|2|3|6|7|8|9   (default: 1, Romans)                ##
##    TZ_MH_PASSWORD       Multihunter password (required on fresh install)    ##
##    TZ_SUPPORT_PASSWORD  Support password     (required on fresh install)    ##
##                                                                             ##
##  Exit codes: 0 = installed (or already installed), 1 = failure.             ##
#################################################################################

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

@set_time_limit(0);
chdir(dirname(__DIR__)); // repo root: makes $autoprefix resolution work

require_once 'GameEngine/config.php';

// ---------------------------------------------------------------------------
// 1. Wait for the database (MariaDB pod may still be starting in K8s)
// ---------------------------------------------------------------------------
$waitMax   = (int) (getenv('TZ_DB_WAIT_SECONDS') ?: 120);
$waitStart = time();
$link      = null;

while (true) {
    $link = @mysqli_connect(SQL_SERVER, SQL_USER, SQL_PASS, SQL_DB,
        defined('SQL_PORT') ? (int) SQL_PORT : 3306);
    if ($link) {
        break;
    }
    if (time() - $waitStart > $waitMax) {
        fwrite(STDERR, "install_cli: database not reachable after {$waitMax}s\n");
        exit(1);
    }
    echo "install_cli: waiting for database...\n";
    sleep(3);
}
mysqli_close($link);

// ---------------------------------------------------------------------------
// 2. Idempotency: if the users table exists and has rows, we are done
// ---------------------------------------------------------------------------
include_once 'GameEngine/Database.php'; // provides global $database
global $database;

try {
    $rows = $database->query_return('SELECT id FROM ' . TB_PREFIX . 'users LIMIT 1');
    if ($rows && count($rows)) {
        echo "install_cli: database already installed (" . TB_PREFIX . "users present), nothing to do\n";
        exit(0);
    }
} catch (Throwable $e) {
    // table missing: fresh install, continue
}

// ---------------------------------------------------------------------------
// 3. Fresh install: structure, world data, croppers
// ---------------------------------------------------------------------------
include_once 'GameEngine/Admin/database.php'; // provides global $admin
require_once 'GameEngine/Lang/loader.php';
tz_load_language(LANG);

echo "install_cli: creating database structure (prefix " . TB_PREFIX . ")...\n";
$result = $database->createDbStructure();
if ($result === -1) {
    fwrite(STDERR, "install_cli: createDbStructure failed (-1)\n");
    exit(1);
}
if ($result === false) {
    echo "install_cli: structure already present, skipping\n";
}

// multi_query() inside those steps leaves trailing result sets on the
// connection; the wizard never noticed because every step was a fresh HTTP
// process. Reconnect between steps to get the same clean state in one CLI run.
$freshDb = function () {
    return new MYSQLi_DB(SQL_SERVER, SQL_USER, SQL_PASS, SQL_DB,
        defined('SQL_PORT') ? (int) SQL_PORT : 3306);
};
$database = $freshDb();
$GLOBALS['db'] = $database;
$GLOBALS['link'] = $database->return_link();

echo "install_cli: populating world data (world size " . WORLD_MAX . ")...\n";
$result = $database->populateWorldData();
if ($result === -1) {
    fwrite(STDERR, "install_cli: populateWorldData failed (-1)\n");
    exit(1);
}
if ($result === false) {
    echo "install_cli: world data already present, skipping\n";
}

$database = $freshDb();
$GLOBALS['db'] = $database;
$GLOBALS['link'] = $database->return_link();

$total = $database->TotalCroppers();
echo "install_cli: building croppers ($total tiles)...\n";
$out = $database->populateCroppers($total, true, 2000,
    function ($done, $target, $pct) {
        static $last = -1;
        $pct = (int) $pct;
        if ($pct !== $last && $pct % 10 === 0) {
            echo "install_cli: croppers $pct% ($done/$target)\n";
            $last = $pct;
        }
    });
if (empty($out['ok'])) {
    fwrite(STDERR, "install_cli: populateCroppers failed: " . ($out['msg'] ?? 'unknown') . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 4. Accounts: reuse the wizard's account step verbatim (it carries several
//    historical bug fixes: Support village spiral search, bcrypt hashing, ...).
//    In CLI, header() calls inside are harmless no-ops.
// ---------------------------------------------------------------------------
$mhPw = getenv('TZ_MH_PASSWORD');
$spw  = getenv('TZ_SUPPORT_PASSWORD');
if (!$mhPw || !$spw) {
    fwrite(STDERR, "install_cli: TZ_MH_PASSWORD and TZ_SUPPORT_PASSWORD are required on fresh install\n");
    exit(1);
}

$_POST = [
    'mhpw'               => $mhPw,
    'spw'                => $spw,
    'aname'              => getenv('TZ_ADMIN_NAME') ?: 'admin',
    'aemail'             => getenv('TZ_ADMIN_EMAIL') ?: 'admin@example.com',
    'apass'              => getenv('TZ_ADMIN_PASSWORD') ?: '',
    'atribe'             => getenv('TZ_ADMIN_TRIBE') ?: '1',
    'admin_rank'         => 'false',
    'admin_support_msgs' => 'true',
    'admin_raidable'     => 'true',
];

echo "install_cli: seeding accounts (Multihunter, Support, admin '{$_POST['aname']}')...\n";
$database = $freshDb();
$GLOBALS['db'] = $database;
$GLOBALS['link'] = $database->return_link();
// accounts.php uses paths relative to its own directory (../../GameEngine/...),
// which mod_php provided as cwd; replicate that here.
// ob_start keeps the trailing header("Location: ...") in accounts.php from
// warning about headers already sent (it is a no-op in CLI anyway).
ob_start();
chdir('install/include');
include 'accounts.php';
chdir(dirname(__DIR__));
ob_end_clean();

echo "install_cli: done\n";
exit(0);
