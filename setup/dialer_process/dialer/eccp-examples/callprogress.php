#!/usr/bin/php
<?php
/**
 * ECCP Example: Enable/Disable Call Progress Tracking
 *
 * This example demonstrates the callprogress() method which enables or disables
 * call progress event notifications for the ECCP client.
 *
 * USAGE:
 *   ./callprogress.php [0|1]
 *
 * ARGUMENTS:
 *   0 - Disable call progress tracking
 *   1 - Enable call progress tracking
 *
 * TEST SCENARIOS:
 *
 *   1. Verify dialer is running:
 *      systemctl status issabeldialer
 *
 *   2. Enable call progress tracking:
 *      su - asterisk -c "/opt/issabel/dialer/eccp-examples/callprogress.php 1"
 *
 *   3. Disable call progress tracking:
 *      su - asterisk -c "/opt/issabel/dialer/eccp-examples/callprogress.php 0"
 *
 *   4. Monitor dialer logs during testing:
 *      tail -f /opt/issabel/dialer/dialerd.log | grep -i eccp
 *
 * PREREQUISITES: None (no agent authentication required)
 */
require_once ("/var/www/html/modules/agent_console/libs/ECCP.class.php");

if (count($argv) < 2) {
    fprintf(STDERR, $argv[0]." [0|1]\n");
    exit(0);
}

$x = new ECCP();
try {
    print "Connect...\n";
    $cr = $x->connect("localhost", "agentconsole", "agentconsole");
    if (isset($cr->failure)) die('Failed to connect to ECCP - '.$cr->failure->message."\n");

    $enable = ($argv[1] == '1');
    print "Setting call progress to ".($enable ? 'enabled' : 'disabled')."...\n";
    $r = $x->callprogress($enable);
    print_r($r);

    print "Disconnect...\n";
    $x->disconnect();
} catch (Exception $e) {
    print_r($e);
    print_r($x->getParseError());
}
?>
