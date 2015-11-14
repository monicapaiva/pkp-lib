<?php

/**
 * @file classes/plugins/PluginHelper.inc.php
 *
 * Copyright (c) 2014-2015 Simon Fraser University Library
 * Copyright (c) 2003-2015 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class PluginHelper
 * @ingroup classes_plugins
 *
 * @brief Helper class implementing plugin administration functions.
 */

import('lib.pkp.classes.site.Version');
import('lib.pkp.classes.site.VersionCheck');
import('lib.pkp.classes.file.FileManager');
import('classes.install.Install');
import('classes.install.Upgrade');

define('PLUGIN_ACTION_UPLOAD', 'upload');
define('PLUGIN_ACTION_UPGRADE', 'upgrade');

define('PLUGIN_VERSION_FILE', 'version.xml');
define('PLUGIN_INSTALL_FILE', 'install.xml');
define('PLUGIN_UPGRADE_FILE', 'upgrade.xml');

class PluginHelper {
	/**
	 * Constructor.
	 * @param $function string PLUGIN_ACTION_...
	 */
	function PluginHelper() {
	}

	/**
	 * Extract and validate a plugin (prior to installation)
	 * @param $filePath string Full path to plugin archive
	 * @param $originalFileName string Original filename of plugin archive
	 * @return string|null Extracted plugin path on success; null on error
	 */
	function extractPlugin($filePath, $originalFileName, &$errorMsg) {
		// tar archive basename (less potential version number) must
		// equal plugin directory name and plugin files must be in a
		// directory named after the plug-in (potentially with version)
		$matches = array();
		String::regexp_match_get('/^[a-zA-Z0-9]+/', basename($originalFileName, '.tar.gz'), $matches);
		$pluginShortName = array_pop($matches);
		if (!$pluginShortName) {
			$errorMsg = __('manager.plugins.invalidPluginArchive');
			return null;
		}

		// Create random dirname to avoid symlink attacks.
		$pluginExtractDir = dirname($filePath) . DIRECTORY_SEPARATOR . $pluginShortName . substr(md5(mt_rand()), 0, 10);
		mkdir($pluginExtractDir);

		// Test whether the tar binary is available for the export to work
		$tarBinary = Config::getVar('cli', 'tar');
		if (!empty($tarBinary) && file_exists($tarBinary)) {
			exec($tarBinary.' -xzf ' . escapeshellarg($filePath) . ' -C ' . escapeshellarg($pluginExtractDir));
		} else {
			$errorMsg = __('manager.plugins.tarCommandNotFound');
		}

		if (empty($errorMsg)) {
			// Look for a directory named after the plug-in's short
			// (alphanumeric) name within the extracted archive.
			if (is_dir($tryDir = $pluginExtractDir . '/' . $pluginShortName)) {
				return $tryDir; // Success
			}

			// Failing that, look for a directory named after the
			// archive. (Typically also contains the version number
			// e.g. with github generated release archives.)
			String::regexp_match_get('/^[a-zA-Z0-9.-]+/', basename($originalFileName, '.tar.gz'), $matches);
			if (is_dir($tryDir = $pluginExtractDir . '/' . array_pop($matches))) {
				// We found a directory named after the archive
				// within the extracted archive. (Typically also
				// contains the version number, e.g. github
				// generated release archives.)
				return $tryDir;
			}
			$errorMsg = __('manager.plugins.invalidPluginArchive');
		}

		return null;
	}

	/**
	 * Installs an extracted plugin
	 * @param $path string path to plugin Directory
	 * @param $errorMsg string Reference to string receiving error message
	 * @return Version|null Version of installed plugin on success
	 */
	function installPlugin($path, &$errorMsg) {
		$versionFile = $path . '/' . PLUGIN_VERSION_FILE;

		$pluginVersion = VersionCheck::getValidPluginVersionInfo($versionFile, $errorMsg);
		if (!$pluginVersion) return null;

		$versionDao = DAORegistry::getDAO('VersionDAO'); /* @var $versionDao VersionDAO */
		$installedPlugin = $versionDao->getCurrentVersion($pluginVersion->getProductType(), $pluginVersion->getProduct(), true);

		if (!$installedPlugin) {
			$pluginLibDest = Core::getBaseDir() . '/' . PKP_LIB_PATH . '/' . strtr($pluginVersion->getProductType(), '.', '/') . '/' . $pluginVersion->getProduct();
			$pluginDest = Core::getBaseDir() . '/' . strtr($pluginVersion->getProductType(), '.', '/') . '/' . $pluginVersion->getProduct();

			// Copy the plug-in from the temporary folder to the
			// target folder.
			// Start with the library part (if any).
			$libPath = $path . '/lib';
			$fileManager = new FileManager();
			if (is_dir($libPath)) {
				if(!$fileManager->copyDir($libPath, $pluginLibDest)) {
					$errorMsg = __('manager.plugins.copyError');
					return null;
				}
				// Remove the library part of the temporary folder.
				$fileManager->rmtree($libPath);
			}

			// Continue with the application-specific part (mandatory).
			if (!$fileManager->copyDir($path, $pluginDest)) {
				$errorMsg = __('manager.plugins.copyError');
				return null;
			}

			// Remove the temporary folder.
			$fileManager->rmtree(dirname($path));

			// Upgrade the database with the new plug-in.
			$installFile = $pluginDest . '/' . PLUGIN_INSTALL_FILE;
			if(!is_file($installFile)) $installFile = Core::getBaseDir() . '/' . PKP_LIB_PATH . '/xml/defaultPluginInstall.xml';
			assert(is_file($installFile));
			$params = $this->_getConnectionParams();
			$installer = new Install($params, $installFile, true);
			$installer->setCurrentVersion($pluginVersion);
			if (!$installer->execute()) {
				// Roll back the copy
				if (is_dir($pluginLibDest)) $fileManager->rmtree($pluginLibDest);
				if (is_dir($pluginDest)) $fileManager->rmtree($pluginDest);
				$errorMsg = __('manager.plugins.installFailed', array('errorString' => $installer->getErrorString()));
				return null;
			}

			$versionDao->insertVersion($pluginVersion, true);
			return $pluginVersion;
		} else {
			if ($this->_checkIfNewer($pluginVersion->getProductType(), $pluginVersion->getProduct(), $pluginVersion)) {
				$errorMsg = __('manager.plugins.pleaseUpgrade');
			} else {
				$errorMsg = __('manager.plugins.installedVersionOlder');
			}
		}
		return null;
	}

