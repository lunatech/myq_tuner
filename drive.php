<?php
function result2hash($result)
{
  $hash;
  while ($row = mysql_fetch_assoc($result)) {
    $hash[$row['Variable_name']] = $row['Value'];
  }
  return $hash;
}


$link = mysql_connect('localhost', 'root', '')
  or die('Could not connect: ' . mysql_error());
$mysql_vars   = mysql_query("show  /*!50000 GLOBAL */ variables") or die('Query failed: ' . mysql_error());
$mysql_status = mysql_query("show  /*!50000 GLOBAL */ status") or die('Query failed: ' . mysql_error());
$vars = result2hash($mysql_vars);
$status = result2hash($mysql_status);

include "CMyqFindRecommendations.php";
$x = new Myq_BaseRecommendations($vars,$status,true);
var_dump($x->get_analysis());
print "Getting tuneups\n\n";
var_dump($x->get_recommendations());
print "\n";
?>
