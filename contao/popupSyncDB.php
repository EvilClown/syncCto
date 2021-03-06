<?php

/**
 * Contao Open Source CMS
 *
 * @copyright  MEN AT WORK 2013 
 * @package    syncCto
 * @license    GNU/LGPL 
 * @filesource
 */

/**
 * Initialize the system
 */
define('TL_MODE', 'BE');
require_once('../system/initialize.php');

/**
 * Class SyncCtoPopup
 */
class popupSyncDB extends Backend
{

    // Vars
    protected $intClientID;
    protected $strMode;
    // Helper Classes
    protected $objSyncCtoHelper;
    // Temp data
    protected $arrSyncSettings = array();
    protected $arrErrors = array();

    // defines
    const STEP_NORMAL_DB = 'nd';
    const STEP_CLOSE_DB  = 'cl';
    const STEP_ERROR_DB  = 'er';

    
    /**
     * Initialize the object
     */
    public function __construct()
    {   
        // Imports
        $this->import('Input');
        $this->import('BackendUser', 'User');

        parent::__construct();

        // Check user auth
        $this->User->authenticate();

        // Set language from get or user
        if ($this->Input->get('language') != '')
        {
            $GLOBALS['TL_LANGUAGE'] = $this->Input->get('language');
        }
        else
        {
            $GLOBALS['TL_LANGUAGE'] = $this->User->language;
        }

        // Load language
        $this->loadLanguageFile('default');
        $this->loadLanguageFile("modules");
        $this->loadLanguageFile("tl_syncCto_database");

        $this->objSyncCtoHelper = SyncCtoHelper::getInstance();

        $this->initGetParams();
    }

    /**
     * Load the template list and go through the steps
     */
    public function run()
    {
        if ($this->mixStep == self::STEP_NORMAL_DB)
        {
            // Set client for communication
            try
            {
                $this->loadSyncSettings();

                $this->showNormalDatabase();

                $this->saveSyncSettings();

                unset($_POST);
            }
            catch (Exception $exc)
            {
                $this->arrErrors[] = $exc->getMessage();
                $this->mixStep     = self::STEP_ERROR_DB;
            }
        }

        if ($this->mixStep == self::STEP_CLOSE_DB)
        {
            $this->showClose();
        }

        if ($this->mixStep == self::STEP_ERROR_DB)
        {
            $this->showError();
        }

        // Output template
        $this->output();
    }

