<?php

//Uncomment these to enable error output to the response.
// error_reporting(E_ALL);
// ini_set('display_errors', 'On');
//Uncomment this line (and comment the two above) to disable error output.
error_reporting(0);

require ('PaypalIPN.php');
require ('GoogleDriveSpreadsheets.php');

$ipn = new PaypalIPN();
$googleDrive = new GoogleSpreadsheet();

// Uncomment to test in the Paypal sandbox.
//$ipn->useSandbox();
$verified = $ipn -> verifyIPN();
// $verified = true;
if ($verified) {
    /*
     * Process IPN
     * A list of variables is available here:
     * https://developer.paypal.com/webapps/developer/docs/classic/ipn/integration-guide/IPNandPDTVariables/
     */
    if ($_POST["payer_email"])
        $senderEmail = $_POST["payer_email"];
    else
        $senderEmail = $_POST["sender_email"];
    $paymentAmount = floatval($_POST["mc_gross"]);
    $transactionType = $_POST["txn_type"];

    if (isSuccessfulPayment($transactionType)) {
        //If it's a $40 (or $37.50 for legacy) payment, extend membership by 1 month
        //If it's a $430 (or $400 for legacy) payment, extend membership by 1 year
        if ($_POST["item_name"])
            $memo = $_POST["item_name"];
        else
            $memo = $_POST["memo"];
        if ($memo && strpos($memo, 'Membership') !== 0 && $paymentAmount < 400) {
            $googleDrive -> updateRow($senderEmail, "month", $paymentAmount);
        } else if ($memo && strpos($memo, 'Membership') !== 0 && $paymentAmount >= 400) {
            $googleDrive -> updateRow($senderEmail, "year", $paymentAmount);
        } else if ($paymentAmount == 40 || $paymentAmount == 37.50 || $paymentAmount == 60) {
            $googleDrive -> updateRow($senderEmail, "month", $paymentAmount);
        } else if ($paymentAmount == 430 || $paymentAmount == 400 || $paymentAmount == 645) {
            $googleDrive -> updateRow($senderEmail, "year", $paymentAmount);
        }
    } else if (isPaymentReversal($transactionType)) {
        //If someone filed a chargeback or adjustment,
        //we should un-apply their membership extension.
        if ($paymentAmount == -40 || $paymentAmount == -37.50 || $paymentAmount == 60) {
            $googleDrive -> updateRow($senderEmail, "removeMonth", $paymentAmount);
        }
        if ($paymentAmount == -430 || $paymentAmount == -400 || $paymentAmount == -645) {
            $googleDrive -> updateRow($senderEmail, "removeYear", $paymentAmount);
        }
    } else if (isPaymentFailure($transactionType)) {
        //idk, leave a note somewhere?
    }
}

// Reply with an empty 200 response to indicate to paypal the IPN was received correctly.
header("HTTP/1.1 200 OK");

function isSuccessfulPayment($transactionType) {
    if ($transactionType === "web_accept" || $transactionType === "express_checkout" || $transactionType === "masspay" || $transactionType === "merch_pmt" || $transactionType === "pro_hosted" || $transactionType === "recurring_payment" || $transactionType === "subscr_payment" || $transactionType === "send_money" || $transactionType === "virtual_terminal" || $transactionType === "cart") {
        if ($_POST["payment_status"] && strtolower($_POST["payment_status"]) === "completed") {
            return true;
        }
    }
    return false;
}

function isPaymentReversal($transactionType) {
    if ($_POST["case_type"] && $_POST["case_type"] === "chargeback") {
        return true;
    }
    if ($transactionType === "adjustment") {
        return true;
    }
    return false;
}

function isPaymentFailure($transactionType) {
    if ($transactionType === "recurring_payment_expired" || $transactionType === "recurring_payment_failed" || $transactionType === "recurring_payment_suspended_due_to_max_failed_payment" || $transactionType === "recurring_payment_suspended" || $transactionType === "subscr_eot" || $transactionType === "subscr_failed") {
        return true;
    }
    return false;
}
