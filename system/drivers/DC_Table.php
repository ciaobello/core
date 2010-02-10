<?php if (!defined('TL_ROOT')) die('You can not access this file directly!');

/**
 * TYPOlight Open Source CMS
 * Copyright (C) 2005-2010 Leo Feyer
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
 * @copyright  Leo Feyer 2005-2010
 * @author     Leo Feyer <http://www.typolight.org>
 * @package    System
 * @license    LGPL
 * @filesource
 */


/**
 * Class DC_Table
 *
 * Provide methods to modify the database.
 * @copyright  Leo Feyer 2005-2010
 * @author     Leo Feyer <http://www.typolight.org>
 * @package    Controller
 */
class DC_Table extends DataContainer implements listable, editable
{

	/**
	 * Name of the parent table
	 * @param  string
	 */
	protected $ptable;

	/**
	 * Names of one or more child tables
	 * @param  array
	 */
	protected $ctable;

	/**
	 * ID of the current record
	 * @param  int
	 */
	protected $id;

	/**
	 * IDs of all root records
	 * @param  mixed
	 */
	protected $root;

	/**
	 * ID of the button container
	 * @param string
	 */
	protected $bid;

	/**
	 * Limit (database query)
	 * @param  string
	 */
	protected $limit;

	/**
	 * First sorting field
	 * @param  string
	 */
	protected $firstOrderBy;

	/**
	 * Order by (database query)
	 * @param  array
	 */
	protected $orderBy = array();

	/**
	 * Fields of a new or duplicated record
	 * @param  array
	 */
	protected $set = array();

	/**
	 * IDs of all records that are currently displayed
	 * @param  array
	 */
	protected $current = array();

	/**
	 * Show the current table as tree
	 * @param  boolean
	 */
	protected $treeView = false;

	/**
	 * True if a new version has to be created
	 * @param boolean
	 */
	protected $blnCreateNewVersion = false;

	/**
	 * True if one of the form fields is uploadable
	 * @param boolean
	 */
	protected $blnUploadable = false;


	/**
	 * Initialize the object
	 * @param string
	 */
	public function __construct($strTable)
	{
		parent::__construct();
		$this->intId = $this->Input->get('id');

		// Clear the clipboard
		if (isset($_GET['clipboard']))
		{
			$this->Session->set('CLIPBOARD', array());
			$this->redirect($this->getReferer());
		}

		// Check whether the table is defined
		if (!strlen($strTable) || !count($GLOBALS['TL_DCA'][$strTable]))
		{
			$this->log('Could not load data container configuration for "' . $strTable . '"', 'DC_Table __construct()', TL_ERROR);
			trigger_error('Could not load data container configuration', E_USER_ERROR);
		}

		// Set IDs and redirect
		if ($this->Input->post('FORM_SUBMIT') == 'tl_select')
		{
			$ids = deserialize($this->Input->post('IDS'));

			if (!is_array($ids) || count($ids) < 1)
			{
				$this->reload();
			}

			$session = $this->Session->getData();
			$session['CURRENT']['IDS'] = deserialize($this->Input->post('IDS'));
			$this->Session->setData($session);

			if (isset($_POST['edit']))
			{
				$this->redirect(str_replace('act=select', 'act=editAll', $this->Environment->request));
			}
			elseif (isset($_POST['delete']))
			{
				$this->redirect(str_replace('act=select', 'act=deleteAll', $this->Environment->request));
			}
			elseif (isset($_POST['override']))
			{
				$this->redirect(str_replace('act=select', 'act=overrideAll', $this->Environment->request));
			}
			elseif (isset($_POST['cut']) || isset($_POST['copy']))
			{
				$arrClipboard = $this->Session->get('CLIPBOARD');

				$arrClipboard[$strTable] = array
				(
					'id' => $ids,
					'mode' => (isset($_POST['cut']) ? 'cutAll' : 'copyAll')
				);

				$this->Session->set('CLIPBOARD', $arrClipboard);
				$this->redirect($this->getReferer());
			}
		}

		$this->strTable = $strTable;
		$this->ptable = $GLOBALS['TL_DCA'][$this->strTable]['config']['ptable'];
		$this->ctable = $GLOBALS['TL_DCA'][$this->strTable]['config']['ctable'];
		$this->treeView = in_array($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'], array(5, 6));
		$this->root = null;

		// Call onload_callback (e.g. to check permissions)
		if (is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['onload_callback']))
		{
			foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['onload_callback'] as $callback)
			{
				if (is_array($callback))
				{
					$this->import($callback[0]);
					$this->$callback[0]->$callback[1]($this);
				}
			}
		}

		// Get the IDs of all root records (tree view)
		if ($this->treeView)
		{
			$table = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 6) ? $this->ptable : $this->strTable;

			 // Unless there are any root records specified, use all records with parent ID 0
			if (!isset($GLOBALS['TL_DCA'][$table]['list']['sorting']['root']) || $GLOBALS['TL_DCA'][$table]['list']['sorting']['root'] === false)
			{
				$objIds = $this->Database->prepare("SELECT id FROM " . $table . " WHERE pid=?" . ($this->Database->fieldExists('sorting', $strTable) ? ' ORDER BY sorting' : ''))
										 ->execute(0);

				if ($objIds->numRows > 0)
				{
					$this->root = $objIds->fetchEach('id');
				}
			}

			// Get root records from global configuration file
			elseif (is_array($GLOBALS['TL_DCA'][$table]['list']['sorting']['root']))
			{
				$this->root = $this->eliminateNestedPages($GLOBALS['TL_DCA'][$table]['list']['sorting']['root'], $table);
			}
		}

		// Get the IDs of all root records (list view or parent view)
		elseif (is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['root']))
		{
			$this->root = array_unique($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['root']);
		}
	}


	/**
	 * Return an object property
	 * @param string
	 * @return mixed
	 */
	public function __get($strKey)
	{
		switch ($strKey)
		{
			case 'id':
				return $this->intId;
				break;

			case 'parentTable':
				return $this->ptable;
				break;

			case 'childTable':
				return $this->ctable;
				break;

			case 'rootIds':
				return $this->root;
				break;

			case 'createNewVersion':
				return $this->blnCreateNewVersion;
				break;

			default:
				return parent::__get($strKey);
				break;
		}
	}


	/**
	 * List all records of a particular table
	 * @return string
	 */
	public function showAll()
	{
		$return = '';
		$this->limit = '';
		$this->bid = 'tl_buttons';

		// Clean up old tl_undo and tl_log entries
		if ($this->strTable == 'tl_undo' && strlen($GLOBALS['TL_CONFIG']['undoPeriod']))
		{
			$this->Database->prepare("DELETE FROM tl_undo WHERE tstamp<?")
						   ->execute(intval(time() - $GLOBALS['TL_CONFIG']['undoPeriod']));
		}

		elseif ($this->strTable == 'tl_log' && strlen($GLOBALS['TL_CONFIG']['logPeriod']))
		{
			$this->Database->prepare("DELETE FROM tl_log WHERE tstamp<?")
						   ->execute(intval(time() - $GLOBALS['TL_CONFIG']['logPeriod']));
		}

		$this->reviseTable();

		// Add to clipboard
		if ($this->Input->get('act') == 'paste')
		{
			$arrClipboard = $this->Session->get('CLIPBOARD');

			$arrClipboard[$this->strTable] = array
			(
				'id' => $this->Input->get('id'),
				'childs' => $this->Input->get('childs'),
				'mode' => $this->Input->get('mode')
			);

			$this->Session->set('CLIPBOARD', $arrClipboard);
		}

		if ($this->treeView)
		{
			$return .= $this->treeView();
		}

		else
		{
			if ($this->Input->get('table') && $GLOBALS['TL_DCA'][$this->strTable]['config']['ptable'] && $this->Database->fieldExists('pid', $this->strTable))
			{
				$this->procedure[] = 'pid=?';
				$this->values[] = CURRENT_ID;
			}

			$return .= $this->panel();
			$return .= ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 4) ? $this->parentView() : $this->listView();

			// Add another panel at the end of the page
			if (strpos($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['panelLayout'], 'limit') !== false && ($strLimit = $this->limitMenu(true)) != false)
			{
				$return .= '

<form action="'.ampersand($this->Environment->request, true).'" class="tl_form" method="post">
<div class="tl_formbody">
<input type="hidden" name="FORM_SUBMIT" value="tl_filters_limit" />

<div class="tl_panel_bottom">

<div class="tl_submit_panel tl_subpanel">
<input type="image" name="btfilter" id="btfilter" src="system/themes/' . $this->getTheme() . '/images/reload.gif" class="tl_img_submit" alt="apply changes" value="apply changes" />
</div>' . $strLimit . '

<div class="clear"></div>

</div>

</div>
</form>
';
			}
		}

		// Store the current IDs
		$session = $this->Session->getData();
		$session['CURRENT']['IDS'] = $this->current;
		$this->Session->setData($session);

		return $return;
	}


	/**
	 * Return all non-excluded fields of a record as HTML table
	 * @return string
	 */
	public function show()
	{
		if (!strlen($this->intId))
		{
			return '';
		}

		$objRow = $this->Database->prepare("SELECT * FROM " . $this->strTable . " WHERE id=?")
								 ->limit(1)
								 ->execute($this->intId);

		if ($objRow->numRows < 1)
		{
			return '';
		}

		$count = 1;
		$return = '';
		$row = $objRow->row();

		// Get all fields
		$fields = array_keys($row);
		$allowedFields = array('id', 'pid', 'sorting', 'tstamp');

		if (is_array($GLOBALS['TL_DCA'][$this->strTable]['fields']))
		{
			$allowedFields = array_unique(array_merge($allowedFields, array_keys($GLOBALS['TL_DCA'][$this->strTable]['fields'])));
		}

		// Use the field order of the DCA file
		$fields = array_intersect($allowedFields, $fields);

		// Show all allowed fields
		foreach ($fields as $i)
		{
			if (!in_array($i, $allowedFields) || $GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['inputType'] == 'password' || $GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['eval']['doNotShow'])
			{
				continue;
			}

			// Special treatment for table tl_undo
			if ($this->strTable == 'tl_undo' && $i == 'data')
			{
				continue;
			}

			$value = deserialize($row[$i]);
			$class = (($count++ % 2) == 0) ? ' class="tl_bg"' : '';
		
			// Get field value
			if (strlen($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['foreignKey']))
			{
				$temp = array();
				$chunks = explode('.', $GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['foreignKey']);

				foreach ((array) $value as $v)
				{
					$objKey = $this->Database->prepare("SELECT " . $chunks[1] . " FROM " . $chunks[0] . " WHERE id=?")
											 ->limit(1)
											 ->execute($v);

					if ($objKey->numRows)
					{
						$temp[] = $objKey->$chunks[1];
					}
				}

				$row[$i] = implode(', ', $temp);
			}

			elseif (is_array($value))
			{
				foreach ($value as $kk=>$vv)
				{
					if (is_array($vv))
					{
						$vals = array_values($vv);
						$value[$kk] = $vals[0].' ('.$vals[1].')';
					}
				}

				$row[$i] = implode(', ', $value);
			}

			elseif ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['eval']['rgxp'] == 'date')
			{
				$row[$i] = $this->parseDate($GLOBALS['TL_CONFIG']['dateFormat'], $value);
			}

			elseif ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['eval']['rgxp'] == 'time')
			{
				$row[$i] = $this->parseDate($GLOBALS['TL_CONFIG']['timeFormat'], $value);
			}

			elseif ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['eval']['rgxp'] == 'datim' || in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['flag'], array(5, 6, 7, 8, 9, 10)) || $i == 'tstamp')
			{
				$row[$i] = $this->parseDate($GLOBALS['TL_CONFIG']['datimFormat'], $value);
			}

			elseif ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['inputType'] == 'checkbox' && !$GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['eval']['multiple'])
			{
				$row[$i] = strlen($value) ? $GLOBALS['TL_LANG']['MSC']['yes'] : $GLOBALS['TL_LANG']['MSC']['no'];
			}

			elseif (is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['reference']))
			{
				$row[$i] = strlen($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['reference'][$row[$i]]) ? ((is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['reference'][$row[$i]])) ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['reference'][$row[$i]][0] : $GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['reference'][$row[$i]]) : $row[$i];
			}

			// Label
			if (count($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['label']))
			{
				$label = is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['label']) ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['label'][0] : $GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['label'];
			}

			else
			{
				$label = is_array($GLOBALS['TL_LANG']['MSC'][$i]) ? $GLOBALS['TL_LANG']['MSC'][$i][0] : $GLOBALS['TL_LANG']['MSC'][$i];
			}

			if (!strlen($label))
			{
				$label = $i;
			}

			$return .= '
  <tr>
    <td'.$class.'><span class="tl_label">'.$label.': </span></td>
    <td'.$class.'>'.$row[$i].'</td>
  </tr>';
		}

		// Special treatment for tl_undo
		if ($this->strTable == 'tl_undo')
		{
			$arrData = deserialize($objRow->data);

			foreach ($arrData as $strTable=>$arrTableData)
			{
				$this->loadLanguageFile($strTable);
				$this->loadDataContainer($strTable);

				foreach ($arrTableData as $arrRow)
				{
					$count = 0;
					$return .= '
  <tr>
    <td colspan="2" style="padding:0px;"><div style="margin-bottom:26px; line-height:24px; border-bottom:1px dotted #cccccc;">&nbsp;</div></td>
  </tr>';

					foreach ($arrRow as $i=>$v)
					{
						if (is_array(deserialize($v)))
						{
							continue;
						}

						$class = (($count++ % 2) == 0) ? ' class="tl_bg"' : '';

						// Get the field label
						if (count($GLOBALS['TL_DCA'][$strTable]['fields'][$i]['label']))
						{
							$label = is_array($GLOBALS['TL_DCA'][$strTable]['fields'][$i]['label']) ? $GLOBALS['TL_DCA'][$strTable]['fields'][$i]['label'][0] : $GLOBALS['TL_DCA'][$strTable]['fields'][$i]['label'];
						}

						else
						{
							$label = is_array($GLOBALS['TL_LANG']['MSC'][$i]) ? $GLOBALS['TL_LANG']['MSC'][$i][0] : $GLOBALS['TL_LANG']['MSC'][$i];
						}

						if (!strlen($label))
						{
							$label = $i;
						}

						$return .= '
  <tr>
    <td'.$class.'><span class="tl_label">'.$label.': </span></td>
    <td'.$class.'>'.$v.'</td>
  </tr>';
					}
				}
			}
		}

		// Return table
		return '
<div id="tl_buttons">
<a href="'.$this->getReferer(true).'" class="header_back" title="'.specialchars($GLOBALS['TL_LANG']['MSC']['backBT']).'" accesskey="b" onclick="Backend.getScrollOffset();">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a>
</div>

<h2 class="sub_headline">'.sprintf($GLOBALS['TL_LANG']['MSC']['showRecord'], ($this->intId ? 'ID '.$this->intId : '')).'</h2>

