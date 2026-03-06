#!/usr/bin/php
<?php
require_once ("/var/www/html/modules/agent_console/libs/ECCP.class.php");

if (count($argv) < 4) die("Use: {$argv[0]} agentchannel agentpassword target_agent\n");
$agentname = $argv[1];
$agentpass = $argv[2];

$x = new ECCP();
try {
	print "Connect...\n";
	$cr = $x->connect("localhost", "agentconsole", "agentconsole");
	if (isset($cr->failure)) die('Failed to connect to ECCP - '.$cr->failure->message."\n");
    $x->setAgentNumber($agentname);
    $x->setAgentPass($agentpass);
	print_r($x->getAgentStatus());
	print "Iniciando transferencia a agente...\n";
	print "Source agent: $agentname\n";
	print "Target agent: {$argv[3]}\n";
	$r = $x->transfercallagent($argv[3]);
	print_r($r);
	print "Disconnect...\n";
	$x->disconnect();
} catch (Exception $e) {
	print_r($e);
	print_r($x->getParseError());
}
?>
