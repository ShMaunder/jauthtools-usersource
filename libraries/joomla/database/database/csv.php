<?php
/**
 * CSV File Parser
 * 
 * Loads a CSV file and presents it like a database 
 * 
 * PHP5
 *  
 * Created on Oct 10, 2008
 * 
 * @package JAuthTools
 * @author Sam Moffatt <pasamio@gmail.com>
 * @license GNU/GPL http://www.gnu.org/licenses/gpl.html
 * @copyright 2009 Sam Moffatt 
 * @version SVN: $Id:$
 * @see http://joomlacode.org/gf/project/jauthtools   JoomlaCode Project: Joomla! Authentication Tools    
 */
 
jimport('joomla.database.database');
jimport('sqlparser.Parser'); // pear for some reason likes having different cases

class JDatabaseCSV extends JDatabase {
	var $name = 'csv';
	

	// Hide these from JObject 
	private $_tables = Array();  // table cache
	private $_queries = Array(); // query cache
	private $_results = Array();	// results cache
	private $_dirty = 0;			// sets if the query is dirty (e.g. rerun) 
	private $_insertid = 0;		// last insert id
	private $_instancename = '';	// the instance name
	
	// These can be exposed via get/set routines
	public $_cursor = 0;		// current cursor id
	protected $persist = 0;		// persist data changes (e.g. write out later)
	protected $affectedrows = 0;	// number of rows affected by the last change
	
	
	private $current = null; // current result		

	function __construct($options) {
		$this->parser = new Sql_Parser();
		if(isset($options['name'])) $this->addTable($options);
		$this->persist = array_key_exists('persist', $options) ? $options['persist'] : 0;
		$this->_instancename = array_key_exists('instancename', $options) ? $options['instancename'] : 'unknown';
		parent::__construct($options);
	}
	
	function addTable($options) {
		if(isset($options['name']) && isset($options['file'])) {
			$maxlinelength = array_key_exists('maxlinelength', $options) ? $options['maxlinelength'] : 1000;
			$key = array_key_exists('key', $options) ? $options['key'] : null; 
			$name = $options['name'];
			$fh = fopen($options['file'],'r');
			if(!$fh) {
				JError::raiseWarning(10,'Failed to load database '. $name);
				return false;
			}
			$cols = fgetcsv($fh, $maxlinelength); // max 1000 chars; over kill really
			$this->_tables[$name] = new CSVTable($name, $cols, $key); // kill any existing table or create new
			while($row = fgetcsv($fh, $maxlinelength)) {
				$this->_tables[$name]->addRow($row);
			}
			fclose($fh);
		}
	}
	
	function test() {
		return (function_exists( 'fgetcsv' ));
	}
	
	function connected() {
		return true; // always connected
	}
	
	function getEscaped($text, $extra = false) {
		return '"' . addslashes($text) .'"';
	}
	
	function setQuery( $sql, $offset = 0, $limit = 0, $prefix='#__' ) {
		$this->_dirty = 1; // set the dirty flag
		parent::setQuery( $sql, $offset, $limit, $prefix );
	}
	
	function query() {
		$this->affectedrows = 0; // reset this
		$this->_dirty = 0; // and this
		
		$tree = $this->parser->parse($this->_sql); // parse it
		switch($tree['command']) {
			case 'select':
				$result = $this->_handleSelect($tree);
				break;
			default:
				$this->setError('Unsupported SQL query!');
				return false;
				break;
		}
		if(!$result) {
			return false;
		}
		$this->_queries[] = $this->_sql; // log the query
		return Array('tree'=>$tree,'result'=>$result);
	}
	
	function getAffectedRows() {
		return $this->affectedrows;
	}
	
	function explain() {
		return 'EXPLAIN not supported';
	}
	
	function getNumRows( $cur = null ) {
		if($cur) $cur = $this->current;
		return isset($results[$cur]) ? count($result[$cur]) : false;
	}
	
	function loadResult() {
		if($this->_dirty && !($cur = $this->query())) {
			return null;
		}
		if(!$this->_dirty) $cur = $this->current;
		$ret = null;
		if(isset($this->_results[$this->current][0][0])) {
			$ret = $this->_results[$this->current][0][0];
		}
		return $ret;
	}
	
