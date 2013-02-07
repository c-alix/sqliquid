<?php

class SQLiquid {
	
	protected $_init_done;
	protected $_null_result;
	protected $_where;
	protected $_or_where;
	protected $_join;
	protected $_groupby;
	protected $_order;
	protected $_debug;
	protected $_pretend;
	protected $_cols;
	protected $_fields;
	protected $_table;
	public $_db;
	
	/**
	 * Can instantiate with a pkey, or an array (or object) of values to be loaded
	 */
	public function __construct($load_id=false,$fields='')
	{
		$this->_init();
		
		if ($load_id)
		{
			if (is_numeric($load_id))
			{
				$this->loadById($load_id,$fields);
			}
			else
			{
				$this->loadValset($load_id);
			}
		}
	}
	
	protected function _init()
	{
		static $schema;
		
		$CI = get_instance();
		$this->_db = $CI->db;
	
		$this->_null_result = false;
		$this->_table = strtolower(substr(get_class($this),0,-6));
		
		$table = $this->_table;
		
		if (!isset($schema[$table]))
		{
			$cache_key = "Schema: $table";
			
			//if (!$schema[$table] = MCache::get($cache_key))
			{
				$q = $CI->db->query("DESCRIBE $table");
				$cols = $q->result();
				//var_dump($cols);
				
				foreach ($cols as $r)
				{
					if ($r->Null == 'YES')
					{
						$r->Type .= ' NULL';
					}
					
					$this->_cols[$r->Field] = $r->Type;
				}
				
				// Get subclass defined cols (override table desc)
				$cols = get_object_vars($this);
				foreach ($cols as $col_name=>$col_val)
				{
					if (substr($col_name,0,1) != '_')
					{
						$this->_cols[$col_name] = $col_val;
					}
				}
		
				$this->_loadAttribs();
				
				$schema[$table] = $this->_cols;
				
				//MCache::put($cache_key,$schema[$table]);
			}
		}
		
		$this->_cols = $schema[$table];
		
		foreach ($this->_cols as $k=>$v)
		{
			$this->$k = null;
		}
		
		$this->_init_done = true;
	}
	
	public function getCols()
	{
		return $this->_cols;
	}
	
	/**
	 * This prevents trying to set fields which dont exist
	 */
	public function __set($key,$val)
	{
		//if ($this->_init_done && !isset($this->$key))
		
		if ($this->_init_done && !isset($this->$key) && substr($key,0,1) != '_')
		{
			echo "Field '$key' does not exist <br/>\n";
			return;
		}
		
		$this->$key = $val;
	}
	
	public function __toString()
	{
		if ($this->_null_result)
		{
			return '0';
		}
		else
		{
			return '1';
		}
	}
	
	public function __call($field, $arguments)
	{
		if (!empty($field) && isset($this->_cols[$field]))
		{
			$attr = $this->_cols[$field];
			
			//var_dump($this->_cols);
			
			return new DataProp($this->_table,$field,$this->$field,$attr);
		}
		else
		{
			echo "$field not found in model";
			return new StdClass;
		}
	}
	
	/**
	 * Loads values to instance from DB
	 *
	 * @param integer $load_id
	 * @param string $fields
	 * @return bool
	 */
	public function loadById($load_id='',$fields='')
	{
		if ($load_id)
		{
			$pkey = $this->_get_pkey();
			$this->$pkey = $load_id;
		}
		
		$arr = $this->get($fields,1);
		
		if (is_array($arr) && isset($arr[0]))
		{
			foreach ($arr[0] as $key=>$val)
			{
				$this->$key = $val;
			}			
			return true;
		}
		else
		{
			$this->_null_result = true;
			return false;
		}
	}

	/**
	 * Loads values into instance
	 *
	 * @param array $arr OR stdClass
	 */
	public function loadValset($arr)
	{
		if (is_array($arr))
		{
			foreach ($this->_cols as $key=>$val)
			{
				if (isset($arr[$key]) && $arr[$key] !== null)
				{
					$this->$key = $arr[$key];
				}
			}
		}
		elseif ($arr instanceof stdClass)
		{
			foreach ($this->_cols as $key=>$val)
			{
				if (isset($arr->$key) && $arr->$key !== null)
				{
					$this->$key = $arr->$key;
				}
			}
		}
	}
	
