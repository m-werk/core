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
 * Class PageRegular
 *
 * Provide methods to handle a regular front end page.
 * @copyright  Leo Feyer 2005-2012
 * @author     Leo Feyer <http://www.contao.org>
 * @package    Controller
 */
class PageRegular extends \Frontend
{

	/**
	 * Generate a regular page
	 * @param Database_Result|Model
	 */
	public function generate($objPage)
	{
		$GLOBALS['TL_KEYWORDS'] = '';
		$GLOBALS['TL_LANGUAGE'] = $objPage->language;

		$this->loadLanguageFile('default');

		// Static URLs
		$this->setStaticUrl('TL_FILES_URL', $objPage->staticFiles);
		$this->setStaticUrl('TL_SCRIPT_URL', $objPage->staticSystem);
		$this->setStaticUrl('TL_PLUGINS_URL', $objPage->staticPlugins);

		// Get the page layout
		$objLayout = $this->getPageLayout($objPage->layout);
		$objPage->template = ($objLayout->template != '') ? $objLayout->template : 'fe_page';
		$objPage->templateGroup = $objLayout->pid['templates'];

		// Store the output format
		list($strFormat, $strVariant) = explode('_', $objLayout->doctype);
		$objPage->outputFormat = $strFormat;
		$objPage->outputVariant = $strVariant;

		// Initialize the template
		$this->createTemplate($objPage, $objLayout);

		// Initialize modules and sections
		$arrCustomSections = array();
		$arrSections = array('header', 'left', 'right', 'main', 'footer');
		$arrModules = deserialize($objLayout->modules);

		// Get all modules in a single DB query
		$arrModuleIds = array_map(function($arr) { return $arr['mod']; }, $arrModules);
		$objModules = \ModuleCollection::findMultipleByIds($arrModuleIds);

		if ($objModules !== null)
		{
			foreach ($arrModules as $arrModule)
			{
				// Replace the module ID with the result row
				if ($arrModule['mod'] > 0)
				{
					$objModules->next();
					$arrModule['mod'] = $objModules;
				}

				// Generate the modules
				if (in_array($arrModule['col'], $arrSections))
				{
					// Filter active sections (see #3273)
					if ($arrModule['col'] == 'header' && !$objLayout->header)
					{
						continue;
					}
					if ($arrModule['col'] == 'left' && $objLayout->cols != '2cll' && $objLayout->cols != '3cl')
					{
						continue;
					}
					if ($arrModule['col'] == 'right' && $objLayout->cols != '2clr' && $objLayout->cols != '3cl')
					{
						continue;
					}
					if ($arrModule['col'] == 'footer' && !$objLayout->footer)
					{
						continue;
					}

					$this->Template->$arrModule['col'] .= $this->getFrontendModule($arrModule['mod'], $arrModule['col']);
				}
				else
				{
					$arrCustomSections[$arrModule['col']] .= $this->getFrontendModule($arrModule['mod'], $arrModule['col']);
				}
			}
		}

		$this->Template->sections = $arrCustomSections;

		// HOOK: modify the page or layout object
		if (isset($GLOBALS['TL_HOOKS']['generatePage']) && is_array($GLOBALS['TL_HOOKS']['generatePage']))
		{
			foreach ($GLOBALS['TL_HOOKS']['generatePage'] as $callback)
			{
				$this->import($callback[0]);
				$this->$callback[0]->$callback[1]($objPage, $objLayout, $this);
			}
		}

		// Set the page title and description AFTER the modules have been generated
		$this->Template->mainTitle = $objPage->rootTitle;
		$this->Template->pageTitle = strlen($objPage->pageTitle) ? $objPage->pageTitle : $objPage->title;

		// Meta robots tag
		$this->Template->robots = ($objPage->robots != '') ? $objPage->robots : 'index,follow';

		// Remove shy-entities (see #2709)
		$this->Template->mainTitle = str_replace('[-]', '', $this->Template->mainTitle);
		$this->Template->pageTitle = str_replace('[-]', '', $this->Template->pageTitle);

		// Assign the title (backwards compatibility)
		$this->Template->title = $this->Template->mainTitle . ' - ' . $this->Template->pageTitle;
		$this->Template->description = str_replace(array("\n", "\r", '"'), array(' ' , '', ''), $objPage->description);

		// Body onload and body classes
		$this->Template->onload = trim($objLayout->onload);
		$this->Template->class = trim($objLayout->cssClass . ' ' . $objPage->cssClass);

		// HOOK: extension "bodyclass"
		if (in_array('bodyclass', $this->Config->getActiveModules()))
		{
			if (strlen($objPage->cssBody))
			{
				$this->Template->class .= ' ' . $objPage->cssBody;
			}
		}

		// Execute AFTER the modules have been generated and create footer scripts first
		$this->createFooterScripts($objPage, $objLayout);
		$this->createHeaderScripts($objPage, $objLayout);

		// Add an invisible character to empty sections (IE fix)
		if ($this->Template->header == '' && $objLayout->header)
		{
			$this->Template->header = '&nbsp;';
		}
		if ($this->Template->left == '' && ($objLayout->cols == '2cll' || $objLayout->cols == '3cl'))
		{
			$this->Template->left = '&nbsp;';
		}
		if ($this->Template->right == '' && ($objLayout->cols == '2clr' || $objLayout->cols == '3cl'))
		{
			$this->Template->right = '&nbsp;';
		}
		if ($this->Template->footer == '' && $objLayout->footer)
		{
			$this->Template->footer = '&nbsp;';
		}

		// Print the template to the screen
		$this->Template->output();
	}


