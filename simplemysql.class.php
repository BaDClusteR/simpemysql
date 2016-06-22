<?php

/* vim: set ts=4 sw=4 sts=4 et: */
/**
 * Class SimpleMySQL
 *
 * @author     BaD ClusteR
 * @license    http://www.gnu.org/licenses/gpl.html GPL license agreement
 * @version    1.2
 * @link       http://badcluster.ru
 *
 * Safe work with MySQL queries.
 *
 * Supported placeholders:
 *      ?s - strings
 *      ?i - integer values
 *      ?f - float/double values
 *      ?n - identifier (table/field name)
 *      ?a - set (turns array('a', 'b', 'c', ...) into IN('a', 'b', 'c', ...)
 *      ?u - fields enumeration (turns array('field1' => 'value1', 'field2' => 'value2', ...) into `field1` = 'value1', `field2` = 'value2', ...)
 *      ?p - query part (string value that will be inserted into the query without any modifications)
 */

class SimpleMySQL
{
    /**
     * @var float Last query time
     */
    private $time = 0.0;
    /**
     * @var mysli Link to MySQL connection
     */
    private $sql_link;
    /**
     * @var string Encoding
     */
    private $encoding = "UTF-8";
    /**
     * @var string Query results encoding
     */
    private $db_encoding = "UTF-8";
    /**
     * @var string DB encoding (will be set after connection)
     */
    private $res_encoding = "utf8";
    /**
     * @var bool If checked and Encoding != Query results encoding, Queries will be iconv'ed before the performing and results will be iconv'ed after the query performing
     */
    private $decode_queries = false;
    /**
     * @var bool If checked, SimpleMySQL will generate PHP Error each time its method gets a variable of unexpected type.
     */
    private $typesError = false;
    /**
     * @var bool Checks if SimpleMySQL is having connection with some DB (needed in disconnect() method)
     */
    private $connected = false;
    /**
     * @var bool Whether to write errors to log file or not
     * @since 1.2
     */
    private $logErrors = false;
    /**
     * @var string File path where errors will be written
     * @since 1.2
     */
    private $logFile = "";


    function __construct($login, $pass, $db, $host = "localhost", $port = "3306", $res_encoding = "utf8")
    {
        $this->connect($login, $pass, $db, $host, $port, $res_encoding);
    }

    function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Connect to a database.
     *
     * @param string $login MySQL login
     * @param string $pass MySQL password
     * @param string $db Database name
     * @param string $host MySQL host
     * @param string $port MySQL port
     * @param string $res_encoding Encoding in which all queries will be performed
     *
     * @since v. 1.0
     */
    public function connect($login, $pass, $db, $host = "localhost", $port = "3306", $res_encoding = "utf8")
    {
        $this->sql_link = new mysqli($host, $login, $pass, $db, $port);
        if ($this->sql_link->connect_error != "")
            $this->error("Can't connect to MySQL server. Error #" . $this->sql_link->connect_errno . ": " . $this->sql_link->connect_error);
        $this->sql_link->query("SET NAMES " . $res_encoding);
        $this->res_encoding = $res_encoding;
        $this->connected = true;
    }

    /**
     * Disconnect from database.
     *
     * @since v. 1.0
     */
    public function disconnect()
    {
        if ($this->connected)
        {
            $this->sql_link->close();
            $this->connected = false;
        }
    }

    /**
     * Disconnect from database (if connected) and connect again using given options
     *
     * @param string $login MySQL login
     * @param string $pass MySQL password
     * @param string $db Database name
     * @param string $host MySQL host
     * @param string $port MySQL port
     * @param string $res_encoding Encoding in which all queries will be performed
     *
     * @since v. 1.0
     */
    public function reconnect($login, $pass, $db, $host = "localhost", $port = "3306", $res_encoding = "utf8")
    {
        $this->disconnect();
        $this->connect($login, $pass, $db, $host, $port, $res_encoding);
    }

    /**
     * If set, SimpleMySQL will generate PHP Error each time its method gets a variable of unexpected type.
     *
     * @param bool $val
     *
     * @since v. 1.0
     */
    public function setTypesError($val)
    {
        $this->typesError = (bool) $val;
    }

