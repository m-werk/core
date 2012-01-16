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
 * @package    Backend
 * @license    LGPL
 */


/**
 * Run in a custom namespace, so the class can be replaced
 */
namespace Contao;


/**
 * Class ContentElementModel
 *
 * Provide methods to find and save content elements.
 * @copyright  Leo Feyer 2005-2012
 * @author     Leo Feyer <http://www.contao.org>
 * @package    Model
 */
class ContentElementModel extends \Model
{

	/**
	 * Name of the table
	 * @var string
	 */
	protected static $strTable = 'tl_content';


	/**
	 * Find all published content elements of an article
	 * @param integer
	 * @return Model|null
	 */
	public static function findPublishedByPid($intPid)
	{
		$objElements = \Database::getInstance()->prepare("SELECT * FROM tl_content WHERE pid=?" . (!BE_USER_LOGGED_IN ? " AND invisible=''" : "") . " ORDER BY sorting")
											   ->execute($intPid);

		if ($objElements->numRows < 1)
		{
			return null;
		}

		return new static($objElements);
	}
								 
}

?>