<table cellpadding="0" cellspacing="0" class="tl_show" summary="Table lists all details of an entry">'.$return.'
</table>';
	}


	/**
	 * Insert a new row into a database table
	 * @param array
	 */
	public function create($set=array())
	{
		// Get all default values for the new entry
		foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'] as $k=>$v)
		{
			if (isset($v['default']))
			{
				$this->set[$k] = is_array($v['default']) ? serialize($v['default']) : $v['default'];
			}
		}

		// Set passed values
		if (is_array($set) && count($set))
		{
			$this->set = array_merge($this->set, $set);
		}

		// Get the new position
		$this->getNewPosition('new', (strlen($this->Input->get('pid')) ? $this->Input->get('pid') : null), ($this->Input->get('mode') == '2' ? true : false));

		// Empty clipboard
		$arrClipboard = $this->Session->get('CLIPBOARD');
		$arrClipboard[$this->strTable] = array();
		$this->Session->set('CLIPBOARD', $arrClipboard);

		// Insert the record if the table is not closed and switch to edit mode
		if (!$GLOBALS['TL_DCA'][$this->strTable]['config']['closed'])
		{
			$this->set['tstamp'] = 0;

			$objInsertStmt = $this->Database->prepare("INSERT INTO " . $this->strTable . " %s")
											->set($this->set)
											->execute();

			if ($objInsertStmt->affectedRows)
			{
				$s2e = $GLOBALS['TL_DCA'][$this->strTable]['config']['switchToEdit'] ? '&s2e=1' : '';
				$insertID = $objInsertStmt->insertId;

				// Save new record in the session
				$new_records = $this->Session->get('new_records');
				$new_records[$this->strTable][] = $insertID;
				$this->Session->set('new_records', $new_records);

				// Add a log entry
				$this->log('A new entry in table "'.$this->strTable.'" has been created (ID: '.$insertID.')', 'DC_Table create()', TL_GENERAL);
				$this->redirect($this->switchToEdit($insertID).$s2e);
			}
		}

		$this->redirect($this->getReferer());
	}


	/**
	 * Assign a new position to an existing record
	 * @param boolean
	 */
	public function cut($blnDoNotRedirect=false)
	{
		$cr = array();

		// ID and PID are mandatory
		if (!$this->intId || !strlen($this->Input->get('pid')))
		{
			$this->redirect($this->getReferer());
		}

		// Get the new position
		$this->getNewPosition('cut', $this->Input->get('pid'), ($this->Input->get('mode') == '2' ? true : false));

		// Avoid circular references when there is no parent table
		if ($this->Database->fieldExists('pid', $this->strTable) && !strlen($this->ptable))
		{
			$cr = $this->getChildRecords($this->intId, $this->strTable);
			$cr[] = $this->intId;
		}

		// Empty clipboard
		$arrClipboard = $this->Session->get('CLIPBOARD');
		$arrClipboard[$this->strTable] = array();
		$this->Session->set('CLIPBOARD', $arrClipboard);

		// Update the record
		if (in_array($this->set['pid'], $cr))
		{
			$this->log('Attempt to relate record "'.$this->intId.'" of table "'.$this->strTable.'" to its child record "'.$this->Input->get('pid').'" (circular reference)', 'DC_Table cut()', TL_ERROR);
			$this->redirect('typolight/main.php?act=error');
		}

		$this->set['tstamp'] = time();

		// HOOK: style sheet category
		if ($this->strTable == 'tl_style')
		{
			$filter = $this->Session->get('filter');
			$category = $filter['tl_style_' . CURRENT_ID]['category'];

			if ($category != '')
			{
				$this->set['category'] = $category;
			}
		}

		$this->Database->prepare("UPDATE " . $this->strTable . " %s WHERE id=?")
					   ->set($this->set)
					   ->execute($this->intId);

		if (!$blnDoNotRedirect)
		{
			$this->redirect($this->getReferer());
		}
	}


	/**
	 * Move all selected records
	 */
	public function cutAll()
	{
		// PID is mandatory
		if (!strlen($this->Input->get('pid')))
		{
			$this->redirect($this->getReferer());
		}

		$arrClipboard = $this->Session->get('CLIPBOARD');

		if (isset($arrClipboard[$this->strTable]) && is_array($arrClipboard[$this->strTable]['id']))
		{
			foreach ($arrClipboard[$this->strTable]['id'] as $id)
			{
				$this->intId = $id;
				$this->cut(true);
				$this->Input->setGet('pid', $id);
				$this->Input->setGet('mode', 1);
			}
		}

		$this->redirect($this->getReferer());
	}


	/**
	 * Duplicate a particular record of the current table
	 * @param boolean
	 * @return integer
	 */
	public function copy($blnDoNotRedirect=false)
	{
		if (!$this->intId)
		{
			$this->redirect($this->getReferer());
		}

		$objRow = $this->Database->prepare("SELECT * FROM " . $this->strTable . " WHERE id=?")
								 ->limit(1)
								 ->execute($this->intId);

		// Copy values if the record contains data
		if ($objRow->numRows)
		{
			foreach ($objRow->fetchAssoc() as $k=>$v)
			{
				if (in_array($k, array_keys($GLOBALS['TL_DCA'][$this->strTable]['fields'])))
				{
					// Reset all unique, doNotCopy and fallback fields to their default value
					if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['eval']['unique'] || $GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['eval']['doNotCopy'] || $GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['eval']['fallback'])
					{
						$v = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['default'] ? ((is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['default'])) ? serialize($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['default']) : $GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['default']) : '';
					}

					// Set fields (except password fields)
					$this->set[$k] = ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['inputType'] == 'password' ? '' : $v);
				}
			}

			// HOOK: style sheet category
			if ($this->strTable == 'tl_style')
			{
				$filter = $this->Session->get('filter');
				$category = $filter['tl_style_' . CURRENT_ID]['category'];

				if ($category != '')
				{
					$this->set['category'] = $category;
				}
			}
		}

		// Get the new position
		$this->getNewPosition('copy', (strlen($this->Input->get('pid')) ? $this->Input->get('pid') : null), ($this->Input->get('mode') == '2' ? true : false));

		// Empty clipboard
		$arrClipboard = $this->Session->get('CLIPBOARD');
		$arrClipboard[$this->strTable] = array();
		$this->Session->set('CLIPBOARD', $arrClipboard);

		// Insert the record if the table is not closed and switch to edit mode
		if (!$GLOBALS['TL_DCA'][$this->strTable]['config']['closed'])
		{
			$this->set['tstamp'] = ($blnDoNotRedirect ? time() : 0);

			$objInsertStmt = $this->Database->prepare("INSERT INTO " . $this->strTable . " %s")
											->set($this->set)
											->execute();

			if ($objInsertStmt->affectedRows)
			{
				$insertID = $objInsertStmt->insertId;

				// Save new record in the session
				if (!$blnDoNotRedirect)
				{
					$new_records = $this->Session->get('new_records');
					$new_records[$this->strTable][] = $insertID;
					$this->Session->set('new_records', $new_records);
				}

				// Duplicate records of the child table
				$this->copyChilds($this->strTable, $insertID, $this->intId, $insertID);

				// Add a log entry
				$this->log('A new entry in table "'.$this->strTable.'" has been created (ID: '.$insertID.')', 'DC_Table copy()', TL_GENERAL);

				// Switch to edit mode
				if (!$blnDoNotRedirect)
				{
					$this->redirect($this->switchToEdit($insertID));
				}

				return $insertID;
			}
		}

		if (!$blnDoNotRedirect)
		{
			$this->redirect($this->getReferer());
		}

		return false;
	}


	/**
	 * Duplicate all child records of a duplicated record
	 * @param string
	 * @param int
	 * @param int
	 * @param int
	 */
	protected function copyChilds($table, $insertID, $id, $parentId)
	{
		$time = time();
		$copy = array();
		$cctable = array();
		$ctable = $GLOBALS['TL_DCA'][$table]['config']['ctable'];

		if (!$GLOBALS['TL_DCA'][$table]['config']['ptable'] && strlen($this->Input->get('childs')) && $this->Database->fieldExists('pid', $table) && $this->Database->fieldExists('sorting', $table))
		{
			$ctable[] = $table;
		}

		if (!is_array($ctable))
		{
			return;
		}

		// Walk through each child table
		foreach ($ctable as $v)
		{
			$this->loadDataContainer($v);
			$cctable[$v] = $GLOBALS['TL_DCA'][$v]['config']['ctable'];

			if (!$GLOBALS['TL_DCA'][$v]['config']['doNotCopyRecords'] && strlen($v))
			{
				$objCTable = $this->Database->prepare("SELECT * FROM " . $v . " WHERE pid=?" . ($this->Database->fieldExists('sorting', $v) ? " ORDER BY sorting" : ""))
											->execute($id);

				foreach ($objCTable->fetchAllAssoc() as $row)
				{
					foreach ($row as $kk=>$vv)
					{
						// Exclude the duplicated record itself
						if ($v == $table && $row['id'] == $parentId)
						{
							continue;
						}

						if ($kk != 'id')
						{
							// Reset all unique, doNotCopy and fallback fields to their default value
							if ($GLOBALS['TL_DCA'][$v]['fields'][$kk]['eval']['unique'] || $GLOBALS['TL_DCA'][$v]['fields'][$kk]['eval']['doNotCopy'] || $GLOBALS['TL_DCA'][$v]['fields'][$kk]['eval']['fallback'])
							{
								$vv = $GLOBALS['TL_DCA'][$v]['fields'][$kk]['default'] ? ((is_array($GLOBALS['TL_DCA'][$v]['fields'][$kk]['default'])) ? serialize($GLOBALS['TL_DCA'][$v]['fields'][$kk]['default']) : $GLOBALS['TL_DCA'][$v]['fields'][$kk]['default']) : '';
							}

							$copy[$v][$row['id']][$kk] = $vv;
						}
					}

					$copy[$v][$row['id']]['pid'] = $insertID;
					$copy[$v][$row['id']]['tstamp'] = $time;
				}
			}
		}

		// Duplicate the child records
		foreach ($copy as $k=>$v)
		{
			if (count($v))
			{
				foreach ($v as $kk=>$vv)
				{
					$objInsertStmt = $this->Database->prepare("INSERT INTO " . $k . " %s")
													->set($vv)
													->execute();

					if ($objInsertStmt->affectedRows && (count($cctable[$k]) || $GLOBALS['TL_DCA'][$k]['list']['sorting']['mode'] == 5) && $kk != $parentId)
					{
						$this->copyChilds($k, $objInsertStmt->insertId, $kk, $parentId);
					}
				}
			}
		}
	}


	/**
	 * Move all selected records
	 */
	public function copyAll()
	{
		// PID is mandatory
		if (!strlen($this->Input->get('pid')))
		{
			$this->redirect($this->getReferer());
		}

		$arrClipboard = $this->Session->get('CLIPBOARD');

		if (isset($arrClipboard[$this->strTable]) && is_array($arrClipboard[$this->strTable]['id']))
		{
			foreach ($arrClipboard[$this->strTable]['id'] as $id)
			{
				$this->intId = $id;
				$id = $this->copy(true);
				$this->Input->setGet('pid', $id);
				$this->Input->setGet('mode', 1);
			}
		}

		$this->redirect($this->getReferer());
	}


	/**
	 * Calculate the new position of a moved or inserted record
	 * @param string
	 * @param integer
	 * @param boolean
	 */
	protected function getNewPosition($mode, $pid=null, $insertInto=false)
	{
		// If there is pid and sorting
		if ($this->Database->fieldExists('pid', $this->strTable) && $this->Database->fieldExists('sorting', $this->strTable))
		{
			// PID is not set - only valid for duplicated records, as they get the same parent ID as the original record!
			if (is_null($pid) && $this->intId && $mode == 'copy')
			{
				$pid = $this->intId;
			}

			// PID is set (insert after or into the parent record)
			if (is_numeric($pid))
			{
				// Insert the current record at the beginning when inserting into the parent record
				if ($insertInto)
				{
					$newPID = $pid;
					$objSorting = $this->Database->prepare("SELECT MIN(sorting) AS sorting FROM " . $this->strTable . " WHERE pid=?")
												 ->execute($pid);

					// Select sorting value of the first record
					if ($objSorting->numRows)
					{
						$curSorting = $objSorting->sorting;

						// Resort if the new sorting value is not an integer or smaller than 1
						if (($curSorting % 2) != 0 || $curSorting < 1)
						{
							$objNewSorting = $this->Database->prepare("SELECT id, sorting FROM " . $this->strTable . " WHERE pid=? ORDER BY sorting" )
															->execute($pid);

							$count = 2;
							$newSorting = 128;

							while ($objNewSorting->next())
							{
								$this->Database->prepare("UPDATE " . $this->strTable . " SET sorting=? WHERE id=?")
											   ->limit(1)
											   ->execute(($count++*128), $objNewSorting->id);
							}
						}

						// Else new sorting = (current sorting / 2)
						else $newSorting = ($curSorting / 2);
					}

					// Else new sorting = 128
					else $newSorting = 128;
				}

				// Else insert the current record after the parent record
				elseif ($pid > 0)
				{
					$objSorting = $this->Database->prepare("SELECT * FROM " . $this->strTable . " WHERE id=?")
												 ->limit(1)
												 ->execute($pid);

					// Set parent ID of the current record as new parent ID
					if ($objSorting->numRows)
					{
						$newPID = $objSorting->pid;
						$curSorting = $objSorting->sorting;

						// Do not proceed without a parent ID
						if (is_numeric($newPID))
						{
							$objNextSorting = $this->Database->prepare("SELECT MIN(sorting) AS sorting FROM " . $this->strTable . " WHERE pid=? AND sorting>?")
											  				 ->execute($newPID, $curSorting);

							// Select sorting value of the next record
							if (!is_null($objNextSorting->sorting))
							{
								$nxtSorting = $objNextSorting->sorting;

								// Resort if the new sorting value is no integer or bigger than a MySQL integer
								if ((($curSorting + $nxtSorting) % 2) != 0 || $nxtSorting >= 4294967295)
								{
									$count = 1;

									$objNewSorting = $this->Database->prepare("SELECT id, sorting FROM " . $this->strTable . " WHERE pid=? ORDER BY sorting")
																	->execute($newPID);

									while ($objNewSorting->next())
									{
										$this->Database->prepare("UPDATE " . $this->strTable . " SET sorting=? WHERE id=?")
													   ->execute(($count++*128), $objNewSorting->id);

										if ($objNewSorting->sorting == $curSorting)
										{
											$newSorting = ($count++*128);
										}
									}
								}

								// Else new sorting = (current sorting + next sorting) / 2
								else $newSorting = (($curSorting + $nxtSorting) / 2);
							}

							// Else new sorting = (current sorting + 128)
							else $newSorting = ($curSorting + 128);
						}
					}

					// Use the given parent ID as parent ID
					else
					{
						$newPID = $pid;
						$newSorting = 128;
					}
				}

				// Set new sorting and new parent ID
				$this->set['pid'] = intval($newPID);
				$this->set['sorting'] = intval($newSorting);
			}
		}

		// If there is only pid
		elseif ($this->Database->fieldExists('pid', $this->strTable))
		{
			// PID is not set - only valid for duplicated records, as they get the same parent ID as the original record!
			if (is_null($pid) && $this->intId && $mode == 'copy')
			{
				$pid = $this->intId;
			}

			// PID is set (insert after or into the parent record)
			if (is_numeric($pid))
			{
				// Insert the current record into the parent record
				if ($insertInto)
				{
					$this->set['pid'] = $pid;
				}

				// Else insert the current record after the parent record
				elseif ($pid > 0)
				{
					$objParentRecord = $this->Database->prepare("SELECT * FROM " . $this->strTable . " WHERE id=?")
													  ->limit(1)
													  ->execute($pid);

					if ($objParentRecord->numRows)
					{
						$this->set['pid'] = $objParentRecord->pid;
					}
				}
			}
		}

		// If there is only sorting
		elseif ($this->Database->fieldExists('sorting', $this->strTable))
		{
			// ID is set (insert after the current record)
			if ($this->intId)
			{
				$objCurrentRecord = $this->Database->prepare("SELECT * FROM " . $this->strTable . " WHERE id=?")
												   ->limit(1)
											 	   ->execute($this->intId);

				// Select current record
				if ($objCurrentRecord->numRows)
				{
					$curSorting = $objCurrentRecord->sorting;

					$objNextSorting = $this->Database->prepare("SELECT MIN(sorting) AS sorting FROM " . $this->strTable . " WHERE sorting>?")
													 ->execute($curSorting);

					// Select sorting value of the next record
					if ($objNextSorting->numRows)
					{
						$nxtSorting = $objNextSorting->sorting;

						// Resort if the new sorting value is no integer or bigger than a MySQL integer field
						if ((($curSorting + $nxtSorting) % 2) != 0 || $nxtSorting >= 4294967295)
						{
							$count = 1;

							$objNewSorting = $this->Database->execute("SELECT id, sorting FROM " . $this->strTable . " ORDER BY sorting");

							while ($objNewSorting->next())
							{
								$this->Database->prepare("UPDATE " . $this->strTable . " SET sorting=? WHERE id=?")
											   ->execute(($count++*128), $objNewSorting->id);

								if ($objNewSorting->sorting == $curSorting)
								{
									$newSorting = ($count++*128);
								}
							}
						}

						// Else new sorting = (current sorting + next sorting) / 2
						else $newSorting = (($curSorting + $nxtSorting) / 2);
					}

					// Else new sorting = (current sorting + 128)
					else $newSorting = ($curSorting + 128);

					// Set new sorting
					$this->set['sorting'] = intval($newSorting);
				}

				// ID is not set (insert at the end)
				else
				{
					$objNextSorting = $this->Database->execute("SELECT MAX(sorting) AS sorting FROM " . $this->strTable);

					if ($objNextSorting->numRows)
					{
						$this->set['sorting'] = intval($objNextSorting->sorting + 128);
					}
				}
			}
		}
	}


	/**
	 * Delete a record of the current table table and save it to tl_undo
	 * @param boolean
	 */
	public function delete($blnDoNotRedirect=false)
	{
		if (!$this->intId)
		{
			$this->redirect($this->getReferer());
		}

		$data = array();
		$delete = array();

		// Do not save records from tl_undo itself
		if ($this->strTable == 'tl_undo')
		{
			$this->Database->prepare("DELETE FROM " . $this->strTable . " WHERE id=?")
						   ->limit(1)
						   ->execute($this->intId);

			$this->redirect($this->getReferer());
		}

		// If there is a PID field but no parent table
		if ($this->Database->fieldExists('pid', $this->strTable) && !strlen($this->ptable))
		{
			$delete[$this->strTable] = $this->getChildRecords($this->intId, $this->strTable);
			array_unshift($delete[$this->strTable], $this->intId);
		}

		else
		{
			$delete[$this->strTable] = array($this->intId);
		}

		// Delete all child records if there is a child table
		if (count($this->ctable))
		{
			foreach ($delete[$this->strTable] as $id)
			{
				$this->deleteChilds($this->strTable, $id, $delete);
			}
		}

		$affected = 0;

		// Save each record of each table
		foreach ($delete as $table=>$fields)
		{
			foreach ($fields as $k=>$v)
			{
				$objSave = $this->Database->prepare("SELECT * FROM " . $table . " WHERE id=?")
										  ->limit(1)
										  ->execute($v);

				if ($objSave->numRows)
				{
					$data[$table][$k] = $objSave->fetchAssoc();
				}

				$affected++;
			}
		}

		$this->import('BackendUser', 'User');

		$objUndoStmt = $this->Database->prepare("INSERT INTO tl_undo (pid, tstamp, fromTable, query, affectedRows, data) VALUES (?, ?, ?, ?, ?, ?)")
									  ->execute($this->User->id, time(), $this->strTable, 'DELETE FROM '.$this->strTable.' WHERE id='.$this->intId, $affected, serialize($data));

		// Delete the records
		if ($objUndoStmt->affectedRows)
		{
			// Call ondelete_callback
			if (is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['ondelete_callback']))
			{
				foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['ondelete_callback'] as $callback)
				{
					if (is_array($callback))
					{
						$this->import($callback[0]);
						$this->$callback[0]->$callback[1]($this);
					}
				}
			}

			// Delete the records
			foreach ($delete as $table=>$fields)
			{
				foreach ($fields as $k=>$v)
				{
					$this->Database->prepare("DELETE FROM " . $table . " WHERE id=?")
								   ->limit(1)
								   ->execute($v);
				}
			}

			// Add a log entry unless we are deleting from tl_log itself
			if ($this->strTable != 'tl_log')
			{
				$this->log('DELETE FROM '.$this->strTable.' WHERE id='.$data[$this->strTable][0]['id'], 'DC_Table delete()', TL_GENERAL);
			}
		}

		if (!$blnDoNotRedirect)
		{
			$this->redirect($this->getReferer());
		}
	}


	/**
	 * Delete all records that are currently shown
	 */
	public function deleteAll()
	{
		$session = $this->Session->getData();
		$ids = $session['CURRENT']['IDS'];

		if (is_array($ids) && strlen($ids[0]))
		{
			foreach ($ids as $id)
			{
				$this->intId = $id;
				$this->delete(true);
			}
		}

		$this->redirect($this->getReferer());
	}


	/**
	 * Recursively get all related table names and records
	 * @param string
	 * @param integer
	 * @param array
	 */
	public function deleteChilds($table, $id, &$delete)
	{
		$cctable = array();
		$ctable = $GLOBALS['TL_DCA'][$table]['config']['ctable'];

		if (!is_array($ctable))
		{
			return;
		}

		// Walk through each child table
		foreach ($ctable as $v)
		{
			$this->loadDataContainer($v);
			$cctable[$v] = $GLOBALS['TL_DCA'][$v]['config']['ctable'];

			$objDelete = $this->Database->prepare("SELECT id FROM " . $v . " WHERE pid=?")
										->execute($id);

			if (!$GLOBALS['TL_DCA'][$v]['config']['doNotDeleteRecords'] && strlen($v) && $objDelete->numRows)
			{
				foreach ($objDelete->fetchAllAssoc() as $row)
				{
					$delete[$v][] = $row['id'];

					if (count($cctable[$v]))
					{
						$this->deleteChilds($v, $row['id'], $delete);
					}
				}
			}
		}
	}


	/**
	 * Restore one or more deleted records
	 */
	public function undo()
	{
		$objRecords = $this->Database->prepare("SELECT * FROM " . $this->strTable . " WHERE id=?")
									 ->limit(1)
								 	 ->execute($this->intId);

		// Check whether there is a record
		if ($objRecords->numRows < 1)
		{
			$this->redirect($this->getReferer());
		}

		$error = false;
		$query = $objRecords->query;
		$data = deserialize($objRecords->data);

		if (!is_array($data))
		{
			$this->redirect($this->getReferer());
		}

		// Restore the data
		foreach ($data as $table=>$fields)
		{
			foreach ($fields as $row)
			{
				$restore = array();

				foreach ($row as $k=>$v)
				{
					$restore[$k] = $v;
				}

				$objInsertStmt = $this->Database->prepare("INSERT INTO " . $table . " %s")
												->set($restore)
												->execute();

				// Do not delete record from tl_undo if there is an error
				if ($objInsertStmt->affectedRows < 1)
				{
					$error = true;
				}
			}
		}

		// Add log entry and delete record from tl_undo if there was no error
		if (!$error)
		{
			$this->log('Undone '.$query, 'DC_Table undo()', TL_GENERAL);

			$this->Database->prepare("DELETE FROM " . $this->strTable . " WHERE id=?")
						   ->limit(1)
						   ->execute($this->intId);
		}

		$this->redirect($this->getReferer());
	}


	/**
	 * Change the order of two neighbour database records
	 */
	public function move()
	{
		// Proceed only if all mandatory variables are set
		if ($this->intId && $this->Input->get('sid') && (!$GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['root'] || !in_array($this->intId, $this->root)))
		{
			$objRow = $this->Database->prepare("SELECT * FROM " . $this->strTable . " WHERE id=? OR id=?")
									 ->limit(2)
									 ->execute($this->intId, $this->Input->get('sid'));

			$row = $objRow->fetchAllAssoc();

			if ($row[0]['pid'] == $row[1]['pid'])
			{
				$this->Database->prepare("UPDATE " . $this->strTable . " SET sorting=? WHERE id=?")
							   ->execute($row[0]['sorting'], $row[1]['id']);

				$this->Database->prepare("UPDATE " . $this->strTable . " SET sorting=? WHERE id=?")
							   ->execute($row[1]['sorting'], $row[0]['id']);
			}
		}

		$this->redirect($this->getReferer());
	}


	/**
	 * Autogenerate a form to edit the current database record
	 * @param integer
	 * @param integer
	 * @return string
	 */
	public function edit($intID=false, $ajaxId=false)
	{
		if ($GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'])
		{
			$this->log('Table ' . $this->strTable . ' is not editable', 'DC_Table edit()', TL_ERROR);
			$this->redirect('typolight/main.php?act=error');
		}

		if ($intID)
		{
			$this->intId = $intID;
		}

		$return = '';
		$this->values[] = $this->intId;
		$this->procedure[] = 'id=?';
		$this->blnCreateNewVersion = false;

		// Change version
		if ($GLOBALS['TL_DCA'][$this->strTable]['config']['enableVersioning'] && $this->Input->post('FORM_SUBMIT') == 'tl_version' && strlen($this->Input->post('version')))
		{
			$objData = $this->Database->prepare("SELECT * FROM tl_version WHERE fromTable=? AND pid=? AND version=?")
									  ->limit(1)
									  ->execute($this->strTable, $this->intId, $this->Input->post('version'));

			if ($objData->numRows)
			{
				$data = deserialize($objData->data);

				if (is_array($data))
				{
					$this->Database->prepare("UPDATE " . $objData->fromTable . " %s WHERE id=?")
								   ->set($data)
								   ->execute($this->intId);

					$this->Database->prepare("UPDATE tl_version SET active='' WHERE pid=?")
								   ->execute($this->intId);

					$this->Database->prepare("UPDATE tl_version SET active=1 WHERE pid=? AND version=?")
								   ->execute($this->intId, $this->Input->post('version'));

					$this->log(sprintf('Version %s of record ID %s (table %s) has been restored', $this->Input->post('version'), $this->intId, $this->strTable), 'DC_Table edit()', TL_GENERAL);
				}
			}

			$this->reload();
		}

		// Get current record
		$objRow = $this->Database->prepare("SELECT * FROM " . $this->strTable . " WHERE id=?")
								 ->limit(1)
								 ->executeUncached($this->intId);

		// Redirect if there is no record with the given ID
		if ($objRow->numRows < 1)
		{
			$this->log('Could not load record ID "'.$this->intId.'" of table "'.$this->strTable.'"!', 'DC_Table edit()', TL_ERROR);
			$this->redirect('typolight/main.php?act=error');
		}

		$this->objActiveRecord = $objRow;

		// Create a new version if there is none
		if ($GLOBALS['TL_DCA'][$this->strTable]['config']['enableVersioning'])
		{
			$objVersion = $this->Database->prepare("SELECT id FROM tl_version WHERE fromTable=? AND pid=?")
										 ->limit(1)
										 ->execute($this->strTable, $this->intId);

			if ($objVersion->numRows < 1)
			{
				$this->createNewVersion($this->strTable, $this->intId);
			}
		}

		// Build an array from boxes and rows
		$this->strPalette = $this->getPalette();
		$boxes = trimsplit(';', $this->strPalette);
		$legends = array();

		if (count($boxes))
		{
			foreach ($boxes as $k=>$v)
			{
				$eCount = 1;
				$boxes[$k] = trimsplit(',', $v);

				foreach ($boxes[$k] as $kk=>$vv)
				{
					if (preg_match('/^\[.*\]$/i', $vv))
					{
						++$eCount;
						continue;
					}

					if (preg_match('/^\{.*\}$/i', $vv))
					{
						$legends[$k] = substr($vv, 1, -1);
						unset($boxes[$k][$kk]);
					}

					elseif ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$vv]['exclude'] || !is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$vv]))
					{
						unset($boxes[$k][$kk]);
					}
				}

				// Unset a box if it does not contain any fields
				if (count($boxes[$k]) < $eCount)
				{
					unset($boxes[$k]);
				}
			}

			$class = 'tl_tbox block';
			$fs = $this->Session->get('fieldset_states');

			// Render boxes
			foreach ($boxes as $k=>$v)
			{
				$strAjax = '';
				$blnAjax = false;
				$legend = '';

				if (isset($legends[$k]))
				{
					list($key, $cls) = explode(':', $legends[$k]);
					$legend = "\n" . '<legend onclick="AjaxRequest.toggleFieldset(this, \'' . $key . '\', \'' . $this->strTable . '\')">' . (isset($GLOBALS['TL_LANG'][$this->strTable][$key]) ? $GLOBALS['TL_LANG'][$this->strTable][$key] : $key) . '</legend>';
				}

				if (!$GLOBALS['TL_CONFIG']['oldBeTheme'])
				{
					if (isset($fs[$this->strTable][$key]))
					{
						$class .= ($fs[$this->strTable][$key] ? '' : ' collapsed');
					}
					else
					{
						$class .= (($cls && $legend) ? ' ' . $cls : '');
					}

					$return .= "\n\n" . '<fieldset' . ($key ? ' id="pal_'.$key.'"' : '') . ' class="' . $class . ($legend ? '' : ' nolegend') . '">' . $legend;
				}
				else
				{
					$return .= "\n\n" . '<div class="'.$class.'">';
				}

				// Build rows of the current box
				foreach ($v as $kk=>$vv)
				{
					if ($vv == '[EOF]')
					{
						if ($this->Input->post('isAjax') && $blnAjax)
						{
							return $strAjax . '<input type="hidden" name="FORM_FIELDS[]" value="'.specialchars($this->strPalette).'" />';
						}

						$blnAjax = false;
						$return .= "\n" . '</div>';

						continue;
					}

					if (preg_match('/^\[.*\]$/i', $vv))
					{
						$thisId = 'sub_' . substr($vv, 1, -1);
						$blnAjax = ($this->Input->post('isAjax') && $ajaxId == $thisId) ? true : false;
						$return .= "\n" . '<div id="'.$thisId.'">';

						continue;
					}

					$this->strField = $vv;
					$this->strInputName = $vv;
					$this->varValue = $objRow->$vv;

					// Call load_callback
					if (is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['load_callback']))
					{
						foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['load_callback'] as $callback)
						{
							if (is_array($callback))
							{
								$this->import($callback[0]);
								$this->varValue = $this->$callback[0]->$callback[1]($this->varValue, $this);
							}
						}

						$this->objActiveRecord->{$this->strField} = $this->varValue;
					}

					// Build row
					$blnAjax ? $strAjax .= $this->row() : $return .= $this->row();
				}

				$class = 'tl_box block';

				if (!$GLOBALS['TL_CONFIG']['oldBeTheme'])
				{
					$return .= "\n" . '</fieldset>';
				}
				else
				{
					$return .= "\n" . '</div>';
				}
			}
		}

		$version = '';

		// Check versions
		if ($GLOBALS['TL_DCA'][$this->strTable]['config']['enableVersioning'])
		{
			$objVersion = $this->Database->prepare("SELECT tstamp, version, username, active FROM tl_version WHERE fromTable=? AND pid=? ORDER BY version DESC")
									     ->execute($this->strTable, $this->intId);

			if ($objVersion->numRows > 1)
			{
				$versions = '';

				while ($objVersion->next())
				{
					$versions .= '
  <option value="'.$objVersion->version.'"'.($objVersion->active ? ' selected="selected"' : '').'>'.$GLOBALS['TL_LANG']['MSC']['version'].' '.$objVersion->version.' ('.$this->parseDate($GLOBALS['TL_CONFIG']['datimFormat'], $objVersion->tstamp).') '.$objVersion->username.'</option>';
				}

				$version = '
<div class="tl_version_panel">

<form action="'.ampersand($this->Environment->request, true).'" id="tl_version" class="tl_form" method="post">
<div class="tl_formbody">
<input type="hidden" name="FORM_SUBMIT" value="tl_version" />
<select name="version" class="tl_select">'.$versions.'
</select>
<input type="submit" name="showVersion" id="showVersion" class="tl_submit" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['restore']).'" />
</div>
</form>

</div>
';
			}
		}

		// Add some buttons and end the form
		$return .= '
