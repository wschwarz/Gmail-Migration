<?php
require_once("actions.php");
function main() {
	global $path;
	global $domain, $domainAdmin, $domainAdminPass, $forcedTestAccounts;
	global $actionList;
	global $accounts;
	global $dbTable;
	
	global $argc, $argv;

	debug("\n\n" . date('r') . "\n");
	debug("\n\nStarting migration process...\n");
	
	setupContactsConnection(); 
	setupActionListConnection();
	$entry = GrabNewJobs();
	if ($entry != null) {
		debug("Processing " . $entry . "\n");
		checkOutEntry($entry);
		processBatch($entry);
		closeActionListConnection();
		cleanupContactsConnection();
	}
	debug("\n\nMigration Finished\n\n");
}




function setupContactsConnection() {
	global $contactsConnection;
	global $contactsDsn;
	global $contactsUsername;
	global $contactsPassword;
	
	// Establish connection to the database
	debug("Connecting to SQL Express database=$contactsDsn using username=$contactsUsername\n\n");
	$contactsConnection = odbc_connect($contactsDsn, $contactsUsername, $contactsPassword) or die("Failed to setup connection to the contacts database\n");
}


function cleanupContactsConnection() {
	global $contactsConnection;
	
	odbc_close($contactsConnection);
	unset($contactsConnection);
}


function getAccounts($ldapFilter) {
	global $ldapHost, $ldapBaseDn, $ldapUsername, $ldapPassword, $ldapAttributes;
	$dn = array();
	debug("\nSearching $ldapHost for accounts...");
	$ad = ldap_connect($ldapHost) or die("Failed to connect to $ldapHost");
	ldap_set_option($ad, LDAP_OPT_REFERRALS, 0);
	ldap_set_option($ad, LDAP_OPT_PROTOCOL_VERSION, 3);
	$ldap = ldap_bind($ad, $ldapUsername, $ldapPassword) or die("Could not bind to host $ldapHost");

	$search=ldap_search($ad, $ldapBaseDn, $ldapFilter, $ldapAttributes) or die("Failed to search $ldapHost");

	cleanlist(ldap_get_entries($ad, $search), $dn, 0);
	$accounts = samRecursive($dn, &$ad);

	ldap_unbind($ad);
	debug("Found " . count($accounts) . " account(s)");
	return $accounts;
}


function createAliasArray() {
	global $aliasArray;
	
	debug("Creating Alias Array...");

	$aliasFile = fopen("aliases.txt", "r")
		or exit("Unable to open file!");

	while(!feof($aliasFile)) {
		$currentLine = fgets($aliasFile);
		$currentLine = strtolower($currentLine);
		$currentLine = str_replace('"', '', $currentLine);
	
		list($alias, $email) = explode("=", $currentLine); // Separates current line into alias and  email address
		
		if (ereg('@lawnet.ucla.edu', $email))	{
			list($user, $mailDomain) = explode("@", $email);
		}
		else	{
			$user = $email;
			$user = str_replace("\r\n", "", $user);
		}
		$aliasArray[$alias] = $user; // Adds the alias as the key and the username as the value to an associative array
	}
	
	fclose($aliasFile);
}


function samRecursive($members, &$ad) {
	global $ldapAttributes;
	unset($members['count']);   // get rid of array's 'count' key => value so not to include it for iteration

	$users = Array();
	foreach($members as $member) {
		$filter = "(objectclass=*)";

		$result = ldap_search($ad, $member, $filter, $ldapAttributes) or die("failed to query for $member");
		$entries = ldap_get_entries($ad, $result);

		unset($entries['count']);

		// if member is the Person
		if(count($entries)>0) {
			if($entries[0]['objectclass'][1] == 'person') {
				// append a person's name to array
				$users[] = strtolower($entries[0]['samaccountname'][0]);
			}
	    /*		We are no longer supporting sub-groups - only direct members!
			// if member is the Group
			if($entries[0]['objectclass'][1] == 'group') {
				// obtain all members of this group
				$membersOfGroup = $entries[0]['member'];    
				
				// call a recursive function
				$users = array_merge($users, samRecursive($membersOfGroup, &$ad));

			}*/
		}
	}
 
	// return All the users
	return $users;
}


