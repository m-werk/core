<?php

/**
 * Contao Open Source CMS
 * Copyright (C) 2005-2012 Leo Feyer
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
 * PHP version 5.3
 * @copyright  Leo Feyer 2005-2012
 * @author     Leo Feyer <http://www.contao.org>
 * @package    Frontend
 * @license    LGPL
 */


/**
 * Run in a custom namespace, so the class can be replaced
 */
namespace Contao;


/**
 * Class Frontend
 *
 * Provide methods to manage front end controllers.
 * @copyright  Leo Feyer 2005-2012
 * @author     Leo Feyer <http://www.contao.org>
 * @package    Controller
 */
abstract class Frontend extends \Controller
{

	/**
	 * Meta array
	 * @var array
	 */
	protected $arrMeta = array();

	/**
	 * Aux array
	 * @var array
	 */
	protected $arrAux = array();

	/**
	 * Processed files array
	 * @var array
	 */
	protected $arrProcessed = array();


	/**
	 * Load the database object
	 */
	protected function __construct()
	{
		parent::__construct();
		$this->import('Database');
	}


	/**
	 * Split the current request into fragments, strip the URL suffix, recreate the $_GET array and return the page ID
	 * @return mixed
	 */
	protected function getPageIdFromUrl()
	{
		if ($GLOBALS['TL_CONFIG']['disableAlias'])
		{
			return is_numeric($this->Input->get('id')) ? $this->Input->get('id') : null;
		}

		if ($this->Environment->request == '')
		{
			return null;
		}

		// Get the request string without the index.php fragment
		if ($this->Environment->request == 'index.php')
		{
			$strRequest = '';
		}
		else
		{
			list($strRequest) = explode('?', str_replace('index.php/', '', $this->Environment->request), 2);
		}

		// Remove the URL suffix if not just a language root (e.g. en/) is requested
		if ($strRequest != '' && (!$GLOBALS['TL_CONFIG']['addLanguageToUrl'] || !preg_match('@^[a-z]{2}/$@', $strRequest)))
		{
			$intSuffixLength = strlen($GLOBALS['TL_CONFIG']['urlSuffix']);

			// Return false if the URL suffix does not match (see #2864)
			if ($intSuffixLength > 0)
			{
				if (substr($strRequest, -$intSuffixLength) != $GLOBALS['TL_CONFIG']['urlSuffix'])
				{
					return false;
				}

				$strRequest = substr($strRequest, 0, -$intSuffixLength);
			}
		}

		// Extract the language
		if ($GLOBALS['TL_CONFIG']['addLanguageToUrl'])
		{
			$arrMatches = array();

			// Use the matches instead of substr() (thanks to Mario Müller)
			if (preg_match('@^([a-z]{2})/(.*)$@', $strRequest, $arrMatches))
			{
				$this->Input->setGet('language', $arrMatches[1]);
				$strRequest = $arrMatches[2];
			}
			else
			{
				return false; // Language not provided
			}
		}

		$arrFragments = null;

		// Use folder-style URLs
		if ($GLOBALS['TL_CONFIG']['folderUrl'] && strpos($strRequest, '/') !== false)
		{
			$strAlias = $strRequest;
			$arrOptions = array($strAlias);

			// Compile all possible aliases by applying dirname() to the request (e.g. news/archive/item, news/archive, news)
			while (strpos($strAlias, '/') !== false)
			{
				$strAlias = dirname($strAlias);
				$arrOptions[] = $strAlias;
			}

			// Check if there is a page with a matching alias
			$objPage = \PageModel::findByAliases($arrOptions);

			if ($objPage !== null)
			{
				// The request consists of the alias only
				if ($strRequest == $objPage->alias)
				{
					$arrFragments = array($strRequest);
				}
				// Remove the alias from the request string, explode it and then re-insert the alias at the beginning 
				else
				{
					$arrFragments = explode('/', substr($strRequest, strlen($objPage->alias) + 1));
					array_unshift($arrFragments, $objPage->alias);
				}
			}
		}

		// If folderUrl is deactivated or did not find a matching page
		if ($arrFragments === null)
		{
			$arrFragments = explode('/', $strRequest);
		}

		// Add the second fragment as auto_item if the number of fragments is even
		if ($GLOBALS['TL_CONFIG']['useAutoItem'] && count($arrFragments) % 2 == 0)
		{
			array_insert($arrFragments, 1, array('auto_item'));
		}

		// HOOK: add custom logic
		if (isset($GLOBALS['TL_HOOKS']['getPageIdFromUrl']) && is_array($GLOBALS['TL_HOOKS']['getPageIdFromUrl']))
		{
			foreach ($GLOBALS['TL_HOOKS']['getPageIdFromUrl'] as $callback)
			{
				$this->import($callback[0]);
				$arrFragments = $this->$callback[0]->$callback[1]($arrFragments);
			}
		}

		$arrFragments = array_map('urldecode', $arrFragments);

		// Add the fragments to the $_GET array
		for ($i=1; $i<count($arrFragments); $i+=2)
		{
			// Return false the request contains an auto_item keyword (duplicate content) (see #4012)
			if ($GLOBALS['TL_CONFIG']['useAutoItem'] && in_array($arrFragments[$i], $GLOBALS['TL_AUTO_ITEM']))
			{
				return false;
			}

			$this->Input->setGet($arrFragments[$i], $arrFragments[$i+1]);
		}

		return ($arrFragments[0] != '') ? $arrFragments[0] : null;
	}