</div>

<div class="tl_formbody_submit">

<div class="tl_submit_container">
<input type="submit" name="save" id="save" class="tl_submit" accesskey="s" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['save']).'" />
<input type="submit" name="saveNclose" id="saveNclose" class="tl_submit" accesskey="c" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['saveNclose']).'" />' . (!$GLOBALS['TL_DCA'][$this->strTable]['config']['closed'] ? '
<input type="submit" name="saveNcreate" id="saveNcreate" class="tl_submit" accesskey="n" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['saveNcreate']).'" />' : '') . ($this->Input->get('s2e') ? '
<input type="submit" name="saveNedit" id="saveNedit" class="tl_submit" accesskey="e" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['saveNedit']).'" />' : (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 4 || strlen($GLOBALS['TL_DCA'][$this->strTable]['config']['ptable']) || $GLOBALS['TL_DCA'][$this->strTable]['config']['switchToEdit']) ? '
<input type="submit" name="saveNback" id="saveNback" class="tl_submit" accesskey="g" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['saveNback']).'" />' : '')) .'
</div>

</div>
</form>';

		// Begin the form (-> DO NOT CHANGE THIS ORDER -> this way the onsubmit attribute of the form can be changed by a field)
		$return = $version . '
<div id="tl_buttons">
<a href="'.$this->getReferer(true).'" class="header_back" title="'.specialchars($GLOBALS['TL_LANG']['MSC']['backBT']).'" accesskey="b" onclick="Backend.getScrollOffset();">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a>
</div>

<h2 class="sub_headline">'.sprintf($GLOBALS['TL_LANG']['MSC']['editRecord'], ($this->intId ? 'ID '.$this->intId : '')).'</h2>'.$this->getMessages().'

<form action="'.ampersand($this->Environment->request, true).'" id="'.$this->strTable.'" class="tl_form" method="post" enctype="' . ($this->blnUploadable ? 'multipart/form-data' : 'application/x-www-form-urlencoded') . '"'.(count($this->onsubmit) ? ' onsubmit="'.implode(' ', $this->onsubmit).'"' : '').'>
<div class="tl_formbody_edit">
<input type="hidden" name="FORM_SUBMIT" value="'.specialchars($this->strTable).'" />
<input type="hidden" name="FORM_FIELDS[]" value="'.specialchars($this->strPalette).'" />'.($this->noReload ? '

<p class="tl_error">'.$GLOBALS['TL_LANG']['ERR']['general'].'</p>' : '').$return;

		// Reload the page to prevent _POST variables from being sent twice
		if ($this->Input->post('FORM_SUBMIT') == $this->strTable && !$this->noReload)
		{
			$arrValues = $this->values;
			array_unshift($arrValues, time());

			// Call onsubmit_callback
			if (is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['onsubmit_callback']))
			{
				foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['onsubmit_callback'] as $callback)
				{
					$this->import($callback[0]);
					$this->$callback[0]->$callback[1]($this);
				}
			}

			// Save current version
			if ($this->blnCreateNewVersion && $this->Input->post('SUBMIT_TYPE') != 'auto')
			{
				$this->createNewVersion($this->strTable, $this->intId);
				$this->log(sprintf('A new version of record ID %s (table %s) has been created', $this->intId, $this->strTable), 'DC_Table edit()', TL_GENERAL);
			}

			// Set current timestamp (-> DO NOT CHANGE ORDER version - timestamp)
			$this->Database->prepare("UPDATE " . $this->strTable . " SET tstamp=? WHERE id=?")
						   ->execute(time(), $this->intId);

			// Redirect
			if (isset($_POST['saveNclose']))
			{
				$_SESSION['TL_INFO'] = '';
				$_SESSION['TL_ERROR'] = '';
				$_SESSION['TL_CONFIRM'] = '';

				setcookie('BE_PAGE_OFFSET', 0, 0, '/');
				$this->redirect($this->getReferer());
			}

			elseif (isset($_POST['saveNedit']))
			{
				$_SESSION['TL_INFO'] = '';
				$_SESSION['TL_ERROR'] = '';
				$_SESSION['TL_CONFIRM'] = '';

				setcookie('BE_PAGE_OFFSET', 0, 0, '/');
				$strUrl = $this->addToUrl($GLOBALS['TL_DCA'][$this->strTable]['list']['operations']['edit']['href']);

				$strUrl = preg_replace('/(&amp;)?s2e=[^&]*/i', '', $strUrl);
				$strUrl = preg_replace('/(&amp;)?act=[^&]*/i', '', $strUrl);

				$this->redirect($strUrl);
			}

			elseif (isset($_POST['saveNback']))
			{
				$_SESSION['TL_INFO'] = '';
				$_SESSION['TL_ERROR'] = '';
				$_SESSION['TL_CONFIRM'] = '';

				setcookie('BE_PAGE_OFFSET', 0, 0, '/');
				$this->redirect($this->Environment->script . '?do=' . $this->Input->get('do'));
			}

			elseif (isset($_POST['saveNcreate']))
			{
				$_SESSION['TL_INFO'] = '';
				$_SESSION['TL_ERROR'] = '';
				$_SESSION['TL_CONFIRM'] = '';

				setcookie('BE_PAGE_OFFSET', 0, 0, '/');
				$strUrl = $this->Environment->script . '?do=' . $this->Input->get('do');

				if (isset($_GET['table']))
				{
					$strUrl .= '&amp;table=' . $this->Input->get('table');
				}

				// Tree view
				if ($this->treeView)
				{
					$strUrl .= '&amp;act=create&amp;mode=1&amp;pid=' . $this->intId;
				}

				// Parent view
				elseif ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 4)
				{
					$strUrl .= $this->Database->fieldExists('sorting', $this->strTable) ? '&amp;act=create&amp;mode=1&amp;pid=' . $this->intId . '&amp;id=' . CURRENT_ID : '&amp;act=create&amp;mode=2&amp;pid=' . CURRENT_ID;
				}

				// List view
				else
				{
					$strUrl .= strlen($GLOBALS['TL_DCA'][$this->strTable]['config']['ptable']) ? '&amp;act=create&amp;mode=2&amp;pid=' . CURRENT_ID : '&amp;act=create';
				}

				$this->redirect($strUrl);
			}

			$this->reload();
		}

		// Set the focus if there is an error
		if ($this->noReload)
		{
			$return .= '

<script type="text/javascript">
<!--//--><![CDATA[//><!--
window.addEvent(\'domready\', function()
{
    Backend.vScrollTo(($(\'' . $this->strTable . '\').getElement(\'label.error\').getPosition().y - 20));
});
//--><!]]>
</script>';
		}

		return $return;
	}


	/**
	 * Autogenerate a form to edit all records that are currently shown
	 * @param integer
	 * @param integer
	 * @return string
	 */
	public function editAll($intId=false, $ajaxId=false)
	{
		if ($GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'])
		{
			$this->log('Table ' . $this->strTable . ' is not editable', 'DC_Table editAll()', TL_ERROR);
			$this->redirect('typolight/main.php?act=error');
		}

		$return = '';
		$this->import('BackendUser', 'User');

		// Get current IDs from session
		$session = $this->Session->getData();
		$ids = $session['CURRENT']['IDS'];

		if ($this->Input->post('isAjax'))
		{
			$ids = array($intId);
		}

		// Save field selection in session
		if ($this->Input->post('FORM_SUBMIT') == $this->strTable.'_all' && $this->Input->get('fields'))
		{
			$session['CURRENT'][$this->strTable] = deserialize($this->Input->post('all_fields'));
			$this->Session->setData($session);
		}

		// Add fields
		$fields = $session['CURRENT'][$this->strTable];

		if (is_array($fields) && count($fields) && $this->Input->get('fields'))
		{
			$class = 'tl_tbox block';

			// Walk through each record
			foreach ($ids as $id)
			{
				$this->intId = $id;
				$this->procedure = array('id=?');
				$this->values = array($this->intId);
				$this->blnCreateNewVersion = false;
				$this->strPalette = trimsplit('[;,]', $this->getPalette());

				// Create a new version if there is none
				if ($GLOBALS['TL_DCA'][$this->strTable]['config']['enableVersioning'])
				{
					$objVersion = $this->Database->prepare("SELECT id FROM tl_version WHERE fromTable=? AND pid=?")
												 ->limit(1)
												 ->execute($this->strTable, $this->intId);

					if ($objVersion->numRows < 1)
					{
						$this->createNewVersion($this->strTable, $this->intId);
					}
				}

				// Add meta fields if the current user is an administrator
				if ($this->User->isAdmin)
				{
					if ($this->Database->fieldExists('sorting', $this->strTable))
					{
						array_unshift($this->strPalette, 'sorting');
					}

					if ($this->Database->fieldExists('pid', $this->strTable))
					{
						array_unshift($this->strPalette, 'pid');
					}

					$GLOBALS['TL_DCA'][$this->strTable]['fields']['pid'] = array('label'=>&$GLOBALS['TL_LANG']['MSC']['pid'], 'inputType'=>'text', 'eval'=>array('rgxp'=>'digit'));
					$GLOBALS['TL_DCA'][$this->strTable]['fields']['sorting'] = array('label'=>&$GLOBALS['TL_LANG']['MSC']['sorting'], 'inputType'=>'text', 'eval'=>array('rgxp'=>'digit'));
				}

				// Begin current row
				$strAjax = '';
				$blnAjax = false;
				$return .= '
<div class="'.$class.'">';

				$class = 'tl_box block';
				$formFields = array();

				// Get field values
				$objValue = $this->Database->prepare("SELECT * FROM " . $this->strTable . " WHERE id=?")
										   ->limit(1)
										   ->execute($this->intId);

				// Store active record
				$this->objActiveRecord = $objValue;

				foreach ($this->strPalette as $v)
				{
					// Check whether field is excluded
					if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['exclude'])
					{
						continue;
					}

					if ($v == '[EOF]')
					{
						if ($this->Input->post('isAjax') && $blnAjax)
						{
							return $strAjax . '<input type="hidden" name="FORM_FIELDS_'.$id.'[]" value="'.specialchars(implode(',', $formFields)).'" />';
						}

						$blnAjax = false;
						$return .= "\n  " . '</div>';

						continue;
					}

					if (preg_match('/^\[.*\]$/i', $v))
					{
						$thisId = 'sub_' . substr($v, 1, -1) . '_' . $id;
						$blnAjax = ($this->Input->post('isAjax') && $ajaxId == $thisId) ? true : false;
						$return .= "\n  " . '<div id="'.$thisId.'">';

						continue;
					}

					if (!in_array($v, $fields))
					{
						continue;
					}

					$this->strField = $v;
					$this->strInputName = $v.'_'.$this->intId;
					$formFields[] = $v.'_'.$this->intId;

					// Set default value and try to load the current value from DB
					$this->varValue = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['default'] ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['default'] : '';

					if ($objValue->$v !== false)
					{
						$this->varValue = $objValue->$v;
					}

					// Call load_callback
					if (is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['load_callback']))
					{
						foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['load_callback'] as $callback)
						{
							$this->import($callback[0]);
							$this->varValue = $this->$callback[0]->$callback[1]($this->varValue, $this);
						}
					}

					// Re-set the current value
					$this->objActiveRecord->{$this->strField} = $this->varValue;

					// Build the current row
					$blnAjax ? $strAjax .= $this->row() : $return .= $this->row();
				}

				// Close box
				$return .= '
  <input type="hidden" name="FORM_FIELDS_'.$this->intId.'[]" value="'.specialchars(implode(',', $formFields)).'" />
</div>';

				// Save record
				if ($this->Input->post('FORM_SUBMIT') == $this->strTable && !$this->noReload)
				{
					// Call onsubmit_callback
					if (is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['onsubmit_callback']))
					{
						foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['onsubmit_callback'] as $callback)
						{
							$this->import($callback[0]);
							$this->$callback[0]->$callback[1]($this);
						}
					}

					// Create a new version
					if ($this->blnCreateNewVersion && $this->Input->post('SUBMIT_TYPE') != 'auto')
					{
						$this->createNewVersion($this->strTable, $this->intId);
						$this->log(sprintf('A new version of record ID %s (table %s) has been created', $this->intId, $this->strTable), 'DC_Table editAll()', TL_GENERAL);
					}

					// Set current timestamp (-> DO NOT CHANGE ORDER version - timestamp)
					$this->Database->prepare("UPDATE " . $this->strTable . " SET tstamp=? WHERE id=?")
								   ->execute(time(), $this->intId);
				}
			}

			// Add the form
			$return = '

