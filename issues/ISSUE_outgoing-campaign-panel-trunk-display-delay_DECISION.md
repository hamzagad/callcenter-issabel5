# Decision: Outgoing Campaign Panel Trunk Display Delay

## Executive Summary

The trunk is not displayed immediately in the Outgoing Campaign Panel when a new outgoing call enters "Placing" status. This is because `marcarLlamada()` passes the trunk value to the progress notification but never stores it on the `Llamada` object itself (`$this->_trunk`). When the panel polls `resumenLlamada()`, it returns NULL for trunk. The trunk only appears later when the Asterisk channel is created and the channel setter derives the trunk from the channel name.

Two fixes are required:
1. **Primary (dialer):** Set `$this->_trunk` in `marcarLlamada()` so the trunk is available immediately.
2. **Secondary (web panel):** Include trunk in `computeStateHash()` so that any late trunk changes trigger a frontend update via SSE.

### Critique of Suggested Fix

The investigation's proposed fix is correct and safe. Key analysis:

- **No conflict with channel setter (line 414-415):** When `channel` is later set, it overwrites `_trunk` with a channel-derived value, which is fine since the real channel trunk should match the campaign trunk.
- **No conflict with actualchannel setter (line 434-439):** For Local channel calls, the sequence is: early trunk set → overwritten by Local channel → correctly restored by actualchannel setter. The `strpos($this->_trunk, 'Local/') === 0` condition still works correctly.
- **NULL trunk campaigns handled:** When `$trunk` is NULL (dial-plan routing), the `if (!is_null($trunk))` guard prevents setting a NULL value, preserving the existing channel-derivation behavior.
- **No impact on retried/failed calls:** The `_cb_Originate` failure path (line 625) uses the trunk parameter for progress notification only, not for `_trunk` assignment. Fix 1 sets trunk before originate, so it's already available.

## Final Implementation Plan

### Step 1: Set trunk on Llamada object in marcarLlamada()

**File:** `/opt/issabel/dialer/Llamada.class.php`
**Location:** Inside `marcarLlamada()`, after line 598 (after `$paramProgreso` array), before the `_tuberia->msg_SQLWorkerProcess_notificarProgresoLlamada` call.

Add:
```php
        // Set trunk on the object so resumenLlamada() can return it immediately
        // ES: Establecer trunk en el objeto para que resumenLlamada() lo retorne de inmediato
        if (!is_null($trunk)) {
            $this->_trunk = $trunk;
        }
```

### Step 2: Include trunk in computeStateHash() fingerprint

**File:** `/var/www/html/modules/rep_outgoing_campaigns_panel/index.php`
**Location:** Line 386, inside the active calls fingerprint loop.

Change:
```php
$fingerprint .= ',' . (isset($call['callid']) ? $call['callid'] : '') . ':' . (isset($call['callstatus']) ? $call['callstatus'] : '');
```
To:
```php
$fingerprint .= ',' . (isset($call['callid']) ? $call['callid'] : '') . ':' . (isset($call['callstatus']) ? $call['callstatus'] : '') . ':' . (isset($call['trunk']) ? $call['trunk'] : '');
```

### Step 3: Restart the dialer daemon

```bash
systemctl restart issabeldialer
```

## Exact Shell Commands for Fix Applier

### Fix 1 - Llamada.class.php (dialer side)

```bash
# Backup
/bin/cp /opt/issabel/dialer/Llamada.class.php /opt/issabel/dialer/Llamada.class.php.bak.trunk-display

# Apply fix: Add trunk assignment after $paramProgreso definition in marcarLlamada()
sed -i '/msg_SQLWorkerProcess_notificarProgresoLlamada(\$paramProgreso);/{
    i\        // Set trunk on the object so resumenLlamada() can return it immediately\n        // ES: Establecer trunk en el objeto para que resumenLlamada() lo retorne de inmediato\n        if (!is_null($trunk)) {\n            $this->_trunk = $trunk;\n        }\n
}' /opt/issabel/dialer/Llamada.class.php
```

### Fix 2 - rep_outgoing_campaigns_panel/index.php (web side)

```bash
# Backup
/bin/cp /var/www/html/modules/rep_outgoing_campaigns_panel/index.php /var/www/html/modules/rep_outgoing_campaigns_panel/index.php.bak.trunk-display

# Apply fix: Add trunk to active calls fingerprint in computeStateHash()
sed -i "s|\$fingerprint .= ',' . (isset(\$call\['callid'\]) ? \$call\['callid'\] : '') . ':' . (isset(\$call\['callstatus'\]) ? \$call\['callstatus'\] : '');|\$fingerprint .= ',' . (isset(\$call['callid']) ? \$call['callid'] : '') . ':' . (isset(\$call['callstatus']) ? \$call['callstatus'] : '') . ':' . (isset(\$call['trunk']) ? \$call['trunk'] : '');|" /var/www/html/modules/rep_outgoing_campaigns_panel/index.php
```

### Step 3 - Restart dialer

```bash
systemctl restart issabeldialer
```

## Rollback Strategy

```bash
# Restore Llamada.class.php
/bin/cp /opt/issabel/dialer/Llamada.class.php.bak.trunk-display /opt/issabel/dialer/Llamada.class.php

# Restore rep_outgoing_campaigns_panel/index.php
/bin/cp /var/www/html/modules/rep_outgoing_campaigns_panel/index.php.bak.trunk-display /var/www/html/modules/rep_outgoing_campaigns_panel/index.php

# Restart dialer
systemctl restart issabeldialer
```

## Log Collecting Pattern

```bash
# Monitor dialer for trunk assignment during call placement
tail -f /opt/issabel/dialer/dialerd.log | grep -E "(trunk|Placing|marcarLlamada|resumenLlamada)"

# Monitor ECCP campaign status requests (to see trunk in responses)
tail -f /opt/issabel/dialer/dialerd.log | grep -i "getcampaignstatus"

# Monitor web panel SSE for hash changes
tail -f /var/log/httpd/ssl_error_log | grep -i "rep_outgoing_campaigns_panel"
```

## Test Steps

1. Start an outgoing campaign with an explicit trunk configured
2. Open the "Outgoing Campaign Panel" (`rep_outgoing_campaigns_panel`)
3. Wait for new calls to be dialed (status "Placing" or "Ringing")
4. **Verify:** Trunk column should show the trunk name immediately when call enters "Placing" status
5. **Verify:** No need to wait for channel creation or page refresh
6. Also test a campaign with no trunk (dial plan routing) to ensure it still works correctly (trunk shows "-" initially, then appears when channel is created)
