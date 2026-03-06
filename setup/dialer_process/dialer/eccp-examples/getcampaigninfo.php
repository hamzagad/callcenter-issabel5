#!/usr/bin/php
<?php
/**
 * ECCP Example: Get Campaign Information
 *
 * This example demonstrates the getcampaigninfo() method which retrieves
 * campaign configuration including forms, scripts, and working times.
 *
 * USAGE:
 *   ./getcampaigninfo.php [incoming|outgoing] [campaign-id]
 *
 * ARGUMENTS:
 *   incoming|outgoing - Campaign type
 *   campaign-id       - Numeric ID of the campaign
 *
 * TEST SCENARIOS:
 *
 *   1. Verify dialer is running:
 *      systemctl status issabeldialer
 *
 *   2. Get a valid campaign ID from database:
 *      mysql -u root -p$(grep mysqlrootpwd /etc/issabel.conf | cut -d= -f2) \
 *        -e "SELECT id, name, tipo FROM call_center.campania LIMIT 3;"
 *
 *   3. Get outgoing campaign info:
 *      su - asterisk -c "/opt/issabel/dialer/eccp-examples/getcampaigninfo.php outgoing 1"
 *
 *   4. Get incoming campaign info:
 *      su - asterisk -c "/opt/issabel/dialer/eccp-examples/getcampaigninfo.php incoming 1"
 *
 *   5. Monitor dialer logs during testing:
 *      tail -f /opt/issabel/dialer/dialerd.log | grep -i eccp
 *
 * PREREQUISITES: None (no agent authentication required)
 */
require_once ("/var/www/html/modules/agent_console/libs/ECCP.class.php");

if (count($argv) < 3) {
    fprintf(STDERR, $argv[0]." [incoming|outgoing] [campaign-id]\n");
    exit(0);
}

$x = new ECCP();
try {
    print "Connect...\n";
    $cr = $x->connect("localhost", "agentconsole", "agentconsole");
    if (isset($cr->failure)) die('Failed to connect to ECCP - '.$cr->failure->message."\n");

    print "Getting campaign info...\n";
    $r = $x->getcampaigninfo($argv[1], $argv[2]);
    print_r($r);

    print "Disconnect...\n";
    $x->disconnect();
} catch (Exception $e) {
    print_r($e);
    print_r($x->getParseError());
}
?>