	/**
	 * Get a page layout and return it as database result object
	 * @param integer
	 * @return Database_Result
	 */
	protected function getPageLayout($intId)
	{
		$objLayout = \LayoutModel::findByIdOrFallback($intId);

		// Die if there is no layout at all
		if ($objLayout === null)
		{
			header('HTTP/1.1 501 Not Implemented');
			$this->log('Could not find layout ID "' . $intId . '"', 'PageRegular getPageLayout()', TL_ERROR);
			die('No layout specified');
		}

		return $objLayout;
	}


	/**
	 * Create a new template
	 * @param Database_Result|Model
	 * @param Database_Result|Model
	 */
	protected function createTemplate($objPage, $objLayout)
	{
		$this->Template = new \FrontendTemplate($objPage->template);

		// Generate the DTD
		if ($objPage->outputFormat == 'xhtml')
		{
			if ($objPage->outputVariant == 'strict')
			{
				$this->Template->doctype = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">' . "\n";
			}
			else
			{
				$this->Template->doctype = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">' . "\n";
			}
		}

		$strFramework = '';

		// Generate the CSS framework
		if (!$objLayout->skipFramework)
		{
			// Wrapper
			if ($objLayout->static)
			{
				$arrSize = deserialize($objLayout->width);
				$arrMargin = array('left'=>'0 auto 0 0', 'center'=>'0 auto', 'right'=>'0 0 0 auto');
				$strFramework .= sprintf('#wrapper{width:%s;margin:%s;}', $arrSize['value'] . $arrSize['unit'], $arrMargin[$objLayout->align]) . "\n";
			}

			// Header
			if ($objLayout->header)
			{
				$arrSize = deserialize($objLayout->headerHeight);

				if ($arrSize['value'] != '' && $arrSize['value'] >= 0)
				{
					$strFramework .= sprintf('#header{height:%s;}', $arrSize['value'] . $arrSize['unit']) . "\n";
				}
			}

			$strMain = '';

			// Left column
			if ($objLayout->cols == '2cll' || $objLayout->cols == '3cl')
			{
				$arrSize = deserialize($objLayout->widthLeft);

				if ($arrSize['value'] != '' && $arrSize['value'] >= 0)
				{
					$strFramework .= sprintf('#left{width:%s;}', $arrSize['value'] . $arrSize['unit']) . "\n";
					$strMain .= sprintf('margin-left:%s;', $arrSize['value'] . $arrSize['unit']);
				}
			}

			// Right column
			if ($objLayout->cols == '2clr' || $objLayout->cols == '3cl')
			{
				$arrSize = deserialize($objLayout->widthRight);

				if ($arrSize['value'] != '' && $arrSize['value'] >= 0)
				{
					$strFramework .= sprintf('#right{width:%s;}', $arrSize['value'] . $arrSize['unit']) . "\n";
					$strMain .= sprintf('margin-right:%s;', $arrSize['value'] . $arrSize['unit']);
				}
			}

			// Main column
			if ($strMain != '')
			{
				$strFramework .= sprintf('#main{%s}', $strMain) . "\n";
			}

			// Footer
			if ($objLayout->footer)
			{
				$arrSize = deserialize($objLayout->footerHeight);

				if ($arrSize['value'] != '' && $arrSize['value'] >= 0)
				{
					$strFramework .= sprintf('#footer{height:%s;}', $arrSize['value'] . $arrSize['unit']) . "\n";
				}
			}

			// Add layout specific CSS
			if ($strFramework != '')
			{
				if ($objPage->outputFormat == 'xhtml')
				{
					$this->Template->framework .= '<style type="text/css" media="screen">' . "\n";
					$this->Template->framework .= '/* <![CDATA[ */' . "\n";
					$this->Template->framework .= $strFramework;
					$this->Template->framework .= '/* ]]> */' . "\n";
					$this->Template->framework .= '</style>' . "\n";
				}
				else
				{
					$this->Template->framework .= '<style media="screen">' . "\n";
					$this->Template->framework .= $strFramework;
					$this->Template->framework .= '</style>' . "\n";
				}
			}
		}

		$this->Template->mooScripts = '';

		// jQuery scripts
		if ($objLayout->jSource != '')
		{
			if ($objLayout->jSource == 'j_googleapis' || $objLayout->jSource == 'j_fallback')
			{
				$protocol = $this->Environment->ssl ? 'https://' : 'http://';
				$this->Template->mooScripts .= '<script' . (($objPage->outputFormat == 'xhtml') ? ' type="text/javascript"' : '') . ' src="' . $protocol . 'ajax.googleapis.com/ajax/libs/jquery/' . JQUERY . '/jquery.min.js"></script>' . "\n";

				// Local fallback (thanks to DyaGa)
				if ($objLayout->jSource == 'j_fallback')
				{
					$this->Template->mooScripts .= '<script' . (($objPage->outputFormat == 'xhtml') ? ' type="text/javascript"' : '') . '>window.jQuery || document.write(\'<script src="' . TL_PLUGINS_URL . 'plugins/jQuery/core/' . JQUERY . '/jquery.js">\x3C/script>\')</script>' . "\n";
				}
			}
			else
			{
				$this->Template->mooScripts .= '<script' . (($objPage->outputFormat == 'xhtml') ? ' type="text/javascript"' : '') . ' src="plugins/jQuery/core/' . JQUERY . '/jquery.js"></script>' . "\n";
			}
		}

		// MooTools scripts
		if ($objLayout->mooSource != '')
		{
			if ($objLayout->mooSource == 'moo_googleapis' || $objLayout->mooSource == 'moo_fallback')
			{
				$protocol = $this->Environment->ssl ? 'https://' : 'http://';
				$this->Template->mooScripts .= '<script' . (($objPage->outputFormat == 'xhtml') ? ' type="text/javascript"' : '') . ' src="' . $protocol . 'ajax.googleapis.com/ajax/libs/mootools/' . MOOTOOLS . '/mootools-yui-compressed.js"></script>' . "\n";

				// Local fallback (thanks to DyaGa)
				if ($objLayout->mooSource == 'moo_fallback')
				{
					$this->Template->mooScripts .= '<script' . (($objPage->outputFormat == 'xhtml') ? ' type="text/javascript"' : '') . '>window.MooTools || document.write(\'<script src="' . TL_PLUGINS_URL . 'plugins/mootools/core/' . MOOTOOLS . '/mootools-core.js">\x3C/script>\')</script>' . "\n";
				}

				$this->Template->mooScripts .= '<script' . (($objPage->outputFormat == 'xhtml') ? ' type="text/javascript"' : '') . ' src="' . TL_PLUGINS_URL . 'plugins/mootools/more/' . MOOTOOLS . '/mootools-more.js"></script>' . "\n";
				$this->Template->mooScripts .= '<script' . (($objPage->outputFormat == 'xhtml') ? ' type="text/javascript"' : '') . ' src="' . TL_PLUGINS_URL . 'plugins/mootools/mobile/mootools-mobile.js"></script>' . "\n";
			}
			else
			{
				$objCombiner = new \Combiner();

				$objCombiner->add('plugins/mootools/core/' . MOOTOOLS . '/mootools-core.js', MOOTOOLS);
				$objCombiner->add('plugins/mootools/more/' . MOOTOOLS . '/mootools-more.js', MOOTOOLS);
				$objCombiner->add('plugins/mootools/mobile/mootools-mobile.js', MOOTOOLS);

				$this->Template->mooScripts .= '<script' . (($objPage->outputFormat == 'xhtml') ? ' type="text/javascript"' : '') . ' src="' . $objCombiner->getCombinedFile() . '"></script>' . "\n";
			}
		}

		// Initialize sections
		$this->Template->header = '';
		$this->Template->left = '';
		$this->Template->main = '';
		$this->Template->right = '';
		$this->Template->footer = '';

		// Initialize custom layout sections
		$this->Template->sections = array();
		$this->Template->sPosition = $objLayout->sPosition;

		// Default settings
		$this->Template->layout = $objLayout;
		$this->Template->language = $GLOBALS['TL_LANGUAGE'];
		$this->Template->charset = $GLOBALS['TL_CONFIG']['characterSet'];
		$this->Template->base = $this->Environment->base;
		$this->Template->disableCron = $GLOBALS['TL_CONFIG']['disableCron'];
	}