function cleanlist($entries, &$accounts, $level) {
	unset($entries['count']);
	foreach($entries as $entry) {
		if(isset($entry["member"]) && is_array($entry["member"])) {
			cleanlist($entry["member"], &$accounts, $level+1);
		}
		else if($level == 0) {
			array_push($accounts, $entry["dn"]);
		}
		else {
			array_push($accounts, $entry);
		}
	}
}

function recursiveCopyFolder($source, $destination, $account) {
	debug("Copying folder $source to $destination\n");
	if (file_exists($destination)) die("Could not move folder, destination exists");
	executeCommand("mv $source $destination");
	executeCommand("touch $destination/$account");
	executeCommand("mkdir $source");
	actionEnableImailForwarding($account);
}

function cleanDirectory($directory, $source, $account) {
	debug("Cleaning $directory\n");
	$verifyFile = $directory . "/" . $account;
	/*if (file_exists($source)) {
		debug("Destination exists. Deleting $source");
		executeCommand("rm -rf $source");
	}*/
	if (file_exists($directory) && file_exists($verifyFile)) {
		executeCommand("mv $directory /Volumes/users/!Complete/$account");
	}
	else debug("Could not move $directory. $source belongs to another user");
	//executeCommand("rm -rf $directory");
}

function debug($message) {
	echo $message;
}

function processBatch($account) {
	global $path;

	//$file = fopen(time() . ".txt", "w+");
	
	debug("Processing ...\n");
	debug("==================================\n");

	/* pop - reads the last elements and removes it from the array */
	/* Disable Imail Account must happen first before any actions are done */
	if (actionDisableImailAccount($account)) {
		$result = GrabActionList($account);
		actions($account, $result);
	}
	//fwrite($file, $account."\n");
	
	debug("==================================\n\n");
		
		
	//fclose($file);	
}
/* 
	* $url = URL to post to
	* $method = method (post or put)
	* $body = is the XML body 
*/
function googleApi($url, $method, $body, $additionalHeaders = NULL, $username = NULL, $password = NULL, $authenticationHeaders = NULL) {
	$results = "";

	echo "\n\nInitiating $method API call to $url...\n";
	//echo "\nBODY = $body\n\n";
	$curl = curl_init($url);

	$token = googleLogin($username, $password, $authenticationHeaders);
	debug("\nToken Initiated = $token\n");
	$headers[] = "Content-type: application/atom+xml";
	$headers[] = "Authorization: GoogleLogin auth=$token";
	if($additionalHeaders !==NULL) {
		$headers[] = $additionalHeaders;	
	}
	/*if($method == "post") {
		$headers[] = "Content-Length: " . strlen($body);	
	}*/
	//curl_setopt($curl, CURLOPT_VERBOSE, TRUE);
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
	curl_setopt($curl, CURLOPT_HEADER, TRUE);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

	switch(strtolower($method)) {
		case "put":
			debug("Method call = PUT\n");
	
			$stream = tmpfile();
			fwrite($stream, $body);
			fseek($stream, 0);
				
			curl_setopt($curl, CURLOPT_PUT, true);
			curl_setopt($curl, CURLOPT_INFILE, $stream);
			curl_setopt($curl, CURLOPT_INFILESIZE, strlen($body));

			$results = curl_exec($curl);
			fclose($stream);

			break;
		case "post":
			debug("Method call = POST\n");

			curl_setopt($curl, CURLOPT_POST, true);			
			curl_setopt($curl, CURLOPT_POSTFIELDS, $body);

			$results = curl_exec($curl);
			break;
	}

	if(curl_errno($curl) != 0) {
		$message = curl_error($curl);
		curl_close($curl);
		die("CURL Error: " . $message);		
	}
	else {
		curl_close($curl);
	}
	debug($results);
	return $results;
}


