<?php
require_once("defines.php");
require_once("api.php");
global $processCount;
global $migrationAccount;
global $path;
// Running 5 concurrent jobs 
while (file_exists("running" . $processCount)) {
	if ($processCount >= $maxProcesses) die("Process limit reached.");
	$processCount++;
}
if (file_exists($path.$migrationAccount.$processCount)) die("Process limit reached.");
debug($processCount);
executeCommand("touch running$processCount" );
$migrationAccount = $migrationAccount . $processCount;
main();	
executeCommand("rm running$processCount");
?>


