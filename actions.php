<?php


function actions($account, $row) {
	$actionList = explode(", ", $row["ActionList"]);
	if (array_search("ForwardingLawnet", $actionList) === false) $actionList = array_merge(array("ForwardingLawnet"), $actionList);
	$originalList = $actionList;
	$errList = $actionList;
	global $path;
	$address = GrabEmailAddress($account);
	sendMessage("Beginning", $address, $account, $actionList);
	debug(print_r($actionList, true));
	while(($action = array_shift($actionList)) != null) {
		switch(trim(strtolower($action))) {
			case "email":
				if (actionMigrateEmail($account)!=false) array_splice ($errList, array_search("Email", $errList), 1);
				break;			
			case "forwarding":
				if (actionMigrateForwarding($account)) array_splice ($errList, array_search("Forwarding", $errList), 1);
				break;
			case "forwardinglawnet":
				if (actionDisableForwardingLawNET($account)) array_splice ($errList, array_search("ForwardingLawnet", $errList), 1);
				break;
			/*case "alias":
				if (!actionMigrateAlias($account)) $errList .= "Alias, ";
				break;*/
			case "vacation":
				if (actionMigrateVacation($account)) array_splice ($errList, array_search("Vacation", $errList), 1);
				break;
			case "signature":
				if (actionMigrateSignature($account)) array_splice ($errList, array_search("Signature", $errList), 1);
				break;
			case "contacts":
				if (actionMigrateContacts($account)) array_splice ($errList, array_search("Contacts", $errList), 1);
				break;
		}
	}
	if (count($errList) == 0) {
		$errList = null;
		//actionEnableImailForwarding($account);
		debug("Process complete");
		if (!file_exists("/Volumes/users/!Complete/$account") && file_exists($path.$account)) actionNonEmailCleanUp($account);
		sendMessage("Complete", $address, $account, $originalList);
	}
	if ($errList == null) UpdateActionList($account, null);
	else {
		UpdateActionList($account, implode(", ", $errList));
		debug("Process failed");
		sendMessage("Failed", $address, $account, $errList);
	}
}


/***************************************************************************************************
	START INDIVIDUAL ACTION FUNCTIONS
***************************************************************************************************/


function actionMigrateForwarding($account) {
	global $domain;
	global $path;
	$success = true;

	debug("Migrating forwarding rule for $account...\n");

	/* figure out what the forwarding rule is for $account */
	if (file_exists($path . $account . '/forward.ima')) {
		$forwardingAddress = file_get_contents($path . $account . '/forward.ima', true);
		$forwardingAddress = trim($forwardingAddress);
		if($forwardingAddress != "" && strrpos($forwardingAddress, '@') != false && strtolower($forwardingAddress) != strtolower("$account@lawnet.ucla.edu") && strtolower($forwardingAddress) != strtolower("$account@f.lawnet.ucla.edu")) {
			$body = "<?xml version=\"1.0\" encoding=\"utf-8\"?>";
			$body .= "<atom:entry xmlns:atom=\"http://www.w3.org/2005/Atom\" xmlns:apps=\"http://schemas.google.com/apps/2006\">";
			$body .= "<apps:property name=\"enable\" value=\"true\" />";
			$body .= "<apps:property name=\"forwardTo\" value=\"$forwardingAddress\" />";
			$body .= "<apps:property name=\"action\" value=\"KEEP\" />";
			$body .= "</atom:entry>";
		
			$results = googleApi("https://apps-apis.google.com/a/feeds/emailsettings/2.0/$domain/$account/forwarding", "put", $body);
			$success = ActionPassed($results);
		  }
	}
	return $success;
}

function actionNonEmailCleanUp($account) {
	global $path;
	$source = $path.$account;
	$destination = "/Volumes/users/!Complete/$account";
	debug("Copying folder $source to $destination\n");		executeCommand("mv $source $destination");	
	executeCommand("mkdir $source");
	actionEnableImailForwarding($account);
}

function actionDisableForwardingLawNET($account) {
	global $domain;
	global $path;
	if (CheckDoneBefore($account)) return true;
	else {
		debug("Disabling LawNET forwarding rule for $account...\n");

		/* figure out what the forwarding rule is for $account */
		$body = "<?xml version=\"1.0\" encoding=\"utf-8\"?>";
		$body .= "<atom:entry xmlns:atom=\"http://www.w3.org/2005/Atom\" xmlns:apps=\"http://schemas.google.com/apps/2006\">";
		$body .= "<apps:property name=\"enable\" value=\"false\" />";		
		$body .= "</atom:entry>";
		
		$results = googleApi("https://apps-apis.google.com/a/feeds/emailsettings/2.0/$domain/$account/forwarding", "put", $body);
		if (ActionPassed($results) && SetDisableForwarding($account)) return true;
		else return false;
	}
}

