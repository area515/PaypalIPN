<?php
//With help from https://gist.github.com/karlkranich/de225928665dc6b83667

require_once ("google-api-php-client/vendor/autoload.php");
include_once ("google-api-php-client/examples/templates/base.php");
include_once ("OtherStuff.php");

class GoogleSpreadsheet {

    public function updateRow($emailAddress, $timeInterval, $paymentAmount) {

        global $fileId;
        $client = $this -> getGoogleClient();
        //Is the following needed? $service is never used.
        //$service = new Google_Service_Sheets($client);

        $tokenArray = $client -> fetchAccessTokenWithAssertion();
        $accessToken = $tokenArray["access_token"];

        $spreadsheetRows = $this -> queryFileByEmailAddress($fileId, $emailAddress, $accessToken);
        $tableXML = simplexml_load_string($spreadsheetRows);

        //Loop over this, because family memberships might have multiple cards under the same email
        foreach ($tableXML -> entry as $entry) {

            //Look for the "Expiration Date" column, add time to it, then save the row back to the Google spreadsheet
            //Again, note that all the column names need to be lowercased for some reason.
            $column = $this -> findColumnFromEntry("expirationdate", $entry);
            if (isset($column)) {
                $expDate = $this -> parseDateFromColumn($column);
                $newExpDate = $this -> updateExpirationDate($expDate, $timeInterval);

                $rowId = $entry -> id;
                //$rowId contains an entire url, where the last param contains the actual id. Strip out the real id.
                $rowId = substr($rowId, strrpos($rowId, '/') + 1);
                $expDate = $expDate -> format("m/d/Y");
                $this -> updateRowInFile($fileId, $rowId, $newExpDate, $accessToken);
                $this -> addRowToAuditFile($emailAddress, $expDate, $newExpDate, $paymentAmount, $accessToken);
            }
            $entry = array_pop($tableXML);
        }
    }

    function findColumnFromEntry($columnName, $entry) {

        foreach ($entry -> children('gsx', TRUE) as $column)
            if ($column -> getName() === $columnName)
                return $column;

        return null;
    }

    function parseDateFromColumn($column) {

        $expDate = date_create_from_format("m/d/Y", $column);
        //Account for Y2K. Dates manually entered in the spreadsheet may have two-digit year values.
        $longTimeAgo = new DateTime('1970-01-01');
        if ($expDate < $longTimeAgo) {
            $expDate = date_create_from_format('m/d/y', $column);
        }
        return $expDate;
    }

    function updateExpirationDate($expDate, $timeInterval) {

        $newExpiration = clone $expDate;
        $today = new DateTime();
        if ($expDate < $today) {
            $newExpiration = $today;
        }
        if ($timeInterval === "month") {
            $newExpiration = $newExpiration -> add(new DateInterval("P1M"));
        } else if ($timeInterval === "year") {
            $newExpiration = $newExpiration -> add(new DateInterval("P1Y"));
        } else if ($timeInterval === "removeMonth") {
            $newExpiration = $newExpiration -> sub(new DateInterval("P1M"));
        } else if ($timeInterval === "removeYear") {
            $newExpiration = $newExpiration -> sub(new DateInterval("P1Y"));
        }
        return $newExpiration -> format("m/d/Y");
    }

    function queryFileByEmailAddress($fileId, $emailAddress, $accessToken) {

        $url = "https://spreadsheets.google.com/feeds/list/$fileId/od6/private/full?sq=quantity>9";
        //We're going to query for a row with a matching email, since Paypal uses email as a unique id
        //Be careful with the query; google's api's suck. Must be paypalemail="$emailAddress";. Note the use of lowercase column names, and the double quotes.
        $queryByEmail = "paypalemail=\"$emailAddress\"";

        $headers = ["Authorization" => "Bearer $accessToken", "GData-Version" => "3.0"];
        $httpClient = new GuzzleHttp\Client(['headers' => $headers]);
        $resp = $httpClient -> request('GET', $url, ['query' => ['sq' => $queryByEmail]]);
        $body = $resp -> getBody() -> getContents();

        return $body;
    }

    function updateRowInFile($fileId, $rowId, $expDate, $accessToken) {

        $url = "https://spreadsheets.google.com/feeds/list/$fileId/od6/private/full/$rowId";
        $headers = ["Authorization" => "Bearer $accessToken", 'GData-Version' => '3.0', 'Content-Type' => 'application/atom+xml', 'If-Match' => '*'];
        $postBody = "<entry xmlns=\"http://www.w3.org/2005/Atom\" xmlns:gsx=\"http://schemas.google.com/spreadsheets/2006/extended\" xmlns:gd=\"http://schemas.google.com/g/2005\"><id>https://spreadsheets.google.com/feeds/list/$fileId/od6/$rowId</id><gsx:expirationdate>$expDate</gsx:expirationdate></entry>";

        $httpClient = new GuzzleHttp\Client(['headers' => $headers]);
        $resp = $httpClient -> request('PUT', $url, ['body' => $postBody]);
        $body = $resp -> getBody() -> getContents();
        $code = $resp -> getStatusCode();
        if ($code != 200) {
            $reason = $resp -> getReasonPhrase();
            throw new Exception("Couldn't update spreadsheet - got $code : $reason");
        }
    }

    function addRowToAuditFile($emailAddress, $oldExpiration, $newExpiration, $amount, $accessToken) {
        
        global $auditFileId;
		$now = new DateTime() -> format("m/d/Y");
        $url = "https://spreadsheets.google.com/feeds/list/$auditFileId/od6/private/full";
        $headers = ["Authorization" => "Bearer $accessToken", 'Content-Type' => 'application/atom+xml'];
        $postBody = "<entry xmlns=\"http://www.w3.org/2005/Atom\" xmlns:gsx=\"http://schemas.google.com/spreadsheets/2006/extended\"><gsx:paypalemail>$emailAddress</gsx:paypalemail><gsx:amountpaid>$amount</gsx:amountpaid><gsx:oldexpirationdate>$oldExpiration</gsx:oldexpirationdate><gsx:newexpirationdate>$newExpiration</gsx:newexpirationdate><gsx:processeddate>$now</gsx:processeddate></entry>";
        $httpClient = new GuzzleHttp\Client(['headers' => $headers]);
        $resp = $httpClient -> request('POST', $url, ['body' => $postBody]);
        $body = $resp -> getBody() -> getContents();
        $code = $resp -> getStatusCode();
        if ($code < 200 && $code >= 300) {
            //Accept any 200 response. I think it actually returns a 201 Created.
            $reason = $resp->getReasonPhrase();
            throw new Exception("Couldn't add a row to the audit sheet - got $code : $reason");
        }
    }

    function getGoogleClient() {

        putenv("GOOGLE_APPLICATION_CREDENTIALS=service-account-credentials.json");
        $client = new Google_Client();
        $client -> useApplicationDefaultCredentials();
        $client -> setApplicationName("Payment_Notifications");
        $client -> setScopes(['https://www.googleapis.com/auth/drive', 'https://spreadsheets.google.com/feeds']);
        //$client->setDeveloperKey("");

        return $client;
    }
}
