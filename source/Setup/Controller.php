<?php
/**
 * This file is part of OXID eShop Community Edition.
 *
 * OXID eShop Community Edition is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OXID eShop Community Edition is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with OXID eShop Community Edition.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @link      http://www.oxid-esales.com
 * @copyright (C) OXID eSales AG 2003-2016
 * @version   OXID eShop CE
 */

namespace OxidEsales\EshopCommunity\Setup;

use Exception;
use OxidEsales\Eshop\Core\ConfigFile;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\Edition\EditionPathProvider;
use OxidEsales\Eshop\Core\Edition\EditionRootPathProvider;
use OxidEsales\Eshop\Core\Edition\EditionSelector;

/**
 * Class holds scripts (controllers) needed to perform shop setup steps
 */
class Controller extends Core
{
    /** @var View */
    private $view = null;

    // BEGIN: Controllers

    /**
     * Controller constructor
     */
    public function __construct()
    {
        $this->view = new View();
    }

    /**
     * First page with system requirements check
     *
     * @return string
     */
    public function systemReq()
    {
        $setup = $this->getSetupInstance();
        $language = $this->getLanguageInstance();
        $utils = $this->getUtilitiesInstance();

        $continue = true;
        $groupModuleInfo = array();

        $htaccessUpdateError = false;
        try {
            $path = $utils->getDefaultPathParams();
            $path['sBaseUrlPath'] = $utils->extractRewriteBase($path['sShopURL']);
            //$oUtils->updateHtaccessFile( $aPath, "admin" );
            $utils->updateHtaccessFile($path);
        } catch (Exception $exception) {
            //$oView->setMessage( $oExcp->getMessage() );
            $htaccessUpdateError = true;
        }

        $systemRequirements = getSystemReqCheck();
        $info = $systemRequirements->getSystemInfo();
        foreach ($info as $groupName => $modules) {
            // translating
            $translatedGroupName = $language->getModuleName($groupName);
            foreach ($modules as $moduleName => $moduleState) {
                // translating
                $continue = $continue && ( bool )abs($moduleState);

                // was unable to update htaccess file for mod_rewrite check
                if ($htaccessUpdateError && $moduleName == 'server_permissions') {
                    $class = $setup->getModuleClass(0);
                    $continue = false;
                } else {
                    $class = $setup->getModuleClass($moduleState);
                }
                $groupModuleInfo[$translatedGroupName][] = array('module' => $moduleName,
                    'class' => $class,
                    'modulename' => $language->getModuleName($moduleName));
            }
        }

        $this->setViewOptions(
            'STEP_0_TITLE',
            [
                "blContinue" => $continue,
                "aGroupModuleInfo" => $groupModuleInfo,
                "aLanguages" => getLanguages(),
                "sLanguage" => $this->getSessionInstance()->getSessionParam('setup_lang'),
            ]
        );

        return "systemreq.php";
    }

    /**
     * Welcome page
     *
     * @return string
     */
    public function welcome()
    {
        $session = $this->getSessionInstance();

        //setting admin area default language
        $adminLanguage = $session->getSessionParam('setup_lang');
        $this->getUtilitiesInstance()->setCookie("oxidadminlanguage", $adminLanguage, time() + 31536000, "/");

        $this->setViewOptions(
            'STEP_1_TITLE',
            [
                "aCountries" => getCountryList(),
                "aLocations" => getLocation(),
                "aLanguages" => getLanguages(),
                "sShopLang" => $session->getSessionParam('sShopLang'),
                "sLanguage" => $this->getLanguageInstance()->getLanguage(),
                "sLocationLang" => $session->getSessionParam('location_lang'),
                "sCountryLang" => $session->getSessionParam('country_lang')
            ]
        );

        return "welcome.php";
    }