function actionEnableImailForwarding($account) {
	global $path;
	debug("Beginning Add Imail Forwarding Rule\n");
	if (file_exists($path . $account . '/forward.ima')) {
		$source = $path . $account . '/forward.ima';
		$destination = $path . $account . '/forward_old.ima';
		executeCommand("mv $source $destination");
	}
	$file = fopen("forward.ima", "w+");
	fwrite($file, $account . "@f.lawnet.ucla.edu");
	fclose($file);
	$destination = $path . $account . '/';
	return(executeCommand("mv forward.ima $destination"));
}

function actionChangeUserPassword($account) {
	global $domain;
	global $migrationPassword;
	$account = strtolower($account);
	$url = 'https://apps-apis.google.com/a/feeds/' . $domain . '/user/2.0/' . $account;
	$token = googleLogin();
	$headers[] = "Content-type: application/atom+xml";
	$headers[] = "Authorization: GoogleLogin auth=$token";
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
	curl_setopt($ch, CURLOPT_HEADER, TRUE);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_URL, $url);
	$content = curl_exec($ch);
	debug("Retrieving User Info errors: " . curl_error($ch) . "\n\n");
	curl_close($ch);
	$hashvalue = md5($migrationPassword);
	$hashfunction = "MD5";
	$newContent = str_replace("<apps:login userName='$account'", "<apps:login userName='$account' password='$hashvalue' hashFunctionName='$hashfunction'", $content);
	$newContent = substr($newContent, strpos($newContent, "<?xml version='1.0' encoding='UTF-8'?>"));
	print_r($newContent);
	$results = googleApi("https://apps-apis.google.com/a/feeds/" . $domain . "/user/2.0/" . $account, "PUT", $newContent );
	return(ActionPassed($results));
}

function actionMigrateVacation($account) {
	debug("Migrating vacation for $account...\n");
	
	global $domain;
	global $path;
	$success = true;
	
	if (file_exists($path . $account . '/vacation.ima')) {
		$vacationText = file_get_contents($path . $account . '/vacation.ima', true);
		$vacationText = strip_tags($vacationText);
		//$vacation = nl2br($vacationText);
		$vacation = str_replace("&", "&amp;", $vacationText);
		
		if ($vacation != "") {
			$body = "<?xml version=\"1.0\" encoding=\"utf-8\"?>";
			$body .= "<atom:entry xmlns:atom=\"http://www.w3.org/2005/Atom\" xmlns:apps=\"http://schemas.google.com/apps/2006\">";
			$body .= "<apps:property name=\"enable\" value=\"true\" />";
			$body .= "<apps:property name=\"subject\" value=\"Default\" />";
			$body .= "<apps:property name=\"message\" value=\"$vacation\" />";
			$body .= "<apps:property name=\"contactsOnly\" value=\"false\" />";
			$body .= "</atom:entry>";
			
			$results = googleApi("https://apps-apis.google.com/a/feeds/emailsettings/2.0/$domain/$account/vacation", "put", $body);
			$success = ActionPassed($results);
		}
	}
	elseif (file_exists($path . $account . '/vacation.bak')) {
		$vacationText = file_get_contents($path . $account . '/vacation.bak', true);
		$vacationText = strip_tags($vacationText);
		//$vacation = nl2br($vacationText);
		$vacation = str_replace("&", "&amp;", $vacationText);
		
		if ($vacation != "")	{
			$body = "<?xml version=\"1.0\" encoding=\"utf-8\"?>";
			$body .= "<atom:entry xmlns:atom=\"http://www.w3.org/2005/Atom\" xmlns:apps=\"http://schemas.google.com/apps/2006\">";
			$body .= "<apps:property name=\"enable\" value=\"false\" />";
			$body .= "<apps:property name=\"subject\" value=\"Default\" />";
			$body .= "<apps:property name=\"message\" value=\"$vacation\" />";
			$body .= "<apps:property name=\"contactsOnly\" value=\"false\" />";
			$body .= "</atom:entry>";
			
			$results = googleApi("https://apps-apis.google.com/a/feeds/emailsettings/2.0/$domain/$account/vacation", "put", $body);
			$success = ActionPassed($results);
		}
	}
	return $success;
}