	/**
	 * Create all header scripts
	 * @param Database_Result|Model
	 * @param Database_Result|Model
	 */
	protected function createHeaderScripts($objPage, $objLayout)
	{
		$strStyleSheets = '';
		$strCcStyleSheets = '';
		$arrStyleSheets = deserialize($objLayout->stylesheet);
		$strTagEnding = ($objPage->outputFormat == 'xhtml') ? ' />' : '>';

		// Google web fonts
		if ($objLayout->webfonts != '')
		{
			$protocol = $this->Environment->ssl ? 'https://' : 'http://';
			$strStyleSheets .= '<link' . (($objPage->outputFormat == 'xhtml') ? ' type="text/css"' : '') .' rel="stylesheet" href="' . $protocol . 'fonts.googleapis.com/css?family=' . $objLayout->webfonts . '"' . $strTagEnding . "\n";
		}

		$objCombiner = new \Combiner();

		// Skip the Contao framework style sheet
		if (!$objLayout->skipFramework)
		{
			$objCombiner->add('assets/contao/framework.css');
		}

		// Skip the TinyMCE style sheet
		if (!$objLayout->skipTinymce && file_exists(TL_ROOT . '/' . $GLOBALS['TL_CONFIG']['uploadPath'] . '/tinymce.css'))
		{
			$objCombiner->add($GLOBALS['TL_CONFIG']['uploadPath'] . '/tinymce.css', filemtime(TL_ROOT .'/'. $GLOBALS['TL_CONFIG']['uploadPath'] . '/tinymce.css'), 'all');
		}

		// Internal style sheets
		if (is_array($GLOBALS['TL_CSS']) && !empty($GLOBALS['TL_CSS']))
		{
			foreach (array_unique($GLOBALS['TL_CSS']) as $stylesheet)
			{
				list($stylesheet, $media, $mode) = explode('|', $stylesheet);

				if ($mode == 'static')
				{
					$objCombiner->add($stylesheet, filemtime(TL_ROOT . '/' . $stylesheet), $media);
				}
				else
				{
					$strStyleSheets .= '<link' . (($objPage->outputFormat == 'xhtml') ? ' type="text/css"' : '') . ' rel="stylesheet" href="' . $this->addStaticUrlTo($stylesheet) . '" media="' . (($media != '') ? $media : 'all') . '"' . $strTagEnding . "\n";
				}
			}
		}

		// User style sheets
		if (is_array($arrStyleSheets) && strlen($arrStyleSheets[0]))
		{
			$objStylesheets = \StyleSheetCollection::findByIds($arrStyleSheets);

			if ($objStylesheets !== null)
			{
				while ($objStylesheets->next())
				{
					$media = implode(',', deserialize($objStylesheets->media));

					// Overwrite the media type with a custom media query
					if ($objStylesheets->mediaQuery != '')
					{
						$media = $objStylesheets->mediaQuery;
					}

					// Aggregate regular style sheets
					if (!$objStylesheets->cc && !$objStylesheets->hasFontFace)
					{
						$objCombiner->add('assets/css/' . $objStylesheets->name . '.css', max($objStylesheets->tstamp, $objStylesheets->tstamp2), $media);
					}
					else
					{
						$strStyleSheet = '<link' . (($objPage->outputFormat == 'xhtml') ? ' type="text/css"' : '') . ' rel="stylesheet" href="' . TL_SCRIPT_URL . 'css/' . $objStylesheets->name . '.css" media="' . $media . '"' . $strTagEnding;

						if ($objStylesheets->cc)
						{
							$strStyleSheet = '<!--[' . $objStylesheets->cc . ']>' . $strStyleSheet . '<![endif]-->';
						}

						$strCcStyleSheets .= $strStyleSheet . "\n";
					}
				}
			}
		}

		$arrExternal = deserialize($objLayout->external);

		// External style sheets
		if (is_array($arrExternal) && !empty($arrExternal))
		{
			foreach (array_unique($arrExternal) as $stylesheet)
			{
				if ($stylesheet == '')
				{
					continue;
				}

				// Validate the file name
				if (strpos($stylesheet, '..') !== false || strncmp($stylesheet, $GLOBALS['TL_CONFIG']['uploadPath'] . '/', strlen($GLOBALS['TL_CONFIG']['uploadPath']) + 1) !== 0)
				{
					throw new \Exception("Invalid path $stylesheet");
				}

				list($stylesheet, $media, $mode) = explode('|', $stylesheet);
 
				// Only .css files are allowed
				if (substr($stylesheet, -4) != '.css')
				{
					throw new \Exception("Invalid file extension $stylesheet");
				}

				// Check whether the file exists
				if (!file_exists(TL_ROOT . '/' . $stylesheet))
				{
					continue;
				}

				// Include the file
				if ($mode == 'static')
				{
					$objCombiner->add($stylesheet, filemtime(TL_ROOT . '/' . $stylesheet), $media);
				}
				else
				{
					$strStyleSheets .= '<link' . (($objPage->outputFormat == 'xhtml') ? ' type="text/css"' : '') . ' rel="stylesheet" href="' . $this->addStaticUrlTo($stylesheet) . '" media="' . (($media != '') ? $media : 'all') . '"' . $strTagEnding . "\n";
				}
			}
		}

		// Create the aggregated style sheet
		if ($objCombiner->hasEntries())
		{
			$strStyleSheets .= '<link' . (($objPage->outputFormat == 'xhtml') ? ' type="text/css"' : '') . ' rel="stylesheet" href="' . $objCombiner->getCombinedFile() . '" media="all"' . $strTagEnding . "\n";
		}

		// Add the debug style sheet
		if ($GLOBALS['TL_CONFIG']['debugMode'])
		{
			$strStyleSheets .= '<link rel="stylesheet" href="' . $this->addStaticUrlTo('assets/contao/debug.css') . '" media="all">' . "\n";
		}

		// Always add conditional style sheets at the end
		$strStyleSheets .= $strCcStyleSheets;

		$newsfeeds = deserialize($objLayout->newsfeeds);
		$calendarfeeds = deserialize($objLayout->calendarfeeds);

		// Add newsfeeds
		if (is_array($newsfeeds) && !empty($newsfeeds))
		{
			$objFeeds = \NewsFeedCollection::findByIds($newsfeeds);

			if ($objFeeds !== null)
			{
				while($objFeeds->next())
				{
					$base = strlen($objFeeds->feedBase) ? $objFeeds->feedBase : $this->Environment->base;
					$strStyleSheets .= '<link rel="alternate" href="' . $base . 'share/' . $objFeeds->alias . '.xml" type="application/' . $objFeeds->format . '+xml" title="' . $objFeeds->title . '"' . $strTagEnding . "\n";
				}
			}
		}

		// Add calendarfeeds
		if (is_array($calendarfeeds) && !empty($calendarfeeds))
		{
			$objFeeds = \CalendarFeedCollection::findByIds($calendarfeeds);

			if ($objFeeds !== null)
			{
				while($objFeeds->next())
				{
					$base = strlen($objFeeds->feedBase) ? $objFeeds->feedBase : $this->Environment->base;
					$strStyleSheets .= '<link rel="alternate" href="' . $base . 'share/' . $objFeeds->alias . '.xml" type="application/' . $objFeeds->format . '+xml" title="' . $objFeeds->title . '"' . $strTagEnding . "\n";
				}
			}
		}

		$strHeadTags = '';

		// Add internal scripts
		if (is_array($GLOBALS['TL_JAVASCRIPT']) && !empty($GLOBALS['TL_JAVASCRIPT']))
		{
			foreach (array_unique($GLOBALS['TL_JAVASCRIPT']) as $javascript)
			{
				$strHeadTags .= '<script' . (($objPage->outputFormat == 'xhtml') ? ' type="text/javascript"' : '') . ' src="' . $this->addStaticUrlTo($javascript) . '"></script>' . "\n";
			}
		}

		// Add internal <head> tags
		if (is_array($GLOBALS['TL_HEAD']) && !empty($GLOBALS['TL_HEAD']))
		{
			foreach (array_unique($GLOBALS['TL_HEAD']) as $head)
			{
				$strHeadTags .= trim($head) . "\n";
			}
		}

		// Add user <head> tags
		if (($strHead = trim($objLayout->head)) != false)
		{
			$strHeadTags .= $strHead . "\n";
		}

		$this->Template->stylesheets = $strStyleSheets;
		$this->Template->head = $strHeadTags;
	}


