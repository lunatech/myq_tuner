<?php

// Tune this
//
//
$user = 'root';
$pass = '';
$host = 'localhost';

// No changes required below

function result2hash($result)
{
  $hash;
  while ($row = mysql_fetch_assoc($result)) {
    $hash[$row['Variable_name']] = $row['Value'];
  }
  return $hash;
}

function print_tbl($header,$arr)
{
  print "\n==========\t$header\t==========\n";
  foreach ($arr as $key => $value) {
    if (is_string($key)) {      
      printf ("%-10s: %s\n",$key,$value);
    }
    else 
      {
	printf ("%10s\n",$value);
      }
  }
}


$link = mysql_connect($host,$user,$pass)  or die('Could not connect: ' . mysql_error());
$mysql_vars   = mysql_query("show  /*!50000 GLOBAL */ variables") or die('Query failed: ' . mysql_error());
$mysql_status = mysql_query("show  /*!50000 GLOBAL */ status") or die('Query failed: ' . mysql_error());
$vars = result2hash($mysql_vars);
$status = result2hash($mysql_status);

include "CMyqFindRecommendations.php";
$x = new Myq_BaseRecommendations($vars,$status,false);
$x->analyze();
print_tbl("Info",$x->get_info());
print_tbl("No problems detected in following",$x->get_good_info());
print_tbl("Problems",$x->get_bad_info());
print_tbl("General tuneups",$x->get_general_tuneups());
print_tbl("Variable tuneups",$x->get_variable_tuneups());

?>