function actionDisableImailAccount($account) {
	$file = fopen($account . ".reg", "w+");
	debug("Beginning disable imail\n");
	fwrite($file, "Windows Registry Editor Version 5.00\n\n[HKEY_LOCAL_MACHINE\\SOFTWARE\\Ipswitch\\IMail\\Domains\\lawnet.ucla.edu\\Users\\" . $account . "]\n\"Flags\"=dword:00002081");
	fclose($file);
	$source = $account . ".reg";
	$destination = "/Volumes/disabledAcct/" . $account . ".reg";
	if (!file_exists($destination)) {
		return(executeCommand("mv $source $destination"));
	}
	return false;
}


function actionMigrateSignature($account) {
	global $domain;
	global $path;
	
	$success = true;
	
	debug("Migrating signature for $account...\n");
	debug("Checking folder $path$account/signature.txt\n");
	if (file_exists($path . $account . '/signature.txt')) {
		$signatureText = file_get_contents($path . $account . '/signature.txt', true);
		$signature = strip_tags($signatureText);
				
		if ($signature != "")	{
			$body = "<?xml version=\"1.0\" encoding=\"utf-8\"?>";
			$body .= "<atom:entry xmlns:atom=\"http://www.w3.org/2005/Atom\" xmlns:apps=\"http://schemas.google.com/apps/2006\">";
			$body .= "<apps:property name=\"signature\" value=\"$signature\" />";
			$body .= "</atom:entry>";
			
			$results = googleApi("https://apps-apis.google.com/a/feeds/emailsettings/2.0/$domain/$account/signature", "put", $body);
			$success = ActionPassed($results);
		}
	}
	return $success;
}