	/**
	 * Return the root page ID (backwards compatibility)
	 * @return integer
	 */
	protected function getRootIdFromUrl()
	{
		$objRootPage = $this->getRootPageFromUrl();
		return $objRootPage->numRows ? $objRootPage->id : 0;
	}


	/**
	 * Try to find a root page based on language and URL
	 * @return Database_Result
	 */
	protected function getRootPageFromUrl()
	{
		// HOOK: add custom logic
		if (isset($GLOBALS['TL_HOOKS']['getRootPageFromUrl']) && is_array($GLOBALS['TL_HOOKS']['getRootPageFromUrl']))
		{
			foreach ($GLOBALS['TL_HOOKS']['getRootPageFromUrl'] as $callback)
			{
				$this->import($callback[0]);
				$objRootPage = $this->$callback[0]->$callback[1]();

				if (is_object($objRootPage))
				{
					return $objRootPage;
				}
			}
		}

		$host = $this->Environment->host;

		// The language is set in the URL
		if ($GLOBALS['TL_CONFIG']['addLanguageToUrl'] && !empty($_GET['language']))
		{
			$objRootPage = \PageModel::findFirstPublishedRootByHostAndLanguage($host, $this->Input->get('language'));

			// No matching root page found
			if ($objRootPage === null)
			{
				header('HTTP/1.1 404 Not Found');
				$this->log('No root page found (host "' . $host . '", language "'. $this->Input->get('language') .'"', 'Frontend getRootPageFromUrl()', TL_ERROR);
				die('No root page found');
			}

			// Prefer a mobile website if the visitor uses a mobile device
			$this->redirectToMobile($objRootPage);
		}

		// No language given
		else
		{
			$accept_language = $this->Environment->httpAcceptLanguage;

			// Find the matching root pages (thanks to Andreas Schempp)
			$objRootPage = \PageModel::findFirstPublishedRootByHostAndLanguage($host, $accept_language);

			// No matching root page found
			if ($objRootPage === null)
			{
				header('HTTP/1.1 404 Not Found');
				$this->log('No root page found (host "' . $this->Environment->host . '", languages "'.implode(', ', $this->Environment->httpAcceptLanguage).'")', 'Frontend getRootPageFromUrl()', TL_ERROR);
				die('No root page found');
			}

			// Prefer a mobile website if the visitor uses a mobile device
			$this->redirectToMobile($objRootPage);

			// Redirect to the language root (e.g. en/)
			if ($GLOBALS['TL_CONFIG']['addLanguageToUrl'] && !$GLOBALS['TL_CONFIG']['doNotRedirectEmpty'] && $this->Environment->request == '')
			{
				$this->redirect((!$GLOBALS['TL_CONFIG']['rewriteURL'] ? 'index.php/' : '') . $objRootPage->language . '/', 302);
			}
		}

		return $objRootPage;
	}


