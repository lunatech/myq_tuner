<?
/**
 * (c) Raj Shekhar <rajlist@rajshekhar.net>
 * derived from  MySQLTuner (mysqltuner.com)
 *
 * License: GPL v2
 */

error_reporting(E_ALL);

class Myq_BaseRecommendations
{
  // an array of the good variables
  private $good;
  
  // an array of bad variables, which might be required to tune
  private $bad;
  
  // hash of variables that have been calculated using mysql's status
  // variables and status fields. The datastructure of this variable is
  // $calculated["var_name"]=value
  // Please choose a variable name that is explicit enough
  private $mycalc;

  // are we debugging
  private $debug;
  
  // variables, i.e. what we get from "show variables"  
  private $myq_vars;

  // status counters, i.e. what we get from "show status"
  private $myq_status;
  
  private $general_tuneups  = array();
  private $variable_tuneups = array();
  private $good_info = array();
  private $bad_info  = array();
  private $info = array();

  // pretty print debug messages
  function dbg_print($msg)
  {
    if ($this->debug)  {print "\n<br>$msg";}
  }

  function __construct($myq_vars,$myq_status,$debug=false) 
  {
    if($debug)   {print  "\n<br>In constructor";}
    $this->myq_vars = $myq_vars;
    $this->myq_status = $myq_status;
    $this->debug = $debug;
    $this->dbg_print("starting");    
    // print_r($this->mycalc);
  }

  public function analyze()
  {
    $mycalc = $this->calculate_derived_status($this->myq_vars,$this->myq_status);
    if (isset($mycalc)) {
      $this->dbg_print("Sucessfully calculated derived values");
      $this->mycalc = $mycalc;
      $analysis = $this->get_analysis();      
      $this->good_info = $analysis['good'];
      $this->bad_info  = $analysis['bad'];
      $this->info  = $analysis['info'];
    }
  }

