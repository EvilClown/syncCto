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
 * Class for file operations
 */
class SyncCtoFiles extends Backend
{
    /* -------------------------------------------------------------------------
     * Vars
     */

    // Singelten pattern
    protected static $instance         = null;
    // Vars
    protected $strSuffixZipName = "File-Backup.zip";
    protected $strTimestampFormat;
    protected $intMaxMemoryUsage;
    protected $intMaxExecutionTime;
    // Lists
    protected $arrFolderBlacklist;
    protected $arrFileBlacklist;
    protected $arrRootFolderList;
    // Objects 
    protected $objSyncCtoHelper;
    protected $objFiles;

    /* -------------------------------------------------------------------------
     * Core
     */

    /**
     * Constructor
     */
    protected function __construct()
    {
        parent::__construct();

        // Init
        $this->objSyncCtoHelper   = SyncCtoHelper::getInstance();
        $this->objFiles           = Files::getInstance();
        $this->strTimestampFormat = str_replace(array(':', ' '), array('', '_'), $GLOBALS['TL_CONFIG']['datimFormat']);

        // Load blacklists and whitelists
        $this->arrFolderBlacklist = $this->objSyncCtoHelper->getBlacklistFolder();
        $this->arrFileBlacklist   = $this->objSyncCtoHelper->getBlacklistFile();
        $this->arrRootFolderList  = $this->objSyncCtoHelper->getWhitelistFolder();

        $arrSearch = array("\\", ".", "^", "?", "*", "/");
        $arrReplace = array("\\\\", "\\.", "\\^", ".?", ".*", "\\/");

        foreach ($this->arrFolderBlacklist as $key => $value)
        {
            $this->arrFolderBlacklist[$key] = str_replace($arrSearch, $arrReplace, $value);
        }

        foreach ($this->arrFileBlacklist as $key => $value)
        {
            $this->arrFileBlacklist[$key] = str_replace($arrSearch, $arrReplace, $value);
        }

        // Get memory limit
        $this->intMaxMemoryUsage = intval(str_replace(array("m", "M", "k", "K"), array("000000", "000000", "000", "000"), ini_get('memory_limit')));
        $this->intMaxMemoryUsage = $this->intMaxMemoryUsage / 100 * 30;

        // Get execution limit
        $this->intMaxExecutionTime = intval(ini_get('max_execution_time'));
        $this->intMaxExecutionTime = intval($this->intMaxExecutionTime / 100 * 25);
    }

    /**
     * @return SyncCtoFiles 
     */
    public function __clone()
    {
        return self::$instance;
    }

    /**
     * @return SyncCtoFiles 
     */
    public static function getInstance()
    {
        if (self::$instance == null)
        {
            self::$instance = new SyncCtoFiles();
        }

        return self::$instance;
    }

    /* -------------------------------------------------------------------------
     * Getter / Setter - Functions
     */

    /**
     * Return zipname
     * 
     * @return string
     */
    public function getSuffixZipName()
    {
        return $this->strSuffixZipName;
    }

    /**
     * Set zipname
     * 
     * @param string $strSuffixZipName 
     */
    public function setSuffixZipName($strSuffixZipName)
    {
        $this->strSuffixZipName = $strSuffixZipName;
    }

    /**
     * Get timestamp format
     * 
     * @return string 
     */
    public function getTimestampFormat()
    {
        return $this->strTimestampFormat;
    }

    /**
     * Set timestamp format
     * 
     * @param type $strTimestampFormat 
     */
    public function setTimestampFormat($strTimestampFormat)
    {
        $this->strTimestampFormat = $strTimestampFormat;
    }

    /* -------------------------------------------------------------------------
     * Checksum Functions
     */

    protected function getChecksumFiles($booCore = false, $booFiles = false)
    {
        $arrChecksum = array();

        $arrFiles = $this->getFileList($booCore, $booFiles);

        // Check each file
        foreach ($arrFiles as $value)
        {
            // Get filesize
            $intSize = filesize(TL_ROOT . "/" . $value);

            if ($intSize < 0 && $intSize != 0)
            {
                $arrChecksum[md5($value)] = array(
                    "path"         => $value,
                    "checksum"     => 0,
                    "size"         => -1,
                    "state"        => SyncCtoEnum::FILESTATE_BOMBASTIC_BIG,
                    "transmission" => SyncCtoEnum::FILETRANS_WAITING,
                );
            }
            else if ($intSize >= $GLOBALS['SYC_SIZE']['limit_ignore'])
            {
                $arrChecksum[md5($value)] = array(
                    "path"         => $value,
                    "checksum"     => 0,
                    "size"         => $intSize,
                    "state"        => SyncCtoEnum::FILESTATE_BOMBASTIC_BIG,
                    "transmission" => SyncCtoEnum::FILETRANS_WAITING,
                );
            }
            else if ($intSize >= $GLOBALS['SYC_SIZE']['limit'])
            {
                $arrChecksum[md5($value)] = array(
                    "path"         => $value,
                    "checksum"     => md5_file(TL_ROOT . "/" . $value),
                    "size"         => $intSize,
                    "state"        => SyncCtoEnum::FILESTATE_TOO_BIG,
                    "transmission" => SyncCtoEnum::FILETRANS_WAITING,
                );
            }
            else
            {
                $arrChecksum[md5($value)] = array(
                    "path"         => $value,
                    "checksum"     => md5_file(TL_ROOT . "/" . $value),
                    "size"         => $intSize,
                    "state"        => SyncCtoEnum::FILESTATE_FILE,
                    "transmission" => SyncCtoEnum::FILETRANS_WAITING,
                );
            }
        }

        return $arrChecksum;
    }