    /**
     * License confirmation page
     *
     * @return string
     */
    public function license()
    {
        $licenseFile = "lizenz.txt";
        $licenseContent = $this->getUtilitiesInstance()->getFileContents(
            $this->getUtilitiesInstance()->getSetupDirectory()
            . '/'. ucfirst($this->getLanguageInstance()->getLanguage())
            . '/' . $licenseFile
        );

        $this->setViewOptions(
            'STEP_2_TITLE',
            [
                "aLicenseText" => $licenseContent,
            ]
        );

        return "license.php";
    }

    /**
     * DB info entry page
     *
     * @return string
     */
    public function dbInfo()
    {
        $view = $this->getView();
        $session = $this->getSessionInstance();
        $systemRequirements = getSystemReqCheck();

        $eulaOptionValue = $this->getUtilitiesInstance()->getRequestVar("iEula", "post");
        $eulaOptionValue = (int)($eulaOptionValue ? $eulaOptionValue : $session->getSessionParam("eula"));
        if (!$eulaOptionValue) {
            $setup = $this->getSetupInstance();
            $setup->setNextStep($setup->getStep("STEP_WELCOME"));
            $view->setMessage($this->getLanguageInstance()->getText("ERROR_SETUP_CANCELLED"));

            return "licenseerror.php";
        }

        $databaseConfigValues = $session->getSessionParam('aDB');
        if (!isset($databaseConfigValues)) {
            // default values
            $databaseConfigValues['dbHost'] = "localhost";
            $databaseConfigValues['dbUser'] = "";
            $databaseConfigValues['dbPwd'] = "";
            $databaseConfigValues['dbName'] = "";
            $databaseConfigValues['dbiDemoData'] = 1;
        }

        $this->setViewOptions(
            'STEP_3_TITLE',
            [
                "aDB" => $databaseConfigValues,
                "blMbStringOn" => $systemRequirements->getModuleInfo('mb_string'),
                "blUnicodeSupport" => $systemRequirements->getModuleInfo('unicode_support')
            ]
        );

        return "dbinfo.php";
    }

    /**
     * Setup paths info entry page
     *
     * @return string
     */
    public function dirsInfo()
    {
        $session = $this->getSessionInstance();
        $setup = $this->getSetupInstance();

        if ($this->userDecidedOverwriteDB()) {
            $session->setSessionParam('blOverwrite', true);
        }

        $this->setViewOptions(
            'STEP_4_TITLE',
            [
                "aAdminData" => $session->getSessionParam('aAdminData'),
                "aPath" => $this->getUtilitiesInstance()->getDefaultPathParams(),
                "aSetupConfig" => ["blDelSetupDir" => $setup->deleteSetupDirectory()],
            ]
        );

        return "dirsinfo.php";
    }

    /**
     * Testing database connection
     *
     * @return string
     */
    public function dbConnect()
    {
        $setup = $this->getSetupInstance();
        $session = $this->getSessionInstance();
        $language = $this->getLanguageInstance();

        $view = $this->getView();
        $view->setTitle('STEP_3_1_TITLE');

        $databaseConfigValues = $this->getUtilitiesInstance()->getRequestVar("aDB", "post");
        $databaseConfigValues['iUtfMode'] = 1;
        $session->setSessionParam('aDB', $databaseConfigValues);

        // check if iportant parameters are set
        if (!$databaseConfigValues['dbHost'] || !$databaseConfigValues['dbName']) {
            $setup->setNextStep($setup->getStep('STEP_DB_INFO'));
            $view->setMessage($language->getText('ERROR_FILL_ALL_FIELDS'));

            return "default.php";
        }

        try {
            // ok check DB Connection
            $database = $this->getDatabaseInstance();
            $database->openDatabase($databaseConfigValues);
        } catch (Exception $exception) {
            if ($exception->getCode() === Database::ERROR_DB_CONNECT) {
                $setup->setNextStep($setup->getStep('STEP_DB_INFO'));
                $view->setMessage($language->getText('ERROR_DB_CONNECT') . " - " . $exception->getMessage());

                return "default.php";
            } elseif ($exception->getCode() === Database::ERROR_MYSQL_VERSION_DOES_NOT_FIT_REQUIREMENTS) {
                $setup->setNextStep($setup->getStep('STEP_DB_INFO'));
                $view->setMessage($exception->getMessage());

                return "default.php";
            } else {
                try {
                    // if database is not there, try to create it
                    $database->createDb($databaseConfigValues['dbName']);
                } catch (Exception $exception) {
                    $setup->setNextStep($setup->getStep('STEP_DB_INFO'));
                    $view->setMessage($exception->getMessage());

                    return "default.php";
                }
                $view->setViewParam("blCreated", 1);
            }
        }

        $view->setViewParam("aDB", $databaseConfigValues);

        // check if DB is already UP and running
        if (!$this->databaseCanBeOverwritten($database)) {
            $this->formMessageIfDBCanBeOverwritten($databaseConfigValues['dbName'], $view, $language, $session->getSid(), $setup->getStep('STEP_DIRS_INFO'));
            return "default.php";
        }

        $setup->setNextStep($setup->getStep('STEP_DIRS_INFO'));

        return "dbconnect.php";
    }

