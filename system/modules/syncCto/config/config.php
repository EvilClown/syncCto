<?php
if (!defined('TL_ROOT')) die('You can not access this file directly!');

/**
 * Contao Open Source CMS
 * Copyright (C) 2005-2010 Leo Feyer
 *
 * Formerly known as TYPOlight Open Source CMS.
 *
 * This program is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public
 * License along with this program. If not, please visit the Free
 * Software Foundation website at <http://www.gnu.org/licenses/>.
 *
 * PHP version 5
 * @copyright  MEN AT WORK 2011 
 * @package    syncCto
 * @license    GNU/LGPL 
 * @filesource
 */
$objInput = Input::getInstance();

/**
 * Current syncCto version
 */
$GLOBALS['SYC_VERSION'] = '2.0.0';

/**
 * Back end modules
 */
$i = array_search('profile', array_keys($GLOBALS['BE_MOD']));
$GLOBALS['BE_MOD'] = array_merge(
        array_slice($GLOBALS['BE_MOD'], 0, $i), array(
    'syncCto' => array(
        'syncCto_settings' => array(
            'tables' => array('tl_syncCto_settings'),
            'icon' => 'system/modules/syncCto/html/iconSettings.png'
        ),
        'synccto_clients' => array(
            'tables' => array('tl_synccto_clients', 'tl_syncCto_clients_syncTo', 'tl_syncCto_clients_syncFrom'),
            'icon' => 'system/modules/syncCto/html/iconClients.png',
            'callback' => 'SyncCtoModuleClient',
        ),
        'syncCto_backups' => array(
            'tables' => array('tl_syncCto_backup_file', 'tl_syncCto_backup_db', 'tl_syncCto_restore_file', 'tl_syncCto_restore_db'),
            'icon' => 'system/modules/syncCto/html/iconBackups.png',
            'callback' => 'SyncCtoModuleBackup',
        ),
        'syncCto_stats' => array(
            // 'tables' => array('tl_syncCto_settings'),
            'icon' => 'system/modules/syncCto/html/iconStats.png'
        ),
        'syncCto_check' => array(
            'icon' => 'system/modules/syncCto/html/iconCheck.png',
            'callback' => 'SyncCtoModuleCheck',
        )
    )
        ), array_slice($GLOBALS['BE_MOD'], $i)
);

// Backup
define("SYNCCTO_SMALL", 1);
define("SYNCCTO_FULL", 2);

$GLOBALS['SYC_BACKUP'] = array_merge_recursive(array(
    'backup' => array(
        'option_small' => SYNCCTO_SMALL,
        'option_full' => SYNCCTO_FULL,
    ),
        ), is_array($GLOBALS['SYC_BACKUP']) ? $GLOBALS['SYC_BACKUP'] : array()
);

$GLOBALS['SYC_SYNC'] = array_merge_recursive(array(
    'files' => array(
        'option_small' => SYNCCTO_SMALL,
        'option_full' => SYNCCTO_FULL,
    ),
        ), is_array($GLOBALS['SYC_SYNC']) ? $GLOBALS['SYC_SYNC'] : array()
);

/**
 * Hooks
 */
$GLOBALS['TL_HOOKS']['executePreActions'][] = array('tl_synccto_clients', 'pingClientStatus');
$GLOBALS['TL_HOOKS']['parseBackendTemplate'][] = array('SyncCtoCallback', 'checkExtensions');

/**
 * Permissions
 */
$GLOBALS['TL_PERMISSIONS'][] = 'syncCto_clients';
$GLOBALS['TL_PERMISSIONS'][] = 'syncCto_clients_p';
$GLOBALS['TL_PERMISSIONS'][] = 'syncCto_tables';

/**
 * Callbacks are only used for overview screen
 */
if ($objInput->get("do") == 'syncCto_backups' && $objInput->get("table") != '' && ($objInput->get("act") == '' || $objInput->get("act") == 'edit'))
{
    unset($GLOBALS['BE_MOD']['syncCto']['syncCto_backups']['callback']);
}

if ($objInput->get("do") == 'synccto_clients' && ($objInput->get("table") == 'tl_syncCto_clients_syncTo' || $objInput->get("table") == 'tl_syncCto_clients_syncFrom' || $objInput->get("table") == '' ) && $objInput->get("act") != 'start')
{
    unset($GLOBALS['BE_MOD']['syncCto']['synccto_clients']['callback']);
}

// Size limit for files in bytes, will be checked
$GLOBALS['SYC_SIZE']['limit'] = 104857600;
// Size limit for files in bytes, completely ignored
$GLOBALS['SYC_SIZE']['limit_ignore'] = 209715200;

/**
 * Blacklists
 */