function googleLogin($username=NULL, $password=NULL, $postQuery=NULL) {
	global $domainAdmin;
	global $domainAdminPass;
	if($username === NULL) {
		$username = $domainAdmin;
	}
	if($password === NULL) {
		$password = $domainAdminPass;
	}
	if($postQuery === NULL) {
		$postQuery = "&accountType=HOSTED&service=apps";
	}

	$handler = fopen("tokens/$username.txt", "a+");
	$info = fstat($handler);
	$diff = time() - $info["ctime"];
	$diff = $diff/(60*60*24);


	/* Recreate token if token was created more than 24 hours ago or unless the token file was just created */
	if( $info["size"] == 0 || $diff >= 1) {
		debug("\nInitializing authentication token (username=$username)...\n\n");
		$curl = curl_init("https://www.google.com/accounts/ClientLogin");
		$headers[] = "Content-type: application/x-www-form-urlencoded";

		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($curl, CURLOPT_POST, 1);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		//curl_setopt($curl, CURLOPT_VERBOSE, true);
		curl_setopt($curl, CURLOPT_HEADER, true);
	
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		
	
		$postQuery = "Email=" . urlencode($username) . "&Passwd=" . urlencode($password) . $postQuery;
		//debug("Post body $postQuery");
		curl_setopt($curl, CURLOPT_POSTFIELDS, $postQuery);
		$result = curl_exec($curl);
		//echo $result;		
		if(curl_errno($curl)==0) {
			//fwrite($handler, substr($result, strpos($result,"SID=")+4, 203));
			fwrite($handler, substr($result, strpos($result, "Auth=")+5, strlen($result)-(strpos($result,"Auth=")+6)));
			rewind($handler); /* set the pointer back to 0 */
		}
		else {
			$message = curl_error($curl);
			curl_close($curl);
			fclose($handler);
			die($message);
		}
		curl_close($curl);
	}
	$token = fread($handler,255);
	
	fclose($handler);
	
	//die("token=$token");
	return $token;
}

function shutDown($folder, $account) {
	global $path, $processCount;
	if (file_exists($folder)) {
		debug("Process failed for $account");
		$directory = $folder;
		$source = $path.$account;
		debug("Restoring pre-migration state \n");
		$verifyFile = $directory . "/" . $account;		
		if (file_exists($directory) && file_exists($verifyFile)) {
			executeCommand("rm -rf $source");
			executeCommand("mv $directory $source");
		}
		else debug("Could not move $directory. $source belongs to another user");
		executeCommand("rm running$processCount");
	}
}

/* gets response header and checks for response code 200 */
function ActionPassed($results) {
	$responseValues = explode(" ", $results);
	debug($responseValues[0] . $responseValues[1] . $responseValues[2]);
	debug("Checking Action. \n");
	$errcode = $responseValues[1];
	if (substr($errcode, 0, 1) != '2')
		return false;
	else
		return true;
}


function setupActionListConnection() {
	global $actionDsn;
	global $actionUsername;
	global $actionPassword;
	global $actionConnection;
	$actionConnection = odbc_connect($actionDsn, $actionUsername, $actionPassword) or die("Failed to setup connection to the contacts database\n");
}


function closeActionListConnection() {
	global $actionConnection;
	odbc_close($actionConnection);
	unset($actionConnection);
}

/* Custom mailer to send out messages */
function sendMessage($type, $address, $account, $actionList) {
	$actionString = "";
	while (($action = array_pop($actionList)) != null) {
		$actionString .= "     " . $action. "\n";
	}
	$actionString = str_replace("ForwardingLawnet", "Setup Process..", $actionString); 
	$mailer = new PHPMailer();
	$mailer->AddAddress($address, $account);
	$mailer->AddCC("help@law.ucla.edu");
	$mailer->Subject = "LawNET Google Migration $type";
	$mailer->ContentType = "text/plain";
	switch(strtolower($type)) {
		case "beginning":
			$mailer->Body = "Dear $account,\n\nThe following items you selected are being migrated.\n\n";
			$mailer->Body .= "$actionString\n\nIf you are currently logged in to your iMail account, please log off immediately! Not doing so may affect the migration process and you may loose your entire mailbox. We will send you a second e-mail confirming the status of the migration process.";
			break;
		case "complete":
			$mailer->Body = "Dear $account,\n\nThe following items have been successfully migrated.\n\n";
			$mailer->Body .= "$actionString\n\nYou may now login to the new LawNET email powered by Google at mail.lawnet.ucla.edu.\n\nWe sincerely hope everything is in order, if you have any questions or concerns please do not hesitate to contact the LawNET help desk at (310) 825-4689 or e-mail help@law.ucla.edu.";
			break;
		case "failed":
			$mailer->Body = "Dear $account,\n\nThe following items could not be migrated.\n\n";
			$mailer->Body .= "$actionString\n\nWe will re-try these actions soon. We will let you know when they finish.\n\nIf you have any questions or concerns please do not hesitate to contact the LawNET help desk at (310) 825-4689 or email help@law.ucla.edu.";
			break;
	}	
	$mailer->send();
	unset($mailer);
}