	/**
	 * Loads values from DataProp rendered Post
	 * Example: users__fname
	 * 
	 * TODO change naming format to "data[cms_sites][all_reviews]"
	 *
	 * @param array $arr
	 */
	public function loadPost($arr)
	{
		$table_key = $this->_table.'__';
		
		foreach ($this->_cols as $key=>$val)
		{
			$arr_key = $table_key.$key;
			
			if (isset($arr[$arr_key]) && $arr[$arr_key] !== null)
			{
				$this->$key = $arr[$arr_key];
			}
		}
	}
	
	/**
	 * Selects from DB, returns array
	 *
	 * @param string $fields
	 * @param integer $limit
	 * @param integer $offset
	 * @param bool $count_only
	 * @return array
	 */
	public function get($fields='', $limit=false, $offset=false, $count_only=false)
	{
		$table = $this->_table;
		
		$join = '';
		$where = '';
		$or_where = '';
		$order = '';
		
		foreach ($this->_cols as $key=>$val)
		{
			// automatically add a filter for values we already have
			if ($this->$key !== null)
			{
				if (!isset($auto_where))
				{
					$auto_where = array();
				}
			
				$auto_where[] = "(`$table`.$key = '".addslashes($this->$key)."')";
			}
		}

		if (empty($fields))
		{
			$fields = "`$table`.*";
		}

		if (isset($this->_fields))
		{
			$fields .= ', '.substr($this->_fields,0,-2);
		}

		if (is_array($this->_join))
		{
			$join = implode(' ',$this->_join);
			$add_ins[] = $join;
		}
		
		
		if (isset($auto_where) && is_array($auto_where))
		{
			$where = 'WHERE '.implode(' AND ',$auto_where);
		}
		
		if (is_array($this->_where))
		{
			if (empty($where))
			{
				$where = 'WHERE '.implode(' AND ',$this->_where);
			}
			else
			{
				$where .= ' AND '.implode(' AND ',$this->_where);
			}	
		}
		$add_ins[] = $where;
		
		if (is_array($this->_or_where))
		{
			if (empty($where))
			{
				$or_where = 'WHERE '.implode(' OR ',$this->_or_where);
			}
			else
			{
				$where .= ' AND '.implode(' AND ',$this->_or_where);
			}	
			$add_ins[] = $or_where;
		}
		

		if (is_array($this->_groupby))
		{
			$groupby = 'GROUP BY '.implode(' ',$this->_groupby);
			$add_ins[] = $groupby;
		}
		
		if (is_array($this->_order))
		{
			$order = 'ORDER BY '.implode(', ',$this->_order);
			$add_ins[] = $order;
		}
		
		if ($limit > 0)
		{
			$add_ins[] = "LIMIT $limit";
		}
		if ($offset > 0)
		{
			$add_ins[] = "OFFSET $offset";
		}
		
		if (is_array($add_ins))
		{
			$add_ins = ' '.implode(' ',$add_ins);
		}
		
		if ($count_only)
		{
			$sql = "SELECT COUNT(*) AS count FROM `{$table}`{$add_ins}";
		}
		else
		{
			$sql = "SELECT {$fields} FROM `{$table}`{$add_ins}";
		}

		if ($this->_debug)
		{
			echo "Ran SQL: \"$sql\"\n";
		}
		
		$q = $this->_db->query($sql);
		
		if ($count_only)
		{
			$q = $q->result();
			return $q[0]->count;
		}
		else
		{
			return $q->result();
		}
	}
	
	/**
	 * Returns one row
	 */
	public function getOne($fields='')
	{
		$res = $this->get($fields, 1);
		
		if (isset($res[0]))
		{
			return $res[0];
		}
		
		return false;
	}
	
	/**
	 * Returns one column from one row
	 */
	function getVal($fields='')
	{
		$res = $this->getOne($fields);
		
		if (is_object($res))
		{
			$field = array_keys(get_object_vars($res));
			
			if (is_array($field))
			{
				$field = $field[0];
				
				return $res->$field;
			}
		}
	}
	
	/**
	 * Returns an array like $[field1] = field2;
	 */
	function getList($fields='', $limit=false, $offset=false)
	{
		$res = $this->get($fields, $limit, $offset);
		
		if (is_array($res) && isset($res[0]) && is_object($res[0]))
		{
			$fields_list = explode(',',$fields);
			if (count($fields_list) != 2)
			{
				$fields_list = array_keys(get_object_vars($res[0]));
			}
			$key = trim($fields_list[0]);
			$val = trim($fields_list[1]);
			
			foreach ($res as $r)
			{
				$ret[$r->$key] = $r->$val;
			}
			
			return $ret;
		}
		return false;
	}
	