  /**
   * calculates some percentages based on mysql's current status
   * counters and variables
   * @param array $myvar a hash of mysql's "show variables" command
   * @param array $mystat a hash of mysql's "show status" command
   * @return array|boolean  returns false, if the server has not received any queries.  Else, it retunrs an array $mycalc 
   */
  private function calculate_derived_status($myvar,$mystat)
  {
    $this->dbg_print("calculating derived values");
    $this->dbg_print ("Sanity checking server for queries = $this->myq_status['Questions']");
    if ($this->myq_status['Questions'] < 1) {
      array_push ($this->bad, "Your server has not answered any queries - cannot continue...");
      return false;
    }
    $this->dbg_print("Calculating Per-thread memory");   
    $mycalc['per_thread_buffers'] = $myvar['read_buffer_size'] + $myvar['read_rnd_buffer_size'] + $myvar['sort_buffer_size'] + $myvar['thread_stack'] + $myvar['join_buffer_size'];
    $mycalc['total_per_thread_buffers'] = $mycalc['per_thread_buffers'] * $myvar['max_connections'];
    $mycalc['max_total_per_thread_buffers'] = $mycalc['per_thread_buffers'] * $mystat['Max_used_connections'];

    // Server-wide memory
    $this->dbg_print("Calculating server wide memory allocated"); 
    $mycalc['max_tmp_table_size'] = ($myvar['tmp_table_size'] > $myvar['max_heap_table_size']) ? $myvar['max_heap_table_size'] : $myvar['tmp_table_size'] ;
    $mycalc['server_buffers'] = $myvar['key_buffer_size'] + $mycalc['max_tmp_table_size'];
    $mycalc['server_buffers'] += (isset($myvar['innodb_buffer_pool_size'])) ? $myvar['innodb_buffer_pool_size'] : 0 ;
    $mycalc['server_buffers'] += (isset($myvar['innodb_additional_mem_pool_size'])) ? $myvar['innodb_additional_mem_pool_size'] : 0 ;
    $mycalc['server_buffers'] += (isset($myvar['innodb_log_buffer_size'])) ? $myvar['innodb_log_buffer_size'] : 0 ;
    $mycalc['server_buffers'] += (isset($myvar['query_cache_size'])) ? $myvar['query_cache_size'] : 0 ;
    
    // global memory
    $this->dbg_print("Calculating global memory"); 
    $mycalc['max_used_memory'] = $mycalc['server_buffers'] + $mycalc{"max_total_per_thread_buffers"};
    $mycalc['total_possible_used_memory'] = $mycalc['server_buffers'] + $mycalc['total_per_thread_buffers'];

    // Slow queries
    $mycalc['pct_slow_queries'] = (int)(($mystat['Slow_queries']/$mystat['Questions']) * 100);

    // Connections
    $mycalc['pct_connections_used'] = (int)(($mystat['Max_used_connections']/$myvar['max_connections']) * 100);
    $mycalc['pct_connections_used'] = ($mycalc['pct_connections_used'] > 100) ? 100 : $mycalc['pct_connections_used'] ;
	
    // Key buffers
    $mycalc['pct_key_buffer_used'] = sprintf("%.1f",(1 - (($mystat['Key_blocks_unused'] * $myvar['key_cache_block_size']) / $myvar['key_buffer_size'])) * 100);
    $mycalc['pct_keys_from_mem'] = sprintf("%.1f",(100 - (($mystat['Key_reads'] / $mystat['Key_read_requests']) * 100)));

    
    // TODO: fix myisam index calculations
    /*
      $mycalc['total_myisam_indexes'] = `mysql $mysqllogin -Bse "SELECT IFNULL(SUM(INDEX_LENGTH),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA NOT IN ('information_schema') AND ENGINE = 'MyISAM';"`;
      if (defined $mycalc['total_myisam_indexes'] and $mycalc['total_myisam_indexes'] =~ /^0\n$/) { 
      $mycalc['total_myisam_indexes'] = "fail"; 
      } elsif (defined $mycalc['total_myisam_indexes']) {
      chomp($mycalc['total_myisam_indexes']);
      }

    */

    $mycalc['query_cache_efficiency'] = sprintf("%.1f",($mystat['Qcache_hits'] / ($mystat['Com_select'] + $mystat['Qcache_hits'])) * 100);
    if ($myvar['query_cache_size']) {
      $mycalc['pct_query_cache_used'] = sprintf("%.1f",100 - ($mystat['Qcache_free_memory'] / $myvar['query_cache_size']) * 100);
    }
    if ($mystat['Qcache_lowmem_prunes'] == 0) {
      $mycalc['query_cache_prunes_per_day'] = 0;
    } else {
      $mycalc['query_cache_prunes_per_day'] = (int)($mystat['Qcache_lowmem_prunes'] / ($mystat['Uptime']/86400));
    }


    // Sorting
    $mycalc['total_sorts'] = $mystat['Sort_scan'] + $mystat['Sort_range'];
    if ($mycalc['total_sorts'] > 0) {
      $mycalc['pct_temp_sort_table'] = (int)(($mystat['Sort_merge_passes'] / $mycalc['total_sorts']) * 100);
    }
	
    // Joins
    $mycalc['joins_without_indexes'] = $mystat['Select_range_check'] + $mystat['Select_full_join'];
    $mycalc['joins_without_indexes_per_day'] = (int)($mycalc['joins_without_indexes'] / ($mystat['Uptime']/86400));

    // Temporary tables
    if ($mystat['Created_tmp_tables'] > 0) {
      if ($mystat['Created_tmp_disk_tables'] > 0) {
	$mycalc['pct_temp_disk'] = (int)(($mystat['Created_tmp_disk_tables'] / ($mystat['Created_tmp_tables'] + $mystat['Created_tmp_disk_tables'])) * 100);
      } else {
	$mycalc['pct_temp_disk'] = 0;
      }
    }

    // Table cache
    if ($mystat['Opened_tables'] > 0) {
      $mycalc['table_cache_hit_rate'] = (int)($mystat['Open_tables']*100/$mystat['Opened_tables']);
    } else {
      $mycalc['table_cache_hit_rate'] = 100;
    }
	
    // Open files
    if ($myvar['open_files_limit'] > 0) {
      $mycalc['pct_files_open'] = (int)($mystat['Open_files']*100/$myvar['open_files_limit']);
    }
	
    // Table locks
    if ($mystat['Table_locks_immediate'] > 0) {
      if ($mystat['Table_locks_waited'] == 0) {
	$mycalc['pct_table_locks_immediate'] = 100;
      } else {
	$mycalc['pct_table_locks_immediate'] = (int)($mystat['Table_locks_immediate']*100/($mystat['Table_locks_waited'] + $mystat['Table_locks_immediate']));
      }
    }

    // Thread cache
    $mycalc['thread_cache_hit_rate'] = (int)(100 - (($mystat['Threads_created'] / $mystat['Connections']) * 100));

    // Other
    if ($mystat['Connections'] > 0) {
      $mycalc['pct_aborted_connections'] = (int)(($mystat['Aborted_connects']/$mystat['Connections']) * 100);
    }
    if ($mystat['Questions'] > 0) {
      $mycalc['total_reads'] = $mystat['Com_select'];
      /*  TODO: change total_writes to total_changes, because it counts inserts, dels, updates and replaces
       */
      $mycalc['total_writes'] = $mystat['Com_delete'] + $mystat['Com_insert'] + $mystat['Com_update'] + $mystat['Com_replace'];
      if ($mycalc['total_reads'] == 0) {
	$mycalc['pct_reads'] = 0;
	$mycalc['pct_writes'] = 100;
      } else {
	$mycalc['pct_reads'] = (int)(($mycalc['total_reads']/($mycalc['total_reads']+$mycalc['total_writes'])) * 100);
	$mycalc['pct_writes'] = 100-$mycalc['pct_reads'];
      }
      
    }

    // InnoDB
    if ($myvar['have_innodb'] == "YES") {
      $mycalc['innodb_log_size_pct'] = ($myvar['innodb_log_file_size'] * 100 / $myvar['innodb_buffer_pool_size']);
    }

    //print_r($mycalc);
    return $mycalc;
  }  