    /**
     * Creating database
     *
     * @return string
     */
    public function dbCreate()
    {
        $setup = $this->getSetupInstance();
        $session = $this->getSessionInstance();
        $language = $this->getLanguageInstance();

        $view = $this->getView();
        $view->setTitle('STEP_4_2_TITLE');

        $databaseConfigValues = $session->getSessionParam('aDB');

        $database = $this->getDatabaseInstance();
        $database->openDatabase($databaseConfigValues);

        // testing if Views can be created
        try {
            $database->testCreateView();
        } catch (Exception $exception) {
            // Views can not be created
            $view->setMessage($exception->getMessage());
            $setup->setNextStep($setup->getStep('STEP_DB_INFO'));

            return "default.php";
        }

        // check if DB is already UP and running
        if (!$this->databaseCanBeOverwritten($database)) {
            $this->formMessageIfDBCanBeOverwritten($databaseConfigValues['dbName'], $view, $language, $session->getSid(), $setup->getStep('STEP_DB_CREATE'));
            return "default.php";
        }

        //setting database collation
        $utfMode = 1;
        $database->setMySqlCollation($utfMode);

        try {
            $baseSqlDir = $this->getUtilitiesInstance()->getSqlDirectory(EditionSelector::COMMUNITY);
            $database->queryFile("$baseSqlDir/database_schema.sql");

            // install demo/initial data
            try {
                $this->installShopData($database, $databaseConfigValues['dbiDemoData']);
            } catch (Exception $exception) {
                // there where problems with queries
                $view->setMessage($language->getText('ERROR_BAD_DEMODATA') . "<br><br>" . $exception->getMessage());

                return "default.php";
            }

            $this->getUtilitiesInstance()->regenerateViews();
        } catch (Exception $exception) {
            $view->setMessage($exception->getMessage());

            return "default.php";
        }

        $editionSqlDir = $this->getUtilitiesInstance()->getSqlDirectory();

        //update dyn pages / shop country config options (from first step)
        $database->saveShopSettings(array());

        //applying utf-8 specific queries

        if ($utfMode) {
            $database->queryFile("$editionSqlDir/latin1_to_utf8.sql");

            //converting oxconfig table field 'oxvarvalue' values to utf
            $database->setMySqlCollation(0);
            $database->convertConfigTableToUtf();
        }

        try {
            $adminData = $session->getSessionParam('aAdminData');
            // creating admin user
            $database->writeAdminLoginData($adminData['sLoginName'], $adminData['sPassword']);
        } catch (Exception $exception) {
            $view->setMessage($exception->getMessage());

            return "default.php";
        }

        $view->setMessage($language->getText('STEP_4_2_UPDATING_DATABASE'));
        $this->onDirsWriteSetStep($setup);

        return "default.php";
    }