	/**
	 * Redirect to another root page if the visitor uses a mobile device
	 * @param Database_Result|Model
	 */
	protected function redirectToMobile($objRootPage)
	{
		if (!$objRootPage->mobile || $objRootPage->jumpTo < 1 || !$this->Environment->agent->mobile)
		{
			return;
		}

		$objMobilePage = \PageModel::findPublishedById($objRootPage->jumpTo);

		if ($objMobilePage !== null && $objMobilePage->dns != '')
		{
			$this->redirect(($this->Environment->ssl ? 'https://' : 'http://') . $objMobilePage->dns . TL_PATH);
		}
	}


	/**
	 * Overwrite the parent method as front end URLs are handled differently
	 * @param string
	 * @param boolean
	 * @return string
	 */
	protected function addToUrl($strRequest, $blnIgnoreParams=false)
	{
		$arrGet = $blnIgnoreParams ? array() : $_GET;

		// Clean the $_GET values (thanks to thyon)
		foreach (array_keys($arrGet) as $key)
		{
			$arrGet[$key] = $this->Input->get($key, true);
		}

		$arrFragments = preg_split('/&(amp;)?/i', $strRequest);

		// Merge the new request string
		foreach ($arrFragments as $strFragment)
		{
			list($key, $value) = explode('=', $strFragment);

			if ($value == '')
			{
				unset($arrGet[$key]);
			}
			else
			{
				$arrGet[$key] = $value;
			}
		}

		// Unset the language parameter
		if ($GLOBALS['TL_CONFIG']['addLanguageToUrl'])
		{
			unset($arrGet['language']);
		}

		// Determine connector and separator
		if ($GLOBALS['TL_CONFIG']['disableAlias'])
		{
			$strConnector = '&amp;';
			$strSeparator = '=';
		}
		else
		{
			$strConnector = '/';
			$strSeparator = '/';
		}

		$strParams = '';

		// Compile the parameters string
		foreach ($arrGet as $k=>$v)
		{
			$strParams .= $strConnector . urlencode($k) . $strSeparator . urlencode($v);
		}

		// Do not use aliases
		if ($GLOBALS['TL_CONFIG']['disableAlias'])
		{
			return 'index.php?' . preg_replace('/^&(amp;)?/i', '', $strParams);
		}

		global $objPage;
		$pageId = ($objPage->alias != '') ? $objPage->alias : $objPage->id;

		// Get the page ID from URL if not set
		if (empty($pageId))
		{
			$pageId = $this->getPageIdFromUrl();
		}

		$pageLanguage = '';

		// Add the language
		if ($GLOBALS['TL_CONFIG']['addLanguageToUrl'])
		{
			$pageLanguage = $objPage->rootLanguage . '/';
		}

		return ($GLOBALS['TL_CONFIG']['rewriteURL'] ? '' : 'index.php/') . $pageLanguage . $pageId . $strParams . $GLOBALS['TL_CONFIG']['urlSuffix'];
	}


	/**
	 * Redirect to a jumpTo page or reload the current page
	 * @param integer|array
	 * @param string
	 * @param string
	 */
	protected function jumpToOrReload($intId, $strParams=null, $strForceLang=null)
	{
		global $objPage;

		if (is_array($intId))
		{
			if ($intId['id'] == $objPage->id)
			{
				$this->reload();
			}
			else
			{
				$this->redirect($this->generateFrontendUrl($intId, $strParams, $strForceLang));
			}
		}
		elseif ($intId > 0)
		{
			if ($intId == $objPage->id)
			{
				$this->reload();
			}
			else
			{
				$objNextPage = \PageModel::findPublishedById($intId);

				if ($objNextPage !== null)
				{
					$this->redirect($this->generateFrontendUrl($objNextPage->row(), $strParams, $strForceLang));
				}
			}
		}

		$this->reload();
	}


