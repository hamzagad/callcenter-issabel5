#!/bin/bash

#stop service and remove from startup
systemctl stop issabeldialer 2>/dev/null || true
chkconfig --del issabeldialer 2>/dev/null || true
chkconfig --level 2345 issabeldialer off 2>/dev/null || true
systemctl daemon-reload 2>/dev/null || true

#remove folder and files
rm -rf /var/www/html/modules/{agent_break,agent_console,agent_journey,agents,break_administrator,callcenter_config}
rm -rf /var/www/html/modules/{calls_detail,calls_per_agent,calls_per_hour,campaign_in,campaign_monitoring,campaign_out}
rm -rf /var/www/html/modules/{cb_extensions,client,dont_call_list,eccp_users,external_url,form_designer,form_list}
rm -rf /var/www/html/modules/{graphic_calls,hold_time,ingoings_calls_success,login_logout,queues}
rm -rf /var/www/html/modules/{rep_agent_information,rep_agents_monitoring,rep_incoming_calls_monitoring}
rm -rf /var/www/html/modules/{rep_incoming_campaigns_panel,rep_outgoing_campaigns_panel,reports_break,rep_trunks_used_per_hour}

#remove dialer
rm -rf /opt/issabel/dialer
rm -rf /etc/rc.d/init.d/issabeldialer
rm -rf /etc/logrotate.d/issabeldialer
rm -rf /usr/bin/issabel-callcenter-local-dnc
rm -rf /usr/share/issabel/module_installer/callcenter/

#remove menu
issabel-menuremove call_center

#remove call center contexts from extensions_custom.conf
EXTENSIONS_FILE="/etc/asterisk/extensions_custom.conf"
if [ -f "$EXTENSIONS_FILE" ]; then
    if grep -q "; BEGIN ISSABEL CALL-CENTER CONTEXTS DO NOT REMOVE THIS LINE" "$EXTENSIONS_FILE"; then
        sed -i '/^; BEGIN ISSABEL CALL-CENTER CONTEXTS DO NOT REMOVE THIS LINE$/,/^; END ISSABEL CALL-CENTER CONTEXTS DO NOT REMOVE THIS LINE$/d' "$EXTENSIONS_FILE"
        echo "Removed call center contexts from $EXTENSIONS_FILE"
        # Reload Asterisk dialplan
        asterisk -rx "dialplan reload" 2>/dev/null || true
    fi
fi

#remove database
echo ""
read -p "Do you want to delete the call_center database? (y/n): " DELETE_DB
if [ "$DELETE_DB" = "y" ] || [ "$DELETE_DB" = "Y" ]; then
    MYSQL_ROOT_PWD=$(grep '^mysqlrootpwd=' /etc/issabel.conf | cut -d'=' -f2)
    if [ -n "$MYSQL_ROOT_PWD" ]; then
        mysql -u root -p"$MYSQL_ROOT_PWD" -e "DROP DATABASE IF EXISTS call_center;" 2>/dev/null
        if [ $? -eq 0 ]; then
            echo "Database call_center deleted successfully."
        else
            echo "Error deleting database. You can delete it manually."
        fi
    else
        echo "Could not read MySQL root password"
        echo "You can delete the database manually by dropping call_center database"
    fi
else
    echo "Database call_center was not deleted."
fi

echo "Call Center Module removed successfully"