	function loadResultArray($numinarray = 0) {
		if($this->_dirty && !($cur = $this->query())) {
			return null;
		}
		if(!$this->_dirty) $cur = $this->current;
		$ret = Array();
		if(isset($this->_results[$this->current][0])) {
			foreach($this->_results[$this->current][0] as $row) {
				$ret[] = $row[$numinarray];
			}
		}
		
		
		return $ret;
	}
	
	function loadAssoc() {
		if($this->_dirty && !($cur = $this->query())) {
			return null;
		}
		if(!$this->_dirty) $cur = $this->current;
		// TODO: Finish this function
		// code below transplanted from CSVTable class
		// turns numeric indexes into assoc ones
		$newarray = Array();
		foreach($this->_results[$cur] as $key=>$value) {
			if(is_integer($key)) {
				$newarray[$this->columns[$key]] = $value;
				//$row[$this->columns[$key]] = $value;
			} else {
				$newarray[$key] = $value;
			}
		}
		
		return $newarrray;
		
		
	}
	
	function insertid() {
		return $this->_insertid;
	}
	
	function getVersion() {
		return '0.1';
	}
	
	function getCollation() { 
		return 'latin1_swedish_ci'; // fake this
	}
	
	function getTableList()  {
		return array_keys($this->_tables);
	}
	
	/**
	 * Frees a result
	 * @param Cursor pointing to result
	 * @return boolean Result of operation
	 */
	function freeResult($cur) {
		if(array_key_exists($cur, $this->_results)) {
			unset($this->_results);
			return true;
		} else {
			$this->setError('Invalid resultset');
			return false;
		}
	}
	
	function _handleSelect($tree) {
		// check that the tables are valid
		if (count (array_intersect($tree['table_names'],array_keys($this->_tables))) == count($tree['table_names'])) {
			// TODO: check for table aliases 
			if($wildcard = array_search('*',$tree['column_names'])) {
				$tree['column_names'] = array_merge($tree['column_names'], $this->_tables[$tree['table_names'][0]]->columns);
				unset($tree[$wildcard]);
				// Dirty performance hack! 
				// Actually, this is a bug - we should check for joins or functions in other fields
				//return $this->_tables[$tree['table_names'][0]]->rows;	
			}
			$table = $tree['table_names'][0];
			$this->current = $this->_cursor;
			++$this->_cursor; // should really put a lock on this but since php is single threaded we're safe
			$cur = $this->current;
			$this->_results[$cur] = Array();
			foreach($this->_tables[$table]->rows as $tkey=>$tval) {
				$this->_results[$cur][] =& $this->_tables[$table]->rows[$tkey];
			}
			// grab and parse the where clause
			if(isset($tree['where_clause'])) {
				$results = $this->_applyWhere($table, $tree['where_clause'], $cur);
			}
			return ;
		} else {
			$this->setError('Table doesn\'t exist!');
			return false;
		}
	}
	
	function _applyWhere($table, $where_clause, $cur) {
		//if($where_clause['arg_1'])
		foreach($where_clause as $key => $clause) {
			if(array_key_exists('type', $clause)) {
				if(is_array($clause['type'])) {
					// TODO: Handle array clauses
				} else {
					switch($clause['type']) {
						case 'ident':
							
							break;
					}
				}
			} else {
				$this->_applyWhere($table, $clause, $cur);
			}
		}
	}

	
}

class CSVTable {
	var $tablename = '';
	var $columns = Array();
	var $rows = Array();
	var $pkey_index = Array();
	private $key = '';
	private $keyindex = -1;
	
	function __construct($tablename, $columns=Array(), $key=null) {
		$this->tablename = $tablename;
		$this->columns = $columns;
		if($key) {
			foreach($columns as $index=>$colname) {
				if($colname == $key) {
					$this->key = $key;
					$this->keyindex = $index;
				}
			}
		}
	}
	
	function addRow($row)  {
		
		if($this->keyindex > -1) {
			$this->rows[	$row[$this->keyindex]] = $row;
		} else {
			$this->rows[] = $row;
		}
	}
}