	/**
	 * Check whether a back end or front end user is logged in
	 * @param string
	 * @return boolean
	 */
	protected function getLoginStatus($strCookie)
	{
		$hash = sha1(session_id() . (!$GLOBALS['TL_CONFIG']['disableIpCheck'] ? $this->Environment->ip : '') . $strCookie);

		// Validate the cookie hash
		if ($this->Input->cookie($strCookie) == $hash)
		{
			// Try to find the session
			$objSession = \SessionModel::findByHashAndName($hash, $strCookie);

			// Validate the session ID and timeout
			if ($objSession !== null && $objSession->sessionID == session_id() && ($GLOBALS['TL_CONFIG']['disableIpCheck'] || $objSession->ip == $this->Environment->ip) && ($objSession->tstamp + $GLOBALS['TL_CONFIG']['sessionTimeout']) > time())
			{
				// Disable the cache if a back end user is logged in
				if (TL_MODE == 'FE' && $strCookie == 'BE_USER_AUTH')
				{
					$_SESSION['DISABLE_CACHE'] = true;

					// Always return false if we are not in preview mode (show hidden elements)
					if (!$this->Input->cookie('FE_PREVIEW'))
					{
						$_SESSION['TL_USER_LOGGED_IN'] = false;
						return false;
					}
				}

				// The session could be verified
				$_SESSION['TL_USER_LOGGED_IN'] = true;
				return true;
			}
		}

		// Reset the cache settings
		if (TL_MODE == 'FE' && $strCookie == 'BE_USER_AUTH')
		{
			$_SESSION['DISABLE_CACHE'] = false;
		}

		// The session could not be verified
		$_SESSION['TL_USER_LOGGED_IN'] = false;
		return false;
	}


	/**
	 * Parse the meta.txt file of a folder
	 * @param string
	 * @param boolean
	 */
	protected function parseMetaFile($strPath, $blnIsFile=false)
	{
		if (in_array($strPath, $this->arrProcessed))
		{
			return;
		}

		$strFile = $strPath . '/meta_' . $GLOBALS['TL_LANGUAGE'] . '.txt';

		if (!file_exists(TL_ROOT . '/' . $strFile))
		{
			$strFile = $strPath . '/meta.txt';

			if (!file_exists(TL_ROOT . '/' . $strFile))
			{
				return;
			}
		}

		$strBuffer = file_get_contents(TL_ROOT . '/' . $strFile);
		$strBuffer = utf8_convert_encoding($strBuffer, $GLOBALS['TL_CONFIG']['characterSet']);
		$arrBuffer = array_filter(trimsplit('[\n\r]+', $strBuffer));

		foreach ($arrBuffer as $v)
		{
			list($strLabel, $strValue) = array_map('trim', explode('=', $v, 2));
			$this->arrMeta[$strLabel] = array_map('trim', explode('|', $strValue));

			if (!$blnIsFile || in_array($strPath . '/' . $strLabel, $this->multiSRC))
			{
				$this->arrAux[] = $strPath . '/' . $strLabel;
			}
		}

		$this->arrProcessed[] = $strPath;
	}


	/**
	 * Prepare a text to be used in the meta description tag
	 * @param string
	 * @return string
	 */
	protected function prepareMetaDescription($strText)
	{
		$this->import('String');

		$strText = $this->replaceInsertTags($strText);
		$strText = strip_tags($strText);
		$strText = str_replace("\n", ' ', $strText);
		$strText = $this->String->substr($strText, 180);

		return trim($strText);
	}
}

?>