function actionMigrateContacts($account) {
	debug("Migrating contacts for $account...\n");
	actionChangeUserPassword($account);
	global $contactsConnection;	
	global $actionConnection;
	
	global $migrationPassword;
		
	global $domain;
	
	$success = true;

	$jobTitle = "";
	$company = "";
	$department = "";
	$email = "";
	$contactName = "";
	$addressHome = "";
	$addressBiz = "";
	$homePhone = "";
	$bizPhone = "";
	$bizFax = "";
	$mobilePhone = "";
	
	$accountEmail = "$account@lawnet.ucla.edu";
	$countQuery = "Select count(*) FROM dbo.Contacts as c INNER JOIN dbo.Users as u ON c.Owner = u.ID AND u.LoginName = '".$accountEmail."'";
	$countResult = odbc_exec($contactsConnection, $countQuery);
	odbc_fetch_row($countResult);
	$countResult = odbc_result($countResult, 1);
	$countResult = (int)$countResult;
	//This query obtains the contact records belonging to the current student
	$userContactQuery = "SELECT row_number() OVER (Order BY c.Name) as rowindex, c.ID, CAST(c.Name AS TEXT) AS Name, CAST(c.JobTitle AS TEXT) AS JobTitle, CAST(c.Company AS TEXT) AS Company, CAST(c.Department AS TEXT) AS Department FROM dbo.Contacts as c INNER JOIN dbo.Users as u ON c.Owner = u.ID AND u.LoginName = '$accountEmail'";
	$contactArrayFinal = WithQuery($countResult, $userContactQuery);
	
	//This loop runs through each contact record belonging to the current student
	while ($contactRecord=array_pop($contactArrayFinal)) {
			$contactName = $contactRecord['Name'];
			$contactName = trim($contactName);
			//Beginning formation of XML entry
			$body = "<atom:entry xmlns:atom='http://www.w3.org/2005/Atom' xmlns:gd='http://schemas.google.com/g/2005'>";
			$body .= "<atom:category scheme='http://schemas.google.com/g/2005#kind' term='http://schemas.google.com/contact/2008#contact' />";
			$body .= "<atom:title type='text'>$contactName</atom:title>";
			
			//Assigning department info, if it exists, to variable and adding to XML entry
			if ($contactRecord['Department'] != "")	{
				$department = $contactRecord['Department'];
				$department = trim($department);
				$body .= "<atom:content type='text'>Department - $department</atom:content>";
			}
			else
				$body .= "<atom:content type='text'>Notes</atom:content>";
													
			
			//This query obtains the email address and 4 possible types of phone numbers for the current contact
			$emailCountQuery = "Select count(*) FROM (Select * FROM dbo.PhoneNumbers WHERE OwnerID = '".$contactRecord['ID']."' AND LEN(RTRIM(Number))>0 UNION Select * FROM dbo.EmailAddresses WHERE OwnerID = '".$contactRecord['ID']."' AND LEN(RTRIM(Address))>0) as u";
			$emailCountResult = odbc_exec($contactsConnection, $emailCountQuery);
			odbc_fetch_row($emailCountResult);
			$emailCountResult = odbc_result($emailCountResult, 1);
			$emailCount = (int)$emailCountResult;
			$emailPhoneQuery = "SELECT row_number() OVER (Order BY Name) as rowindex, CAST(Name AS varchar) AS Name, CAST(Number AS varchar) AS Value FROM dbo.PhoneNumbers WHERE OwnerID = '".$contactRecord['ID']."' AND LEN(RTRIM(Number))>0 UNION SELECT row_number() OVER (Order BY Name) as rowindex, 'Email' as Name, CAST(Address AS varchar) AS Value FROM dbo.EmailAddresses WHERE OwnerID = '".$contactRecord['ID']."' AND LEN(RTRIM(Address))>0";
			$emailArray = WithQuery($emailCount, $emailPhoneQuery);

			
			while ($emailPhoneArray = array_pop($emailArray))	{
				switch ($emailPhoneArray['Name'])	{
					case "Email":
						$pattern = '/<|>/';
						$replacement = '';
						$email=preg_replace( $pattern , $replacement , $emailPhoneArray['Value'] );
						$body .= "<gd:email rel='http://schemas.google.com/g/2005#work' address='$email'/>";
						break;
						
					case "Home-Phone":
						$homePhone = $emailPhoneArray['Value'];
						$homePhone = trim($homePhone);
						$homePhone = str_replace("&", "&amp;", $homePhone);
						$body .= "<gd:phoneNumber rel='http://schemas.google.com/g/2005#home'>$homePhone</gd:phoneNumber>";
						break;
						
					case "Business-Phone":
						$bizPhone = $emailPhoneArray['Value'];
						$bizPhone = trim($bizPhone);
						$bizPhone = str_replace("&", "&amp;", $bizPhone);
						$body .= "<gd:phoneNumber rel='http://schemas.google.com/g/2005#work'>$bizPhone</gd:phoneNumber>";
						break;
						
					case "Business-Fax":
						$bizFax = $emailPhoneArray['Value'];
						$bizFax = trim($bizFax);
						$bizFax = str_replace("&", "&amp;", $bizFax);
						$body .= "<gd:phoneNumber rel='http://schemas.google.com/g/2005#work_fax'>$bizFax</gd:phoneNumber>";
						break;
						
					case "Mobile-Phone":
						$mobilePhone = $emailPhoneArray['Value'];
						$mobilePhone = trim($mobilePhone);
						$mobilePhone = str_replace("&", "&amp;", $mobilePhone);
						$body .= "<gd:phoneNumber rel='http://schemas.google.com/g/2005#mobile'>$mobilePhone</gd:phoneNumber>";
						break;
				}
			}
			
			//This query obtains the Home and Business Mailing Addresses for the current contact
			$addressCountQuery = "Select Count(*) From dbo.Addresses WHERE OwnerID = '".$contactRecord['ID']."' AND (LEN(RTRIM(Address1))>0 OR LEN(RTRIM(Town))>0 OR LEN(RTRIM(County))>0 OR LEN(RTRIM(Country))>0 OR LEN(RTRIM(Postcode))>0)";
			$addressCountResult = odbc_exec($contactsConnection, $addressCountQuery);
			odbc_fetch_row($addressCountResult);
			$addressCountResult = odbc_result($addressCountResult, 1);
			$addressCount = (int)$addressCountResult;
			$addressesQuery = "SELECT row_number() OVER (Order BY Name) as rowindex, CAST(Name AS varchar) AS Name, CAST(Address1 AS TEXT) AS Address1, CAST(Town AS TEXT) AS Town, CAST(County AS TEXT) AS County, CAST(Country AS TEXT) AS Country, CAST(Postcode AS TEXT) AS Postcode  FROM dbo.Addresses WHERE OwnerID = '".$contactRecord['ID']."' AND (LEN(RTRIM(Address1))>0 OR LEN(RTRIM(Town))>0 OR LEN(RTRIM(County))>0 OR LEN(RTRIM(Country))>0 OR LEN(RTRIM(Postcode))>0)";
			$addressArray = WithQuery($addressCount, $addressesQuery);

			
			while ($addressesArray = array_pop($addressArray))	{
				foreach(array_keys($addressesArray) as $key) {
					$addressesArray[$key] = trim($addressesArray[$key]);	
				}
				if ($addressesArray['Name'] == "Business-" && ($addressesArray['Address1'] != "" || $addressesArray['Town'] != "" || $addressesArray['County'] != "" || $addressesArray['Postcode'] != "" || $addressesArray['Country'] != ""))	{
					$addressBiz = $addressesArray['Name'] ." ". $addressesArray['Address1'] ." ". $addressesArray['Town'] ." ". $addressesArray['Postcode'] ." ". $addressesArray['Country']."";
					$addressBiz = trim($addressBiz);
					$body .= "<gd:postalAddress rel='http://schemas.google.com/g/2005#work'>$addressBiz</gd:postalAddress>";
				}
				if ($addressesArray['Name'] == "Home-" && ($addressesArray['Address1'] != "" || $addressesArray['Town'] != "" || $addressesArray['County'] != "" || $addressesArray['Postcode'] != "" || $addressesArray['Country'] != ""))	{
					$addressHome = $addressesArray['Name'] ." ". $addressesArray['Address1'] ." ". $addressesArray['Town'] ." ". $addressesArray['County'] ." ". $addressesArray['Country']."";
					$addressHome = trim($addressBiz);
					$body .= "<gd:postalAddress rel='http://schemas.google.com/g/2005#home'>$addressHome</gd:postalAddress>";
				}
			}

			//Assigning organization info, if it exists, to variables and adding to XML entry
			if (($contactRecord['JobTitle'] != "") || ($contactRecord['Company'] != "")) 	{
				$body .= "<gd:organization rel='http://schemas.google.com/g/2005#work'>";
				
				if ($contactRecord['JobTitle'] != "")	{
					$jobTitle = $contactRecord['JobTitle'];
					$jobTitle = trim($jobTitle);
					$body .= "<gd:orgTitle>$jobTitle</gd:orgTitle>";
				}
			
				if ($contactRecord['Company'] != "")	{
					$company = $contactRecord['Company'];
					$company = trim($company);
					$body .= "<gd:orgName>$company</gd:orgName>";
				}
			
				$body .= "</gd:organization>";
			}
			
			$body .= "</atom:entry>";
			$body = str_replace("&", "&amp;", $body);
			$results = googleApi("http://www.google.com/m8/feeds/contacts/" . urlencode($accountEmail) . "/full", "post", $body, "GData-Version: 2", $accountEmail,  $migrationPassword, "&accountType=HOSTED&service=cp&source=ucla_lawnet_2");
			$success = ActionPassed($results);
		}
		return $success;
}

