#!/usr/bin/php
<?php
/**
 * ECCP Example: Filter Events by Agent
 *
 * This example demonstrates the filterbyagent() method which filters
 * ECCP events to only receive events for a specific agent.
 *
 * USAGE:
 *   ./filterbyagent.php [agent-channel] [agent-password]
 *
 * ARGUMENTS:
 *   agent-channel   - Agent channel (e.g., Agent/9000, SIP/1001)
 *   agent-password  - Agent password
 *
 * TEST SCENARIOS:
 *
 *   1. Verify dialer is running:
 *      systemctl status issabeldialer
 *
 *   2. Get agent credentials from database:
 *      mysql -u root -p$(grep mysqlrootpwd /etc/issabel.conf | cut -d= -f2) \
 *        -e "SELECT number, nombre, password FROM call_center.agent WHERE status = 'A' LIMIT 3;"
 *
 *   3. Agent must be logged in first via agent console or:
 *      su - asterisk -c "/opt/issabel/dialer/eccp-examples/agentlogin.php Agent/9000 password 9000"
 *
 *   4. Set filter by agent:
 *      su - asterisk -c "/opt/issabel/dialer/eccp-examples/filterbyagent.php Agent/9000 password"
 *
 *   5. Monitor dialer logs during testing:
 *      tail -f /opt/issabel/dialer/dialerd.log | grep -i "filter\|eccp"
 *
 * PREREQUISITES:
 *   - Agent must exist in database
 *   - Agent authentication required (setAgentNumber/setAgentPass)
 */
require_once ("/var/www/html/modules/agent_console/libs/ECCP.class.php");

if (count($argv) < 3) die("Use: {$argv[0]} agentchannel agentpassword\n");

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

    print "Setting filter by agent...\n";
    $r = $x->filterbyagent();
    print_r($r);

    print "Disconnect...\n";
    $x->disconnect();
} catch (Exception $e) {
    print_r($e);
    print_r($x->getParseError());
}
?>