// Tables
$GLOBALS['SYC_CONFIG']['table_hidden'] = array(
    'tl_log',
    'tl_session',
    'tl_undo',
);

// Folders
$GLOBALS['SYC_CONFIG']['folder_blacklist'] = array(
    'system/tmp*',
    'system/htm*',
    'system/logs*',
    '*/syncCto_backups*',
);

// Files only Sync.
$GLOBALS['SYC_CONFIG']['file_blacklist'] = array(
    '*.htaccess',
    '*/localconfig.php',
);

// Folders
$GLOBALS['SYC_CONFIG']['local_blacklist'] = array(
    'websitePath',
    'installPassword',
    'encryptionKey',
    'dbDriver',
    'dbHost',
    'dbUser',
    'dbPass',
    'dbDatabase',
    'dbPconnect',
    'dbCharset',
    'dbPort',
    'ctoCom_APIKey',
);

/**
 * Whitelist
 */
// Folders
$GLOBALS['SYC_CONFIG']['folder_whitelist'] = array(
    'contao',
    'plugins',
    'system',
    'templates',
    'typolight',
);

/**
 * Global configuration
 */
// Folder path configuration
$GLOBALS['SYC_PATH']['db'] = $GLOBALS['TL_CONFIG']['uploadPath'] . '/syncCto_backups/database/';
$GLOBALS['SYC_PATH']['file'] = $GLOBALS['TL_CONFIG']['uploadPath'] . '/syncCto_backups/files/';
$GLOBALS['SYC_PATH']['debug'] = $GLOBALS['TL_CONFIG']['uploadPath'] . '/syncCto_backups/debug/';
$GLOBALS['SYC_PATH']['tmp'] = "system/tmp/";

// Timestamp for files
$GLOBALS['SYC_CONFIG']['format'] = "Ymd_H-i-s";

/**
 * CSS & JS Files
 */
if ($objInput->get("table") == 'tl_syncCto_clients_syncTo' || $objInput->get("table") == 'tl_syncCto_clients_syncFrom')
{
    $GLOBALS['TL_CSS'][] = 'system/modules/syncCto/html/css/filelist_src.css';
    $GLOBALS['TL_JAVASCRIPT'][] = 'system/modules/syncCto/html/js/htmltable.js';
    $GLOBALS['TL_JAVASCRIPT'][] = 'system/modules/syncCto/html/js/filelist_src.js';
}

if ($objInput->get("do") == 'syncCto_check')
{
    $GLOBALS['TL_CSS'][] = 'system/modules/syncCto/html/css/systemcheck_src.css';
}

if ((($objInput->get("do") == 'synccto_clients') && $objInput->get("act") == '') && $objInput->get("table") == '')
{
    $GLOBALS['TL_JAVASCRIPT'][] = 'system/modules/syncCto/html/js/ping_src.js';
}

/**
 * CtoCommunication RPC Calls
 */
$GLOBALS["CTOCOM_FUNCTIONS"]["SYNCCTO_VERSION"] = array(
    "class" => "SyncCtoRPCFunctions",
    "function" => "getVersionSyncCto",
    "typ" => "GET",
    "parameter" => FALSE,
);

$GLOBALS["CTOCOM_FUNCTIONS"]["SYNCCTO_PARAMETER"] = array(
    "class" => "SyncCtoRPCFunctions",
    "function" => "getClientParameter",
    "typ" => "GET",
    "parameter" => FALSE,
);

$GLOBALS["CTOCOM_FUNCTIONS"]["SYNCCTO_FILEBACKUP"] = array(
    "class" => "SyncCtoFiles",
    "function" => "runDump",
    "typ" => "GET",
    "parameter" => FALSE,
);

$GLOBALS["CTOCOM_FUNCTIONS"]["SYNCCTO_CHECKSUM_COMPARE"] = array(
    "class" => "SyncCtoFiles",
    "function" => "runCecksumCompare",
    "typ" => "POST",
    "parameter" => array("checksumlist"),
);

$GLOBALS["CTOCOM_FUNCTIONS"]["SYNCCTO_CHECKSUM_CORE"] = array(
    "class" => "SyncCtoFiles",
    "function" => "runChecksumCore",
    "typ" => "GET",
    "parameter" => FALSE,
);

$GLOBALS["CTOCOM_FUNCTIONS"]["SYNCCTO_CHECKSUM_TLFILES"] = array(
    "class" => "SyncCtoFiles",
    "function" => "runChecksumTlFiles",
    "typ" => "POST",
    "parameter" => array("fileList"),
);

$GLOBALS["CTOCOM_FUNCTIONS"]["SYNCCTO_PURGETEMP"] = array(
    "class" => "SyncCtoFiles",
    "function" => "purgeTemp",
    "typ" => "GET",
    "parameter" => FALSE,
);
?>