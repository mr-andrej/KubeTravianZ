<?php

#################################################################################
##  KubeTravianZ - account seeding for the CLI installer                       ##
## --------------------------------------------------------------------------- ##
##  Adapted from install/include/accounts.php (wizard step 4) so the K8s       ##
##  image does not need to ship the install/ wizard at all.                    ##
##  Differences: no $_POST parsing (caller populates $tzInstallAccounts),      ##
##  no header() redirects, no config.php rewriting (config is rendered by      ##
##  Helm). The village-assignment logic (including the Support spiral          ##
##  search fix) is unchanged.                                                  ##
##                                                                             ##
##  Expects: cwd = repo root, GameEngine/Database.php + Admin/database.php     ##
##  already included (globals $database, $admin), LANG defined.                ##
##  Caller sets $tzInstallAccounts = [mhpw, spw, aname, aemail, apass, atribe]##
#################################################################################

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$mhpw   = $tzInstallAccounts['mhpw'];
$spw    = $tzInstallAccounts['spw'];
$aname  = $tzInstallAccounts['aname'];
$aemail = $tzInstallAccounts['aemail'];
$apass  = $tzInstallAccounts['apass'];
$atribe = (int) $tzInstallAccounts['atribe'];

if ($mhpw === '' || $spw === '') {
    fwrite(STDERR, "install_accounts: Multihunter and Support passwords are required\n");
    exit(1);
}
if (strtolower($aname) === 'natars') {
    fwrite(STDERR, "install_accounts: 'Natars' is a reserved admin name\n");
    exit(1);
}

global $database, $admin;

// create Admin user, if details were provided
if (
    $aname !== '' &&
    $aemail !== '' &&
    $apass !== '' &&
    $atribe > 0 &&
    strtolower($aname) !== 'multihunter' &&
    strtolower($aname) !== 'support'
) {
    mysqli_query($database->dblink, "INSERT INTO " . TB_PREFIX . "users SET username = '".$database->escape($aname)."', password = '" . password_hash($apass, PASSWORD_BCRYPT, ['cost' => 12]) . "', email = '".$database->escape($aemail)."', tribe = ".$atribe.", access = 9, is_bcrypt = 1, desc1 = '[#MH]\n[#TEAM]', desc2 = '[#MULTIHUNTER]\n[#roman]'") OR DIE (mysqli_error($database->dblink));
    $uid = mysqli_insert_id($database->dblink);
    $admin_village_created = false;
    $xcoor = round(WORLD_MAX / 2);
    $addUnitsWrefs = [];
    $addTechWrefs = [];
    $addABTechWrefs = [];

    while (!$admin_village_created) {
        $wid = $admin->getWref($xcoor++, round(WORLD_MAX / 2));
        $status = $database->getVillageState($wid);
        if($status == 0) {
            $database->setFieldTaken($wid);
            $database->addVillage($wid, $uid, $aname, 1);

            // Admin village always gets the balanced 4-4-4-6 layout (type 3),
            // not a random tile type.
            $database->addResourceFields($wid, 3);
            $addUnitsWrefs[] = $wid;
            $addTechWrefs[] = $wid;
            $addABTechWrefs[] = $wid;
            $admin_village_created = true;
        }
    }

    $database->addUnits($addUnitsWrefs);
    $database->addTech($addTechWrefs);
    $database->addABTech($addABTechWrefs);
}

// set up MultiHunter
mysqli_query($database->dblink, "UPDATE " . TB_PREFIX . "users SET password = '" . password_hash($mhpw, PASSWORD_BCRYPT,['cost' => 12]) . "', desc1 = '[#MH]', desc2 = '[#MULTIHUNTER]' WHERE username = 'Multihunter'");
$wid = $admin->getWref(0, 0);
$uid = 5;
$status = $database->getVillageState($wid);
if($status == 0) {
    $database->setFieldTaken($wid);
    $database->addVillage($wid, $uid, 'Multihunter', 1);
    $database->addResourceFields($wid, $database->getVillageType($wid, false));
    $database->addUnits($wid);
    $database->addTech($wid);
    $database->addABTech($wid);
}

// set up Support
mysqli_query($database->dblink, "UPDATE " . TB_PREFIX . "users SET password = '" . password_hash($spw, PASSWORD_BCRYPT,['cost' => 12]) . "' WHERE username = 'Support'");

// Support village: spiral search around (0,0) for a free non-oasis tile.
// Ring 0 is Multihunter's, so start at ring 1. One SQL query per ring
// against fresh DB state (see the bug history in install/include/accounts.php).
$supportRow = mysqli_fetch_assoc(mysqli_query($database->dblink,
    "SELECT id FROM " . TB_PREFIX . "users WHERE username = 'Support' LIMIT 1"));

if ($supportRow) {
    $supportUid = (int) $supportRow['id'];
    $supportHas = mysqli_fetch_assoc(mysqli_query($database->dblink,
        "SELECT COUNT(*) AS total FROM " . TB_PREFIX . "vdata WHERE owner = " . $supportUid));

    if (!$supportHas || (int) $supportHas['total'] === 0) {
        $supportWid = 0;

        for ($radius = 1; $radius <= 20 && $supportWid === 0; $radius++) {
            $ringWids = [];

            for ($dx = -$radius; $dx <= $radius; $dx++) {
                for ($dy = -$radius; $dy <= $radius; $dy++) {
                    // only the current ring's edge (interior was checked before)
                    if (max(abs($dx), abs($dy)) != $radius) {
                        continue;
                    }

                    $ringWids[] = ((int) WORLD_MAX - $dy) * ((int) WORLD_MAX * 2 + 1) + ((int) WORLD_MAX + $dx + 1);
                }
            }

            $supportWid = (int) $database->getFreeVillage($ringWids);
        }

        if ($supportWid > 0) {
            $database->setFieldTaken($supportWid);
            $database->addVillage($supportWid, $supportUid, 'Support', 1);
            $database->addResourceFields($supportWid, 3);
            $database->addUnits($supportWid);
            $database->addTech($supportWid);
            $database->addABTech($supportWid);
        } else {
            fwrite(STDERR, "install_accounts: no free tile for the Support village within radius 20\n");
            exit(1);
        }
    }
}