    /**
     * Show database server and client compare list
     */
    public function showNormalDatabase()
    {
        // Delete functinality
        if (key_exists("delete", $_POST))
        {
            // Make a array from 'serverTables' and 'serverDeleteTables'
            $arrRemoveTables = array();
            
            if (is_array($this->Input->post('serverTables')) && count($this->Input->post('serverTables')) != 0)
            {
                $arrRemoveTables = $this->Input->post('serverTables');
            }
            
            if (is_array($this->Input->post('serverDeleteTables')) && count($this->Input->post('serverDeleteTables')) != 0)
            {
                $arrRemoveTables = array_merge($arrRemoveTables, $this->Input->post('serverDeleteTables'));
            }
            
            // Remove tables from the list.
            foreach ($arrRemoveTables as $value)
            {
                if (isset($this->arrSyncSettings['syncCto_CompareTables']['recommended']) && key_exists($value, $this->arrSyncSettings['syncCto_CompareTables']['recommended']))
                {
                    unset($this->arrSyncSettings['syncCto_CompareTables']['recommended'][$value]);
                }
                else if (isset($this->arrSyncSettings['syncCto_CompareTables']['nonRecommended']) && key_exists($value, $this->arrSyncSettings['syncCto_CompareTables']['nonRecommended']))
                {
                    unset($this->arrSyncSettings['syncCto_CompareTables']['nonRecommended'][$value]);
                }
            }
        }
        // Close functinality
        else if (key_exists("transfer", $_POST))
        {
            foreach ($this->arrSyncSettings['syncCto_CompareTables'] as $arrType)
            {
                foreach ($arrType as $keyTable => $valueTable)
                {
                    if ($valueTable['del'] == true)
                    {
                        $this->arrSyncSettings['syncCto_SyncDeleteTables'][] = $keyTable;
                    }
                    else
                    {
                        $this->arrSyncSettings['syncCto_SyncTables'][] = $keyTable;
                    }
                }
            }

            unset($this->arrSyncSettings['syncCto_CompareTables']);

            $this->mixStep = self::STEP_CLOSE_DB;
            return;
        }

        // If no table is found skip the view
        if (count($this->arrSyncSettings['syncCto_CompareTables']['recommended']) == 0
                && count($this->arrSyncSettings['syncCto_CompareTables']['nonRecommended']) == 0)
        {
            unset($this->arrSyncSettings['syncCto_CompareTables']);
            $this->arrSyncSettings['syncCto_SyncDeleteTables'] = array();
            $this->arrSyncSettings['syncCto_SyncTables'] = array();

            $this->mixStep = self::STEP_CLOSE_DB;
            return;
        }

        // Make a look up
        foreach ((array) $this->arrSyncSettings['syncCto_CompareTables']['recommended'] as $strKey => $arrValueA)
        {
            $arrTransServer = $this->lookUpName($arrValueA['server']['name']);
            $arrTransClient = $this->lookUpName($arrValueA['client']['name']);
            
            $this->arrSyncSettings['syncCto_CompareTables']['recommended'][$strKey]['server']['tname'] = $arrTransServer['tname'];
            $this->arrSyncSettings['syncCto_CompareTables']['recommended'][$strKey]['server']['iname'] = $arrTransServer['iname'];
            $this->arrSyncSettings['syncCto_CompareTables']['recommended'][$strKey]['client']['tname'] = $arrTransClient['tname'];
            $this->arrSyncSettings['syncCto_CompareTables']['recommended'][$strKey]['client']['iname'] = $arrTransClient['iname'];
        }

        foreach ((array) $this->arrSyncSettings['syncCto_CompareTables']['nonRecommended'] as $strKey => $arrValueA)
        {
            $arrTransServer = $this->lookUpName($arrValueA['server']['name']);
            $arrTransClient = $this->lookUpName($arrValueA['client']['name']);

            $this->arrSyncSettings['syncCto_CompareTables']['nonRecommended'][$strKey]['server']['tname'] = $arrTransServer['tname'];
            $this->arrSyncSettings['syncCto_CompareTables']['nonRecommended'][$strKey]['server']['iname'] = $arrTransServer['iname'];
            $this->arrSyncSettings['syncCto_CompareTables']['nonRecommended'][$strKey]['client']['tname'] = $arrTransClient['tname'];
            $this->arrSyncSettings['syncCto_CompareTables']['nonRecommended'][$strKey]['client']['iname'] = $arrTransClient['iname'];
        }

        $this->Template                 = new BackendTemplate("be_syncCto_database");
        $this->Template->headline       = $GLOBALS['TL_LANG']['MSC']['comparelist'];
        $this->Template->arrCompareList = $this->arrSyncSettings['syncCto_CompareTables'];
        $this->Template->close          = FALSE;
        $this->Template->error          = FALSE;

        $objExtern = $this->Database
                ->prepare('SELECT address, path FROM tl_synccto_clients WHERE id=?')
                ->execute($this->intClientID);

        $this->Template->clientPath = $objExtern->address . $objExtern->path;
        $this->Template->serverPath = $this->Environment->base;
    }

