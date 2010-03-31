<?php
function result2hash($result)
{
  $hash;
  while ($row = mysql_fetch_assoc($result)) {
    $hash[$row['Variable_name']] = $row['Value'];
  }
  return $hash;
}

function printconsoletable($header,$arr)
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

$link = mysql_connect('localhost', 'root', '')
  or die('Could not connect: ' . mysql_error());
$mysql_vars   = mysql_query("show  /*!50000 GLOBAL */ variables") or die('Query failed: ' . mysql_error());
$mysql_status = mysql_query("show  /*!50000 GLOBAL */ status") or die('Query failed: ' . mysql_error());
$vars = result2hash($mysql_vars);
$status = result2hash($mysql_status);

include "CMyqFindRecommendations.php";
$x = new Myq_BaseRecommendations($vars,$status,false);
$x->analyze();
printconsoletable("Info",$x->get_info());
printconsoletable("No problems detected in following",$x->get_good_info());
printconsoletable("Problems",$x->get_bad_info());
printconsoletable("General tuneups",$x->get_general_tuneups());
printconsoletable("Variable tuneups",$x->get_variable_tuneups());

/* var_dump($x->get_bad_info()); */


?>
