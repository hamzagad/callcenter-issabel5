# Decision: Outgoing Campaign Calls Count Less Than Expected

## Executive Summary

Orphaned `Success` calls with `end_time IS NULL` from previous dialer sessions (22+ days old) inflate `_countActiveCalls()`, consuming the entire channel budget (`effective_max = 0`) and preventing new calls. The root cause is a gap in Change #46 (Placing cleanup) — it was never extended to other active statuses. Change #47 then exposed this gap by adding `Success` calls with `NULL end_time` to the active call count.

**Fix strategy: Comprehensive two-layer cleanup (startup + runtime).**

- **Startup**: Clean ALL active-status calls (Placing, Ringing, OnQueue, OnHold, Success with NULL end_time) — no call survives a dialer restart.
- **Runtime**: Safety net for individually missed Hangup events during normal operation.

---

## Critique of Investigation Proposal

### 1. Callback extension calls DO NOT survive restart

The investigation did not address whether callback-type agent calls (SIP/PJSIP/IAX2) should be preserved. Analysis confirms they **cannot survive a restart**:

- At startup, `current_calls` and `current_call_entry` tables are wiped (line 146-147)
- All in-memory `Llamada` objects are destroyed (process restart = new objects)
- Even if Asterisk channels remain bridged (agent still talking to customer), the dialer has lost all tracking — it cannot process their Hangup events
- The Llamada state machine (`Placing → Ringing → OnQueue → Success → Hangup`) exists only in-memory

**Conclusion**: Startup cleanup is unconditionally safe for ALL statuses, regardless of agent type (Agent, SIP, PJSIP, IAX2).

### 2. No MySQL date functions — calculate in PHP

The investigation used `NOW()`, `DATE_SUB()`, and `COALESCE()` in SQL queries. Per project convention, all date/time calculations must be done in PHP before query execution:

- `NOW()` → `$sNow = date('Y-m-d H:i:s');`
- `DATE_SUB(NOW(), INTERVAL N HOUR)` → `$sThreshold = date('Y-m-d H:i:s', time() - $iSeconds);`
- `COALESCE(start_time, datetime_originate, NOW())` → use `end_time = start_time` (column reference, not a function) — this gives 0 duration which is acceptable for orphaned calls and avoids polluting average duration stats with fake multi-day durations

### 3. ALL active statuses must be cleaned at restart

The investigation only addressed `Success` and `Placing`. But **every pre-hangup status** is vulnerable:

| Status | Current Cleanup | Risk | Proposed Action at Startup |
|--------|----------------|------|---------------------------|
| **Placing** | YES (line 157) | Blocks channel budget | → `Failure` (existing) |
| **Ringing** | NO | Blocks channel budget | → `Failure` (same as Placing — never connected) |
| **OnQueue** | NO | Blocks channel budget | → `Failure` (entered queue but never linked to agent) |
| **OnHold** | NO | Blocks channel budget | → `Hangup` with `end_time = start_time` (was connected) |
| **Success** (NULL end_time) | NO | Blocks channel budget | → `Hangup` with `end_time = start_time` (was connected) |

### 4. Side effect on `_checkCampaignDataExhausted`

This method (line 2437) also calls `_countActiveCalls()`. Orphaned calls prevented campaigns from being marked finished when data ran out. The fix resolves this as well.

### 5. 12-hour runtime threshold too conservative

Reduced to **4 hours** — no call center call lasts 4 hours; 12h leaves the system broken too long after a missed hangup event.

---

## Final Implementation Plan

### Step 1: Extend startup Placing cleanup to include Ringing and OnQueue

**File**: `/opt/issabel/dialer/CampaignProcess.class.php`
**Location**: Existing startup cleanup (lines 157-159)

Modify the existing `WHERE status = 'Placing'` to `WHERE status IN ('Placing', 'Ringing', 'OnQueue')`. These are all pre-connection statuses that should become `Failure`.

### Step 2: Add startup cleanup for connected calls (OnHold + Success)

**File**: `/opt/issabel/dialer/CampaignProcess.class.php`
**Location**: After the existing orphaned Placing cleanup (after line 168)

New try/catch block that finalizes `OnHold` and `Success` (with NULL end_time) calls to `Hangup` with `end_time = start_time`. Timestamp calculated in PHP.

### Step 3: Extend runtime `_cleanOrphanedPlacingCalls()` to include Ringing

**File**: `/opt/issabel/dialer/CampaignProcess.class.php`
**Location**: Existing method (line 2395-2397)

Extend `WHERE status = 'Placing'` to `WHERE status IN ('Placing', 'Ringing')` — same 5-minute timeout applies.

### Step 4: Add runtime cleanup method `_cleanOrphanedConnectedCalls()`

**File**: `/opt/issabel/dialer/CampaignProcess.class.php`
**Location**: After `_cleanOrphanedPlacingCalls()` method (after line 2408)

New private method that cleans `Success` calls (and `OnHold` calls) with NULL end_time older than 4 hours.