    /**
     * Create a xml file with all files
     * 
     * @param string $strXMLFile Full filepath
     * @param $intInformations $intSize The size
     * @param boolean $booCore Core scan
     * @param boolean $booFiles Files scan
     * @return boolean 
     */
    protected function getChecksumFileAsXML($strXMLFile, $booCore = false, $booFiles = false, $intInformations = SyncCtoEnum::FILEINFORMATION_SMALL)
    {
        $strXMLFile = $this->objSyncCtoHelper->standardizePath($strXMLFile);

        $objFile = new File($strXMLFile);
        $objFile->delete();
        $objFile->close();

        $arrFiles = $this->getFileList($booCore, $booFiles);

        if (count($arrFiles) == 0)
        {
            return false;
        }

        // Create XML File
        $objXml = new XMLWriter();
        $objXml->openMemory();
        $objXml->setIndent(true);
        $objXml->setIndentString("\t");

        // XML Start
        $objXml->startDocument('1.0', 'UTF-8');
        $objXml->startElement('fileslist');

        // Write meta (header)
        $objXml->startElement('metatags');
        $objXml->writeElement('version', $GLOBALS['SYC_VERSION']);
        $objXml->writeElement('create_unix', time());
        $objXml->writeElement('create_date', date('Y-m-d', time()));
        $objXml->writeElement('create_time', date('H:i', time()));
        $objXml->endElement(); // End metatags

        $objXml->startElement('files');

        for ($i = 0; $i < count($arrFiles); $i++)
        {
            // Get filesize
            $intSize = filesize(TL_ROOT . "/" . $arrFiles[$i]);

            if ($intSize < 0 && $intSize != 0)
            {
                continue;
            }
            else
            {
                if ($intInformations == SyncCtoEnum::FILEINFORMATION_SMALL)
                {
                    $objXml->startElement('file');
                    $objXml->writeAttribute("id", md5($arrFiles[$i]));
                    $objXml->writeAttribute("ai", $i);
                    $objXml->text($arrFiles[$i]);
                    $objXml->endElement(); // End file
                }
                else if ($intInformations == SyncCtoEnum::FILEINFORMATION_BIG)
                {
                    $objXml->startElement('file');
                    $objXml->writeAttribute("id", md5($arrFiles[$i]));
                    $objXml->writeAttribute("ai", $i);
                    $objXml->text($arrFiles[$i]);
                    $objXml->endElement(); // End file
                }
            }

            if ($this->intMaxMemoryUsage < memory_get_usage(true))
            {
                $objFile->append($objXml->flush(true), "");
                $objFile->close();
            }
        }

        $objXml->endElement(); // End files
        $objXml->endElement(); // End fileslist

        $objFile->append($objXml->flush(true), "");
        $objFile->close();

        return true;
    }

    /**
     * Create a checksum list from contao core folders
     * 
     * @CtoCommunication Enable
     * @return array 
     */
    protected function getChecksumFolders($booCore = false, $booFiles = false)
    {
        $arrFolderList = $this->getFolderList($booCore, $booFiles);
        $arrChecksum   = array();

        // Check each file
        foreach ($arrFolderList as $value)
        {
            $arrChecksum[md5($value)] = array(
                "path"         => $value,
                "checksum"     => 0,
                "size"         => 0,
                "state"        => SyncCtoEnum::FILESTATE_FOLDER,
                "transmission" => SyncCtoEnum::FILETRANS_WAITING,
            );
        }

        return $arrChecksum;
    }
    
    /**
     * Create a checksum list from contao core folders
     * 
     * @CtoCommunication Enable
     * @return array 
     */
    public function runChecksumFolderCore()
    {
        return $this->getChecksumFolders(true, false);
    }

    /**
     * Create a checksum list from contao folders
     * 
     * @CtoCommunication Enable
     * @return array 
     */
    public function runChecksumFolderFiles()
    {
        return $this->getChecksumFolders(false, true);
    }

    /**
     * Create a checksum list from contao core
     * 
     * @CtoCommunication Enable
     * @return array 
     */
    public function runChecksumCore()
    {
        return $this->getChecksumFiles(true, false);
    }

    /**
     * Create a checksum list from contao files
     * 
     * @CtoCommunication Enable
     * @return array 
     */
    public function runChecksumFiles()
    {
        return $this->getChecksumFiles(false, true);
    }