<h2 class="sub_headline_all">'.sprintf($GLOBALS['TL_LANG']['MSC']['all_info'], $this->strTable).'</h2>

<form action="'.ampersand($this->Environment->request, true).'" id="'.$this->strTable.'" class="tl_form" method="post" enctype="' . ($this->blnUploadable ? 'multipart/form-data' : 'application/x-www-form-urlencoded') . '">
<div class="tl_formbody_edit">
<input type="hidden" name="FORM_SUBMIT" value="'.$this->strTable.'" />'.($this->noReload ? '

<p class="tl_error">'.$GLOBALS['TL_LANG']['ERR']['general'].'</p>' : '').$return.'

</div>

<div class="tl_formbody_submit">

<div class="tl_submit_container">
<input type="submit" name="save" id="save" class="tl_submit" accesskey="s" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['save']).'" />
<input type="submit" name="saveNclose" id="saveNclose" class="tl_submit" accesskey="c" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['saveNclose']).'" />
</div>

</div>
</form>';

			// Set the focus if there is an error
			if ($this->noReload)
			{
				$return .= '

<script type="text/javascript">
<!--//--><![CDATA[//><!--
window.addEvent(\'domready\', function()
{
    Backend.vScrollTo(($(\'' . $this->strTable . '\').getElement(\'label.error\').getPosition().y - 20));
});
//--><!]]>
</script>';
			}

			// Reload the page to prevent _POST variables from being sent twice
			if ($this->Input->post('FORM_SUBMIT') == $this->strTable && !$this->noReload)
			{
				if ($this->Input->post('saveNclose'))
				{
					setcookie('BE_PAGE_OFFSET', 0, 0, '/');
					$this->redirect($this->getReferer());
				}

				$this->reload();
			}
		}

		// Else show a form to select the fields
		else
		{
			$options = '';
			$fields = array();

			// Add fields of the current table
			$fields = array_merge($fields, array_keys($GLOBALS['TL_DCA'][$this->strTable]['fields']));

			// Add meta fields if the current user is an administrator
			if ($this->User->isAdmin)
			{
				if ($this->Database->fieldExists('sorting', $this->strTable) && !in_array('sorting', $fields))
				{
					array_unshift($fields, 'sorting');
				}

				if ($this->Database->fieldExists('pid', $this->strTable) && !in_array('pid', $fields))
				{
					array_unshift($fields, 'pid');
				}
			}

			// Show all non-excluded fields
			foreach ($fields as $field)
			{
				if ($field == 'pid' || $field == 'sorting' || (!$GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['exclude'] && !$GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['doNotShow'] && (strlen($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['inputType']) || is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['input_field_callback']))))
				{
					$options .= '
<input type="checkbox" name="all_fields[]" id="all_'.$field.'" class="tl_checkbox" value="'.specialchars($field).'" /> <label for="all_'.$field.'" class="tl_checkbox_label">'.(strlen($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'][0]) ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'][0] : $GLOBALS['TL_LANG']['MSC'][$field][0]).'</label><br />';
				}
			}

			// Return select menu
			$return .= (($_POST && !count($_POST['all_fields'])) ? '

<p class="tl_error">'.$GLOBALS['TL_LANG']['ERR']['general'].'</p>' : '').'

<h2 class="sub_headline_all">'.sprintf($GLOBALS['TL_LANG']['MSC']['all_info'], $this->strTable).'</h2>

<form action="'.ampersand($this->Environment->request, true).'&amp;fields=1" id="'.$this->strTable.'_all" class="tl_form" method="post">
<div class="tl_formbody_edit">
<input type="hidden" name="FORM_SUBMIT" value="'.$this->strTable.'_all" />

<div class="tl_tbox block">
<h3><label for="fields">'.$GLOBALS['TL_LANG']['MSC']['all_fields'][0].'</label></h3>'.(($_POST && !count($_POST['all_fields'])) ? '
<p class="tl_error">'.$GLOBALS['TL_LANG']['ERR']['all_fields'].'</p>' : '').'
<div id="fields" class="tl_checkbox_container">
<input type="checkbox" id="check_all" class="tl_checkbox" onclick="Backend.toggleCheckboxes(this)" /> <label for="check_all" style="color:#a6a6a6;"><em>'.$GLOBALS['TL_LANG']['MSC']['selectAll'].'</em></label><br />'.$options.'
</div>'.(($GLOBALS['TL_CONFIG']['showHelp'] && strlen($GLOBALS['TL_LANG']['MSC']['all_fields'][1])) ? '
<p class="tl_help">'.$GLOBALS['TL_LANG']['MSC']['all_fields'][1].'</p>' : '').'
</div>

</div>

<div class="tl_formbody_submit">

<div class="tl_submit_container">
<input type="submit" name="save" id="save" class="tl_submit" accesskey="s" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['continue']).'" />
</div>

</div>
</form>';
		}

		// Return
		return '
<div id="tl_buttons">
<a href="'.$this->getReferer(true).'" class="header_back" title="'.specialchars($GLOBALS['TL_LANG']['MSC']['backBT']).'" accesskey="b" onclick="Backend.getScrollOffset();">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a>
</div>'.$return;
	}


	/**
	 * Autogenerate a form to override all records that are currently shown
	 * @author Based on a patch by Andreas Schempp
	 * @return string
	 */
	public function overrideAll()
	{
		if ($GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'])
		{
			$this->log('Table ' . $this->strTable . ' is not editable', 'DC_Table overrideAll()', TL_ERROR);
			$this->redirect('typolight/main.php?act=error');
		}

		$return = '';
		$this->import('BackendUser', 'User');

		// Get current IDs from session
		$session = $this->Session->getData();
		$ids = $session['CURRENT']['IDS'];

		// Save field selection in session
		if ($this->Input->post('FORM_SUBMIT') == $this->strTable.'_all' && $this->Input->get('fields'))
		{
			$session['CURRENT'][$this->strTable] = deserialize($this->Input->post('all_fields'));
			$this->Session->setData($session);
		}

		// Add fields
		$fields = $session['CURRENT'][$this->strTable];

		if (is_array($fields) && count($fields) && $this->Input->get('fields'))
		{
			$class = 'tl_tbox block';
			$formFields = array();

			// Save record
			if ($this->Input->post('FORM_SUBMIT') == $this->strTable)
			{
				foreach ($ids as $id)
				{
					$this->intId = $id;
					$this->procedure = array('id=?');
					$this->values = array($this->intId);
					$this->blnCreateNewVersion = false;

					// Create a new version if there is none
					if ($GLOBALS['TL_DCA'][$this->strTable]['config']['enableVersioning'])
					{
						$objVersion = $this->Database->prepare("SELECT id FROM tl_version WHERE fromTable=? AND pid=?")
													 ->limit(1)
													 ->execute($this->strTable, $this->intId);

						if ($objVersion->numRows < 1)
						{
							$this->createNewVersion($this->strTable, $this->intId);
						}
					}

					// Store all fields
					foreach ($fields as $v)
					{
						// Check whether field is excluded
						if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['exclude'])
						{
							continue;
						}

						$this->strField = $v;
						$this->strInputName = $v;
						$this->varValue = '';

						// Make sure the new value is applied
						$GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['eval']['alwaysSave'] = true;

						// Store value
						$this->row();
					}

					// Post processing
					if (!$this->noReload)
					{
						// Call onsubmit_callback
						if (is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['onsubmit_callback']))
						{
							foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['onsubmit_callback'] as $callback)
							{
								$this->import($callback[0]);
								$this->$callback[0]->$callback[1]($this);
							}
						}

						// Create a new version
						if ($this->blnCreateNewVersion)
						{
							$this->createNewVersion($this->strTable, $this->intId);
							$this->log(sprintf('A new version of record ID %s (table %s) has been created', $this->intId, $this->strTable), 'DC_Table editAll()', TL_GENERAL);
						}

						// Set current timestamp (-> DO NOT CHANGE ORDER version - timestamp)
						$this->Database->prepare("UPDATE " . $this->strTable . " SET tstamp=? WHERE id=?")
									   ->execute(time(), $this->intId);
					}
				}
			}

			// Begin current row
			$return .= '
<div class="'.$class.'">';

			foreach ($fields as $v)
			{
				// Check whether field is excluded
				if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['exclude'])
				{
					continue;
				}

				$formFields[] = $v;

				$this->intId = 0;
				$this->procedure = array('id=?');
				$this->values = array($this->intId);
				$this->strField = $v;
				$this->strInputName = $v;
				$this->varValue = '';

				// Disable auto-submit
				$GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['eval']['submitOnChange'] = false;
				$return .= $this->row();
			}

			// Close box
			$return .= '
<input type="hidden" name="FORM_FIELDS[]" value="'.specialchars(implode(',', $formFields)).'" />
</div>';

			// Add the form
			$return = '

<h2 class="sub_headline_all">'.sprintf($GLOBALS['TL_LANG']['MSC']['all_info'], $this->strTable).'</h2>

<form action="'.ampersand($this->Environment->request, true).'" id="'.$this->strTable.'" class="tl_form" method="post" enctype="' . ($this->blnUploadable ? 'multipart/form-data' : 'application/x-www-form-urlencoded') . '">
<div class="tl_formbody_edit">
<input type="hidden" name="FORM_SUBMIT" value="'.$this->strTable.'" />'.($this->noReload ? '

<p class="tl_error">'.$GLOBALS['TL_LANG']['ERR']['general'].'</p>' : '').$return.'

</div>

<div class="tl_formbody_submit">

<div class="tl_submit_container">
<input type="submit" name="save" id="save" class="tl_submit" accesskey="s" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['save']).'" />
<input type="submit" name="saveNclose" id="saveNclose" class="tl_submit" accesskey="c" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['saveNclose']).'" />
</div>

</div>
</form>';

			// Set the focus if there is an error
			if ($this->noReload)
			{
				$return .= '

<script type="text/javascript">
<!--//--><![CDATA[//><!--
window.addEvent(\'domready\', function()
{
    Backend.vScrollTo(($(\'' . $this->strTable . '\').getElement(\'label.error\').getPosition().y - 20));
});
//--><!]]>
</script>';
			}

			// Reload the page to prevent _POST variables from being sent twice
			if ($this->Input->post('FORM_SUBMIT') == $this->strTable && !$this->noReload)
			{
				if ($this->Input->post('saveNclose'))
				{
					setcookie('BE_PAGE_OFFSET', 0, 0, '/');
					$this->redirect($this->getReferer());
				}

				$this->reload();
			}
		}

		// Else show a form to select the fields
		else
		{
			$options = '';
			$fields = array();

			// Add fields of the current table
			$fields = array_merge($fields, array_keys($GLOBALS['TL_DCA'][$this->strTable]['fields']));

			// Add meta fields if the current user is an administrator
			if ($this->User->isAdmin)
			{
				if ($this->Database->fieldExists('sorting', $this->strTable) && !in_array('sorting', $fields))
				{
					array_unshift($fields, 'sorting');
				}

				if ($this->Database->fieldExists('pid', $this->strTable) && !in_array('pid', $fields))
				{
					array_unshift($fields, 'pid');
				}
			}

			// Show all non-excluded fields
			foreach ($fields as $field)
			{
				if ($field == 'pid' || $field == 'sorting' || (!$GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['exclude'] && !$GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['doNotShow'] && (strlen($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['inputType']) || is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['input_field_callback']))))
				{
					$options .= '
<input type="checkbox" name="all_fields[]" id="all_'.$field.'" class="tl_checkbox" value="'.specialchars($field).'" /> <label for="all_'.$field.'" class="tl_checkbox_label">'.(strlen($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'][0]) ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'][0] : $GLOBALS['TL_LANG']['MSC'][$field][0]).'</label><br />';
				}
			}

			// Return select menu
			$return .= (($_POST && !count($_POST['all_fields'])) ? '

<p class="tl_error">'.$GLOBALS['TL_LANG']['ERR']['general'].'</p>' : '').'

<h2 class="sub_headline_all">'.sprintf($GLOBALS['TL_LANG']['MSC']['all_info'], $this->strTable).'</h2>

<form action="'.ampersand($this->Environment->request, true).'&amp;fields=1" id="'.$this->strTable.'_all" class="tl_form" method="post">
<div class="tl_formbody_edit">
<input type="hidden" name="FORM_SUBMIT" value="'.$this->strTable.'_all" />

<div class="tl_tbox block">
<h3><label for="fields">'.$GLOBALS['TL_LANG']['MSC']['all_fields'][0].'</label></h3>'.(($_POST && !count($_POST['all_fields'])) ? '
<p class="tl_error">'.$GLOBALS['TL_LANG']['ERR']['all_fields'].'</p>' : '').'
<div id="fields" class="tl_checkbox_container">
<input type="checkbox" id="check_all" class="tl_checkbox" onclick="Backend.toggleCheckboxes(this)" /> <label for="check_all" style="color:#a6a6a6;"><em>'.$GLOBALS['TL_LANG']['MSC']['selectAll'].'</em></label><br />'.$options.'
</div>'.(($GLOBALS['TL_CONFIG']['showHelp'] && strlen($GLOBALS['TL_LANG']['MSC']['all_fields'][1])) ? '
<p class="tl_help">'.$GLOBALS['TL_LANG']['MSC']['all_fields'][1].'</p>' : '').'
</div>

</div>

<div class="tl_formbody_submit">

<div class="tl_submit_container">
<input type="submit" name="save" id="save" class="tl_submit" accesskey="s" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['continue']).'" />
</div>

</div>
</form>';
		}

		// Return
		return '