	/**
	 * Checks to see if local version of plugin is newer than installed version
	 * @param $productType string Product type of plugin
	 * @param $productName string Product name of plugin
	 * @param $newVersion Version Version object of plugin to check against database
	 * @return boolean
	 */
	function _checkIfNewer($productType, $productName, $newVersion) {
		$versionDao = DAORegistry::getDAO('VersionDAO');
		$installedPlugin = $versionDao->getCurrentVersion($productType, $productName, true);
		if ($installedPlugin && $installedPlugin->compare($newVersion) > 0) return true;
		return false;
	}

	/**
	 * Load database connection parameters into an array (needed for upgrade).
	 * @return array
	 */
	function _getConnectionParams() {
		return array(
			'clientCharset' => Config::getVar('i18n', 'client_charset'),
			'connectionCharset' => Config::getVar('i18n', 'connection_charset'),
			'databaseCharset' => Config::getVar('i18n', 'database_charset'),
			'databaseDriver' => Config::getVar('database', 'driver'),
			'databaseHost' => Config::getVar('database', 'host'),
			'databaseUsername' => Config::getVar('database', 'username'),
			'databasePassword' => Config::getVar('database', 'password'),
			'databaseName' => Config::getVar('database', 'name')
		);
	}

	/**
	 * Upgrade a plugin to a newer version from the user's filesystem
	 * @param $category string
	 * @param $plugin string
	 * @param $path string path to plugin Directory
	 * @param $category string
	 * @param $plugin string
	 * @return Version|null The upgraded version, on success; null on fail
	 */
	function upgradePlugin($category, $plugin, $path, &$errorMsg) {
		$versionFile = $path . '/' . PLUGIN_VERSION_FILE;
		$pluginVersion = VersionCheck::getValidPluginVersionInfo($versionFile, $errorMsg);
		if (!$pluginVersion) return null;

		// Check whether the uploaded plug-in fits the original plug-in.
		if ('plugins.'.$category != $pluginVersion->getProductType()) {
			$errorMsg = __('manager.plugins.wrongCategory');
			return null;
		}

		if ($plugin != $pluginVersion->getProduct()) {
			$errorMsg = __('manager.plugins.wrongName');
			return null;
		}

		$versionDao = DAORegistry::getDAO('VersionDAO');
		$installedPlugin = $versionDao->getCurrentVersion($pluginVersion->getProductType(), $pluginVersion->getProduct(), true);
		if(!$installedPlugin) {
			$errorMsg = __('manager.plugins.pleaseInstall');
			return null;
		}

		if ($this->_checkIfNewer($pluginVersion->getProductType(), $pluginVersion->getProduct(), $pluginVersion)) {
			$errorMsg = __('manager.plugins.installedVersionNewer');
			return null;
		} else {
			$pluginDest = Core::getBaseDir() . '/plugins/' . $category . '/' . $plugin;
			$pluginLibDest = Core::getBaseDir() . '/' . PKP_LIB_PATH . '/plugins/' . $category . '/' . $plugin;

			// Delete existing files.
			$fileManager = new FileManager();
			if (is_dir($pluginDest)) $fileManager->rmtree($pluginDest);
			if (is_dir($pluginLibDest)) $fileManager->rmtree($pluginLibDest);

			// Check whether deleting has worked.
			if(is_dir($pluginDest) || is_dir($pluginLibDest)) {
				$errorMsg = __('message', 'manager.plugins.deleteError');
				return null;
			}

			// Copy the plug-in from the temporary folder to the
			// target folder.
			// Start with the library part (if any).
			$libPath = $path . '/lib';
			if (is_dir($libPath)) {
				if(!$fileManager->copyDir($libPath, $pluginLibDest)) {
					$errorMsg = __('manager.plugins.copyError');
					return null;
				}
				// Remove the library part of the temporary folder.
				$fileManager->rmtree($libPath);
			}

			// Continue with the application-specific part (mandatory).
			if(!$fileManager->copyDir($path, $pluginDest)) {
				$errorMsg = __('manager.plugins.copyError');
				return null;
			}

			// Remove the temporary folder.
			$fileManager->rmtree(dirname($path));

			$upgradeFile = $pluginDest . '/' . PLUGIN_UPGRADE_FILE;
			if($fileManager->fileExists($upgradeFile)) {
				$params = $this->_getConnectionParams();
				$installer = new Upgrade($params, $upgradeFile, true);

				if (!$installer->execute()) {
					$errorMsg = __('manager.plugins.upgradeFailed', array('errorString' => $installer->getErrorString()));
					return null;
				}
			}

			$installedPlugin->setCurrent(0);
			$pluginVersion->setCurrent(1);
			$versionDao->insertVersion($pluginVersion, true);
			return $pluginVersion;
		}
	}
}

?>