	/**
	 * Returns an array like $[field1] = row;
	 */
	public function getIndexed($fields='', $limit=false, $offset=false)
	{
		$res = $this->get($fields, $limit, $offset);
		
		if ($res[0] && is_object($res[0]))
		{
			$key = array_keys(get_object_vars($res[0]));
			$key = $key[0];
			
			foreach ($res as $r)
			{
				$ret[$r->$key] = $r;
			}
			
			return $ret;
		}
		
		return false;
	}
	
	/**
	 * Adds a join to the get() query
	 *
	 * Each field can have an alias
	 * Example: username as created_by
	 *
	 * @param string $table name of foreign table to join with
	 * @param string $join_key name of local field which holds primary key of foreign table
	 * @param string $fields one or more fields to select from the foreign table delimited by commas
	 * @param string $pkey primary key of foreign table
	 * @param string $jointype
	*/
	public function join($table, $join_key, $fields='', $jointype='LEFT')
	{
		static $join_id = 0;

		$join_id++;

		$join_key = explode('=',$join_key);

		if (!isset($join_key[1]))
		{
			$join_key[1] = $join_key[0];
		}
		
		$join_src = '';
				
		if (isset($this->_cols[$join_key[0]]))
		{
			$join_src = "`$this->_table`.";
		}
		
		@list($table, $t_alias) = explode(' ',$table);

		if (!$t_alias)
		{
			$t_alias = 'join'.$join_id;
		}

		if ($fields)
		{
			$fields = explode(',',$fields);

			foreach ($fields as $field)
			{
				$field = trim($field);			
				$this->_fields .= "`$t_alias`.{$field}, ";
			}
		}

		$this->_join[] = "$jointype JOIN `$table` AS $t_alias ON {$join_src}{$join_key[0]} = `$t_alias`.{$join_key[1]}";
	}


	public function delete()
	{
		$table = $this->_table;
		
		foreach ($this->_cols as $key=>$val)
		{
			if ($this->$key !== null)
			{
				$where[] = "`$table`.$key = '".addslashes($this->$key)."'";
			}
		}
		
		// CANT DELETE WITHOUT _SOME_ CONDITION
		if (is_array($where))
		{
			$where = implode(' AND ',$where);
			
			$sql = "DELETE FROM `{$table}` WHERE $where";
			
			if ($this->_pretend)
			{
				echo "Pretend Ran SQL: \"$sql\"\n";
			}
			else
			{
				if ($this->_debug)
				{
					echo "Ran SQL: \"$sql\"\n";
				}
				$this->_db->query($sql);
			}
			return true;
		}
		
		return false;
	}
	
	/**
	 * Determines Add or Update operation
	 *
	 * @return bool
	 */
	public function save()
	{
		$pkey = $this->_get_pkey();

		if (empty($this->$pkey))
		{
			return $this->add();
		}

		return $this->update();
	}

	/**
	 * Insert a record
	 *
	 * @return bool
	 */
	public function add()
	{
		if (!$this->validate())
		{
			return $this->_error;
		}
		
		$pkey = $this->_get_pkey();

		$values = '';
		$fields = '';

		foreach ($this->_cols as $key=>$val)
		{
			if ($key == $pkey) continue;

			if ($this->$key !== null)
			{
				$fields .= "`$key`, ";

				if (is_object($this->$key) && $this->$key instanceof SqlFunc)
				{
					$values .= $this->$key.', ';
				}
				else
				{
					$values .= "'".addslashes($this->$key)."', ";
				}
			}
		}

		$fields = substr($fields,0,-2);
		$values = substr($values,0,-2);

		$sql = "INSERT INTO `$this->_table` ($fields) VALUES ($values)";

		
		if ($this->_debug)
		{
			echo "Ran SQL: \"$sql\"\n";
		}
		
		$this->_db->query($sql);

		$this->$pkey = $this->_db->insert_id();
		
		return true;
	}

