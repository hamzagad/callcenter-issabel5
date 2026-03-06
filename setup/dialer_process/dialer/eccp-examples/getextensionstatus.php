#!/usr/bin/php
<?php
require_once ("/var/www/html/modules/agent_console/libs/ECCP.class.php");

if (count($argv) < 2) die("Use: {$argv[0]} extension\nExample: {$argv[0]} SIP/101\n");

$extension = $argv[1];

$x = new ECCP();
try {
	print "Connect...\n";
	$cr = $x->connect("localhost", "agentconsole", "agentconsole");
	if (isset($cr->failure)) die('Failed to connect to ECCP - '.$cr->failure->message."\n");

	print "Checking extension: $extension\n";
	$response = $x->getextensionstatus($extension);

	if (isset($response->failure)) {
		print "Error: " . $response->failure->message . "\n";
	} else {
		print "Extension: " . $response->extension . "\n";
		print "Registered: " . $response->registered . "\n";
	}

	print "Disconnect...\n";
	$x->disconnect();
} catch (Exception $e) {
	print_r($e);
	print_r($x->getParseError());
}
?>