### Step 5: Call runtime cleanup in `_actualizarCampanias()`

**File**: `/opt/issabel/dialer/CampaignProcess.class.php`
**Location**: After `_cleanOrphanedPlacingCalls()` call (after line 434)

---

## Exact Shell Commands for Fix Applier

### Command 1: Backup the file before modification
```bash
/bin/cp /opt/issabel/dialer/CampaignProcess.class.php /opt/issabel/dialer/CampaignProcess.class.php.bak.$(date +%Y%m%d%H%M%S)
```

### Command 2: Code changes (5 edits to CampaignProcess.class.php)

**Edit A — Extend startup Placing cleanup to include Ringing and OnQueue (modify lines 157-159):**

Change the existing cleanup from:
```php
            $sCleanup = "UPDATE calls SET status = 'Failure', failure_cause = 0, "
                . "failure_cause_txt = 'Orphaned call at startup' "
                . "WHERE status = 'Placing'";
```

To:
```php
            $sCleanup = "UPDATE calls SET status = 'Failure', failure_cause = 0, "
                . "failure_cause_txt = 'Orphaned call at startup' "
                . "WHERE status IN ('Placing', 'Ringing', 'OnQueue')";
```

Update the log message (lines 162-163) from:
```php
                $this->_log->output("INFO: cleaned $iCleaned orphaned Placing calls at startup | "
                    . "limpiadas $iCleaned llamadas Placing huérfanas al inicio");
```

To:
```php
                $this->_log->output("INFO: cleaned $iCleaned orphaned Placing/Ringing/OnQueue calls at startup | "
                    . "limpiadas $iCleaned llamadas Placing/Ringing/OnQueue huérfanas al inicio");
```

Update the error log (lines 166-167) from:
```php
            $this->_log->output("ERR: error cleaning orphaned Placing calls - ".$e->getMessage()
                ." | error limpiando llamadas Placing huérfanas - ".$e->getMessage());
```

To:
```php
            $this->_log->output("ERR: error cleaning orphaned Placing/Ringing/OnQueue calls - ".$e->getMessage()
                ." | error limpiando llamadas Placing/Ringing/OnQueue huérfanas - ".$e->getMessage());
```

**Edit B — Add startup cleanup for connected calls (insert after line 168):**

After the closing `}` of the existing Placing cleanup try/catch block, insert:

```php

        // Clean up orphaned connected calls (OnHold/Success) from previous abnormal termination
        // Limpiar llamadas conectadas huérfanas (OnHold/Success) de una terminación anormal anterior
        try {
            $sStartupTime = date('Y-m-d H:i:s');
            $sCleanup = "UPDATE calls SET status = 'Hangup', end_time = CASE "
                . "WHEN start_time IS NOT NULL THEN start_time "
                . "ELSE ? END "
                . "WHERE (status = 'OnHold' OR (status = 'Success' AND end_time IS NULL))";
            $sth = $this->_db->prepare($sCleanup);
            $sth->execute(array($sStartupTime));
            $iCleaned = $sth->rowCount();
            if ($iCleaned > 0) {
                $this->_log->output("INFO: cleaned $iCleaned orphaned OnHold/Success calls at startup | "
                    . "limpiadas $iCleaned llamadas OnHold/Success huérfanas al inicio");
            }
        } catch (PDOException $e) {
            $this->_log->output("ERR: error cleaning orphaned OnHold/Success calls - ".$e->getMessage()
                ." | error limpiando llamadas OnHold/Success huérfanas - ".$e->getMessage());
        }
```

**Edit C — Extend runtime Placing cleanup to include Ringing (modify line 2395-2397):**

Change from:
```php
        $sPeticion = "UPDATE calls SET status = 'Failure', failure_cause = 0, "
            . "failure_cause_txt = 'Orphaned call at startup' "
            . "WHERE status = 'Placing' AND datetime_originate < ?";
```

To:
```php
        $sPeticion = "UPDATE calls SET status = 'Failure', failure_cause = 0, "
            . "failure_cause_txt = 'Orphaned call - timeout' "
            . "WHERE status IN ('Placing', 'Ringing') AND datetime_originate < ?";
```

Update the log message (lines 2403-2406) from:
```php
            $this->_log->output("WARN: cleaned $iCleaned orphaned Placing calls "
                . "(older than $iPlacingTimeout seconds) | "
                . "limpiadas $iCleaned llamadas Placing huérfanas "
                . "(más antiguas de $iPlacingTimeout segundos)");
```

To:
```php
            $this->_log->output("WARN: cleaned $iCleaned orphaned Placing/Ringing calls "
                . "(older than $iPlacingTimeout seconds) | "
                . "limpiadas $iCleaned llamadas Placing/Ringing huérfanas "
                . "(más antiguas de $iPlacingTimeout segundos)");
```

**Edit D — Add runtime connected call cleanup method (insert after line 2408):**

After the closing `}` of `_cleanOrphanedPlacingCalls()`, insert:

```php

    /**
     * Clean up orphaned connected calls that never got end_time set.
     * These are calls that were connected but the hangup event was missed.
     *
     * Limpiar llamadas conectadas huérfanas que nunca recibieron end_time.
     * Son llamadas que fueron conectadas pero el evento Hangup se perdió.
     */
    private function _cleanOrphanedConnectedCalls()
    {
        // Connected calls older than 2 hours with no end_time are definitely orphaned
        // Llamadas conectadas de más de 2 horas sin end_time son definitivamente huérfanas
        $iConnectedTimeout = 7200; // 2 hours in seconds
        $sThreshold = date('Y-m-d H:i:s', time() - $iConnectedTimeout);

        $sPeticion = "UPDATE calls SET status = 'Hangup', end_time = start_time "
            . "WHERE (status = 'Success' OR status = 'OnHold') "
            . "AND end_time IS NULL "
            . "AND start_time IS NOT NULL "
            . "AND start_time < ?";
        $sth = $this->_db->prepare($sPeticion);
        $sth->execute(array($sThreshold));
        $iCleaned = $sth->rowCount();

        if ($iCleaned > 0) {
            $this->_log->output("WARN: cleaned $iCleaned orphaned connected calls "
                . "(older than " . ($iConnectedTimeout / 3600) . " hours) | "
                . "limpiadas $iCleaned llamadas conectadas huérfanas "
                . "(más antiguas de " . ($iConnectedTimeout / 3600) . " horas)");
        }
    }
```

**Edit E — Call runtime connected cleanup in `_actualizarCampanias()` (insert after line 434):**

After `$this->_cleanOrphanedPlacingCalls();` (line 434), insert:

```php
            // Clean up orphaned connected calls (timeout-based)
            // Limpiar llamadas conectadas huérfanas (basado en timeout)
            $this->_cleanOrphanedConnectedCalls();
```

---

## Rollback Strategy

### Quick rollback (restore backup):
```bash
/bin/cp /opt/issabel/dialer/CampaignProcess.class.php.bak.* /opt/issabel/dialer/CampaignProcess.class.php
systemctl restart issabeldialer
```

### If rollback is needed but calls were already cleaned:
The cleaned calls were already orphaned — their status change does not lose meaningful data. No reversal of the SQL cleanup is needed.

### If the runtime cleanup is too aggressive (false positives):
Increase the timeout from 4 hours to 8 or 12 hours by changing `$iConnectedTimeout = 14400` to `28800` or `43200`.

---

## Test Steps

1. Skip this entry
2. Verify orphaned records are cleared:
   ```bash
   mysql -u root -p$(grep mysqlrootpwd /etc/issabel.conf | cut -d= -f2) call_center -e "SELECT id, status, end_time FROM calls WHERE status IN ('Placing','Ringing','OnQueue','OnHold') OR (status = 'Success' AND end_time IS NULL);"
   ```
3. Apply code changes (Command 3, Edits A-E)
4. Skip this entry
5. Check startup cleanup ran:
   ```bash
   grep -E "orphaned.*(Placing|Ringing|OnQueue|OnHold|Success|connected)" /opt/issabel/dialer/dialerd.log | tail -20
   ```
6. Monitor campaign is placing calls correctly (with 4 agents + 6 max channels, expect 4 concurrent calls):
   ```bash
   grep -E "calls_to_place=[1-9]|channel_budget=[1-9]" /opt/issabel/dialer/dialerd.log | tail -20
   ```
7. Verify TWO-BUDGET calculation shows correct active call count:
   ```bash
   grep "TWO-BUDGET" /opt/issabel/dialer/dialerd.log | tail -10
   ```

---

## Log Collecting Pattern

### Verify orphaned calls were cleaned at startup:
```bash
grep -E "orphaned.*(Placing|Ringing|OnQueue|OnHold|Success|connected)" /opt/issabel/dialer/dialerd.log | tail -20
```

### Monitor active call counts after fix:
```bash
grep -E "_countActiveCalls|channel_budget|calls_to_place" /opt/issabel/dialer/dialerd.log | tail -50
```

### Verify no remaining orphaned calls in database:
```bash
mysql -u root -p$(grep mysqlrootpwd /etc/issabel.conf | cut -d= -f2) call_center -e "
SELECT status, COUNT(*) as count FROM calls
WHERE status IN ('Placing','Ringing','OnQueue','OnHold')
   OR (status = 'Success' AND end_time IS NULL)
GROUP BY status;"
```

### Monitor runtime cleanup activity:
```bash
grep -E "orphaned.*(Placing|Ringing|connected)" /opt/issabel/dialer/dialerd.log | tail -10
```

### Full diagnostic (before/after comparison):
```bash
grep "TWO-BUDGET" /opt/issabel/dialer/dialerd.log | tail -10
```

---

*Decision Date: 2026-03-19*
*Reviewer: Claude Code Thinker*
*Revision: 2 — Addressed callback survival, PHP date convention, all-status cleanup*