    /**
     * Get the integer value of a variable.
     *
     * @param mixed $val Variable
     *
     * @return int
     * @since v. 1.0
     */
    private function parseInt($val)
    {
        if ($this->typesError && !is_int($val))
            $this->error("Expected integer variable.");
        $val = intval($val);
        return $val;
    }

    /**
     * Get the float value of a variable
     *
     * @param mixed $val Variable
     *
     * @return float
     * @since v. 1.0
     */
    private function parseFloat($val)
    {
        if ($this->typesError && !is_int($val) && !is_float($val))
            $this->error("Expected float variable.");
        $val = number_format($val, 0, ".", "");
        return $val;
    }

    /**
     * Get the string value of a variable
     *
     * @param mixed $val Variable
     *
     * @return string
     * @since v. 1.0
     */
    private function parseString($val)
    {
        if ($this->typesError && (is_array($val) || is_object($val)))
            $this->error("Expected string variable.");
        $val = $this->sql_link->real_escape_string($val);
        return "'" . $val . "'";
    }

    public function setDecodeQueries($decode)
    {
        $this->decode_queries = ($decode === false ? false : true);
    }

    public function getDecodeQueries()
    {
        return $this->decode_queries;
    }

    /**
     * Assumes that $val as the name of a table/field and returns formatted value of the variable.
     *
     * @param string $val Variable
     *
     * @return string
     * @since v. 1.0
     */
    private function parseName($val)
    {
        $val = trim($val);
        $arr = array();
        if (strpos($val, " ") !== false)
        {
            $arr = explode(" ", $val);
            $val = $arr[0];
        }
        $val = str_replace(".", "`.`", $val);
        $val = "`" . $this->sql_link->real_escape_string($val) . "`";
        if (sizeof($arr) > 0)
        {
            $arr[0] = $val;
            $val = implode(" ", $arr);
        }
        return $val;
    }

    /**
     * Converts array('a', 'b', 'c', ...) into the string like "IN ('a', 'b', 'c', ...)"
     *
     * @param array $val Variables array
     *
     * @return string
     * @since v. 1.0
     */
    private function parseArr($val)
    {
        if (!is_array($val))
        {
            if ($this->typesError)
                $this->error("Expected array variable.");
            return "()";
        }
        $result = "(";
        foreach ($val as $key => $value)
            $result .= (($result == "(") ? "" : ", ") . $this->parseString($value);
        return $result . ")";
    }

    /**
     * Converts array('field1' => 'value1', 'field2' => 'value2', ...) into the string like "`field1` = 'value1', `field2` = 'value2', ..."
     *
     * @param array $val Variables array
     *
     * @return string
     * @since v. 1.0
     */
    private function parseSet($val)
    {
        if (!is_array($val))
        {
            if ($this->typesError)
                $this->error("Expected array variable.");
            return "";
        }
        $result = "";
        foreach ($val as $key => $value)
            $result .= (($result == "") ? "" : ", ") . $this->parseName($key) . " = " . $this->parseString($value);
        return $result;
    }

    /**
     * Parse query with placeholders
     *
     * @param string $s Raw query
     *
     * @return string
     * @since v. 1.0
     */
    public function parse($s)
    {
        $args = func_get_args();
        array_shift($args);
        return $this->parseVals($s, $args);
    }

    /**
     * Get last inserted ID
     *
     * @return string
     * @since v. 1.0
     */
    public function last_insert_id()
    {
        return $this->sql_link->insert_id;
    }

    /**
     * Get number of affected rows
     *
     * @return int
     * @since v. 1.1
     */
    public function affectedRows()
    {
        return $this->sql_link->affected_rows;
    }

    /**
     * Get last error text
     *
     * @return string
     * @since v. 1.1
     */
    public function errString()
    {
        return $this->sql_link->error;
    }

    /**
     * Get last error code
     *
     * @return int
     * @since v. 1.1
     */
    public function errCode()
    {
        return $this->sql_link->errno;
    }

    /**
     * Get last query time
     *
     * @return float
     * @since v. 1.0
     */
    public function queryTime()
    {
        return $this->time;
    }

    /**
     * Prepare INSERT statement based on the given table name and variables
     *
     * @param string $table Table name
     * @param array $vals Values array
     *
     * @return string
     * @since v. 1.0
     */
    public function prepareInsert($table, $vals)
    {
        $table = $this->parseName($table);
        $result = "INSERT INTO $table";
        if (!is_array($vals))
        {
            $this->error("Array expected.");
            return $result;
        }
        $result .= " SET ";
        foreach ($vals as $key => $value)
        {
            if ($key > 0)
                $result .= ", ";
            $result .= $this->parseName($value['name']) . " = " . $this->parseType($value['value'], $value['type']);
        }
        return $result;
    }

