<?php

require_once('download.class.php');
date_default_timezone_set('America/Los_Angeles');
// sod_download::sendFile("https://www.ccbill.com/cs/manuals/CCBill_SMS_Users_Guide.pdf");\
$new = new sod_download;
// $new->sendFile("text.pdf");
$new->sendFile("http://localhost/download/text.pdf");
// $new->sendFile("http://localhost/download/test.php", "test.pdf");