/* SQL access functions */
function GrabActionList($account) {
	global $actionConnection;
	$query = "Select ActionList From $dbTable Where UserName = '" . $account . "'";
	$result = odbc_exec($actionConnection, $query);
	$fetchArray = odbc_fetch_array($result);	
	return $fetchArray;
}


function UpdateActionList($account, $errList) {
	// update for null case
	global $actionConnection;
	$query = null;
	if ($errList == null)
		$query = "Update $dbTable Set ActionList=null, checkedOut = 0 Where UserName = '" . $account . "'";
	else
		$query = "Update $dbTable Set ActionList='$errList', checkedOut = -1 Where UserName = '$account'";
	$result = odbc_exec($actionConnection, $query);
	return $result;
}


function checkOutEntry($account) {
	global $actionConnection;
	global $processCount;
	$query = "Update $dbTable Set checkedOut = " . $processCount . " Where UserName = '" . $account . "'";
	$result = odbc_exec($actionConnection, $query);
	return $result;
}


function GrabNewJobs() {
	global $actionConnection;
	$query = "Select top 1 UserName From $dbTable Where not ActionList is null and CheckedOut = 0 Order by TimeAdded";
	$result = odbc_exec($actionConnection, $query);
	$fetchArray = odbc_fetch_array($result);
	if ($fetchArray != null)
		return $fetchArray["UserName"];
	else
		return null;
}


function GrabEmailAddress($account) {
	global $actionConnection;
	$query = "Select ContactInfo From $dbTable Where UserName = '" . $account . "'";
	$result = odbc_exec($actionConnection, $query);
	$fetchArray = odbc_fetch_array($result);
	return $fetchArray["ContactInfo"];
}

function WithQuery($count, $query) {
	global $contactsConnection;
	$tempArray = array();
	$finalArray = array();
	$i = 1;
	while($i <= $count) {
		$finalQuery = "With OrderedQuery as ($query) Select * From OrderedQuery Where rowindex = $i";	
		$result = odbc_exec($contactsConnection, $finalQuery);		
		$tempArray = odbc_fetch_array($result);		
		array_push($finalArray, $tempArray);
		$i++;
	}

	return $finalArray;
}

function SetDisableForwarding($account) {
	global $actionConnection;
	$query = "Update $dbTable Set DisableForwarding = 1 Where UserName = '$account'";
	$result = odbc_exec($actionConnection, $query);
	return $result;
}

function CheckDoneBefore($account) {
	global $actionConnection;
	$row = GrabActionList($account);
	$actionList = explode(", ", $row["ActionList"]);
	$query = "Select * From $dbTable Where UserName = '$account' AND DisableForwarding = 0";
	$result = odbc_exec($actionConnection, $query);
	$fetchArray = odbc_fetch_array($result);
	if (($fetchArray === false) && (array_search("ForwardingLawnet", $actionList) === false)) return true;
	else return false;
}

function GrabDistinguishedName($account) {
	global $actionConnection;
	$query = "Select DistinguishedName From $dbTable Where UserName = '" . $account . "'";
	$result = odbc_exec($actionConnection, $query);
	$fetchArray = odbc_fetch_array($result);
	return $fetchArray["DistinguishedName"];
}


/* End of SQL Access functions */
?>