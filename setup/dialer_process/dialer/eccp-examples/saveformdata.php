#!/usr/bin/php
<?php
require_once ("/var/www/html/modules/agent_console/libs/ECCP.class.php");

if (count($argv) < 7) {
    fprintf(STDERR, $argv[0]." agent pass [incoming|outgoing] call-id form-id field-id:value ...\n");
    exit(0);
}

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

    // Build form data array from remaining arguments
    $form_id = $argv[5];
    $formdata = [$form_id => []];
    for ($i = 6; $i < count($argv); $i++) {
        $parts = explode(':', $argv[$i]);
        if (count($parts) == 2) {
            $formdata[$form_id][$parts[0]] = $parts[1];
        }
    }

    print "Saving form data...\n";
    $r = $x->saveformdata($argv[3], $argv[4], $formdata);
    print_r($r);

    print "Disconnect...\n";
    $x->disconnect();
} catch (Exception $e) {
    print_r($e);
    print_r($x->getParseError());
}
?>