function actionEnableImap($account) {
	global $domain;
	debug("Enabling Imap for $account \n");
	$body = "<?xml version=\"1.0\" encoding=\"utf-8\"?>";
	$body .= "<atom:entry xmlns:atom=\"http://www.w3.org/2005/Atom\" xmlns:apps=\"http://schemas.google.com/apps/2006\">";
	$body .= "<apps:property name=\"enable\" value=\"true\" />";
	$body .= "</atom:entry>";
	$results = googleApi("https://apps-apis.google.com/a/feeds/emailsettings/2.0/$domain/$account/imap", "put", $body);
	return ActionPassed($results);
}

function actionMigrateEmail($account) {
	global $syncCommand;
	global $domain, $domainAdmin, $migrationAccount, $migrationPassword;
	global $path;
	if (!file_exists($path.$account)) {
		debug("No Email Folder");
		executeCommand("mkdir $path".$account);
		actionEnableImailForwarding($account);
		return true;
	}
	if (actionChangeUserPassword($account)) {
		$syncCommand = "perl ../../imapsync/imapsync --syncinternaldates --skipsize --host1 $domain --user1 $migrationAccount --password1 $migrationPassword --host2 imap.gmail.com --user2 $account@$domain --password2 $migrationPassword --ssl2 --sep1 / --prefix1 / --regextrans2 's/^Sent/\[Gmail\]\/Sent Mail/' --regextrans2 's/^Deleted/\[Gmail\]\/Trash/' --exclude 'Junk E-mail'";
	
		debug("Migrating email for $account...\n");
		actionEnableImap($account);
		register_shutdown_function("shutDown", $path.$migrationAccount, $account);
		recursiveCopyFolder($path.$account, $path.$migrationAccount, $account);
		$result = executeCommand($syncCommand);
		cleanDirectory($path.$migrationAccount, $path.$account, $account);
		return true;
	}
	else return false;
}



function executeCommand($command) {

	$output = array();
	exec($command, $output, $returnValue);
	
	debug("\n\nOutput from $command\n==============\n" . print_r($output,1) . "\n==================\n");
	return ($returnValue == 0 ? 1:0);
}


/***************************************************************************************************
	END INDIVIDUAL ACTION FUNCTIONS
***************************************************************************************************/
?>