    /**
     * Writing config info
     *
     * @return string
     */
    public function dirsWrite()
    {
        $view = $this->getView();
        $setup = $this->getSetupInstance();
        $session = $this->getSessionInstance();
        $language = $this->getLanguageInstance();
        $utils = $this->getUtilitiesInstance();

        $view->setTitle('STEP_4_1_TITLE');

        $pathCollection = $utils->getRequestVar("aPath", "post");
        $setupConfig = $utils->getRequestVar("aSetupConfig", "post");
        $adminData = $utils->getRequestVar("aAdminData", "post");

        // correct them
        $pathCollection['sShopURL'] = $utils->preparePath($pathCollection['sShopURL']);
        $pathCollection['sShopDir'] = $utils->preparePath($pathCollection['sShopDir']);
        $pathCollection['sCompileDir'] = $utils->preparePath($pathCollection['sCompileDir']);
        $pathCollection['sBaseUrlPath'] = $utils->extractRewriteBase($pathCollection['sShopURL']);

        // using same array to pass additional setup variable
        if (isset($setupConfig['blDelSetupDir']) && $setupConfig['blDelSetupDir']) {
            $setupConfig['blDelSetupDir'] = 1;
        } else {
            $setupConfig['blDelSetupDir'] = 0;
        }

        $session->setSessionParam('aPath', $pathCollection);
        $session->setSessionParam('aSetupConfig', $setupConfig);
        $session->setSessionParam('aAdminData', $adminData);

        // check if important parameters are set
        if (!$pathCollection['sShopURL'] || !$pathCollection['sShopDir'] || !$pathCollection['sCompileDir']
            || !$adminData['sLoginName'] || !$adminData['sPassword'] || !$adminData['sPasswordConfirm']
        ) {
            $setup->setNextStep($setup->getStep('STEP_DIRS_INFO'));
            $view->setMessage($language->getText('ERROR_FILL_ALL_FIELDS'));

            return "default.php";
        }

        // check if passwords match
        if (strlen($adminData['sPassword']) < 6) {
            $setup->setNextStep($setup->getStep('STEP_DIRS_INFO'));
            $view->setMessage($language->getText('ERROR_PASSWORD_TOO_SHORT'));

            return "default.php";
        }

        // check if passwords match
        if ($adminData['sPassword'] != $adminData['sPasswordConfirm']) {
            $setup->setNextStep($setup->getStep('STEP_DIRS_INFO'));
            $view->setMessage($language->getText('ERROR_PASSWORDS_DO_NOT_MATCH'));

            return "default.php";
        }

        // check if email matches pattern
        if (!$utils->isValidEmail($adminData['sLoginName'])) {
            $setup->setNextStep($setup->getStep('STEP_DIRS_INFO'));
            $view->setMessage($language->getText('ERROR_USER_NAME_DOES_NOT_MATCH_PATTERN'));

            return "default.php";
        }

        // write it now
        try {
            $parameters = array_merge(( array )$session->getSessionParam('aDB'), $pathCollection);

            // updating config file
            $utils->updateConfigFile($parameters);

            // updating regular htaccess file
            $utils->updateHtaccessFile($parameters);

            // updating admin htaccess file
            //$oUtils->updateHtaccessFile( $aParams, "admin" );
        } catch (Exception $exception) {
            $setup->setNextStep($setup->getStep('STEP_DIRS_INFO'));
            $view->setMessage($exception->getMessage());

            return "default.php";
        }

        $view->setMessage($language->getText('STEP_4_1_DATA_WAS_WRITTEN'));
        $view->setViewParam("aPath", $pathCollection);
        $view->setViewParam("aSetupConfig", $setupConfig);

        $databaseConfigValues = $session->getSessionParam('aDB');
        $view->setViewParam("aDB", $databaseConfigValues);
        $setup->setNextStep($setup->getStep('STEP_DB_CREATE'));

        return "default.php";
    }