  private function hr_bytes($num) 
  {
    if ($num >= (1024*1024*1024)) { #GB
      return sprintf("%.1f",($num/(1024*1024*1024)))." Gb";
    } elseif ($num >= (1024*1024)) { #MB
      return sprintf("%.1f",($num/(1024*1024)))." Mb";
    } elseif ($num >= 1024) { #KB
      return sprintf("%.1f",($num/1024))." Kb";
    } else {
      return $num."B";
    }
  }

  private function hr_num($num)
  {
	
    if ($num >= pow(1000,3)) { # Billions
      return int(($num/pow(1000,3)))." Billion";
    } elseif ($num >= pow(1000,2)) { # Millions
      return (int)(($num/pow(1000,2)))." Million";
    } elseif ($num >= 1000) { # Thousands
      return (int)(($num/1000))." Thousand";
    } else {
      return $num;
    }
  }

  // Calculates the parameter passed in bytes, and then rounds it to the nearest integer
  private function  hr_bytes_rnd($num)
  {
    if ($num >= pow(1024,3)) { #GB
      return (int)(($num/pow(1024,3)))." Gib";
    } elseif ($num >= pow(1024,2)) { #MB
      return (int)(($num/pow(1024,2)))." Mib";
    } elseif ($num >= 1024) { #KB
      return (int)(($num/1024))." Kb";
    } else {
      return $num." B";
    }
  }


  
  /**
   * checks the counters and variables and outputs recommendations
   * @return array with three keys - good, bad, info
   **/
  public function get_analysis()
  {
    $analysis;
    $mystat = $this->myq_status; $myvar = $this->myq_vars; $mycalc = $this->mycalc;
    $analysis['info'] = array(); $analysis['good'] = array();  $analysis['bad'] = array();
     
    $this->dbg_print("questions ". $mystat['Questions'] ." uptime " .$mystat['Uptime']);
    $analysis['info']['qps'] = sprintf("%.3f",$mystat['Questions']/$mystat['Uptime']); 
    $analysis['info']['TX']  = $this->hr_bytes($mystat['Bytes_sent']);
    $analysis['info']['RX']  = $this->hr_bytes($mystat['Bytes_received']);
    $analysis['info']['Reads to Writes ratio'] = $mycalc['pct_reads']."% / ".$mycalc['pct_writes']."%";
    $analysis['info']['Total buffers']   =  $this->hr_bytes($mycalc['server_buffers'])." global + "
      . $this->hr_bytes($mycalc['per_thread_buffers'])
      . " per thread (".$myvar['max_connections']." max threads)";

    if ($mycalc['pct_slow_queries'] > 5) {
      $analysis['bad']['slow queries']  = "Slow queries: ".$mycalc['pct_slow_queries']."% (".$this->hr_num($mystat['Slow_queries'])."/".$this->hr_num($mystat['Questions'].")");
    } else {
      $analysis['good']['slow queries'] = "Slow queries: ".$mycalc['pct_slow_queries']."% (".$this->hr_num($mystat['Slow_queries'])."/".$this->hr_num($mystat['Questions'].")");
    }
    if ($myvar['long_query_time'] > 10) { array_push($tuneups,"long_query_time (<= 10)"); }
    if (isset($myvar['log_slow_queries'])) {
      if ($myvar['log_slow_queries'] == "OFF") { array_push($this->general_tuneups,"Enable the slow query log to troubleshoot bad queries"); }
    }
    
    // Connections
    if ($mycalc['pct_connections_used'] > 85) {
      array_push($this->variable_tuneups,"Highest connection usage: ". $mycalc['pct_connections_used']."%  ".($mystat['Max_used_connections']."/".$myvar['max_connections']));
      array_push($this->variable_tuneups,"max_connections (> ".$myvar['max_connections'].")");
      array_push($this->variable_tuneups,"wait_timeout (< ".$myvar['wait_timeout'].")","interactive_timeout (< ".$myvar['interactive_timeout'].")");
      array_push($this->general_tuneups,"Reduce or eliminate persistent connections to reduce connection usage");
    } else {
      $analysis['good']['available connections'] = "Highest usage of available connections: ".$mycalc['pct_connections_used']."% ".($mystat['Max_used_connections']."/".$myvar['max_connections']);
    }
    
    // TODO: myisam indexes

    // Query cache
    if ($myvar['query_cache_size'] < 1) {
      $analysis['bad']['query cache'] =  "Query cache is disabled";
      array_push($this->variable_tuneups,"query_cache_size (>= 8M)");
    } elseif ($mystat['Com_select'] == 0) {
      $analysis['bad']['query cache efficiency']  = "Query cache cannot be analyzed - no SELECT statements executed";
    } else {
      if ($mycalc['query_cache_efficiency'] < 20) {
	array_push($this->variable_tuneups, "Query cache efficiency: ".$mycalc['query_cache_efficiency']."% (".$this->hr_num($mystat['Qcache_hits'])." cached / "
		   .$this->hr_num($mystat['Qcache_hits']+$mystat['Com_select'])." selects)");
      }
      
      if ($mycalc['query_cache_prunes_per_day'] > 98) {
	$analysis['bad']['Query cache prunes per day'] = $mycalc['query_cache_prunes_per_day'];
	if ($myvar['query_cache_size'] > 128*1024*1024) {
	  array_push($this->general_tuneups,"Increasing the query_cache size over 128M may reduce performance");
	  array_push($this->variable_tuneups,"query_cache_size (> ".$this->hr_bytes_rnd($myvar['query_cache_size']).") [see warning above]");
	} else {
	  array_push($this->variable_tuneups,"query_cache_size (> ".$this->hr_bytes_rnd($myvar['query_cache_size']).")");
	}
      } else {
	$analysis['good']['Query cache prunes per day']= $mycalc['query_cache_prunes_per_day'];
      }
    }

   
    // Sorting
    if ($mycalc['total_sorts'] == 0) {
      // For the sake of space, we will be quiet here
      // No sorts have run yet
    } elseif ($mycalc['pct_temp_sort_table'] > 10) {
      $analysis['bad']['Sorts requiring temporary tables'] = $mycalc['pct_temp_sort_table']."% (".$this->hr_num($mystat['Sort_merge_passes'])." temp sorts / ".$this->hr_num($mycalc['total_sorts'])." sorts)";
      array_push($this->variable_tuneups,"sort_buffer_size (> ".$this->hr_bytes_rnd($myvar['sort_buffer_size']).")");
      array_push($this->variable_tuneups,"read_rnd_buffer_size (> ".$this->hr_bytes_rnd($myvar['read_rnd_buffer_size']).")");
    } else {
      $analysis['good']['Sorts requiring temporary tables'] = $mycalc['pct_temp_sort_table']."% (".$this->hr_num($mystat['Sort_merge_passes'])." temp sorts / ".$this->hr_num($mycalc['total_sorts'])." sorts)";
    }
	
    // Joins
    if ($mycalc['joins_without_indexes_per_day'] > 250) {
      $analysis['bad']['Joins performed without indexes'] = $mycalc['joins_without_indexes'];
      array_push($this->variable_tuneups,"join_buffer_size (> ".$this->hr_bytes($myvar['join_buffer_size']).", or always use indexes with joins)");
      array_push($this->general_tuneups,"Adjust your join queries to always utilize indexes");
    } else {
      // For the sake of space, we will be quiet here
      // No joins have run without indexes
    }

    // Temporary tables
    if ($mystat['Created_tmp_tables'] > 0) {
      if ($mycalc['pct_temp_disk'] > 25 && $mycalc['max_tmp_table_size'] < 256*1024*1024) {
	$analysis['bad']['Temporary tables created on disk'] = $mycalc['pct_temp_disk']."% (".$this->hr_num($mystat['Created_tmp_disk_tables'])." on disk / ".$this->hr_num($mystat['Created_tmp_disk_tables'] + $mystat['Created_tmp_tables'])." total)";
	array_push($this->variable_tuneups,"tmp_table_size (> ".$this->hr_bytes_rnd($myvar['tmp_table_size']).")");
	array_push($this->variable_tuneups,"max_heap_table_size (> ".$this->hr_bytes_rnd($myvar['max_heap_table_size']).")");
	array_push($this->general_tuneups,"When making adjustments, make tmp_table_size/max_heap_table_size equal");
	array_push($this->general_tuneups,"Reduce your SELECT DISTINCT queries without LIMIT clauses");
      } elseif ($mycalc['pct_temp_disk'] > 25 && $mycalc['max_tmp_table_size'] >= 256) {
	$analysis['bad']['Temporary tables created on disk'] = $mycalc['pct_temp_disk']."% (".$this->hr_num($mystat['Created_tmp_disk_tables'])." on disk / ".$this->hr_num($mystat['Created_tmp_disk_tables'] + $mystat['Created_tmp_tables'])." total)";
	array_push($this->general_tuneups,"Temporary table size is already large - reduce result set size");
	array_push($this->general_tuneups,"Reduce your SELECT DISTINCT queries without LIMIT clauses");
      } else {
	$analysis['good']['Temporary tables created on disk'] = $mycalc['pct_temp_disk']."% (".$this->hr_num($mystat['Created_tmp_disk_tables'])." on disk / ".$this->hr_num($mystat['Created_tmp_disk_tables'] + $mystat['Created_tmp_tables'])." total)";
      }
    } else {
      // For the sake of space, we will be quiet here
      // No temporary tables have been created
    }

    // Thread cache
    if ($myvar['thread_cache_size'] == 0) {
      $analysis['bad']['Thread cache'] ="Thread cache is disabled";
      array_push($this->general_tuneups,"Set thread_cache_size to 4 as a starting value");
      array_push($this->variable_tuneups,"thread_cache_size (start at 4)");
    } else {
      if ($mycalc['thread_cache_hit_rate'] <= 50) {
	$analysis['bad']['Thread cache hit rate'] =  $mycalc['thread_cache_hit_rate']."% (".$this->hr_num($mystat['Threads_created'])." created / ".$this->hr_num($mystat['Connections'])." connections)";
	array_push($this->variable_tuneups,"thread_cache_size (> ".$myvar['thread_cache_size'].")");
      } else {
	$analysis['good']['Thread cache hit rate'] =  $mycalc['thread_cache_hit_rate']."% (".$this->hr_num($mystat['Threads_created'])." created / ".$this->hr_num($mystat['Connections'])." connections)";
      }
    }

    // Table cache
    if ($mystat['Open_tables'] > 0) {
      if ($mycalc['table_cache_hit_rate'] < 20) {
	$analysis['bad']['Table cache hit rate'] = $mycalc['table_cache_hit_rate']."%"." (".$this->hr_num($mystat['Open_tables'])." open / ".$this->hr_num($mystat['Opened_tables'])." opened)";
	if (array_key_exists('table_open_cache',$myvar))  // for mysqlversion > 5.1
	  array_push($this->variable_tuneups,"table_cache (> ".$myvar['table_open_cache'].")");
      } else {
	array_push($this->variable_tuneups,"table_cache (> ".$myvar['table_cache'].")");
      }
      array_push($this->general_tuneups,"Increase table_cache gradually to avoid file descriptor limits");
    } else {
      $analysis['good']['Table cache hit rate'] = $mycalc['table_cache_hit_rate']."%"." (".$this->hr_num($mystat['Open_tables'])." open / ".$this->hr_num($mystat['Opened_tables'])." opened)";
    }
 
    // Open files
    if (array_key_exists('pct_files_open',$mycalc)) {
      if ($mycalc['pct_files_open'] > 85) {
	$analysis['bad']['Open file limit used'] = $mycalc['pct_files_open']."%"." (".$this->hr_num($mystat['Open_files'])."/".$this->hr_num($myvar['open_files_limit']).")";
	array_push($this->variable_tuneups,"open_files_limit (> ".$myvar['open_files_limit'].")");
      } else {
	$analysis['good']['Open file limit used'] = $mycalc['pct_files_open']."%"." (".$this->hr_num($mystat['Open_files'])."/".$this->hr_num($myvar['open_files_limit']).")";
      }
    }
    
    // performance options

    if ($myvar['concurrent_insert'] == "OFF") {
      array_push($this->general_tuneups,"Enable concurrent_insert by setting it to 'ON'");
    } elseif ($myvar['concurrent_insert'] == 0) {
      array_push($this->general_tuneups,"Enable concurrent_insert by setting it to 1");
    }
    if ($mycalc['pct_aborted_connections'] > 5) {
      $analysis['bad']['Connections aborted'] = $mycalc['pct_aborted_connections']."%";
      array_push($this->general_tuneups,"Your applications are not closing MySQL connections properly");
    }
    

    
    return $analysis;
  }

  public function get_variable_tuneups () 
  {
    return $this->variable_tuneups;
  }



  public function get_general_tuneups () 
  {
    return $this->general_tuneups;
  }

  public function get_good_info () 
  {
    return $this->good_info;
  }

  public function get_bad_info () 
  {
    return $this->bad_info;
  }

  public function get_info () 
  {
    return $this->info;
  }


} //end class