    /**
     * Prepare UPDATE statement based on the given table name, variables and conditions
     *
     * @param string $table Table name
     * @param array $vals Values array
     * @param array $conds Conditions array
     *
     * @return string
     * @since v. 1.0
     */
    public function prepareUpdate($table, $vals, $conds)
    {
        $table = $this->parseName($table);
        $result = "UPDATE $table";
        if (!is_array($vals))
        {
            $this->error("Array expected.");
            return $result;
        }
        $result .= " SET ";
        foreach ($vals as $key => $value)
        {
            if ($key > 0)
                $result .= ", ";
            $result .= $this->parseName($value['name']) . " = " . $this->parseType($value['value'], $value['type']);
        }
        if (is_array($conds) && sizeof($conds) > 0)
        {
            $result .= " WHERE ";
            foreach ($conds as $key => $value)
            {
                if ($key > 0)
                    $result .= " AND ";
                $result .= $this->parseName($value['name']) . " = " . $this->parseType($value['value'], $value['type']);
            }
        }
        return $result;
    }

    /**
     * Parse variable value
     *
     * @param mixed $val Variable
     * @param string $type Variable type
     *
     * @return int|float|string
     * @since v. 1.0
     */
    private function parseType($val, $type)
    {
        $result = "";
        switch ($type)
        {
            case "integer":
            case "decimal":
            case "int":     $result .= $this->parseInt($val); break;
            case "double":
            case "float":   $result .= $this->parseFloat($val); break;
            case "str":
            case "string":  $result .= $this->parseString($val); break;
            case "binary":
            case "bin":     $result .= "'" . $this->sql_link->real_escape_string($val) . "'"; break;
            default: $this->error($val . ": unexpected variable type."); return "";
        }
        return $result;
    }

    /**
     * Prepare DELETE statement based on the given table name and conditions
     *
     * @param string $table Table name
     * @param array $conds Conditions array
     *
     * @return string
     * @since v. 1.0
     */
    public function prepareDelete($table, $conds)
    {
        $table = $this->parseName($table);
        $result = "DELETE FROM $table";
        if (is_array($conds) && sizeof($conds) > 0)
        {
            $result .= " WHERE ";
            foreach ($conds as $key => $value)
            {
                if ($key > 0)
                    $result .= " AND ";
                $result .= $this->parseName($value['name']) . " = " . $this->parseType($value['value'], $value['type']);
            }
        }
        elseif (!is_array($conds) && !empty($conds))
            $result .= " WHERE " . $conds;
        return $result;
    }