    /**
     * Make a lookup for a human readable table name.
     * First syncCto language
     * Second the mapping for mod language
     * Last the mod language
     * 
     * @param string $strName Name of table
     * @return string
     */
    public function lookUpName($strName)
    {
        $strBase = str_replace('tl_', "", $strName);

        if ($strName == '-')
        {
            return '-';
        }

        // Make a lookup in synccto language files
        if (is_array($GLOBALS['TL_LANG']['tl_syncCto_database']) && key_exists($strName, $GLOBALS['TL_LANG']['tl_syncCto_database']))
        {
            if (is_array($GLOBALS['TL_LANG']['tl_syncCto_database'][$strName]))
            {
                return $this->formateLookUpName($strName, $GLOBALS['TL_LANG']['tl_syncCto_database'][$strName][0]);
            }
            else
            {
                return $this->formateLookUpName($strName, $GLOBALS['TL_LANG']['tl_syncCto_database'][$strName]);
            }
        }
        
        // Get MM name
        if (in_array('metamodels', $this->Config->getActiveModules()) && preg_match("/^mm_/i", $strName))
        {
            try
            {
                if (!is_null(MetaModelFactory::byTableName($strName)))
                {
                    $objDCABuilder     = MetaModelDcaBuilder::getInstance();
                    $arrDCA            = $objDCABuilder->getDca(MetaModelFactory::byTableName($strName)->get('id'));
                    $arrBackendcaption = deserialize($arrDCA['backendcaption']);

                    $strReturn = MetaModelFactory::byTableName($strName)->getName();

                    foreach ((array) $arrBackendcaption as $value)
                    {
                        if ($value['langcode'] == $this->User->language)
                        {
                            $strReturn = $value['label'];
                            break;
                        }
                    }
                    
                    return $this->formateLookUpName($strName, $strReturn);
                }
            }
            catch (Exception $exc)
            {
                // Nothing to do;
            }
        }
        
        // Little mapping for names        
        if (is_array($GLOBALS['SYC_CONFIG']['database_mapping']) && key_exists($strName, $GLOBALS['SYC_CONFIG']['database_mapping']))
        {
            $strRealSystemName = $GLOBALS['SYC_CONFIG']['database_mapping'][$strName];

            if (is_array($GLOBALS['TL_LANG']['MOD'][$strRealSystemName]))
            {
                return $this->formateLookUpName($strName, $GLOBALS['TL_LANG']['MOD'][$strRealSystemName][0]);
            }
            else
            {
                return $this->formateLookUpName($strName, $GLOBALS['TL_LANG']['MOD'][$strRealSystemName]);
            }
        }

        // Search in mod language array for a translation
        if (key_exists($strBase, $GLOBALS['TL_LANG']['MOD']))
        {            
            if (is_array($GLOBALS['TL_LANG']['MOD'][$strBase]))
            {
                return $this->formateLookUpName($strName, $GLOBALS['TL_LANG']['MOD'][$strBase][0]);
            }
            else
            {
                return $this->formateLookUpName($strName, $GLOBALS['TL_LANG']['MOD'][$strBase]);
            }
        }

        return $this->formateLookUpName($strName, $strName);
    }
    
    /**
     * Return a array with tahble names
     * 
     * @param string $strTableName real name like 'tl_contetn'
     * @param string $strReadableName readable name like 'Conten Elements'
     * 
     * @return array('tname' => [for table], 'iname' => [for title/info])
     */
    protected function formateLookUpName($strTableName, $strReadableName)
    {
        // Check if the function is activate
        if($this->User->syncCto_useTranslatedNames)
        {
            return array(
                'tname' => $strReadableName,
                'iname' => $strTableName
            );
        }
        else
        {
            return array(
                'tname' => $strTableName,
                'iname' => $strReadableName
            );
        }
    }

    /**
     * Close popup and go throug next syncCto step
     */
    public function showClose()
    {
        $this->Template           = new BackendTemplate("be_syncCto_database");
        $this->Template->headline = $GLOBALS['TL_LANG']['MSC']['backBT'];
        $this->Template->close    = TRUE;
        $this->Template->error    = FALSE;
    }