<div id="tl_buttons">
<a href="'.$this->getReferer(true).'" class="header_back" title="'.specialchars($GLOBALS['TL_LANG']['MSC']['backBT']).'" accesskey="b" onclick="Backend.getScrollOffset();">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a>
</div>'.$return;
	}


	/**
	 * Save the current value
	 * @param mixed
	 * @throws Exception
	 */
	protected function save($varValue)
	{
		if ($this->Input->post('FORM_SUBMIT') != $this->strTable)
		{
			return;
		}

		$arrData = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField];

		// Convert date formats into timestamps
		if (strlen($varValue) && in_array($arrData['eval']['rgxp'], array('date', 'time', 'datim')))
		{
			$objDate = new Date($varValue, $GLOBALS['TL_CONFIG'][$arrData['eval']['rgxp'] . 'Format']);
			$varValue = $objDate->tstamp;
		}

		// Make sure unique fields are unique
		if (strlen($varValue) && $arrData['eval']['unique'])
		{
			$objUnique = $this->Database->prepare("SELECT * FROM " . $this->strTable . " WHERE " . $this->strField . "=? AND id!=?")
										->execute($varValue, $this->intId);

			if ($objUnique->numRows)
			{
				throw new Exception(sprintf($GLOBALS['TL_LANG']['ERR']['unique'], (strlen($arrData['label'][0]) ? $arrData['label'][0] : $this->strField)));
			}
		}

		// Call save_callback
		if (is_array($arrData['save_callback']))
		{
			foreach ($arrData['save_callback'] as $callback)
			{
				$this->import($callback[0]);
				$varValue = $this->$callback[0]->$callback[1]($varValue, $this);
			}
		}

		// Save the value if there was no error
		if ((strlen($varValue) || !$arrData['eval']['doNotSaveEmpty']) && ($this->varValue != $varValue || $arrData['eval']['alwaysSave']))
		{
			// If the field is a fallback field, empty all other columns
			if ($arrData['eval']['fallback'] && strlen($varValue))
			{
				$this->Database->execute("UPDATE " . $this->strTable . " SET " . $this->strField . "=''");
			}

			$arrValues = $this->values;
			array_unshift($arrValues, $varValue);

			$objUpdateStmt = $this->Database->prepare("UPDATE " . $this->strTable . " SET " . $this->strField . "=? WHERE " . implode(' AND ', $this->procedure))
											->execute($arrValues);

			if ($objUpdateStmt->affectedRows)
			{
				if ($varValue != $this->varValue)
				{
					if (!$arrData['eval']['submitOnChange'])
					{
						$this->blnCreateNewVersion = true;
					}
				}

				$this->varValue = deserialize($varValue);

				if (is_object($this->objActiveRecord))
				{
					$this->objActiveRecord->{$this->strField} = $this->varValue;
				}
			}
		}
	}


	/**
	 * Return the name of the current palette
	 * @return string
	 */
	public function getPalette()
	{
		$palette = 'default';
		$strPalette = $GLOBALS['TL_DCA'][$this->strTable]['palettes'][$palette];

		// Check whether there are selector fields
		if (count($GLOBALS['TL_DCA'][$this->strTable]['palettes']['__selector__']))
		{
			$sValues = array();
			$subpalettes = array();

			$objFields = $this->Database->prepare("SELECT * FROM " . $this->strTable . " WHERE id=?")
										->limit(1)
										->executeUncached($this->intId);

			// Get selector values from DB
			if ($objFields->numRows > 0)
			{
				foreach ($GLOBALS['TL_DCA'][$this->strTable]['palettes']['__selector__'] as $name)
				{
					if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$name]['inputType'] == 'checkbox' && !$GLOBALS['TL_DCA'][$this->strTable]['fields'][$name]['eval']['multiple'])
					{
						$trigger = $objFields->$name;

						// Overwrite trigger if the page is not reloaded
						if ($this->Input->post('FORM_SUBMIT') == $this->strTable)
						{
							$key = ($this->Input->get('act') == 'editAll') ? $name.'_'.$this->intId : $name;

							if (!$GLOBALS['TL_DCA'][$this->strTable]['fields'][$name]['eval']['submitOnChange'])
							{
								$trigger = $this->Input->post($key);
							}
						}

						if (strlen($trigger))
						{
							$sValues[] = $name;

							// Look for a subpalette
							if (strlen($GLOBALS['TL_DCA'][$this->strTable]['subpalettes'][$name]))
							{
								$subpalettes[$name] = $GLOBALS['TL_DCA'][$this->strTable]['subpalettes'][$name];
							}
						}
					}

					elseif ($objFields->$name)
					{
						$sValues[] = $objFields->$name;
					}
				}
			}

			// Build possible palette names from the selector values
			if (!count($sValues))
			{
				$names = array('default');
			}

			elseif (count($sValues) > 1)
			{
				$names = $this->combiner($sValues);
			}

			else
			{
				$names = array($sValues[0]);
			}

			// Get an existing palette
			foreach ($names as $paletteName)
			{
				if (strlen($GLOBALS['TL_DCA'][$this->strTable]['palettes'][$paletteName]))
				{
					$palette = $paletteName;
					$strPalette = $GLOBALS['TL_DCA'][$this->strTable]['palettes'][$paletteName];

					break;
				}
			}

			// Include subpalettes
			foreach ($subpalettes as $k=>$v)
			{
				$strPalette = preg_replace('/\b'. preg_quote($k, '/').'\b/i', $k.',['.$k.'],'.$v.',[EOF]', $strPalette);
			}
		}

		return $strPalette;
	}


	/**
	 * Delete all incomplete and unrelated records
	 */
	protected function reviseTable()
	{
		$reload = false;
		$ptable = $GLOBALS['TL_DCA'][$this->strTable]['config']['ptable'];
		$ctable = $GLOBALS['TL_DCA'][$this->strTable]['config']['ctable'];

		$new_records = $this->Session->get('new_records');

		// HOOK: addCustomLogic
		if (isset($GLOBALS['TL_HOOKS']['reviseTable']) && is_array($GLOBALS['TL_HOOKS']['reviseTable']))
		{
			foreach ($GLOBALS['TL_HOOKS']['reviseTable'] as $callback)
			{
				$this->import($callback[0]);
				$status = $this->$callback[0]->$callback[1]($this->strTable, $new_records[$this->strTable], $ptable, $ctable);

				if ($status === true)
				{
					$reload = true;
				}
			}
		}

		// Delete all new but incomplete records (tstamp=0)
		if (is_array($new_records[$this->strTable]) && count($new_records[$this->strTable]) > 0)
		{
			$objStmt = $this->Database->execute("DELETE FROM " . $this->strTable . " WHERE id IN(" . implode(',', array_map('intval', $new_records[$this->strTable])) . ") AND tstamp=0");

			if ($objStmt->affectedRows > 0)
			{
				$reload = true;
			}
		}

		// Delete all records of the current table that are not related to the parent table
		if (strlen($ptable))
		{
			$objStmt = $this->Database->execute("DELETE FROM " . $this->strTable . " WHERE NOT EXISTS (SELECT * FROM " . $ptable . " WHERE " . $this->strTable . ".pid = " . $ptable . ".id)");

			if ($objStmt->affectedRows > 0)
			{
				$reload = true;
			}
		}

		// Delete all records of the child table that are not related to the current table
		if (is_array($ctable) && count($ctable))
		{
			foreach ($ctable as $v)
			{
				if (strlen($v))
				{
					$objStmt = $this->Database->execute("DELETE FROM " . $v . " WHERE NOT EXISTS (SELECT * FROM " . $this->strTable . " WHERE " . $v . ".pid = " . $this->strTable . ".id)");

					if ($objStmt->affectedRows > 0)
					{
						$reload = true;
					}
				}
			}
		}

		// Reload the page
		if ($reload)
		{
			$this->reload();
		}
	}


	/**
	 * List all records of the current table as tree and return them as HTML string
	 * @return string
	 */
	protected function treeView()
	{
		$table = $this->strTable;
		$treeClass = 'tl_tree';

		if ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 6)
		{
			$table = $this->ptable;
			$treeClass = 'tl_tree_xtnd';

			$this->loadLanguageFile($table);
			$this->loadDataContainer($table);
		}

		// Get session data and toggle nodes
		if ($this->Input->get('ptg') == 'all')
		{
			$session = $this->Session->getData();
			$node = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 6) ? $this->strTable.'_'.$table.'_tree' : $this->strTable.'_tree';

			// Expand tree
			if (!is_array($session[$node]) || count($session[$node]) < 1 || current($session[$node]) != 1)
			{
				$session[$node] = array();
				$objNodes = $this->Database->execute("SELECT DISTINCT pid FROM " . $table . " WHERE pid>0");

				while ($objNodes->next())
				{
					$session[$node][$objNodes->pid] = 1;
				}
			}

			// Collapse tree
			else
			{
				$session[$node] = array();
			}

			$this->Session->setData($session);
			$this->redirect(preg_replace('/(&(amp;)?|\?)ptg=[^& ]*/i', '', $this->Environment->request));
		}

		// Return if a mandatory field (id, pid, sorting) is missing
		if ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 5 && (!$this->Database->fieldExists('id', $table) || !$this->Database->fieldExists('pid', $table) || !$this->Database->fieldExists('sorting', $table)))
		{
			return '
<p class="tl_empty">strTable "'.$table.'" can not be shown as tree!</p>';
		}

		// Return if there is no parent table
		if ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 6 && !strlen($this->ptable))
		{
			return '
<p class="tl_empty">strTable "'.$table.'" can not be shown as extended tree!</p>';
		}

		$blnClipboard = false;
		$arrClipboard = $this->Session->get('CLIPBOARD');

		// Check clipboard
		if (isset($arrClipboard[$this->strTable]) && count($arrClipboard[$this->strTable]))
		{
			$blnClipboard = true;
			$arrClipboard = $arrClipboard[$this->strTable];
		}

		$label = $GLOBALS['TL_DCA'][$table]['config']['label'];
		$icon = strlen($GLOBALS['TL_DCA'][$table]['list']['sorting']['icon']) ? $GLOBALS['TL_DCA'][$table]['list']['sorting']['icon'] : 'pagemounts.gif';
		$label = $this->generateImage($icon).' <label>'.$label.'</label>';

		// Begin buttons container
		$return = '
<div id="tl_buttons">'.(($this->Input->get('act') == 'select') ? '
<a href="'.$this->getReferer(true).'" class="header_back" title="'.specialchars($GLOBALS['TL_LANG']['MSC']['backBT']).'" accesskey="b" onclick="Backend.getScrollOffset();">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a>' : '') . (($this->Input->get('act') != 'select' && !$GLOBALS['TL_DCA'][$this->strTable]['config']['closed']) ? '
<a href="'.$this->addToUrl('act=paste&amp;mode=create').'" class="header_new" title="'.specialchars($GLOBALS['TL_LANG'][$this->strTable]['new'][1]).'" accesskey="n" onclick="Backend.getScrollOffset();">'.$GLOBALS['TL_LANG'][$this->strTable]['new'][0].'</a>' : '') . (($this->Input->get('act') != 'select') ? $this->generateGlobalButtons() . ($blnClipboard ? ' &nbsp; :: &nbsp; <a href="'.$this->addToUrl('clipboard=1').'" class="header_clipboard" title="'.specialchars($GLOBALS['TL_LANG']['MSC']['clearClipboard']).'" accesskey="b">'.$GLOBALS['TL_LANG']['MSC']['clearClipboard'].'</a>' : '') : '') . '
</div>';

		$tree = '';
		$blnHasSorting = $this->Database->fieldExists('sorting', $table);

		// Call a recursive function that builds the tree
		for ($i=0; $i<count($this->root); $i++)
		{
			$tree .= $this->generateTree($table, $this->root[$i], array('p'=>$this->root[($i-1)], 'n'=>$this->root[($i+1)]), $blnHasSorting, -20, ($blnClipboard ? $arrClipboard : false));
		}

		// Return if there are no records
		if (!strlen($tree) && $this->Input->get('act') != 'paste')
		{
			return $return . '
<p class="tl_empty">'.$GLOBALS['TL_LANG']['MSC']['noResult'].'</p>';
		}

		$return .= (($this->Input->get('act') == 'select') ? '

<form action="'.ampersand($this->Environment->request, true).'" id="tl_select" class="tl_form" method="post">
<div class="tl_formbody">
<input type="hidden" name="FORM_SUBMIT" value="tl_select" />' : '').'

<div class="tl_listing_container" id="tl_listing">'.(isset($GLOBALS['TL_DCA'][$table]['list']['sorting']['breadcrumb']) ? $GLOBALS['TL_DCA'][$table]['list']['sorting']['breadcrumb'] : '').(($this->Input->get('act') == 'select') ? '

<div class="tl_select_trigger">
<label for="tl_select_trigger" class="tl_select_label">'.$GLOBALS['TL_LANG']['MSC']['selectAll'].'</label> <input type="checkbox" id="tl_select_trigger" onclick="Backend.toggleCheckboxes(this)" class="tl_tree_checkbox" />
</div>' : '').'

<ul class="tl_listing ' . $treeClass . '">
  <li class="tl_folder_top" onmouseover="Theme.hoverDiv(this, 1);" onmouseout="Theme.hoverDiv(this, 0);"><div class="tl_left">'.$label.'</div> <div class="tl_right">';

		$_buttons = '&nbsp;';

		// Show paste button only if there are no root records specified
		if ($this->Input->get('act') != 'select' && $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 5 && $blnClipboard && !count($GLOBALS['TL_DCA'][$table]['list']['sorting']['root']) && $GLOBALS['TL_DCA'][$table]['list']['sorting']['root'] !== false)
		{
			// Call paste_button_callback (&$dc, $row, $table, $cr, $childs, $previous, $next)
			if (is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['paste_button_callback']))
			{
				$strClass = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['paste_button_callback'][0];
				$strMethod = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['paste_button_callback'][1];

				$this->import($strClass);
				$_buttons = $this->$strClass->$strMethod($this, array('id'=>0), $table, false, $arrClipboard);
			}
			else
			{
				$imagePasteInto = $this->generateImage('pasteinto.gif', $GLOBALS['TL_LANG'][$this->strTable]['pasteinto'][0], 'class="blink"');
				$_buttons = '<a href="'.$this->addToUrl('act='.$arrClipboard['mode'].'&amp;mode=2&amp;pid=0'.(!is_array($arrClipboard['id']) ? '&amp;id='.$arrClipboard['id'] : '')).'" title="'.specialchars($GLOBALS['TL_LANG'][$this->strTable]['pasteinto'][0]).'" onclick="Backend.getScrollOffset();">'.$imagePasteInto.'</a> ';
			}
		}

		// End table
		$return .= $_buttons . '</div><div style="clear:both;"></div></li>'.$tree.'
</ul>

</div>';

		// Close form
		if ($this->Input->get('act') == 'select')
		{
			$return .= '

<div class="tl_formbody_submit" style="text-align:right;">

<div class="tl_submit_container">
  <input type="submit" name="delete" id="delete" class="tl_submit" accesskey="d" onclick="return confirm(\''.$GLOBALS['TL_LANG']['MSC']['delAllConfirm'].'\');" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['deleteSelected']).'" />
  <input type="submit" name="cut" id="cut" class="tl_submit" accesskey="x" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['moveSelected']).'" />
  <input type="submit" name="copy" id="copy" class="tl_submit" accesskey="c" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['copySelected']).'" />' . (!$GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'] ? '
  <input type="submit" name="override" id="override" class="tl_submit" accesskey="v" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['overrideSelected']).'" />
  <input type="submit" name="edit" id="edit" class="tl_submit" accesskey="s" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['editSelected']).'" />' : '') . '
</div>

