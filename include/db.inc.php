<?php
class db extends PDO {


        private $error;
        private $sql;
        private $bind;
        private $debugger=0;
        public $working="yes";

        public function __construct($dsn, $user="", $passwd="",$debug_level=0) {
                $options = array(
                        PDO::ATTR_PERSISTENT => true,
                       PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                );
                $this->debugger=$debug_level;
                try {
                        parent::__construct($dsn, $user, $passwd, $options);
                } catch (PDOException $e) {
                        $this->error = $e->getMessage();
                        die("Could not connect to the DB");
                }
        }

        private function debug() {
                        if(!$this->debugger) return;
                        $error = array("Error" => $this->error);
                        if(!empty($this->sql))
                                $error["SQL Statement"] = $this->sql;
                        if(!empty($this->bind))
                                $error["Bind Parameters"] = trim(print_r($this->bind, true));

                        $backtrace = debug_backtrace();
                        if(!empty($backtrace)) {
                                foreach($backtrace as $info) {
                                        if($info["file"] != __FILE__)
                                                $error["Backtrace"] = $info["file"] . " at line " . $info["line"];
                                }
                        }
                        $msg = "";
                                $msg .= "SQL Error\n" . str_repeat("-", 50);
                                foreach($error as $key => $val)
                                        $msg .= "\n\n$key:\n$val";

                        if($this->debugger){ 
                                
                                echo nl2br($msg);
                       
                        }
        }

        private function cleanup($bind,$sql="") {
                        if(!is_array($bind)) {
                                if(!empty($bind))
                                        $bind = array($bind);
                                else
                                        $bind = array();
                        }

                        foreach($bind as $key=>$val){
                        if(str_replace($key,"",$sql)==$sql) unset($bind[$key]);
                        }
                        return $bind;
                }




        public function single($sql,$bind="")   {
                        $this->sql = trim($sql);
                        $this->bind = $this->cleanup($bind,$sql);
                        $this->error = "";
                        try {
                                $pdostmt = $this->prepare($this->sql);
                                if($pdostmt->execute($this->bind) !== false) {
                                                return $pdostmt->fetchColumn();
                                }
                        } catch (PDOException $e) {
                                $this->error = $e->getMessage();
                                $this->debug();
                                return false;
                        }
        }


        public function run($sql, $bind="") {
                $this->sql = trim($sql);
                $this->bind = $this->cleanup($bind,$sql);
                $this->error = "";

                try {
                        $pdostmt = $this->prepare($this->sql);
                        if($pdostmt->execute($this->bind) !== false) {
                                if(preg_match("/^(" . implode("|", array("select", "describe", "pragma")) . ") /i", $this->sql))
                                        return $pdostmt->fetchAll(PDO::FETCH_ASSOC);
                                elseif(preg_match("/^(" . implode("|", array("delete", "insert", "update")) . ") /i", $this->sql))
                                        return $pdostmt->rowCount();
                        }
                } catch (PDOException $e) {
                        $this->error = $e->getMessage();
                        $this->debug();
                        return false;
                }
        }

        public function row($sql,$bind=""){
        	$query=$this->run($sql,$bind);
        	if(count($query)==0) return false;
        	if(count($query)>1) return $query;
        	if(count($query)==1){
        		foreach($query as $row) $result=$row;
        		return $result;
        	}
        }



}

?>
