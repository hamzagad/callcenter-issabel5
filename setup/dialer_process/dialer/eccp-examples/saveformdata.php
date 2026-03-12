#!/usr/bin/php
<?php
/**
 * ECCP Example: Save Form Data
 *
 * This example demonstrates the saveformdata() method which saves
 * form data collected during a call.
 *
 * USAGE:
 *   ./saveformdata.php agent pass [incoming|outgoing] call-id form-id field-id:value ...
 *
 * ARGUMENTS:
 *   agent           - Agent channel (e.g., Agent/9000)
 *   pass            - Agent password
 *   incoming|outgoing - Campaign type
 *   call-id         - Numeric call ID from active call
 *   form-id         - Numeric form ID
 *   field-id:value  - Form field ID and value pairs (can specify multiple)
 *
 * TEST SCENARIOS:
 *
 *   1. Verify dialer is running:
 *      systemctl status issabeldialer
 *
 *   2. Get agent credentials:
 *      mysql -u root -p$(grep mysqlrootpwd /etc/issabel.conf | cut -d= -f2) \
 *        -e "SELECT number, nombre, password FROM call_center.agent WHERE status = 'A' LIMIT 1;"
 *
 *   3. Get form ID from database:
 *      mysql -u root -p$(grep mysqlrootpwd /etc/issabel.conf | cut -d= -f2) \
 *        -e "SELECT id, nombre, descripcion FROM call_center.form LIMIT 3;"
 *
 *   4. Get form fields:
 *      mysql -u root -p$(grep mysqlrootpwd /etc/issabel.conf | cut -d= -f2) \
 *        -e "SELECT id, id_form, nombre FROM call_center.form_field WHERE id_form = 1;"
 *
 *   5. Save form data (requires active call with valid call_id):
 *      su - asterisk -c "/opt/issabel/dialer/eccp-examples/saveformdata.php Agent/9000 password outgoing 123 1 10:value1 11:value2"
 *
 *   6. Verify saved data:
 *      mysql -u root -p$(grep mysqlrootpwd /etc/issabel.conf | cut -d= -f2) \
 *        -e "SELECT * FROM call_center.form_data_recolected ORDER BY id DESC LIMIT 5;"
 *
 *   7. Monitor dialer logs during testing:
 *      tail -f /opt/issabel/dialer/dialerd.log | grep -i "formdata\|eccp"
 *
 * PREREQUISITES:
 *   - Agent must be logged in
 *   - Active call with valid call_id
 *   - Valid form_id and field_id from database
 *   - Agent authentication required (setAgentNumber/setAgentPass)
 */
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