</div>
</div>
</form>';
		}

		return $return;
	}


	/**
	 * Generate a particular subpart of the tree and return it as HTML string
	 * @param integer
	 * @param integer
	 * @return string
	 */
	public function ajaxTreeView($id, $level)
	{
		if (!$this->Input->post('isAjax'))
		{
			return '';
		}

		$return = '';
		$table = $this->strTable;
		$blnPtable = false;

		// Load parent table
		if ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 6)
		{
			$table = $this->ptable;

			$this->loadLanguageFile($table);
			$this->loadDataContainer($table);

			$blnPtable = true;
		}

		$blnProtected = false;

		// Check protected pages
		if ($table == 'tl_page')
		{
			$objParent = $this->getPageDetails($id);
			$blnProtected = $objParent->protected ? true : false;
		}

		$margin = ($level * 20);
		$hasSorting = $this->Database->fieldExists('sorting', $table);
		$arrIds = array();

		// Get records
		$objRows = $this->Database->prepare("SELECT id FROM " . $table . " WHERE pid=?" . ($hasSorting ? " ORDER BY sorting" : ""))
							 	  ->execute($id);

		while ($objRows->next())
		{
			$arrIds[] = $objRows->id;
		}

		$blnClipboard = false;
		$arrClipboard = $this->Session->get('CLIPBOARD');

		// Check clipboard
		if (isset($arrClipboard[$this->strTable]) && count($arrClipboard[$this->strTable]))
		{
			$blnClipboard = true;
			$arrClipboard = $arrClipboard[$this->strTable];
		}

		for ($i=0; $i<count($arrIds); $i++)
		{
			$return .= '  ' . trim($this->generateTree($table, $arrIds[$i], array('p'=>$arrIds[($i-1)], 'n'=>$arrIds[($i+1)]), $hasSorting, $margin, ($blnClipboard ? $arrClipboard : false), ($id == $arrClipboard ['id'] || (is_array($arrClipboard ['id']) && in_array($id, $arrClipboard ['id'])) || (!$blnPtable && !is_array($arrClipboard['id']) && in_array($id, $this->getChildRecords($arrClipboard['id'], $table, true)))), $blnProtected));
		}

		return $return;
	}


	/**
	 * Recursively generate the tree and return it as HTML string
	 * @param string
	 * @param integer
	 * @param array
	 * @param boolean
	 * @param integer
	 * @param array
	 * @param boolean
	 * @param boolean
	 * @return string
	 */
	protected function generateTree($table, $id, $arrPrevNext, $blnHasSorting, $intMargin=0, $arrClipboard=false, $blnCircularReference=false, $protectedPage=false)
	{
		static $session;

		$session = $this->Session->getData();
		$node = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 6) ? $this->strTable.'_'.$table.'_tree' : $this->strTable.'_tree';

		// Toggle nodes
		if ($this->Input->get('ptg'))
		{
			$session[$node][$this->Input->get('ptg')] = (isset($session[$node][$this->Input->get('ptg')]) && $session[$node][$this->Input->get('ptg')] == 1) ? 0 : 1;
			$this->Session->setData($session);

			$this->redirect(preg_replace('/(&(amp;)?|\?)ptg=[^& ]*/i', '', $this->Environment->request));
		}

		$objRow = $this->Database->prepare("SELECT * FROM " . $table . " WHERE id=?")
								 ->limit(1)
								 ->execute($id);

		// Return if there is no result
		if ($objRow->numRows < 1)
		{
			$this->Session->setData($session);
			return '';
		}

		$return = '';
		$intSpacing = 20;

		// Add the ID to the list of current IDs
		if ($this->strTable == $table)
		{
			$this->current[] = $objRow->id;
		}

		// Check whether there are child records
		if ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 5 || $this->strTable != $table)
		{
			$objChilds = $this->Database->prepare("SELECT id FROM " . $table . " WHERE pid=?" . ($blnHasSorting ? " ORDER BY sorting" : ''))
										->execute($id);

			if ($objChilds->numRows)
			{
				$childs = $objChilds->fetchEach('id');
			}
		}

		// Check whether the page is protected
		$objRow->protected = ($table == 'tl_page') ? ($objRow->protected || $protectedPage) : false;
		$session[$node][$id] = (is_int($session[$node][$id])) ? $session[$node][$id] : 0;

		$return .= "\n  " . '<li class="'.((($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 5 && $objRow->type == 'root') || $table != $this->strTable) ? 'tl_folder' : 'tl_file').'" onmouseover="Theme.hoverDiv(this, 1);" onmouseout="Theme.hoverDiv(this, 0);"><div class="tl_left" style="padding-left:'.($intMargin + $intSpacing).'px;">';

		// Calculate label and add a toggle button
		$args = array();
		$folderAttribute = 'style="margin-left:20px;"';
		$showFields = $GLOBALS['TL_DCA'][$table]['list']['label']['fields'];
		$level = ($intMargin / $intSpacing + 1);

		if (count($childs))
		{
			$folderAttribute = '';
			$img = ($session[$node][$id] == 1) ? 'folMinus.gif' : 'folPlus.gif';
			$alt = ($session[$node][$id] == 1) ? $GLOBALS['TL_LANG']['MSC']['collapseNode'] : $GLOBALS['TL_LANG']['MSC']['expandNode'];
			$return .= '<a href="'.$this->addToUrl('ptg='.$id).'" title="'.specialchars($alt).'" onclick="Backend.getScrollOffset(); return AjaxRequest.toggleStructure(this, \''.$node.'_'.$id.'\', '.$level.', '.$GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'].');">'.$this->generateImage($img, specialchars($alt), 'style="margin-right:2px;"').'</a>';
		}

		foreach ($showFields as $k=>$v)
		{
			if (strpos($v, ':') !== false)
			{
				list($strKey, $strTable) = explode(':', $v);
				list($strTable, $strField) = explode('.', $strTable);

				$objRef = $this->Database->prepare("SELECT " . $strField . " FROM " . $strTable . " WHERE id=?")
										 ->limit(1)
										 ->execute($objRow->$strKey);

				$args[$k] = $objRef->numRows ? $objRef->$strField : '';
			}
			elseif (in_array($GLOBALS['TL_DCA'][$table]['fields'][$v]['flag'], array(5, 6, 7, 8, 9, 10)))
			{
				$args[$k] = $this->parseDate($GLOBALS['TL_CONFIG']['datimFormat'], $objRow->$v);
			}
			elseif ($GLOBALS['TL_DCA'][$table]['fields'][$v]['inputType'] == 'checkbox' && !$GLOBALS['TL_DCA'][$table]['fields'][$v]['eval']['multiple'])
			{
				$args[$k] = strlen($objRow->$v) ? (strlen($GLOBALS['TL_DCA'][$table]['fields'][$v]['label'][0]) ? $GLOBALS['TL_DCA'][$table]['fields'][$v]['label'][0] : $v) : '';
			}
			else
			{
				$args[$k] = strlen($GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$objRow->$v]) ? $GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$objRow->$v] : $objRow->$v;
			}
		}

		$label = vsprintf(((strlen($GLOBALS['TL_DCA'][$table]['list']['label']['format'])) ? $GLOBALS['TL_DCA'][$table]['list']['label']['format'] : '%s'), $args);

		// Shorten label it if it is too long
		if ($GLOBALS['TL_DCA'][$table]['list']['label']['maxCharacters'] > 0 && $GLOBALS['TL_DCA'][$table]['list']['label']['maxCharacters'] < strlen(strip_tags($label)))
		{
			$this->import('String');
			$label = trim($this->String->substrHtml($label, $GLOBALS['TL_DCA'][$table]['list']['label']['maxCharacters'])) . ' …';
		}

		$label = preg_replace('/\(\) ?|\[\] ?|\{\} ?|<> ?/i', '', $label);

		// Call label_callback ($row, $label, $this)
		if (is_array($GLOBALS['TL_DCA'][$table]['list']['label']['label_callback']))
		{
			$strClass = $GLOBALS['TL_DCA'][$table]['list']['label']['label_callback'][0];
			$strMethod = $GLOBALS['TL_DCA'][$table]['list']['label']['label_callback'][1];

			$this->import($strClass);
			$return .= $this->$strClass->$strMethod($objRow->row(), $label, $this, $folderAttribute);
		}
		else
		{
			$return .= $this->generateImage('iconPLAIN.gif', '', $folderAttribute) . ' ' . $label;
		}

		$return .= '</div> <div class="tl_right">';
		$previous = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 6) ? $arrPrevNext['pp'] : $arrPrevNext['p'];
		$next = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 6) ? $arrPrevNext['nn'] : $arrPrevNext['n'];
		$_buttons = '';

		// Regular buttons ($row, $table, $root, $blnCircularReference, $childs, $previous, $next)
		if ($this->strTable == $table)
		{
			$_buttons .= ($this->Input->get('act') == 'select') ? '<input type="checkbox" name="IDS[]" id="ids_'.$id.'" class="tl_tree_checkbox" value="'.$id.'" />' : $this->generateButtons($objRow->row(), $table, $this->root, $blnCircularReference, $childs, $previous, $next);
		}

		// Paste buttons
		if ($arrClipboard !== false && $this->Input->get('act') != 'select')
		{
			// Call paste_button_callback(&$dc, $row, $table, $blnCircularReference, $arrClipboard, $childs, $previous, $next)
			if (is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['paste_button_callback']))
			{
				$strClass = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['paste_button_callback'][0];
				$strMethod = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['paste_button_callback'][1];

				$this->import($strClass);
				$_buttons .= $this->$strClass->$strMethod($this, $objRow->row(), $table, $blnCircularReference, $arrClipboard, $childs, $previous, $next);
			}

			else
			{
				$imagePasteAfter = $this->generateImage('pasteafter.gif', sprintf($GLOBALS['TL_LANG'][$this->strTable]['pasteafter'][1], $id), 'class="blink"');
				$imagePasteInto = $this->generateImage('pasteinto.gif', sprintf($GLOBALS['TL_LANG'][$this->strTable]['pasteinto'][1], $id), 'class="blink"');

				// Regular tree (on cut: disable buttons of the page all its childs to avoid circular references)
				if ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 5)
				{
					$_buttons .= ($arrClipboard['mode'] == 'cut' && ($blnCircularReference || $arrClipboard['id'] == $id) || $arrClipboard['mode'] == 'cutAll' && ($blnCircularReference || in_array($id, $arrClipboard['id'])) || (count($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['root']) && in_array($id, $this->root))) ? $this->generateImage('pasteafter_.gif', '', 'class="blink"').' ' : '<a href="'.$this->addToUrl('act='.$arrClipboard['mode'].'&amp;mode=1&amp;pid='.$id.(!is_array($arrClipboard['id']) ? '&amp;id='.$arrClipboard['id'] : '')).'" title="'.specialchars(sprintf($GLOBALS['TL_LANG'][$this->strTable]['pasteafter'][1], $id)).'" onclick="Backend.getScrollOffset();">'.$imagePasteAfter.'</a> ';
					$_buttons .= ($arrClipboard['mode'] == 'paste' && ($blnCircularReference || $arrClipboard['id'] == $id) || $arrClipboard['mode'] == 'cutAll' && ($blnCircularReference || in_array($id, $arrClipboard['id']))) ? $this->generateImage('pasteinto_.gif', '', 'class="blink"').' ' : '<a href="'.$this->addToUrl('act='.$arrClipboard['mode'].'&amp;mode=2&amp;pid='.$id.(!is_array($arrClipboard['id']) ? '&amp;id='.$arrClipboard['id'] : '')).'" title="'.specialchars(sprintf($GLOBALS['TL_LANG'][$this->strTable]['pasteinto'][1], $id)).'" onclick="Backend.getScrollOffset();">'.$imagePasteInto.'</a> ';
				}

				// Extended tree
				else
				{
					$_buttons .= ($this->strTable == $table) ? (($arrClipboard['mode'] == 'cut' && ($blnCircularReference || $arrClipboard['id'] == $id) || $arrClipboard['mode'] == 'cutAll' && ($blnCircularReference || in_array($id, $arrClipboard['id']))) ? $this->generateImage('pasteafter_.gif', '', 'class="blink"') : '<a href="'.$this->addToUrl('act='.$arrClipboard['mode'].'&amp;mode=1&amp;pid='.$id.(!is_array($arrClipboard['id']) ? '&amp;id='.$arrClipboard['id'] : '')).'" title="'.specialchars(sprintf($GLOBALS['TL_LANG'][$this->strTable]['pasteafter'][1], $id)).'" onclick="Backend.getScrollOffset();">'.$imagePasteAfter.'</a> ') : '';
					$_buttons .= ($this->strTable != $table) ? '<a href="'.$this->addToUrl('act='.$arrClipboard['mode'].'&amp;mode=2&amp;pid='.$id.(!is_array($arrClipboard['id']) ? '&amp;id='.$arrClipboard['id'] : '')).'" title="'.specialchars(sprintf($GLOBALS['TL_LANG'][$this->strTable]['pasteinto'][1], $id)).'" onclick="Backend.getScrollOffset();">'.$imagePasteInto.'</a> ' : '';
				}
			}
		}

		$return .= (strlen($_buttons) ? $_buttons : '&nbsp;') . '</div><div style="clear:both;"></div></li>';

		// Add records of the table itself
		if ($table != $this->strTable)
		{
			$objChilds = $this->Database->prepare("SELECT id FROM " . $this->strTable . " WHERE pid=?" . ($blnHasSorting ? " ORDER BY sorting" : ''))
							 			->execute($id);

			if ($objChilds->numRows)
			{
				$ids = $objChilds->fetchEach('id');

				for ($j=0; $j<count($ids); $j++)
				{
					$return .= $this->generateTree($this->strTable, $ids[$j], array('pp'=>$ids[($j-1)], 'nn'=>$ids[($j+1)]), $blnHasSorting, ($intMargin + $intSpacing + 20), $arrClipboard, false, ($j<(count($ids)-1) || count($childs)));
				}
			}
		}

		// Begin new submenu
		if (count($childs) && $session[$node][$id] == 1)
		{
			$return .= '<li class="parent" id="'.$node.'_'.$id.'"><ul class="level_'.$level.'">';
		}

		// Add records of the parent table
		if ($session[$node][$id] == 1)
		{
			if (is_array($childs))
			{
				for ($k=0; $k<count($childs); $k++)
				{
					$return .= $this->generateTree($table, $childs[$k], array('p'=>$childs[($k-1)], 'n'=>$childs[($k+1)]), $blnHasSorting, ($intMargin + $intSpacing), $arrClipboard, ((($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 5 && $childs[$k] == $arrClipboard['id']) || $blnCircularReference) ? true : false), ($objRow->protected || $protectedPage));
				}
			}
		}

		// Close submenu
		if (count($childs) && $session[$node][$id] == 1)
		{
			$return .= '</ul></li>';
		}

		$this->Session->setData($session);
		return $return;
	}


	/**
 	 * Show header of the parent table and list all records of the current table
	 * @return string
	 */
	protected function parentView()
	{
		$blnClipboard = false;
		$arrClipboard = $this->Session->get('CLIPBOARD');
		$table = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 6) ? $this->ptable : $this->strTable;
		$blnHasSorting = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['fields'][0] == 'sorting';
		$blnMultiboard = false;

		// Check clipboard
		if (isset($arrClipboard[$table]) && count($arrClipboard[$table]))
		{
			$blnClipboard = true;
			$arrClipboard = $arrClipboard[$table];

			if (is_array($arrClipboard['id']))
			{
				$blnMultiboard = true;
			}
		}

		// Load language file and data container array of the parent table
		$this->loadLanguageFile($this->ptable);
		$this->loadDataContainer($this->ptable);

		$return = '
<div id="tl_buttons">
<a href="'.(($this->Input->get('act') == 'select') ? $this->getReferer(true) : $this->Environment->script.'?do='.$this->Input->get('do')).'" class="header_back" title="'.specialchars($GLOBALS['TL_LANG']['MSC']['backBT']).'" accesskey="b" onclick="Backend.getScrollOffset();">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a>' . (($this->Input->get('act') != 'select') ? ' &#160; :: &#160; ' . (!$GLOBALS['TL_DCA'][$this->strTable]['config']['closed'] ? '
<a href="'.$this->addToUrl(($blnHasSorting ? 'act=paste&amp;mode=create' : 'act=create&amp;mode=2&amp;pid='.$this->intId)).'" class="header_new" title="'.specialchars($GLOBALS['TL_LANG'][$this->strTable]['new'][1]).'" accesskey="n" onclick="Backend.getScrollOffset();">'.$GLOBALS['TL_LANG'][$this->strTable]['new'][0].'</a>' : '') . $this->generateGlobalButtons(). ($blnClipboard ? ' &nbsp; :: &nbsp; <a href="'.$this->addToUrl('clipboard=1').'" class="header_clipboard" title="'.specialchars($GLOBALS['TL_LANG']['MSC']['clearClipboard']).'" accesskey="b">'.$GLOBALS['TL_LANG']['MSC']['clearClipboard'].'</a>' : '') : '') . '
</div>';

		// Get all details of the parent record
		$objParent = $this->Database->prepare("SELECT * FROM " . $this->ptable . " WHERE id=?")
									->limit(1)
									->execute(CURRENT_ID);

		if ($objParent->numRows < 1)
		{
			return $return;
		}

		$return .= (($this->Input->get('act') == 'select') ? '

<form action="'.ampersand($this->Environment->request, true).'" id="tl_select" class="tl_form" method="post">
<div class="tl_formbody">
<input type="hidden" name="FORM_SUBMIT" value="tl_select" />' : '').'

<div class="tl_listing_container">

<div class="tl_header" onmouseover="Theme.hoverDiv(this, 1);" onmouseout="Theme.hoverDiv(this, 0);">';

		// List all records of the child table
		if (!$this->Input->get('act') || $this->Input->get('act') == 'paste' || $this->Input->get('act') == 'select')
		{

			// Header
			$imagePasteNew = $this->generateImage('new.gif', $GLOBALS['TL_LANG'][$this->strTable]['pasteafter'][0]);
			$imagePasteAfter = $this->generateImage('pasteafter.gif', $GLOBALS['TL_LANG'][$this->strTable]['pasteafter'][0], 'class="blink"');
			$imageEditHeader = $this->generateImage('edit.gif', $GLOBALS['TL_LANG'][$this->strTable]['editheader'][0]);

			$return .= '
<div style="text-align:right;">'.(($this->Input->get('act') == 'select') ? '
<label for="tl_select_trigger" class="tl_select_label">'.$GLOBALS['TL_LANG']['MSC']['selectAll'].'</label> <input type="checkbox" id="tl_select_trigger" onclick="Backend.toggleCheckboxes(this)" class="tl_tree_checkbox" />' : (!$GLOBALS['TL_DCA'][$this->ptable]['config']['notEditable'] ? '
<a href="'.preg_replace('/&(amp;)?table=[^& ]*/i', (strlen($this->ptable) ? '&amp;table='.$this->ptable : ''), $this->addToUrl('act=edit')).'" title="'.specialchars($GLOBALS['TL_LANG'][$this->strTable]['editheader'][1]).'">'.$imageEditHeader.'</a>' : '') . (($blnHasSorting && !$GLOBALS['TL_DCA'][$this->strTable]['config']['closed']) ? ' <a href="'.$this->addToUrl('act=create&amp;mode=2&amp;pid='.$objParent->id.'&amp;id='.$this->intId).'" title="'.specialchars($GLOBALS['TL_LANG'][$this->strTable]['pastenew'][0]).'">'.$imagePasteNew.'</a>' : '') . ($blnClipboard ? ' <a href="'.$this->addToUrl('act='.$arrClipboard['mode'].'&amp;mode=2&amp;pid='.$objParent->id . (!$blnMultiboard ? '&amp;id='.$arrClipboard['id'] : '')).'" title="'.specialchars($GLOBALS['TL_LANG'][$this->strTable]['pasteafter'][0]).'" onclick="Backend.getScrollOffset();">'.$imagePasteAfter.'</a>' : '')) . '
</div>';

			// Format header fields
			$add = array();
			$headerFields = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['headerFields'];

			foreach ($headerFields as $v)
			{
				$_v = deserialize($objParent->$v);

				if (is_array($_v))
				{
					$_v = implode(', ', $_v);
				}
				elseif ($GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['inputType'] == 'checkbox' && !$GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['eval']['multiple'])
				{
					$_v = strlen($_v) ? $GLOBALS['TL_LANG']['MSC']['yes'] : $GLOBALS['TL_LANG']['MSC']['no'];
				}
				elseif ($_v && $GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['eval']['rgxp'] == 'date')
				{
					$_v = $this->parseDate($GLOBALS['TL_CONFIG']['dateFormat'], $_v);
				}
				elseif ($_v && $GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['eval']['rgxp'] == 'time')
				{
					$_v = $this->parseDate($GLOBALS['TL_CONFIG']['timeFormat'], $_v);
				}
				elseif ($_v && $GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['eval']['rgxp'] == 'datim')
				{
					$_v = $this->parseDate($GLOBALS['TL_CONFIG']['datimFormat'], $_v);
				}
				elseif ($v == 'tstamp')
				{
					$objMaxTstamp = $this->Database->prepare("SELECT MAX(tstamp) AS tstamp FROM " . $this->strTable . " WHERE pid=?")
												   ->execute($objParent->id);

					if (!$objMaxTstamp->tstamp)
					{
						$objMaxTstamp->tstamp = $objParent->tstamp;
					}

					$_v = $this->parseDate($GLOBALS['TL_CONFIG']['datimFormat'], $objMaxTstamp->tstamp);
				}
				elseif (strlen($GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['foreignKey']))
				{
					$arrForeignKey = trimsplit('.', $GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['foreignKey']);

					$objLabel = $this->Database->prepare("SELECT " . $arrForeignKey[1] . " FROM " . $arrForeignKey[0] . " WHERE id=?")
											   ->limit(1)
											   ->execute($_v);

					if ($objLabel->numRows)
					{
						$_v = $objLabel->$arrForeignKey[1];
					}
				}
				elseif (strlen($GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['reference'][$_v]))
				{
					$_v = $GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['reference'][$_v];
				}

				// Add sorting field
				if (strlen($_v))
				{
					$key = strlen($GLOBALS['TL_LANG'][$this->ptable][$v][0]) ? $GLOBALS['TL_LANG'][$this->ptable][$v][0]  : $v;
					$add[$key] = $_v;
				}
			}

			// Output header data
			$return .= '

<table cellpadding="0" cellspacing="0" class="tl_header_table" summary="Table lists all details of the header record">';

			foreach ($add as $k=>$v)
			{
				if (is_array($v))
				{
					$v = $v[0];
				}

				$return .= '
  <tr>
    <td><span class="tl_label">'.$k.':</span> </td>
    <td>'.$v.'</td>
  </tr>';
			}

			$return .= '
</table>
</div>';

			// Add all records of the current table
			$query = "SELECT * FROM " . $this->strTable;

			if (count($this->procedure))
			{
				$query .= " WHERE " . implode(' AND ', $this->procedure);
			}

			if (is_array($this->root) && count($this->root) > 0)
			{
				$query .= (count($this->procedure) ? " AND " : " WHERE ") . "id IN(" . implode(',', array_map('intval', $this->root)) . ")";
			}

			if (is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['fields']))
			{
				$query .= " ORDER BY " . implode(', ', $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['fields']);
			}

			$objOrderByStmt = $this->Database->prepare($query);

			if (strlen($this->limit))
			{
				$arrLimit = explode(',', $this->limit);
				$objOrderByStmt->limit($arrLimit[1], $arrLimit[0]);
			}

			$objOrderBy = $objOrderByStmt->execute($this->values);

			if ($objOrderBy->numRows < 1)
			{
				return $return . '
<p class="tl_empty_parent_view">'.$GLOBALS['TL_LANG']['MSC']['noResult'].'</p>

</div>';
			}

			if (is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['child_record_callback']))
			{
				$strClass = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['child_record_callback'][0];
				$strMethod = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['child_record_callback'][1];

				$this->import($strClass);
				$row = $objOrderBy->fetchAllAssoc();

				// Make items sortable
				if ($blnHasSorting)
				{
					$return .= '

<ul id="ul_' . CURRENT_ID . '" class="sortable">';
				}

				for ($i=0; $i<count($row); $i++)
				{
					$this->current[] = $row[$i]['id'];
					$imagePasteAfter = $this->generateImage('pasteafter.gif', sprintf($GLOBALS['TL_LANG'][$this->strTable]['pasteafter'][1], $row[$i]['id']), 'class="blink"');
					$imagePasteNew = $this->generateImage('new.gif', sprintf($GLOBALS['TL_LANG'][$this->strTable]['pastenew'][1], $row[$i]['id']));

					// Make items sortable
					if ($blnHasSorting)
					{
						$return .= '
<li id="li_' . $row[$i]['id'] . '">';
					}

					$return .= '

<div class="tl_content" onmouseover="Theme.hoverDiv(this, 1);" onmouseout="Theme.hoverDiv(this, 0);">
<div style="text-align:right;">';

					// Edit multiple
					if ($this->Input->get('act') == 'select')
					{
						$return .= '<input type="checkbox" name="IDS[]" id="ids_'.$row[$i]['id'].'" class="tl_tree_checkbox" value="'.$row[$i]['id'].'" />';
					}

					// Regular buttons
					else
					{
						$return .= $this->generateButtons($row[$i], $this->strTable, $this->root, false, null, $row[($i-1)]['id'], $row[($i+1)]['id']);

						// Sortable table
						if ($blnHasSorting)
						{
							// Create new button
							if (!$GLOBALS['TL_DCA'][$this->strTable]['config']['closed'])
							{
								$return .= ' <a href="'.$this->addToUrl('act=create&amp;mode=1&amp;pid='.$row[$i]['id'].'&amp;id='.$objParent->id).'" title="'.specialchars(sprintf($GLOBALS['TL_LANG'][$this->strTable]['pastenew'][1], $row[$i]['id'])).'">'.$imagePasteNew.'</a>';
							}

							// Prevent circular references
							if ($blnClipboard && $arrClipboard['mode'] == 'cut' && $row[$i]['id'] == $arrClipboard['id'] || $blnMultiboard && $arrClipboard['mode'] == 'cutAll' && in_array($row[$i]['id'], $arrClipboard['id']))
							{
								$return .= ' ' . $this->generateImage('pasteafter_.gif', '', 'class="blink"');
							}

							// Copy/move multiple
							elseif ($blnMultiboard)
							{
								$return .= ' <a href="'.$this->addToUrl('act='.$arrClipboard['mode'].'&amp;mode=1&amp;pid='.$row[$i]['id']).'" title="'.specialchars(sprintf($GLOBALS['TL_LANG'][$this->strTable]['pasteafter'][1], $row[$i]['id'])).'" onclick="Backend.getScrollOffset();">'.$imagePasteAfter.'</a>';
							}

							// Paste buttons
							elseif ($blnClipboard)
							{
								$return .= ' <a href="'.$this->addToUrl('act='.$arrClipboard['mode'].'&amp;mode=1&amp;pid='.$row[$i]['id'].'&amp;id='.$arrClipboard['id']).'" title="'.specialchars(sprintf($GLOBALS['TL_LANG'][$this->strTable]['pasteafter'][1], $row[$i]['id'])).'" onclick="Backend.getScrollOffset();">'.$imagePasteAfter.'</a>';
							}
						}
					}

					$return .= '
</div>'.$this->$strClass->$strMethod($row[$i]).'</div>';

					// Make items sortable
					if ($blnHasSorting)
					{
						$return .= '

</li>';
					}
				}
			}
		}

		// Make items sortable
		if ($blnHasSorting)
		{
			$return .= '
</ul>

<script type="text/javascript">
<!--//--><![CDATA[//><!--
Backend.makeParentViewSortable("ul_' . CURRENT_ID . '");
//--><!]]>
</script>';
		}

		$return .= '