	/**
	 * Create all footer scripts
	 * @param Database_Result|Model
	 * @param Database_Result|Model
	 */
	protected function createFooterScripts($objPage, $objLayout)
	{
		$strScripts = '';

		// jQuery
		$arrJquery = deserialize($objLayout->jquery, true);

		foreach ($arrJquery as $strTemplate)
		{
			if ($strTemplate == '')
			{
				continue;
			}

			$objTemplate = new \FrontendTemplate($strTemplate);
			$strScripts .= $objTemplate->parse();
		}

		// Add the internal jQuery scripts
		if (is_array($GLOBALS['TL_JQUERY']) && !empty($GLOBALS['TL_JQUERY']))
		{
			foreach (array_unique($GLOBALS['TL_JQUERY']) as $script)
			{
				$strScripts .= "\n" . trim($script) . "\n";
			}
		}

		// MooTools
		$arrMootools = deserialize($objLayout->mootools, true);

		foreach ($arrMootools as $strTemplate)
		{
			if ($strTemplate == '')
			{
				continue;
			}

			$objTemplate = new \FrontendTemplate($strTemplate);

			// Backwards compatibility
			try
			{
				$strScripts .= $objTemplate->parse();
			}
			catch (\Exception $e)
			{
				$this->log($e->getMessage(), 'PageRegular createFooterScripts()', TL_ERROR);
			}
		}

		// Add the internal MooTools scripts
		if (is_array($GLOBALS['TL_MOOTOOLS']) && !empty($GLOBALS['TL_MOOTOOLS']))
		{
			foreach (array_unique($GLOBALS['TL_MOOTOOLS']) as $script)
			{
				$strScripts .= "\n" . trim($script) . "\n";
			}
		}

		// Add the custom JavaScript
		if ($objLayout->script != '')
		{
			$strScripts .= "\n" . trim($objLayout->script) . "\n";
		}

		$this->Template->mootools = $strScripts;
	}
}

?>