	/**
	 * Update a record
	 *
	 * @return bool
	 */
	public function update($fields='')
	{		
		if (!$this->validate())
		{
			//throw new Exception('not validate');
			return $this->_error;
		}
		
		$pkey = $this->_get_pkey();

		if (empty($this->$pkey))
		{
			return;
		}

		// optionally specify fields to update
		if ($fields)
		{
			$tmp = explode(',',$fields);
			$fields = array();

			foreach ($tmp as $v)
			{
				$fields[$v] = '';
			}
		}
		else
		{
			$fields = $this->_cols;
		}

		$values = '';

		foreach ($fields as $key=>$val)
		{
			$val = $this->$key;
			
			if ($val !== null && $key != $pkey)
			{
				$attribs = $this->_cols[$key];
				
				if (strstr($key,'roll'))
				{
					if ($val === '' && $attribs['type'] == 'int' && $attribs['null'])
					{
						$val = new SqlFunc('NULL');
					}
				}
				
				if (is_object($val) && $val instanceof SqlFunc)
				{
					$values .= "`$key`={$val}, ";
				}
				else
				{
					$values .= "`$key`='".addslashes($val)."', ";
				}
			}
		}

		$values = substr($values,0,-2);
		
		if (!empty($values))
		{
			$sql = "UPDATE `$this->_table` SET $values WHERE `$pkey` = '{$this->$pkey}'";

			if ($this->_debug)
			{
				echo "Ran SQL: \"$sql\"\n";
			}
			
			$this->_db->query($sql);
			return true;
		}
		
		return false;
	}

	/**
	 * Adds a SQL conditional
	 *
	 * Example:
	 * id = 1
	 *
	 * @param string $sql
	 */
	public function where($sql)
	{
		if (!isset($this->_where))
		{
			$this->_where = array();
		}

		$this->_where[] = "($sql)";
	}
	
	public function orWhere($sql)
	{
		if (!isset($this->_or_where))
		{
			$this->_or_where = array();
		}

		$this->_or_where[] = "($sql)";
	}

	/**
	 * Adds an SQL ORDER BY
	 *
	 * @param string $field name of field with optional desc\asc seperated by one space
	 */
	public function order($field)
	{
		$this->_order[] = "`$field`";
	}
	
	/**
	 * Adds an SQL GROUP BY
	 *
	 * @param string $sql
	 */
	public function group($sql)
	{
		if (!isset($this->_groupby))
		{
			$this->_groupby = array();
		}

		$this->_groupby[] = "($sql)";
	}
	
	public function validate()
	{
		$valid = true;
		
		foreach ($this->_cols as $key=>$attrib)
		{
			//var_dump($this->$key);
			//var_dump($attrib);exit;
			
			$val = (string) $this->$key;
			if ($val === '')
			{
				if (isset($attrib['required']))
				{
					$valid = false;
					$this->addError($key,'Cannot be empty');
				}
				else
				{
					//var_dump($key.' not req');
					continue;
				}
			}
			
			$type = '';
			if (isset($attrib['type']))
			{
				$type = $attrib['type'];
				
				switch ($type)
				{
					case 'int':
						if (!is_numeric($val))
						{
							$valid = false;
							$this->addError($key,'Must be numeric');
						}
						break;
					
					case 'ip':
						// TODO
						//$valid = false;
						//$this->addError($key,"Invalid $type");
						break;
					
					case 'email':
						// TODO
						//$valid = false;
						//$this->addError($key,"Invalid $type");
						break;
				}
			}
			
			if (isset($attrib['maxlength']))
			{
				if ($type == 'str' && strlen($val) > $attrib['maxlength'])
				{
					$valid = false;
					$this->addError($key,"Exceeds maxlength {$attrib['maxlength']}");
				}
			}
			
			if (isset($attrib['match']))
			{
				if (!preg_match($attrib['match'],$val))
				{
					$valid = false;
					$this->addError($key,"Does not match {$attrib['match']}");
				}
			}
		}
		
		if ($this->_debug)
		{
			if (isset($this->_error) && !empty($this->_error))
			{
				var_dump($this->_error);
			}
		}
		
		return $valid;
	}
	
	public function required($field)
	{
		$this->addValidation($field,'required');
	}
	
	public function addValidation($field,$val)
	{
		if (isset($this->_cols[$field]))
		{
			if (is_array($this->_cols[$field]))
			{
				if (is_array($val))
				{
					$key = array_keys($val);
					$this->_cols[$field][$key[0]] = $val[$key[0]];
				}
				else
				{
					$this->_cols[$field][$val] = true;
				}
			}
		}
		
	}
	