    /**
     * Check a filelist with the current filesystem
     * 
     * @param array $arrChecksumList
     * @return array 
     */
    public function runCecksumCompare($arrChecksumList)
    {
        $arrFileList = array();

        foreach ($arrChecksumList as $key => $value)
        {
            if ($value['state'] == SyncCtoEnum::FILESTATE_BOMBASTIC_BIG)
            {
                $arrFileList[$key]        = $arrChecksumList[$key];
                $arrFileList[$key]["raw"] = "file bombastic";
            }
            else if (file_exists(TL_ROOT . "/" . $value['path']))
            {
                if (md5_file(TL_ROOT . "/" . $value['path']) == $value['checksum'])
                {
                    // Do nothing
                }
                else
                {
                    if ($value['state'] == SyncCtoEnum::FILESTATE_TOO_BIG)
                    {
                        $arrFileList[$key]          = $arrChecksumList[$key];
                        $arrFileList[$key]["state"] = SyncCtoEnum::FILESTATE_TOO_BIG_NEED;
                    }
                    else
                    {
                        $arrFileList[$key]          = $arrChecksumList[$key];
                        $arrFileList[$key]["state"] = SyncCtoEnum::FILESTATE_NEED;
                    }
                }
            }
            else
            {
                if ($value['state'] == SyncCtoEnum::FILESTATE_TOO_BIG)
                {
                    $arrFileList[$key]          = $arrChecksumList[$key];
                    $arrFileList[$key]["state"] = SyncCtoEnum::FILESTATE_TOO_BIG_MISSING;
                }
                else
                {
                    $arrFileList[$key]          = $arrChecksumList[$key];
                    $arrFileList[$key]["state"] = SyncCtoEnum::FILESTATE_MISSING;
                }
            }
        }

        return $arrFileList;
    }

    public function searchDeleteFolders($arrChecksumList)
    {
        $arrFolderList = array();

        foreach ($arrChecksumList as $keyItem => $valueItem)
        {
            if (!file_exists(TL_ROOT . "/" . $valueItem["path"]))
            {
                $arrFolderList[$keyItem]          = $valueItem;
                $arrFolderList[$keyItem]["state"] = SyncCtoEnum::FILESTATE_FOLDER_DELETE;
                $arrFolderList[$keyItem]["css"]   = "deleted";
            }
        }
        
        return $arrFolderList;
    }

    /**
     * Check for deleted files with a filelist from an other system
     * 
     * @param array $arrFilelist 
     */
    public function checkDeleteFiles($arrFilelist)
    {
        $arrReturn = array();

        foreach ($arrFilelist as $keyItem => $valueItem)
        {
            if (!file_exists(TL_ROOT . "/" . $valueItem["path"]))
            {
                $arrReturn[$keyItem]          = $valueItem;
                $arrReturn[$keyItem]["state"] = SyncCtoEnum::FILESTATE_DELETE;
                $arrReturn[$keyItem]["css"]   = "deleted";
            }
        }

        return $arrReturn;
    }

    /* -------------------------------------------------------------------------
     * Dump Functions
     */

    /**
     * Make a backup from a filelist
     * 
     * @CtoCommunication Enable
     * @param string $strZip
     * @param array $arrFileList
     * @return string Filename 
     */
    public function runDump($strZip = "", $booCore = false, $arrFiles = array())
    {
        if ($strZip == "")
        {
            $strFilename = date($this->strTimestampFormat) . "_" . $this->strSuffixZipName;
        }
        else
        {
            $strFilename = standardize(str_replace(array(" "), array("_"), preg_replace("/\.zip\z/i", "", $strZip))) . ".zip";
        }
        
        // Replace special chars from the filename..
        $strFilename = str_replace(array_keys($GLOBALS['SYC_CONFIG']['folder_file_replacement']), array_values($GLOBALS['SYC_CONFIG']['folder_file_replacement']), $strFilename);
        
        $strPath = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['file'], $strFilename);

        $objZipArchive = new ZipArchiveCto();

        if (($mixError = $objZipArchive->open($strPath, ZipArchiveCto::CREATE)) !== true)
        {
            throw new Exception($GLOBALS['TL_LANG']['MSC']['error'] . ": " . $objZipArchive->getErrorDescription($mixError));
        }

        $arrFileList    = $this->getFileList($booCore, false);
        $arrFileSkipped = array();

        for ($index = 0; $index < count($arrFiles); $index++)
        {
            if (is_dir(TL_ROOT . "/" . $arrFiles[$index]))
            {
                $arrFiles = array_merge($arrFiles, $this->getFileListFromFolders(array($arrFiles[$index])));
                continue;
            }

            if ($objZipArchive->addFile($arrFiles[$index], $arrFiles[$index]) == false)
            {
                $arrFileSkipped[] = $arrFiles[$index];
            }
        }