    /**
     * Final setup step
     *
     * @return string
     */
    public function finish()
    {
        $session = $this->getSessionInstance();
        $pathCollection = $session->getSessionParam("aPath");

        $this->setViewOptions(
            'STEP_6_TITLE',
            [
                "aPath" => $pathCollection,
                "aSetupConfig" => $session->getSessionParam("aSetupConfig"),
                "blWritableConfig" => is_writable($pathCollection['sShopDir'] . "/config.inc.php")
            ]
        );

        return "finish.php";
    }

    // END: Controllers

    /**
     * Returns View object
     *
     * @return View
     */
    public function getView()
    {
        return $this->view;
    }

    /**
     * @param Setup $setup
     */
    protected function onDirsWriteSetStep($setup)
    {
        $setup->setNextStep($setup->getStep('STEP_FINISH'));
    }

    /**
     * Check if database can be safely overwritten.
     *
     * @param Database $database database instance used to connect to DB
     *
     * @return bool
     */
    private function databaseCanBeOverwritten($database)
    {
        $canBeOverwritten = true;

        if (!$this->userDecidedOverwriteDB()) {
            $canBeOverwritten = !$this->getUtilitiesInstance()->checkDbExists($database);
        }

        return $canBeOverwritten;
    }

    /**
     * Return if user already decided to overwrite database.
     *
     * @return bool
     */
    private function userDecidedOverwriteDB()
    {
        $userDecidedOverwriteDatabase = false;

        $overwriteCheck = $this->getUtilitiesInstance()->getRequestVar("ow", "get");
        $session = $this->getSessionInstance();

        if (isset($overwriteCheck) || $session->getSessionParam('blOverwrite')) {
            $userDecidedOverwriteDatabase = true;
        }

        return $userDecidedOverwriteDatabase;
    }

    /**
     * Show warning-question if database with same name already exists.
     *
     * @param string   $databaseName name of database to check if exist
     * @param View     $view         to set parameters for template
     * @param Language $language     to translate text
     * @param string   $sessionId
     * @param string   $setupStep    where to redirect if chose to rewrite database
     */
    private function formMessageIfDBCanBeOverwritten($databaseName, $view, $language, $sessionId, $setupStep)
    {
        $view->setMessage(
            sprintf($language->getText('ERROR_DB_ALREADY_EXISTS'), $databaseName) .
            "<br><br>" . $language->getText('STEP_4_2_OVERWRITE_DB') . " <a href=\"index.php?sid=" . $sessionId . "&istep=" . $setupStep . "&ow=1\" id=\"step3Continue\" style=\"text-decoration: underline;\">" . $language->getText('HERE') . "</a>"
        );
    }

    /**
     * Installs demodata or initial, dependent on parameter
     *
     * @param Database $database
     * @param int      $demodata
     */
    private function installShopData($database, $demodata = 0)
    {
        $editionSqlDir = $this->getUtilitiesInstance()->getSqlDirectory();
        $baseSqlDir = $this->getUtilitiesInstance()->getSqlDirectory(EditionSelector::COMMUNITY);

        // If demodata files are provided.
        if ($this->getUtilitiesInstance()->checkIfDemodataPrepared($demodata)) {
            $this->getUtilitiesInstance()->migrateDatabase();

            // Install demo data.
            $database->queryFile($this->getUtilitiesInstance()->getDemodataSqlFilePath());
            // Copy demodata files.
            $this->getUtilitiesInstance()->demodataAssetsInstall();
        } else {
            $database->queryFile("$baseSqlDir/initial_data.sql");

            $this->getUtilitiesInstance()->migrateDatabase();

            if ($demodata) {
                $database->queryFile("$editionSqlDir/demodata.sql");
            }
        }
    }

    /**
     * @param string $title
     * @param array  $viewOptions
     */
    private function setViewOptions($title, $viewOptions)
    {
        $view = $this->getView();
        $view->setTitle($title);

        foreach ($viewOptions as $optionKey => $optionValue) {
            $view->setViewParam($optionKey, $optionValue);
        }
    }
}