    /**
     * Show errors
     */
    public function showError()
    {
        $this->Template           = new BackendTemplate("be_syncCto_database");
        $this->Template->headline = $GLOBALS['TL_LANG']['MSC']['error'];
        $this->Template->arrError = $this->arrErrors;
        $this->Template->text     = $GLOBALS['TL_LANG']['ERR']['general'];
        $this->Template->close    = FALSE;
        $this->Template->error    = TRUE;
    }

    /**
     * Output templates
     */
    public function output()
    {
        // Set stylesheets
        $GLOBALS['TL_CSS'][] = TL_SCRIPT_URL . 'system/themes/' . $this->getTheme() . '/main.css';
        $GLOBALS['TL_CSS'][] = TL_SCRIPT_URL . 'system/themes/' . $this->getTheme() . '/basic.css';
        $GLOBALS['TL_CSS'][] = TL_SCRIPT_URL . 'system/themes/' . $this->getTheme() . '/popup.css';
        $GLOBALS['TL_CSS'][] = TL_SCRIPT_URL . 'system/modules/syncCto/html/css/compare.css';

        // Set javascript
        $GLOBALS['TL_JAVASCRIPT'][] = TL_PLUGINS_URL . 'plugins/mootools/' . MOOTOOLS_CORE . '/mootools-core.js';
        $GLOBALS['TL_JAVASCRIPT'][] = 'contao/contao.js';

        if (version_compare(VERSION, '2.11', '=='))
        {
            $GLOBALS['TL_JAVASCRIPT'][] = 'system/modules/syncCto/html/js/htmltable.js';
        }

        $GLOBALS['TL_JAVASCRIPT'][] = 'system/modules/syncCto/html/js/compare.js';

        // Set wrapper template information
        $this->popupTemplate           = new BackendTemplate("be_syncCto_popup");
        $this->popupTemplate->theme    = $this->getTheme();
        $this->popupTemplate->base     = $this->Environment->base;
        $this->popupTemplate->language = $GLOBALS['TL_LANGUAGE'];
        $this->popupTemplate->title    = $GLOBALS['TL_CONFIG']['websiteTitle'];
        $this->popupTemplate->charset  = $GLOBALS['TL_CONFIG']['characterSet'];
        $this->popupTemplate->headline = basename(utf8_convert_encoding($this->strFile, $GLOBALS['TL_CONFIG']['characterSet']));

        
        // Set default information
        $this->Template->id        = $this->intClientID;
        $this->Template->step      = $this->mixStep;
        $this->Template->direction = $this->strMode;

        // Output template
        $this->popupTemplate->content = $this->Template->parse();
        $this->popupTemplate->output();
    }

    // Helper functions --------------------------------------------------------

    /**
     * Initianize get parameter
     */
    protected function initGetParams()
    {
        // Get Client id
        if (strlen($this->Input->get('id')) != 0)
        {
            $this->intClientID = intval($this->Input->get('id'));
        }
        else
        {
            $this->mixStep = self::STEP_ERROR_DB;
            return;
        }

        // Get next step
        if (strlen($this->Input->get('step')) != 0)
        {
            $this->mixStep = $this->Input->get('step');
        }
        else
        {
            $this->mixStep = self::STEP_NORMAL_DB;
        }
        
        // Get direction
        if (strlen($this->Input->get('direction')) != 0)
        {
           $this->strMode = $this->Input->get('direction');
        }
        
    }

    protected function loadSyncSettings()
    {
        $this->arrSyncSettings = $this->Session->get("syncCto_SyncSettings_" . $this->intClientID);

        if (!is_array($this->arrSyncSettings))
        {
            $this->arrSyncSettings = array();
        }
    }

    protected function saveSyncSettings()
    {
        if (!is_array($this->arrSyncSettings))
        {
            $this->arrSyncSettings = array();
        }

        $this->Session->set("syncCto_SyncSettings_" . $this->intClientID, $this->arrSyncSettings);
    }

}

/**
 * Instantiate controller
 */
$objPopup = new popupSyncDB();
$objPopup->run();
?>