    private function parseVals($s, $args)
    {
        $result = "";
        $arr = preg_split('~(\?[nsifuap])~u', $s, null, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($arr as $key => $value)
        {
            switch ($value)
            {
                case "?i": $result .= $this->parseInt($args[0]); array_shift($args); break;
                case "?f": $result .= $this->parseFloat($args[0]); array_shift($args); break;
                case "?s": $result .= $this->parseString($args[0]); array_shift($args); break;
                case "?n": $result .= $this->parseName($args[0]); array_shift($args); break;
                case "?a": $result .= $this->parseArr($args[0]); array_shift($args); break;
                case "?u": $result .= $this->parseSet($args[0]); array_shift($args); break;
                case "?p": $result .= $args[0]; array_shift($args); break;
                default: $result .= $value;
            }
        }
        return $result;
    }

    /**
     * Prepare SELECT statement based on the given table name, variables, conditions etc.
     *
     * @param array $data Data array (array('fields' => array, 'from' => string, 'left_joins' => array, 'right_joins' => array, 'inner_joins' => array, 'where' => array, 'group' => array, 'having' => array, 'order' => array))
     *
     * @return string
     * @since v. 1.0
     */
    public function prepareSelect($data)
    {
        $result = "";
        if (!empty($data['fields']) && is_array($data['fields']) && sizeof($data['fields']) > 0)
        {
            $result = "SELECT ";
            foreach ($data['fields'] as $key => $value)
                $result .= (($key > 0) ? ", " : "") . $this->parseName($value);
        }
        if (!empty($data['from']))
            $result .= " FROM " . $this->parseName($data['from']);
        if (!empty($data['left_joins']) && is_array($data['left_joins']) && sizeof($data['left_joins']) > 0)
            foreach ($data['left_joins'] as $key => $value)
                $result .= " LEFT JOIN " . $this->parseName($key) . " ON (" . $value . ")";
        if (!empty($data['right_joins']) && is_array($data['right_joins']) && sizeof($data['right_joins']) > 0)
            foreach ($data['right_joins'] as $key => $value)
                $result .= " RIGHT JOIN " . $this->parseName($key) . " ON (" . $value . ")";
        if (!empty($data['inner_joins']) && is_array($data['inner_joins']) && sizeof($data['inner_joins']) > 0)
            foreach ($data['inner_joins'] as $key => $value)
                $result .= " INNER JOIN " . $this->parseName($key) . " ON (" . $value . ")";
        if (!empty($data['where']) && is_array($data['where']) && sizeof($data['where']) > 0)
        {
            $result .= " WHERE ";
            $first = true;
            foreach ($data['where'] as $key => $value)
            {
                if (is_array($value))
                    $result .= (!$first ? ((!empty($value['conn']) && strtolower($value['conn']) == 'or') ? " OR " : " AND ") : "") . $this->parseName($value['name']) . " = " .
                        $this->parseType($value['value'], $value['type']);
                else
                    $result .= ((!$first) ? " AND " : "") . $this->parseName($key) . " = " . $this->parseString($value);
                $first = false;
            }
        }
        if (!empty($data['group']) && is_array($data['group']) && sizeof($data['group']) > 0)
        {
            $result .= " GROUP BY ";
            foreach ($data['group'] as $key => $value)
                $result .= (($key > 0) ? ", " : "") . $this->parseName($value);
        }
        if (!empty($data['having']) && is_array($data['having']) && sizeof($data['having']) > 0)
        {
            $result .= " HAVING ";
            $first = true;
            foreach ($data['having'] as $key => $value)
            {
                if (is_array($value))
                    $result .= ((!empty($value['conn']) && strtolower($value['conn']) == 'or') ? " OR " : " AND ") . $this->parseName($value['name']) . " = " .
                        $this->parseType($value['value'], $value['type']);
                else
                    $result .= ((!$first) ? " AND " : "") . $this->parseName($key) . " = " . $this->parseString($value);
                $first = false;
            }
        }
        if (!empty($data['order']) && is_array($data['order']) && sizeof($data['order']) > 0)
        {
            $result .= " ORDER BY ";
            $i = 0;
            foreach ($data['order'] as $key => $value)
            {
                if (is_array($value))
                    $result .= (($i > 0) ? ", " : "") . $this->parseName($value['name']) . " " . ((strtolower($value['type']) == "desc") ? "DESC" : "ASC");
                else
                    $result .= (($i > 0) ? ", " : "") . $this->parseName($value);
                $i++;
            }
        }
        return $result;
    }

    /**
     * Executes the query and returns the result as an array of associative arrays (or FALSE if query wasn't successful)
     *
     * @param string $s SQL query
     *
     * @return array|bool
     * @since v. 1.0
     */
    public function query($s)
    {
        $vals = func_get_args();
        array_shift($vals);
        $s = $this->parseVals($s, $vals);
        $t1 = microtime(true);
        if ($this->decode_queries && $this->encoding != $this->db_encoding)
            $s = iconv($this->encoding, $this->db_encoding, $s);
        $res = $this->sql_link->query($s, MYSQLI_USE_RESULT);
        $t2 = microtime(true);
        $this->time = $t2 - $t1;
        if ($this->sql_link->error != '')
            $this->queryError($this->sql_link->errno, $this->sql_link->error, $s);
        //$this->error("Error executing query.<br /><strong>Query: </strong>" . $s . "<br /><strong>Error (#" . $this->sql_link->errno . "): </strong>" . $this->sql_link->error);
        if ($res === false)
            return false;
        $result = array();
        if (is_bool($res))
            return true;
        //for ($i = 0; $i < $res->field_count; $i++)
        while ($temp = $res->fetch_assoc())
        {
            if ($this->decode_queries && $this->encoding != $this->db_encoding)
            {
                $item = $temp;
                foreach ($item as $key => $value)
                    $item[$key] = iconv($this->db_encoding, $this->encoding . "//IGNORE", $value);
                $result[] = $item;
            }
            else
                $result[] = $temp;
        }
        return $result;
    }

    /**
     * Executes the query and returns the first line of the result as an associative array
     *
     * @param string $s SQL query
     *
     * @return array|bool
     * @since v. 1.0
     */
    public function queryFirst($s)
    {
        $vals = func_get_args();
        array_shift($vals);
        if (strpos($s, " LIMIT ") !== false)
            $s .= " LIMIT 1";
        $s = $this->parseVals($s, $vals);
        $t1 = microtime(true);
        $res = $this->sql_link->query($s, MYSQLI_USE_RESULT);
        $t2 = microtime(true);
        $this->time = $t2 - $t1;
        if ($this->sql_link->error != '')
            $this->queryError($this->sql_link->errno, $this->sql_link->error, $s);
        //$this->error("Error executing query.<br /><strong>Query: </strong>" . $s . "<br /><strong>Error (#" . $this->sql_link->errno . "): </strong>" . $this->sql_link->error);
        if ($res === false)
            return false;
        if ($this->decode_queries && $this->encoding != $this->db_encoding)
        {
            $item = $res->fetch_assoc();
            foreach ($item as $key => $value)
                $item[$key] = iconv($this->db_encoding, $this->encoding, $value);
            $result = $item;
        }
        else
            $result = $res->fetch_assoc();
        return $result;
    }

    /**
     * Executes the query and returns the first cell of the result
     *
     * @param string $s SQL query
     *
     * @return mixed|bool
     * @since v. 1.0
     */
    public function queryFirstCell($s)
    {
        $vals = func_get_args();
        array_shift($vals);
        if (strpos($s, " LIMIT ") === false)
            $s .= " LIMIT 1";
        $s = $this->parseVals($s, $vals);
        $t1 = microtime(true);
        $res = $this->sql_link->query($s, MYSQLI_USE_RESULT);
        $t2 = microtime(true);
        $this->time = $t2 - $t1;
        if ($this->sql_link->error != '')
            $this->queryError($this->sql_link->errno, $this->sql_link->error, $s);
        //$this->error("Error executing query.<br /><strong>Query: </strong>" . $s . "<br /><strong>Error (#" . $this->sql_link->errno . "): </strong>" . $this->sql_link->error);
        if ($res === false)
            return false;
        if ($this->decode_queries && $this->encoding != $this->db_encoding)
        {
            $item = $res->fetch_assoc();
            foreach ($item as $key => $value)
                $item[$key] = iconv($this->db_encoding, $this->encoding, $value);
            $result = $item;
        }
        else
            $result = $res->fetch_assoc();
        if (is_array($result) && sizeof($result) > 0)
            foreach ($result as $key => $value)
                return $value;
        return false;
    }

    /**
     * Trigger PHP error
     *
     * @param string $s Error text
     *
     * @since v. 1.0
     */
    private function error($s)
    {
        $text = "Error in " . __FILE__ . " on line " . __LINE__ . ": $s";
        trigger_error($text, E_USER_ERROR);
    }

    /**
     * Write query error in log file
     *
     * @param int $num Error code
     * @param string $text Error text
     * @param string $query Query text
     *
     * @since 1.2
     */
    private function queryError($num, $text, $query)
    {
        if (!$this->logErrors || empty($this->logFile))
            return;
        $text = "[" . date("d.m.Y H:i:s") . "]: Error in query.\n    Query: $query\n    Error number: $num\n    Error text: $text\n\n";
        $f = fopen(HOME_DIR . $this->logFile, "at");
        fwrite($f, $text);
        fclose($f);
    }

    /**
     * @param bool $state
     *
     * @since 1.2
     */
    public function setLogErrors($state)
    {
        $this->logErrors = (!$state) ? false : true;
    }

    /**
     * @since 1.2
     * @return bool
     */
    public function getLogErrors()
    {
        return $this->logErrors;
    }

    /**
     * @param string $filename Log file path
     *
     * @since 1.2
     */
    public function setLogFile($filename)
    {
        $this->logFile = $filename;
    }

    /**
     * @since 1.2
     * @return string
     */
    public function getLogFile()
    {
        return $this->logFile;
    }
}

?>