	protected function _parseColAttribs($val)
	{
		preg_match("`([\w]+)(\(.+\))?`i",$val,$m);
		
		$attrib = array();
		
		
		if (strstr($val,'NULL'))
		{
			$attrib['null'] = true;
		}
		
		switch ($m[1])
		{
			case 'pkey':
				$attrib['type'] = 'pkey';
				$attrib['maxlength'] = substr($m[2],1,-1);
				break;
				
			case 'decimal':
				$attrib['type'] = 'int';
				break;
				
			case 'int':
				$attrib['type'] = 'int';
				$attrib['maxlength'] = substr($m[2],1,-1);
				break;
			
			case 'varchar':
				$attrib['maxlength'] = substr($m[2],1,-1);
				$attrib['type'] = 'str';
				break;
				
			case 'text':
			case 'longtext':
				$attrib['type'] = 'str';
				$attrib['type_ext'] = 'textarea';
				break;
				
			case 'enum':
				$attrib['type'] = 'enum';
				$attrib['vals'] = explode(',',substr(str_replace("'",'',$m[2]),1,-1));
				break;
		}
		
		return $attrib;
	}
	
	/**
	 * This is done so that _cols will continue to hold field attribs
	 * while the public facing properties are null
	 *
	 */
	protected function _loadAttribs()
	{
		$i=0;
		foreach ($this->_cols as $key=>$val)
		{
			if (is_string($val))
			{
				if ($i == 0)
				{
					$this->_cols[$key] = $this->_parseColAttribs('pkey(11)');
				}
				else
				{
					$this->_cols[$key] = $this->_parseColAttribs($val);
				}
			}
			$i++;
		}
	}

	public function _get_pkey()
	{
		if (!isset($this->_pkey))
		{
			$pkey = array_keys($this->_cols);
			$this->_pkey = $pkey[0];
		}

		return $this->_pkey;
	}

	protected function addError($field, $message)
	{
		$this->_error[$field][] = $message;
	}

	public function getError($field='')
	{
		if ($field)
		{
			return $this->_error[$field];
		}
		else
		{
			return $this->_error;
		}
	}
	
	public function debug($bool=1)
	{
		$this->_debug = $bool;
	}
	
	public function pretend($bool=1)
	{
		$this->_pretend = $bool;
	}
}

class SqlFunc
{
	function __construct($val)
	{
		$this->val = $val;
	}

	function __toString()
	{
		return (string) $this->val;
	}
}

class DataProp
{
	public $table;
	public $field;
	public $field_val;
	public $attr;

	function __construct($table,$field,$field_val,$attr)
	{
		$this->table = $table;
		$this->field = $field;
		$this->field_val = $field_val;
		$this->attr = $attr;
	}
	
	function __toString()
	{
		//return $this->field_val;
		return $this->render();
	}
	
	public function render($type_override='',$style='')
	{
		$html = '';

		$table = $this->table;
		$field = $this->field;
		
		if ($type_override)
		{
			$type = $type_override;
		}
		else
		{
			$type = $this->attr['type'];
			
			if ($this->attr['type_ext'])
			{
				$type = $this->attr['type_ext'];
			}
		}
		
		//var_dump($field,$attr);
		
		if (!empty($style))
		{
			$style = "style=\"$style\" ";
		}
		
		$size = '';
		
		
		switch ($type)
		{
			case 'pkey':
				$html = Html::input($table.'__'.$field,$this->field_val,'hidden');
				break;
				
			case 'textarea':
				if ($this->attr['maxlength'])
				{
					$size = 'maxlength="'.$this->attr['maxlength'].'" ';
				}
				$html = '<textarea class="mdata" cols="70" rows="6" id="'.$table.'__'.$field.'" name="'.$table.'__'.$field.'" '.$size.$style.'>'.$this->field_val.'</textarea>';
				break;
			
			case 'enum':
				$html = Html::select($table.'__'.$field,$this->attr['vals'],$this->field_val);
				break;
				
			default:
				if ($this->attr['maxlength'])
				{
					if ($this->attr['maxlength'] > 80)
					{
						$size = 'size="80" ';
					}
					else
					{
						$size = 'size="'.$this->attr['maxlength'].'" ';
					}
				}
				$html = '<input class="mdata" id="'.$table.'__'.$field.'" name="'.$table.'__'.$field.'" '.$size.$style.'value="'.$this->field_val.'" />';
		}
		
		return $html;
	}	
}