        foreach ($arrFileList as $file)
        {
            if ($objZipArchive->addFile($file, $file) == false)
            {
                $arrFileSkipped[] = $file;
            }
        }

        $objZipArchive->close();

        return array("name"    => $strFilename, "skipped" => $arrFileSkipped);
    }

    /**
     * Make a incremental backup from a filelist
     * 
     * @param string $srtXMLFilelist  Path to XML filelist
     * @param stirng $strZipFolder Path to the folder
     * @param string $strZipFile Name of zipfile. If empty a filename will be create.
     * @return array array{"folder"=>[string],"file"=>[string],"fullpath"=>[string],"xml"=>[string],"done"=>[boolean]}
     * @throws Exception 
     */
    public function runIncrementalDump($srtXMLFilelist, $strZipFolder, $strZipFile = null, $intMaxFilesPerRun = 5)
    {
        $floatTimeStart = microtime(true);

        // Check if filelist exists
        if (!file_exists(TL_ROOT . "/" . $srtXMLFilelist))
        {
            throw new Exception("File not found: " + $srtXMLFilelist);
        }

        // Create, check zip name
        if ($strZipFile == null || $strZipFile == "")
        {
            $strZipFile = date($this->strTimestampFormat) . "_" . $this->strSuffixZipName;
        }
        else
        {
            $strZipFile = str_replace(array(" "), array("_"), preg_replace("/\.zip\z/i", "", $strZipFile)) . ".zip";
        }

        // Build Path
        $strZipFolder = $this->objSyncCtoHelper->standardizePath($strZipFolder);
        $strZipPath   = $this->objSyncCtoHelper->standardizePath($strZipFolder, $strZipFile);

        // Open XML Reader
        $objXml = new DOMDocument("1.0", "UTF-8");
        $objXml->load(TL_ROOT . "/" . $srtXMLFilelist);

        // Check if we have some files
        if ($objXml->getElementsByTagName("file")->length == 0)
        {
            return array(
                "folder"   => $strZipFolder,
                "file"     => $strZipFile,
                "fullpath" => $strZipPath,
                "xml"      => $srtXMLFilelist,
                "done"     => true
            );
        }

        // Open ZipArchive
        $objZipArchive = new ZipArchiveCto();
        if (($mixError      = $objZipArchive->open($strZipPath, ZipArchiveCto::CREATE)) !== true)
        {
            throw new Exception($GLOBALS['TL_LANG']['MSC']['error'] . ": " . $objZipArchive->getErrorDescription($mixError));
        }

        // Get all files
        $objFilesList = $objXml->getElementsByTagName("file");
        $objNodeFiles = $objXml->getElementsByTagName("files")->item(0);
        $arrFinished  = array();
        $intRuns = 0;

        // Run throug each
        foreach ($objFilesList as $file)
        {
            // Check if file exists
            if (file_exists(TL_ROOT . "/" . $file->nodeValue))
            {
                $objZipArchive->addFile($file->nodeValue, $file->nodeValue);
            }

            // Add file to finished list
            $arrFinished[] = $file;
            $intRuns++;

            // After 5 files add all to zip
            if ($intRuns == $intMaxFilesPerRun)
            {
                $objZipArchive->close();
                $objZipArchive->open($strZipPath, ZipArchiveCto::CREATE);
                $intRuns = 0;
            }

            // Check time out
            if ((microtime(true) - $floatTimeStart) > $this->intMaxExecutionTime)
            {
                break;
            }
        }

        // Remove finished files from xml
        foreach ($arrFinished as $value)
        {
            $objNodeFiles->removeChild($value);
        }

        // Close XML and zip
        $objXml->save(TL_ROOT . "/" . $srtXMLFilelist);
        $objZipArchive->close();

        if ($objXml->getElementsByTagName("file")->length == 0)
        {
            $booFinished = true;
        }
        else
        {
            $booFinished = false;
        }

        // Return informations
        return array(
            "folder"   => $strZipFolder,
            "file"     => $strZipFile,
            "fullpath" => $strZipPath,
            "xml"      => $srtXMLFilelist,
            "done"     => $booFinished
        );
    }

    /**
     * Unzip files
     * 
     * @param string $strRestoreFile Path to the zip file
     * @return mixes True - If ervething is okay, Array - If some files could not be extract to a given path.
     * @throws Exception if the zip file was not able to open.
     */
    public function runRestore($strRestoreFile)
    {
        $objZipArchive = new ZipArchiveCto();

        if (($mixError = $objZipArchive->open($strRestoreFile)) !== true)
        {
            throw new Exception($GLOBALS['TL_LANG']['MSC']['error'] . ": " . $objZipArchive->getErrorDescription($mixError));
        }

        if ($objZipArchive->numFiles == 0)
        {
            return;
        }

        $arrErrorFiles = array();

        for ($i = 0; $i < $objZipArchive->numFiles; $i++)
        {
            $filename = $objZipArchive->getNameIndex($i);

            if (!$objZipArchive->extractTo("/", $filename))
            {
                $arrErrorFiles[] = $filename;
            }
        }

        $objZipArchive->close();

        if (count($arrErrorFiles) == 0)
        {
            return true;
        }
        else
        {
            return $arrErrorFiles;
        }
    }

    /* -------------------------------------------------------------------------
     * Scan Functions
     */

    /**
     * Check if the given path is in blacklist of folders
     * 
     * @param string $strPath
     * @return boolean 
     */
    protected function isInBlackFolder($strPath)
    {
        $strPath = $this->objSyncCtoHelper->standardizePath($strPath);

        foreach ($this->arrFolderBlacklist as $value)
        {
            // Search with preg for values            
            if (preg_match("/^" . $value . "/i", $strPath) != 0)
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the given path is in blacklist of files
     * 
     * @param string $strPath
     * @return boolean 
     */
    protected function isInBlackFile($strPath)
    {
        $strPath = $this->objSyncCtoHelper->standardizePath($strPath);

        foreach ($this->arrFileBlacklist as $value)
        {
            // Check if the preg starts with a TL_ROOT
            if (preg_match("/^TL_ROOT/i", $value))
            {
                // Remove the TL_ROOT
                $value = preg_replace("/TL_ROOT\\\\\//i", "", $value);

                // Search with preg for values            
                if (preg_match("/^" . $value . "$/i", $strPath) != 0)
                {
                    return true;
                }
            }
            else
            {
                // Search with preg for values            
                if (preg_match("/" . $value . "$/i", $strPath) != 0)
                {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get all files from a list of folders
     * 
     * @param array $arrFolders
     * @return array A List with all files 
     */
    public function getFileListFromFolders($arrFolders = array())
    {
        $arrAllFolders = array();
        $arrFiles = array();

        foreach ($arrFolders as $strFolder)
        {
            $arrAllFolders = array_merge($arrAllFolders, $this->recursiveFolderList($strFolder));
        }

        foreach ($arrAllFolders as $strFolders)
        {
            $arrResult = scan(TL_ROOT . "/" . $strFolders, true);

            foreach ($arrResult as $strFile)
            {
                if (is_file(TL_ROOT . "/" . $strFolders . "/" . $strFile))
                {
                    if ($this->isInBlackFile($strFolders . "/" . $strFile) == true)
                    {
                        continue;
                    }

                    $arrFiles[] = $strFolders . "/" . $strFile;
                }
            }
        }

        return $arrFiles;
    }

    /**
     * Get a list from all files into root and/or files
     * 
     * @param boolean $booRoot Start search from root
     * @param boolean $booFiles Start search from files
     * @return array A list with all files 
     */
    public function getFileList($booRoot = false, $booFiles = false)
    {
        // Get a list with all folders
        $arrFolder = $this->getFolderList($booRoot, $booFiles);
        $arrFiles  = array();

        // Search files in root folder
        if ($booRoot == true)
        {
            $arrResult = scan(TL_ROOT, true);

            foreach ($arrResult as $strFile)
            {
                if (is_file(TL_ROOT . "/" . $strFile))
                {
                    if ($this->isInBlackFile($strFile) == true)
                    {
                        continue;
                    }

                    $arrFiles[] = $strFile;
                }
            }
        }

        // Search in each folder
        foreach ($arrFolder as $strFolders)
        {
            $arrResult = scan(TL_ROOT . "/" . $strFolders, true);

            foreach ($arrResult as $strFile)
            {
                if (is_file(TL_ROOT . "/" . $strFolders . "/" . $strFile))
                {
                    if ($this->isInBlackFile($strFolders . "/" . $strFile) == true)
                    {
                        continue;
                    }

                    $arrFiles[] = $strFolders . "/" . $strFile;
                }
            }
        }

        return $arrFiles;
    }

    /**
     * Get a list with all folders
     * 
     * @param boolean $booRoot Start search from root
     * @param boolean $booFiles Start search from files
     * @return array A list with all folders
     */
    public function getFolderList($booRoot = false, $booFiles = false)
    {
        $arrFolders = array();

        if ($booRoot == false && $booFiles == false)
        {
            return $arrFolders;
        }

        if ($booRoot == true)
        {
            foreach ($this->arrRootFolderList as $value)
            {
                $arrFolders = array_merge($arrFolders, $this->recursiveFolderList($value));
            }
        }

        if ($booFiles == true)
        {
            $arrFolders = array_merge($arrFolders, $this->recursiveFolderList($GLOBALS['TL_CONFIG']['uploadPath']));
        }

        return $arrFolders;
    }

    /**
     * Scan path for all folders and subfolders
     * 
     * @param string $strPath start folder
     * @return array A list with all folders 
     */
    public function recursiveFolderList($strPath)
    {
        $strPath = $this->objSyncCtoHelper->standardizePath($strPath);

        if (!is_dir(TL_ROOT . "/" . $strPath) || $this->isInBlackFolder($strPath) == true)
        {
            return array();
        }

        $arrFolders = array($strPath);

        $arrResult = scan(TL_ROOT . "/" . $strPath, true);

        foreach ($arrResult as $value)
        {
            if (is_dir(TL_ROOT . "/" . $strPath . "/" . $value))
            {
                if ($this->isInBlackFolder($strPath . "/" . $value) == true)
                {
                    continue;
                }

                $arrFolders = array_merge($arrFolders, $this->recursiveFolderList($strPath . "/" . $value));
            }
        }

        return $arrFolders;
    }

    /* -------------------------------------------------------------------------
     * Folder Operations 
     */

    /**
     * Create syncCto folders if not exists
     */
    public function checkSyncCtoFolders()
    {
        $objFile = new Folder($this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp']));

        $objFile = new Folder($this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['db']));
        $objFile->protect();

        $objFile = new Folder($this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['file']));
        $objFile->protect();
    }

    /**
     * Clear tempfolder or a folder inside of temp
     * 
     * @CtoCommunication Enable
     * @param string $strFolder
     */
    public function purgeTemp($strFolder = null)
    {
        if ($strFolder == null || $strFolder == "")
        {
            $strPath = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp']);
        }
        else
        {
            $strPath = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp'], $strFolder);
        }

        $objFolder = new Folder($strPath);
        $objFolder->clear();
    }

    /**
     * Use the contao maintance
     * 
     * @CtoCommunication Enable
     * @return array
     */
    public function runMaintenance($arrSetings)
    {
        $arrReturn = array(
            "success"  => false,
            "info_msg" => array()
        );

        $this->import('Automator');
        $this->import('StyleSheets');
        $this->import("Database");

        foreach ($arrSetings as $value)
        {
            try
            {
                switch ($value)
                {
                    // Database table
                    // Get all cachable tables from TL_CACHE
                    case "temp_tables":
                        foreach ($GLOBALS['TL_CACHE'] as $k => $v)
                        {
                            if (in_array($v, array("tl_ctocom_cache", "tl_requestcache ")))
                            {
                                continue;
                            }

                            $this->Database->execute("TRUNCATE TABLE " . $v);
                        }
                        break;

                    case "temp_folders":
                        // Html folder
                        $this->Automator->purgeHtmlFolder();
                        // Scripts folder
                        $this->Automator->purgeScriptsFolder();
                        // Temporary folder
                        $this->Automator->purgeTempFolder();
                        break;

                    // CSS files
                    case "css_create":
                        $this->StyleSheets->updateStyleSheets();
                        break;

                    case "xml_create":
                        try
                        {
                            // XML files
                            // HOOK: use the googlesitemap module
                            if (in_array('googlesitemap', $this->Config->getActiveModules()))
                            {
                                $this->import('GoogleSitemap');
                                $this->GoogleSitemap->generateSitemap();
                            }
                            else
                            {
                                $this->Automator->generateSitemap();
                            }
                        }
                        catch (Exception $exc)
                        {
                            $arrReturn["info_msg"][] = "Error by: $value with Msg: " . $exc->getMessage();
                        }

                        try
                        {
                            // HOOK: recreate news feeds
                            if (in_array('news', $this->Config->getActiveModules()))
                            {
                                $this->import('News');
                                $this->News->generateFeeds();
                            }
                        }
                        catch (Exception $exc)
                        {
                            $arrReturn["info_msg"][] = "Error by: $value with Msg: " . $exc->getMessage();
                        }

                        try
                        {
                            // HOOK: recreate calendar feeds
                            if (in_array('calendar', $this->Config->getActiveModules()))
                            {
                                $this->import('Calendar');
                                $this->Calendar->generateFeeds();
                            }
                        }
                        catch (Exception $exc)
                        {
                            $arrReturn["info_msg"][] = "Error by: $value with Msg: " . $exc->getMessage();
                        }
                    default:
                        break;
                }
            }
            catch (Exception $exc)
            {
                $arrReturn["info_msg"][] = "Error by: $value with Msg: " . $exc->getMessage();
            }
        }

        // HOOK: take additional maintenance
        if (isset($GLOBALS['TL_HOOKS']['syncAdditionalMaintenance']) && is_array($GLOBALS['TL_HOOKS']['syncAdditionalMaintenance']))
        {
            foreach ($GLOBALS['TL_HOOKS']['syncAdditionalMaintenance'] as $callback)
            {
                try
                {
                    $this->import($callback[0]);
                    $this->$callback[0]->$callback[1]($arrSetings);
                }
                catch (Exception $exc)
                {
                    $arrReturn["info_msg"][] = "Error by: TL_HOOK $callback[0] | $callback[1] with Msg: " . $exc->getMessage();
                }
            }
        }

        if (count($arrReturn["info_msg"]) != 0)
        {
            return $arrReturn;
        }
        else
        {
            return true;
        }
    }

    /* -------------------------------------------------------------------------
     * File Operations 
     */

    /**
     * Split files function
     * 
     * @CtoCommunication Enable
     * @param type $strSrcFile File start at TL_ROOT exp. system/foo/foo.php
     * @param type $strDesFolder Folder for split files, start at TL_ROOT , exp. system/temp/
     * @param type $strDesFile Name of file without extension. Example: Foo or MyFile
     * @param type $intSizeLimit Split Size in Bytes
     * @return int 
     */
    public function splitFiles($strSrcFile, $strDesFolder, $strDesFile, $intSizeLimit)
    {
        @set_time_limit(3600);

        if ($intSizeLimit < 500 * 1024)
        {
            throw new Exception(vsprintf($GLOBALS['TL_LANG']['ERR']['min_size_limit'], array("500KiB")));
        }

        if (!file_exists(TL_ROOT . "/" . $strSrcFile))
        {
            throw new Exception(vsprintf($GLOBALS['TL_LANG']['ERR']['unknown_file'], array($strSrcFile)));
        }

        $objFolder = new Folder($strDesFolder);
        $objFile   = new File($strSrcFile);

        if ($objFile->filesize < 0)
        {
            throw new Exception($GLOBALS['TL_LANG']['ERR']['64Bit_error']);
        }

        $booRun = true;
        $i      = 0;
        for ($i; $booRun; $i++)
        {
            $fp = fopen(TL_ROOT . "/" . $strSrcFile, "rb");

            if ($fp === FALSE)
            {
                throw new Exception(vsprintf($GLOBALS['TL_LANG']['ERR']['cant_open'], array($strSrcFile)));
            }

            if (fseek($fp, $i * $intSizeLimit, SEEK_SET) === -1)
            {
                throw new Exception(vsprintf($GLOBALS['TL_LANG']['ERR']['cant_open'], array($strSrcFile)));
            }

            if (feof($fp) === TRUE)
            {
                $i--;
                break;
            }

            $data = fread($fp, $intSizeLimit);
            fclose($fp);
            unset($fp);

            $objFileWrite = new File($this->objSyncCtoHelper->standardizePath($strDesFolder, $strDesFile . ".sync" . $i));
            $objFileWrite->write($data);
            $objFileWrite->close();

            unset($objFileWrite);
            unset($data);

            if (( ( $i + 1 ) * $intSizeLimit) > $objFile->filesize)
            {
                $booRun = false;
            }
        }

        return $i;
    }

    /**
     * Rebuild split files
     * 
     * @CtoCommunication Enable
     * @param type $strSplitname
     * @param type $intSplitcount
     * @param type $strMovepath
     * @param type $strMD5
     * @return type 
     */
    public function rebuildSplitFiles($strSplitname, $intSplitcount, $strMovepath, $strMD5)
    {
        // Build savepath
        $strSavePath = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp'], "sync", $strMovepath);

        // Create Folder
        $objFolder = new Folder(dirname($strSavePath));

        // Run for each part file
        for ($i = 0; $i < $intSplitcount; $i++)
        {
            // Build path for part file
            $strReadFile = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp'], $strSplitname, $strSplitname . ".sync" . $i);

            // Check if file exists
            if (!file_exists(TL_ROOT . "/" . $strReadFile))
            {
                throw new Exception(vsprintf($GLOBALS['TL_LANG']['ERR']['unknown_file'], array($strSplitname . ".sync" . $i)));
            }

            // Create new file objects
            $objFilePart  = new File($strReadFile);
            $hanFileWhole = fopen(TL_ROOT . "/" . $strSavePath, "a+");

            // Write part file to main file
            fwrite($hanFileWhole, $objFilePart->getContent());

            // Close objects
            $objFilePart->close();
            fclose($hanFileWhole);

            // Free up memory
            unset($objFilePart);
            unset($hanFileWhole);

            // wait
            sleep(1);
        }

        // Check MD5 Checksum
        if (md5_file(TL_ROOT . "/" . $strSavePath) != $strMD5)
        {
            throw new Exception($GLOBALS['TL_LANG']['ERR']['checksum_error']);
        }

        return true;
    }

    /**
     * Move temp files
     * 
     * @CtoCommunication Enable
     * @param type $arrFileList
     * @return boolean 
     */
    public function moveTempFile($arrFileList)
    {
        foreach ($arrFileList as $key => $value)
        {
            if (!file_exists(TL_ROOT . "/" . $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp'], "sync", $value["path"])))
            {
                $arrFileList[$key]["saved"] = false;
                $arrFileList[$key]["error"] = vsprintf($GLOBALS['TL_LANG']['ERR']['unknown_file'], array($this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp'], "sync", $value["path"])));
                continue;
            }

            $strFolderPath = dirname($value["path"]);

            if ($strFolderPath != ".")
            {
                $objFolder = new Folder($strFolderPath);
                unset($objFolder);
            }

            $strFileSource      = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp'], "sync", $value["path"]);
            $strFileDestination = $this->objSyncCtoHelper->standardizePath($value["path"]);

            if ($this->objFiles->copy($strFileSource, $strFileDestination) == false)
            {
                $arrFileList[$key]["saved"] = false;
                $arrFileList[$key]["error"] = vsprintf($GLOBALS['TL_LANG']['ERR']['cant_move_file'], array($strFileSource, $strFileDestination));
            }
            else
            {
                $arrFileList[$key]["saved"] = true;
            }
        }

        return $arrFileList;
    }

    /**
     * Delete files
     * 
     * @CtoCommunication Enable
     * @param type $arrFileList
     * @return type 
     */
    public function deleteFiles($arrFileList)
    {
        if (count($arrFileList) != 0)
        {
            foreach ($arrFileList as $key => $value)
            {

                if (is_file(TL_ROOT . "/" . $value['path']))
                {
                    try
                    {
                        if ($this->objFiles->delete($value['path']))
                        {
                            $arrFileList[$key]['transmission'] = SyncCtoEnum::FILETRANS_SEND;
                        }
                        else
                        {
                            $arrFileList[$key]['transmission'] = SyncCtoEnum::FILETRANS_SKIPPED;
                            $arrFileList[$key]["skipreason"]   = $GLOBALS['TL_LANG']['ERR']['cant_delete_file'];
                        }
                    }
                    catch (Exception $exc)
                    {
                        $arrFileList[$key]['transmission'] = SyncCtoEnum::FILETRANS_SKIPPED;
                        $arrFileList[$key]["skipreason"]   = $exc->getMessage();
                    }
                }
                else
                {
                    try
                    {
                        $this->objFiles->rrdir($value['path']);
                        $arrFileList[$key]['transmission'] = SyncCtoEnum::FILETRANS_SEND;
                    }
                    catch (Exception $exc)
                    {
                        $arrFileList[$key]['transmission'] = SyncCtoEnum::FILETRANS_SKIPPED;
                        $arrFileList[$key]["skipreason"]   = $exc->getMessage();
                    }
                }
            }
        }

        return $arrFileList;
    }

    /**
     * Receive a file and move it to the right folder.
     * 
     * @CtoCommunication Enable
     * @param type $arrMetafiles
     * @return string 
     */
    public function saveFiles($arrMetafiles)
    {
        if (!is_array($arrMetafiles) || count($_FILES) == 0)
        {
            throw new Exception($GLOBALS['TL_LANG']['ERR']['missing_file_information']);
        }

        $arrResponse = array();

        foreach ($_FILES as $key => $value)
        {
            if (!key_exists($key, $arrMetafiles))
            {
                throw new Exception($GLOBALS['TL_LANG']['ERR']['missing_file_information']);
            }

            $strFolder = $arrMetafiles[$key]["folder"];
            $strFile   = $arrMetafiles[$key]["file"];
            $strMD5    = $arrMetafiles[$key]["MD5"];

            switch ($arrMetafiles[$key]["typ"])
            {
                case SyncCtoEnum::UPLOAD_TEMP:
                    $strSaveFile = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp'], $strFolder, $strFile);
                    break;

                case SyncCtoEnum::UPLOAD_SYNC_TEMP:
                    $strSaveFile = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp'], "sync", $strFolder, $strFile);
                    break;

                case SyncCtoEnum::UPLOAD_SQL_TEMP:
                    $strSaveFile = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp'], "sql", $strFile);
                    break;

                case SyncCtoEnum::UPLOAD_SYNC_SPLIT:
                    $strSaveFile = $this->objSyncCtoHelper->standardizePath($GLOBALS['SYC_PATH']['tmp'], $arrMetafiles[$key]["splitname"], $strFile);
                    break;

                default:
                    throw new Exception($GLOBALS['TL_LANG']['ERR']['unknown_path']);
                    break;
            }

            $objFolder = new Folder(dirname($strSaveFile));

            if ($this->objFiles->move_uploaded_file($value["tmp_name"], $strSaveFile) === FALSE)
            {
                throw new Exception(vsprintf($GLOBALS['TL_LANG']['ERR']['cant_move_file'], array($value["tmp_name"], $strSaveFile)));
            }
            else if ($key != md5_file(TL_ROOT . "/" . $strSaveFile))
            {
                throw new Exception($GLOBALS['TL_LANG']['ERR']['checksum_error']);
            }
            else
            {
                $arrResponse[$key] = "Saving " . $arrMetafiles[$key]["file"];
            }
        }

        return $arrResponse;
    }

    /**
     * Send a file as serelizard array
     * 
     * @CtoCommunication Enable
     * @param string $strPath
     * @return array
     */
    public function getFile($strPath)
    {
        if (!file_exists(TL_ROOT . "/" . $strPath))
        {
            throw new Exception(vsprintf($GLOBALS['TL_LANG']['ERR']['unknown_file'], array($strPath)));
        }

        $objFile    = new File($strPath);
        $strContent = base64_encode($objFile->getContent());
        $objFile->close();

        return array("md5"     => md5_file(TL_ROOT . "/" . $strPath), "content" => $strContent);
    }

}