</div>';

		// Close form
		if ($this->Input->get('act') == 'select')
		{
			$return .= '

<div class="tl_formbody_submit" style="text-align:right;">

<div class="tl_submit_container">
  <input type="submit" name="delete" id="delete" class="tl_submit" accesskey="d" onclick="return confirm(\''.$GLOBALS['TL_LANG']['MSC']['delAllConfirm'].'\');" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['deleteSelected']).'" />
  <input type="submit" name="cut" id="cut" class="tl_submit" accesskey="x" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['moveSelected']).'" />
  <input type="submit" name="copy" id="copy" class="tl_submit" accesskey="c" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['copySelected']).'" />' . (!$GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'] ? '
  <input type="submit" name="override" id="override" class="tl_submit" accesskey="v" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['overrideSelected']).'" />
  <input type="submit" name="edit" id="edit" class="tl_submit" accesskey="s" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['editSelected']).'" />' : '') . '
</div>

</div>
</div>
</form>';
		}

		return $return;
	}


	/**
	 * List all records of the current table and return them as HTML string
	 * @return string
	 */
	protected function listView()
	{
		$return = '';
		$table = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 6) ? $this->ptable : $this->strTable;
		$orderBy = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['fields'];
		$firstOrderBy = preg_replace('/\s+.*$/i', '', $orderBy[0]);

		if (is_array($this->orderBy) && strlen($this->orderBy[0]))
		{
			$orderBy = $this->orderBy;
			$firstOrderBy = $this->firstOrderBy;
		}

		// Show only own undo steps
		if ($this->strTable == 'tl_undo')
		{
			$this->import('BackendUser', 'User');

			if (!$this->User->isAdmin)
			{
				$this->procedure[] = 'pid=?';
				$this->values[] = $this->User->id;
			}
		}

		$query = "SELECT * FROM " . $this->strTable;

		if (count($this->procedure))
		{
			$query .= " WHERE " . implode(' AND ', $this->procedure);
		}

		if (is_array($this->root) && count($this->root) > 0)
		{
			$query .= (count($this->procedure) ? " AND " : " WHERE ") . "id IN(" . implode(',', array_map('intval', $this->root)) . ")";
		}

		if (is_array($orderBy) && strlen($orderBy[0]))
		{
			foreach ($orderBy as $k=>$v)
			{
				if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['eval']['findInSet'])
				{
					$keys = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['options'];

					if (array_is_assoc($keys))
					{
						$keys = array_keys($keys);
					}

					$orderBy[$k] = "FIND_IN_SET(" . $v . ", '" . implode(',', $keys) . "')";
				}
			}

			$query .= " ORDER BY " . implode(', ', $orderBy);
		}

		if ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 1 && ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['flag'] % 2) == 0)
		{
			$query .= " DESC";
		}

		$objRowStmt = $this->Database->prepare($query);

		if (strlen($this->limit))
		{
			$arrLimit = explode(',', $this->limit);
			$objRowStmt->limit($arrLimit[1], $arrLimit[0]);
		}

		$objRow = $objRowStmt->execute($this->values);
		$this->bid = strlen($return) ? $this->bid : 'tl_buttons';

		// Display buttos
		if (!$GLOBALS['TL_DCA'][$this->strTable]['config']['closed'] || count($GLOBALS['TL_DCA'][$this->strTable]['list']['global_operations']))
		{
			$return .= '

<div id="'.$this->bid.'">'.(($this->Input->get('act') == 'select' || $GLOBALS['TL_DCA'][$this->strTable]['config']['ptable']) ? '
<a href="'.$this->getReferer(true).'" class="header_back" title="'.specialchars($GLOBALS['TL_LANG']['MSC']['backBT']).'" accesskey="b" onclick="Backend.getScrollOffset();">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a>' : '') . (($GLOBALS['TL_DCA'][$this->strTable]['config']['ptable'] && $this->Input->get('act') != 'select') ? ' &nbsp; :: &nbsp;' : '') . (($this->Input->get('act') != 'select') ? '
'.(!$GLOBALS['TL_DCA'][$this->strTable]['config']['closed'] ? '<a href="'.(strlen($GLOBALS['TL_DCA'][$this->strTable]['config']['ptable']) ? $this->addToUrl('act=create' . (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] < 4) ? '&amp;mode=2' : '') . '&amp;pid=' . $this->intId) : $this->addToUrl('act=create')).'" class="header_new" title="'.specialchars($GLOBALS['TL_LANG'][$this->strTable]['new'][1]).'" accesskey="n" onclick="Backend.getScrollOffset();">'.$GLOBALS['TL_LANG'][$this->strTable]['new'][0].'</a>' : '') . $this->generateGlobalButtons() : '') . '
</div>';
		}

		// Return "no records found" message
		if ($objRow->numRows < 1)
		{
			$return .= '
<p class="tl_empty">'.$GLOBALS['TL_LANG']['MSC']['noResult'].'</p>';
		}

		// List records
		else
		{
			$result = $objRow->fetchAllAssoc();
			$return .= (($this->Input->get('act') == 'select') ? '

<form action="'.ampersand($this->Environment->request, true).'" id="tl_select" class="tl_form" method="post">
<div class="tl_formbody">
<input type="hidden" name="FORM_SUBMIT" value="tl_select" />' : '').'

<div class="tl_listing_container">'.(($this->Input->get('act') == 'select') ? '

<div class="tl_select_trigger">
<label for="tl_select_trigger" class="tl_select_label">'.$GLOBALS['TL_LANG']['MSC']['selectAll'].'</label> <input type="checkbox" id="tl_select_trigger" onclick="Backend.toggleCheckboxes(this)" class="tl_tree_checkbox" />
</div>' : '').'

<table cellpadding="0" cellspacing="0" class="tl_listing" summary="Table lists records">';

			// Rename each pid to its label and resort the result (sort by parent table)
			if ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 3 && $this->Database->fieldExists('pid', $this->strTable))
			{
				$firstOrderBy = 'pid';
				$showFields = $GLOBALS['TL_DCA'][$table]['list']['label']['fields'];

				foreach ($result as $k=>$v)
				{
					$objField = $this->Database->prepare("SELECT " . $showFields[0] . " FROM " . $this->ptable . " WHERE id=?")
											   ->limit(1)
											   ->execute($v['pid']);

					$result[$k]['pid'] = $objField->$showFields[0];
				}

				$aux = array();

				foreach ($result as $row)
				{
					$aux[] = $row['pid'];
				}

				array_multisort($aux, SORT_ASC, $result);
			}

			// Process result and add label and buttons
			$remoteCur = false;
			$groupclass = 'tl_folder_tlist';
			$arrLookup = array();

			foreach ($result as $row)
			{
				$args = array();
				$this->current[] = $row['id'];
				$showFields = $GLOBALS['TL_DCA'][$table]['list']['label']['fields'];

				// Label
				foreach ($showFields as $k=>$v)
				{
					if (strpos($v, ':') !== false)
					{
						list($strKey, $strTable) = explode(':', $v);
						list($strTable, $strField) = explode('.', $strTable);

						$objRef = $this->Database->prepare("SELECT " . $strField . " FROM " . $strTable . " WHERE id=?")
												 ->limit(1)
												 ->execute($row[$strKey]);

						$args[$k] = $objRef->numRows ? $objRef->$strField : '';
					}
					elseif (in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['flag'], array(5, 6, 7, 8, 9, 10)))
					{
						if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['eval']['rgxp'] == 'date')
						{
							$args[$k] = $this->parseDate($GLOBALS['TL_CONFIG']['dateFormat'], $row[$v]);
						}
						elseif ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['eval']['rgxp'] == 'time')
						{
							$args[$k] = $this->parseDate($GLOBALS['TL_CONFIG']['timeFormat'], $row[$v]);
						}
						else
						{
							$args[$k] = $this->parseDate($GLOBALS['TL_CONFIG']['datimFormat'], $row[$v]);
						}
					}
					elseif ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['inputType'] == 'checkbox' && !$GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['eval']['multiple'])
					{
						$args[$k] = strlen($row[$v]) ? $GLOBALS['TL_DCA'][$table]['fields'][$v]['label'][0] : '';
					}
					else
					{
						$row_v = deserialize($row[$v]);

						if (is_array($row_v))
						{
							$args_k = array();

							foreach ($row_v as $option)
							{
								$args_k[] = strlen($GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$option]) ? $GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$option] : $option;
							}

							$args[$k] = implode(', ', $args_k);
						}
						elseif (isset($GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$row[$v]]))
						{
							$args[$k] = is_array($GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$row[$v]]) ? $GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$row[$v]][0] : $GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$row[$v]];
						}
						else
						{
							$args[$k] = $row[$v];
						}
					}
				}

				// Shorten label it if it is too long
				$label = vsprintf((strlen($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['format']) ? $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['format'] : '%s'), $args);

				if ($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['maxCharacters'] > 0 && $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['maxCharacters'] < strlen(strip_tags($label)))
				{
					$this->import('String');
					$label = trim($this->String->substrHtml($label, $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['maxCharacters'])) . ' …';
				}

				// Remove empty brackets (), [], {}, <> and empty tags from label
				$label = preg_replace('/\( *\) ?|\[ *\] ?|\{ *\} ?|< *> ?/i', '', $label);
				$label = preg_replace('/<[^>]+>\s*<\/[^>]+>/i', '', $label);

				// Build sorting groups
				if ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] > 0)
				{
					$current = $row[$firstOrderBy];
					$orderBy = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['fields'];
					$sortingMode = (count($orderBy) == 1 && $firstOrderBy == $orderBy[0] && strlen($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['flag']) && !strlen($GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['flag'])) ? $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['flag'] : $GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['flag'];

					if($GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['inputType'] == 'checkbox' && !$GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['eval']['multiple'])
					{
						$remoteNew = strlen($current) ? ucfirst($GLOBALS['TL_LANG']['MSC']['yes']) : ucfirst($GLOBALS['TL_LANG']['MSC']['no']);
					}
					elseif(strlen($GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['foreignKey']))
					{
						$key = explode('.', $GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['foreignKey']);

						$objParent = $this->Database->prepare("SELECT " . $key[1] . " FROM " . $key[0] . " WHERE id=?")
													->limit(1)
													->execute($current);

						if ($objParent->numRows)
						{
							$remoteNew = $objParent->$key[1];
						}
					}
					elseif (in_array($sortingMode, array(1, 2)))
					{
						$remoteNew = strlen($current) ? ucfirst(utf8_substr($current , 0, 1)) : '-';
					}
					elseif (in_array($sortingMode, array(3, 4)))
					{
						if (!strlen($GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['length']))
						{
							$GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['length'] = 2;
						}

						$remoteNew = strlen($current) ? ucfirst(utf8_substr($current , 0, $GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['length'])) : '-';
					}
					elseif (in_array($sortingMode, array(5, 6)))
					{
						$remoteNew = strlen($current) ? $this->parseDate($GLOBALS['TL_CONFIG']['dateFormat'], $current) : '-';
					}
					elseif (in_array($sortingMode, array(7, 8)))
					{
						$remoteNew = strlen($current) ? date('Y-m', $current) : '-';
						$intMonth = strlen($current) ? (date('m', $current) - 1) : '-';

						if (strlen($GLOBALS['TL_LANG']['MONTHS'][$intMonth]))
						{
							$remoteNew = strlen($current) ? $GLOBALS['TL_LANG']['MONTHS'][$intMonth] . ' ' . date('Y', $current) : '-';
						}
					}
					elseif (in_array($sortingMode, array(9, 10)))
					{
						$remoteNew = strlen($current) ? date('Y', $current) : '-';
					}
					else
					{
						if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['inputType'] == 'checkbox' && !$GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['eval']['multiple'])
						{
							$remoteNew = strlen($current) ? $firstOrderBy : '';
						}
						elseif (is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['reference']))
						{
							$remoteNew = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['reference'][$current];
						}
						elseif (array_is_assoc($GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['options']))
						{
							$remoteNew = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['options'][$current];
						}
						else
						{
							$remoteNew = $current;
						}

						if (!strlen($remoteNew))
						{
							$remoteNew = '-';
						}
					}

					// Add group header
					if (!$GLOBALS['TL_DCA'][$this->strTable]['config']['disableGrouping'] && ($remoteNew != $remoteCur || $remoteCur === false))
					{
						if (array_is_assoc($GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['options']))
						{
							$group = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['options'][$remoteNew];
						}
						elseif (is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['options_callback']))
						{
							if (!isset($arrLookup[$firstOrderBy]))
							{
								$strClass = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['options_callback'][0];
								$strMethod = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['options_callback'][1];

								$this->import($strClass);
								$arrLookup[$firstOrderBy] = $this->$strClass->$strMethod($this);
							}

							$group = $arrLookup[$firstOrderBy][$remoteNew];
						}
						else
						{
							$group = is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['reference'][$remoteNew] ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['reference'][$remoteNew][0] : $GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['reference'][$remoteNew]);
						}

						if (!strlen($group))
						{
							$group = is_array($GLOBALS['TL_LANG'][$this->strTable][$remoteNew] ? $GLOBALS['TL_LANG'][$this->strTable][$remoteNew][0] : $GLOBALS['TL_LANG'][$this->strTable][$remoteNew]);
						}

						if (!strlen($group))
						{
							$group = $remoteNew;
						}

						// Call group callback ($group, $sortingMode, $firstOrderBy, $row, $this)
						if (is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['group_callback']))
						{
							$strClass = $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['group_callback'][0];
							$strMethod = $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['group_callback'][1];

							$this->import($strClass);
							$group = $this->$strClass->$strMethod($group, $sortingMode, $firstOrderBy, $row, $this);
						}

						$remoteCur = $remoteNew;

						$return .= '
  <tr onmouseover="Theme.hoverRow(this, 1);" onmouseout="Theme.hoverRow(this, 0);">
    <td colspan="2" class="'.$groupclass.'">'.$group.'</td>
  </tr>';
						$groupclass = 'tl_folder_list';
					}
				}

				$return .= '
  <tr onmouseover="Theme.hoverRow(this, 1);" onmouseout="Theme.hoverRow(this, 0);">
    <td class="tl_file_list">';

				// Call label callback ($row, $label, $this)
				if (is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['label_callback']))
				{
					$strClass = $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['label_callback'][0];
					$strMethod = $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['label_callback'][1];

					$this->import($strClass);
					$return .= $this->$strClass->$strMethod($row, $label, $this);
				}
				else
				{
					$return .= $label;
				}

				// Buttons ($row, $table, $root, $blnCircularReference, $childs, $previous, $next)
				$return .= '</td>'.(($this->Input->get('act') == 'select') ? '
    <td class="tl_file_list tl_right_nowrap"><input type="checkbox" name="IDS[]" id="ids_'.$row['id'].'" class="tl_tree_checkbox" value="'.$row['id'].'" /></td>' : '
    <td class="tl_file_list tl_right_nowrap">'.$this->generateButtons($row, $this->strTable, $this->root).'</td>') . '
  </tr>';
			}

			// Close table
			$return .= '
</table>

</div>';

			// Close form
			if ($this->Input->get('act') == 'select')
			{
				$return .= '

<div class="tl_formbody_submit" style="text-align:right;">

<div class="tl_submit_container">
  <input type="submit" name="delete" id="delete" class="tl_submit" accesskey="d" onclick="return confirm(\''.$GLOBALS['TL_LANG']['MSC']['delAllConfirm'].'\');" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['deleteSelected']).'" />' . (!$GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'] ? '
  <input type="submit" name="override" id="override" class="tl_submit" accesskey="v" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['overrideSelected']).'" />
  <input type="submit" name="edit" id="edit" class="tl_submit" accesskey="s" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['editSelected']).'" />' : '') . '
</div>

</div>
</div>
</form>';
			}
		}

		return $return;
	}


	/**
	 * Build the sort panel and return it as string
	 * @return string
	 */
	protected function panel()
	{
		$filter = $this->filterMenu();
		$search = $this->searchMenu();
		$limit = $this->limitMenu();
		$sort = $this->sortMenu();

		if (!strlen($filter) && !strlen($search) && !strlen($limit) && !strlen($sort))
		{
			return '';
		}

		if (!strlen($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['panelLayout']))
		{
			return '';
		}

		if ($this->Input->post('FORM_SUBMIT') == 'tl_filters')
		{
			$this->reload();
		}

		$return = '';
		$panelLayout = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['panelLayout'];
		$arrPanels = trimsplit(';', $panelLayout);
		$intLast = count($arrPanels) - 1;

		for ($i=0; $i<count($arrPanels); $i++)
		{
			$panels = '';
			$submit = '';
			$arrSubPanels = trimsplit(',', $arrPanels[$i]);

			foreach ($arrSubPanels as $strSubPanel)
			{
				if (strlen($$strSubPanel))
				{
					$panels = $$strSubPanel . $panels;
				}
			}

			if ($i == $intLast)
			{
				$submit = '

<div class="tl_submit_panel tl_subpanel">
<input type="image" name="filter" id="filter" src="system/themes/' . $this->getTheme() . '/images/reload.gif" class="tl_img_submit" title="' . $GLOBALS['TL_LANG']['MSC']['apply'] . '" value="apply changes" />
</div>';
			}

			if (strlen($panels))
			{
				$return .= '
<div class="tl_panel">'.$submit.$panels.'

<div class="clear"></div>

</div>';
			}
		}

		$return = '
<form action="'.ampersand($this->Environment->request, true).'" class="tl_form" method="post">
<div class="tl_formbody">
<input type="hidden" name="FORM_SUBMIT" value="tl_filters" />
' . $return . '
</div>
</form>
';

		return $return;
	}


	/**
	 * Return a search form that allows to search results using regular expressions
	 * @return string
	 */
	protected function searchMenu()
	{
		$searchFields = array();
		$session = $this->Session->getData();

		// Get search fields
		foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'] as $k=>$v)
		{
			if ($v['search'])
			{
				$searchFields[] = $k;
			}
		}

		// Return if there are no search fields
		if (!count($searchFields))
		{
			return '';
		}

		// Store search value in the current session
		if ($this->Input->post('FORM_SUBMIT') == 'tl_filters')
		{
			$session['search'][$this->strTable]['value'] = '';
			$session['search'][$this->strTable]['field'] = $this->Input->post('tl_field', true);

			// Make sure the regular expression is valid
			if ($this->Input->postRaw('tl_value') != '')
			{
				try
				{
					$this->Database->prepare("SELECT * FROM " . $this->strTable . " WHERE " . $this->Input->post('tl_field', true) . " REGEXP ?")
								   ->limit(1)
								   ->execute($this->Input->postRaw('tl_value'));

					$session['search'][$this->strTable]['value'] = $this->Input->postRaw('tl_value');
				}

				catch (Exception $e)
				{
					// Nothing to do here
				}
			}

			$this->Session->setData($session);
		}

		// Set search value from session
		elseif (strlen($session['search'][$this->strTable]['value']))
		{
			$this->procedure[] = "CAST(".$session['search'][$this->strTable]['field']." AS CHAR) REGEXP ?";
			$this->values[] = $session['search'][$this->strTable]['value'];
		}

		$options_sorter = array();

		foreach ($searchFields as $field)
		{
			$option_label = strlen($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'][0]) ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'][0] : $GLOBALS['TL_LANG']['MSC'][$field];
			$options_sorter[utf8_romanize($option_label)] = '  <option value="'.specialchars($field).'"'.(($field == $session['search'][$this->strTable]['field']) ? ' selected="selected"' : '').'>'.$option_label.'</option>';
		}

		// Sort by option values
		$options_sorter = natcaseksort($options_sorter);
		$active = strlen($session['search'][$this->strTable]['value']) ? true : false;

		return '

<div class="tl_search tl_subpanel">
<strong>' . $GLOBALS['TL_LANG']['MSC']['search'] . ':</strong>
<select name="tl_field" class="tl_select' . ($active ? ' active' : '') . '">
'.implode("\n", $options_sorter).'
</select>
<span>=</span>
<input type="text" name="tl_value" class="tl_text' . ($active ? ' active' : '') . '" value="'.specialchars($session['search'][$this->strTable]['value']).'" />
</div>';
	}


	/**
	 * Return a select menu that allows to sort results by a particular field
	 * @return string
	 */
	protected function sortMenu()
	{
		if ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] != 2)
		{
			return '';
		}

		$sortingFields = array();

		// Get sorting fields
		foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'] as $k=>$v)
		{
			if ($v['sorting'])
			{
				$sortingFields[] = $k;
			}
		}

		// Return if there are no sorting fields
		if (!count($sortingFields))
		{
			return '';
		}

		$this->bid = 'tl_buttons_a';
		$session = $this->Session->getData();
		$orderBy = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['fields'];
		$firstOrderBy = preg_replace('/\s+.*$/i', '', $orderBy[0]);

		// Add PID to order fields
		if ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 3 && $this->Database->fieldExists('pid', $this->strTable))
		{
			array_unshift($orderBy, 'pid');
		}

		// Set sorting from user input
		if ($this->Input->post('FORM_SUBMIT') == 'tl_filters')
		{
			$session['sorting'][$this->strTable] = in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->Input->post('tl_sort')]['flag'], array(2, 4, 6, 8, 10, 12)) ? $this->Input->post('tl_sort').' DESC' : $this->Input->post('tl_sort');
			$this->Session->setData($session);
		}

		// Overwrite "orderBy" value with session value
		elseif (strlen($session['sorting'][$this->strTable]))
		{
			$overwrite = preg_quote(preg_replace('/\s+.*$/i', '', $session['sorting'][$this->strTable]), '/');
			$orderBy = array_diff($orderBy, preg_grep('/^'.$overwrite.'/i', $orderBy));

			array_unshift($orderBy, $session['sorting'][$this->strTable]);

			$this->firstOrderBy = $overwrite;
			$this->orderBy = $orderBy;
		}

		$options_sorter = array();

		// Sorting fields
		foreach ($sortingFields as $field)
		{
			$options_label = strlen(($lbl = is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label']) ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'][0] : $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'])) ? $lbl : $GLOBALS['TL_LANG']['MSC'][$field];
			$options_sorter[$options_label] = '  <option value="'.specialchars($field).'"'.((!strlen($session['sorting'][$this->strTable]) && $field == $firstOrderBy || $field == str_replace(' DESC', '', $session['sorting'][$this->strTable])) ? ' selected="selected"' : '').'>'.$options_label.'</option>';
		}

		// Sort by option values
		uksort($options_sorter, 'strcasecmp');

		return '

<div class="tl_sorting tl_subpanel">
<strong>' . $GLOBALS['TL_LANG']['MSC']['sortBy'] . ':</strong>
<select name="tl_sort" id="tl_sort" class="tl_select">
'.implode("\n", $options_sorter).'
</select>
</div>';
	}


	/**
	 * Return a select menu to limit results
	 * @param boolean
	 * @return string
	 */
	protected function limitMenu($blnOptional=false)
	{
		$session = $this->Session->getData();
		$filter = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 4) ? $this->strTable.'_'.CURRENT_ID : $this->strTable;

		// Set limit from user input
		if ($this->Input->post('FORM_SUBMIT') == 'tl_filters' || $this->Input->post('FORM_SUBMIT') == 'tl_filters_limit')
		{
			if ($this->Input->post('tl_limit') != 'tl_limit')
			{
				$session['filter'][$filter]['limit'] = $this->Input->post('tl_limit');
			}
			else
			{
				unset($session['filter'][$filter]['limit']);
			}

			$this->Session->setData($session);

			if ($this->Input->post('FORM_SUBMIT') == 'tl_filters_limit')
			{
				$this->reload();
			}
		}

		// Set limit from table configuration
		else
		{
			$this->limit = strlen($session['filter'][$filter]['limit']) ? (($session['filter'][$filter]['limit'] == 'all') ? null : $session['filter'][$filter]['limit']) : '0,' . $GLOBALS['TL_CONFIG']['resultsPerPage'];
			$query = "SELECT COUNT(*) AS total FROM " . $this->strTable;

			if (is_array($this->root))
			{
				$this->procedure[] = 'id IN(' . implode(',', $this->root) . ')';
			}

			if (count($this->procedure))
			{
				$query .= " WHERE " . implode(' AND ', $this->procedure);
			}

			$objTotal = $this->Database->prepare($query)->execute($this->values);
			$total = $objTotal->total;

			// Build options
			if ($total > 0)
			{
				$options = '';
				$options_total = ceil($total / $GLOBALS['TL_CONFIG']['resultsPerPage']);

				// Reset limit if other parameters have decreased the number of results
				if (!is_null($this->limit) && (!strlen($this->limit) || preg_replace('/,.*$/i', '', $this->limit) > $total))
				{
					$this->limit = '0,'.$GLOBALS['TL_CONFIG']['resultsPerPage'];
				}

				// Build options
				for ($i=0; $i<$options_total; $i++)
				{
					$this_limit = ($i*$GLOBALS['TL_CONFIG']['resultsPerPage']).','.$GLOBALS['TL_CONFIG']['resultsPerPage'];
					$upper_limit = ($i*$GLOBALS['TL_CONFIG']['resultsPerPage']+$GLOBALS['TL_CONFIG']['resultsPerPage']);

					if ($upper_limit > $total)
					{
						$upper_limit = $total;
					}

					$options .= '
  <option value="'.$this_limit.'"' . $this->optionSelected($this->limit, $this_limit) . '>'.($i*$GLOBALS['TL_CONFIG']['resultsPerPage']+1).' - '.$upper_limit.'</option>';
				}

				$options .= '
  <option value="all"' . $this->optionSelected($this->limit, null) . '>'.$GLOBALS['TL_LANG']['MSC']['filterAll'].'</option>';
			}

			// Return if there is only one page
			if ($blnOptional && ($total < 1 || $options_total < 2))
			{
				return '';
			}

			$fields .= '
<select name="tl_limit" class="tl_select' . (($session['filter'][$filter]['limit'] != 'all' && $total > $GLOBALS['TL_CONFIG']['resultsPerPage']) ? ' active' : '') . '">
  <option value="tl_limit">'.$GLOBALS['TL_LANG']['MSC']['filterRecords'].'</option>'.$options.'
</select> ';
		}

		return '

<div class="tl_limit tl_subpanel">
<strong>' . $GLOBALS['TL_LANG']['MSC']['showOnly'] . ':</strong>'.$fields.'
</div>';
	}


	/**
	 * Generate the filter panel and return it as HTML string
	 * @return string
	 */
	protected function filterMenu()
	{
		$fields = '';
		$this->bid = 'tl_buttons_a';
		$sortingFields = array();
		$session = $this->Session->getData();
		$filter = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 4) ? $this->strTable.'_'.CURRENT_ID : $this->strTable;

		// Get sorting fields
		foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'] as $k=>$v)
		{
			if ($v['filter'])
			{
				$sortingFields[] = $k;
			}
		}

		// Return if there are no sorting fields
		if (!count($sortingFields))
		{
			return '';
		}

		// Set filter from user input
		if ($this->Input->post('FORM_SUBMIT') == 'tl_filters')
		{
			foreach ($sortingFields as $field)
			{
				if ($this->Input->post($field, true) != 'tl_'.$field)
				{
					$session['filter'][$filter][$field] = $this->Input->post($field, true);
				}
				else
				{
					unset($session['filter'][$filter][$field]);
				}
			}

			$this->Session->setData($session);
		}

		// Set filter from table configuration
		else
		{
			foreach ($sortingFields as $field)
			{
				if (isset($session['filter'][$filter][$field]))
				{
					// Sort by day
					if (in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'], array(5, 6)))
					{
						if ($session['filter'][$filter][$field] == '')
						{
							$this->procedure[] = $field . "=''";
						}
						else
						{
							$objDate = new Date($session['filter'][$filter][$field]);
							$this->procedure[] = $field . ' BETWEEN ? AND ?';
							$this->values[] = $objDate->dayBegin;
							$this->values[] = $objDate->dayEnd;
						}
					}

					// Sort by month
					elseif (in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'], array(7, 8)))
					{
						if ($session['filter'][$filter][$field] == '')
						{
							$this->procedure[] = $field . "=''";
						}
						else
						{
							$objDate = new Date($session['filter'][$filter][$field]);
							$this->procedure[] = $field . ' BETWEEN ? AND ?';
							$this->values[] = $objDate->monthBegin;
							$this->values[] = $objDate->monthEnd;
						}
					}

					// Sort by year
					elseif (in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'], array(9, 10)))
					{
						if ($session['filter'][$filter][$field] == '')
						{
							$this->procedure[] = $field . "=''";
						}
						else
						{
							$objDate = new Date($session['filter'][$filter][$field]);
							$this->procedure[] = $field . ' BETWEEN ? AND ?';
							$this->values[] = $objDate->yearBegin;
							$this->values[] = $objDate->yearEnd;
						}
					}

					// Manual filter
					elseif ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['multiple'])
					{
						$this->procedure[] = $field . ' LIKE ?';
						$this->values[] = '%"' . $session['filter'][$filter][$field] . '"%';
					}

					// Other sort algorithm
					else
					{
						$this->procedure[] = $field . '=?';
						$this->values[] = $session['filter'][$filter][$field];
					}
				}
			}
		}

		// Add sorting options
		foreach ($sortingFields as $field)
		{
			$arrValues = array();
			$arrProcedure = array();

			if ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] == 4)
			{
				$arrProcedure[] = 'pid=?';
				$arrValues[] = CURRENT_ID;
			}

			if (is_array($this->root) && count($this->root) > 0)
			{
				$arrProcedure[] = "id IN(" . implode(',', array_map('intval', $this->root)) . ")";
			}

			$objFields = $this->Database->prepare("SELECT DISTINCT(" . $field . ") FROM " . $this->strTable . ((is_array($arrProcedure) && strlen($arrProcedure[0])) ? ' WHERE ' . implode(' AND ', $arrProcedure) : ''))
										->execute($arrValues);

			// Begin select menu
			$fields .= '
<select name="'.$field.'" id="'.$field.'" class="tl_select' . (isset($session['filter'][$filter][$field]) ? ' active' : '') . '">
  <option value="tl_'.$field.'">'.(is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label']) ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'][0] : $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label']).'</option>';

			if ($objFields->numRows)
			{
				$options = $objFields->fetchEach($field);

				// Sort by day
				if (in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'], array(5, 6)))
				{
					($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'] == 6) ? rsort($options) : sort($options);

					foreach ($options as $k=>$v)
					{
						if ($v == '')
						{
							$options[$v] = '-';
						}
						else
						{
							$options[$v] = $this->parseDate($GLOBALS['TL_CONFIG']['dateFormat'], $v);
						}

						unset($options[$k]);
					}
				}

				// Sort by month
				elseif (in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'], array(7, 8)))
				{
					($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'] == 8) ? rsort($options) : sort($options);

					foreach ($options as $k=>$v)
					{
						if ($v == '')
						{
							$options[$v] = '-';
						}
						else
						{
							$options[$v] = date('Y-m', $v);
							$intMonth = (date('m', $v) - 1);

							if (strlen($GLOBALS['TL_LANG']['MONTHS'][$intMonth]))
							{
								$options[$v] = $GLOBALS['TL_LANG']['MONTHS'][$intMonth] . ' ' . date('Y', $v);
							}
						}

						unset($options[$k]);
					}
				}

				// Sort by year
				elseif (in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'], array(9, 10)))
				{
					($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'] == 10) ? rsort($options) : sort($options);

					foreach ($options as $k=>$v)
					{
						if ($v == '')
						{
							$options[$v] = '-';
						}
						else
						{
							$options[$v] = date('Y', $v);
						}

						unset($options[$k]);
					}
				}

				// Manual filter
				if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['multiple'])
				{
					$moptions = array();

					// TODO: find a more effective solution
					foreach($options as $option)
					{
						$doptions = deserialize($option);

						if (is_array($doptions))
						{
							$moptions = array_merge($moptions, $doptions);
						}
					}

					$options = $moptions;
				}

				$options = array_unique($options);
				$options_callback = array();

				// Load options callback
				if (is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options_callback']) && !$GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['reference'])
				{
					$strClass = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options_callback'][0];
					$strMethod = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options_callback'][1];

					$this->import($strClass);
					$options_callback = $this->$strClass->$strMethod($this);

					// Sort options according to the keys of the callback array
					$options = array_intersect(array_keys($options_callback), $options);
				}

				$options_sorter = array();
				$blnDate = in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'], array(5, 6, 7, 8, 9, 10));

				// Options
				foreach ($options as $kk=>$vv)
				{
					$value = $blnDate ? $kk : $vv;

					// Get name of the parent record
					if ($field == 'pid')
					{
						$showFields = $GLOBALS['TL_DCA'][$this->ptable]['list']['label']['fields'];

						if (!$showFields[0])
						{
							$showFields[0] = 'id';
						}

						$objShowFields = $this->Database->prepare("SELECT " . $showFields[0] . " FROM ". $this->ptable . " WHERE id=?")
														->limit(1)
														->execute($vv);

						if ($objShowFields->numRows)
						{
							$vv = $objShowFields->$showFields[0];
						}
					}

					// Replace ID with the foreign key
					elseif(strlen($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['foreignKey']))
					{
						$key = explode('.', $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['foreignKey']);

						$objParent = $this->Database->prepare("SELECT " . $key[1] . " FROM " . $key[0] . " WHERE id=?")
													->limit(1)
													->execute($vv);

						if ($objParent->numRows)
						{
							$vv = $objParent->$key[1];
						}
					}

					// Replace boolean checkbox value with "yes" and "no"
					elseif ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['isBoolean'] || ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['inputType'] == 'checkbox' && !$GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['multiple']))
					{
						$vv = strlen($vv) ? $GLOBALS['TL_LANG']['MSC']['yes'] : $GLOBALS['TL_LANG']['MSC']['no'];
					}

					// Options callback
					elseif (is_array($options_callback) && count($options_callback) > 0)
					{
						$vv = $options_callback[$vv];
					}

					$option_label = '';

					// Use reference array
					if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['reference']))
					{
						$option_label = is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['reference'][$vv]) ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['reference'][$vv][0] : $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['reference'][$vv];
					}

					// Associative array
					elseif (array_is_assoc($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options']))
					{
						$option_label = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options'][$vv];
					}

					// No empty options allowed
					if (!strlen($option_label))
					{
						$option_label = strlen($vv) ? $vv : '-';
					}

					$options_sorter['  <option value="' . specialchars($value) . '"' . ((isset($session['filter'][$filter][$field]) && $value == $session['filter'][$filter][$field]) ? ' selected="selected"' : '').'>'.$option_label.'</option>'] = utf8_romanize($option_label);
				}

				// Sort by option values
				if (!$blnDate)
				{
					natcasesort($options_sorter);

					if (in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'], array(2, 4, 12)))
					{
						$options_sorter = array_reverse($options_sorter, true);
					}
				}

				$fields .= "\n" . implode("\n", array_keys($options_sorter));
			}

			// End select menu
			$fields .= '
</select> ';
		}

		return '

<div class="tl_filter tl_subpanel">
<strong>' . $GLOBALS['TL_LANG']['MSC']['filter'] . ':</strong>' . $fields . '
</div>';
	}
}

?>