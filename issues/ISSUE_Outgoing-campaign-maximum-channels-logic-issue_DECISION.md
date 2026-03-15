# Decision: Outgoing Campaign Maximum Channels Logic Issue

**Issue Title**: Outgoing-campaign-maximum-channels-logic-issue
**Date**: 2026-03-10
**Status**: Ready for Implementation

---

## Executive Summary

The `_countActiveCalls()` function in `CampaignProcess.class.php` (line 2412) only counts calls with status `("Placing", "Ringing", "OnQueue", "OnHold")`. It excludes calls with status `"Success"` (connected to an agent), causing the system to undercount active channels and place more calls than `max_canales` allows.

**Critical finding from investigation critique**: Simply adding `"Success"` to the IN clause would be **WRONG**. The `calls.status` column remains `"Success"` permanently even after a call ends — the hangup handler sets `end_time` but does NOT change the status back from `"Success"` for normal completed calls (see `Llamada.class.php:1216-1230`). Adding "Success" unconditionally would count ALL ever-successful calls in the campaign, immediately exhausting `max_canales`.

**Correct fix**: Count `"Success"` calls only when `end_time IS NULL` (call is still actively connected to an agent). When the call ends, `end_time` is set and the call drops out of the active count.

**Investigation Option 2 (NOT IN terminal statuses) is also flawed** because:
- It references `"Cancelled"` which doesn't exist as a status
- It omits `"ShortCall"` from terminal statuses
- It would still count ALL "Success" calls (both active and completed)

---

## Final Implementation Plan

### Step 1: Modify `_countActiveCalls()` query

**File**: `/opt/issabel/dialer/CampaignProcess.class.php`
**Line**: 2412

Change the SQL query to include "Success" calls that have no `end_time` (still connected):

**FROM:**
```php
$sPeticion = 'SELECT COUNT(*) FROM calls WHERE id_campaign = ? AND status IN ("Placing", "Ringing", "OnQueue", "OnHold")';
```

**TO:**
```php
$sPeticion = 'SELECT COUNT(*) FROM calls WHERE id_campaign = ? AND (status IN ("Placing", "Ringing", "OnQueue", "OnHold") OR (status = "Success" AND end_time IS NULL))';
```

### Step 2: Update the debug log message

**File**: `/opt/issabel/dialer/CampaignProcess.class.php`
**Lines**: 2420-2422

Update the debug message to reflect the new query scope.

**FROM:**
```php
$this->_log->output("DEBUG: ".__METHOD__.
    " campaign $campaignId has $result active calls (Placing/Ringing/OnQueue/OnHold) | ".
    "campaña $campaignId tiene $result llamadas activas (Placing/Ringing/OnQueue/OnHold)");
```

**TO:**
```php
$this->_log->output("DEBUG: ".__METHOD__.
    " campaign $campaignId has $result active calls (Placing/Ringing/OnQueue/OnHold/Connected) | ".
    "campaña $campaignId tiene $result llamadas activas (Placing/Ringing/OnQueue/OnHold/Conectada)");
```

### Step 3: Update the Pass 1 comment

**File**: `/opt/issabel/dialer/CampaignProcess.class.php`
**Lines**: 596-597

Update the comment to reflect the expanded count.

**FROM:**
```php
// Count active calls for this campaign (Placing, Ringing, OnQueue, OnHold)
// Contar llamadas activas para esta campaña
```

**TO:**
```php
// Count active calls for this campaign (Placing, Ringing, OnQueue, OnHold, Connected)
// Contar llamadas activas para esta campaña
```

### Step 4: Restart the dialer service

Apply the change and restart:
```bash
systemctl restart issabeldialer
```

---

## Exact Shell Commands for Fix Applier

```bash
# Step 1: Modify the SQL query in _countActiveCalls()
sed -i 's|SELECT COUNT(\*) FROM calls WHERE id_campaign = ? AND status IN ("Placing", "Ringing", "OnQueue", "OnHold")|SELECT COUNT(*) FROM calls WHERE id_campaign = ? AND (status IN ("Placing", "Ringing", "OnQueue", "OnHold") OR (status = "Success" AND end_time IS NULL))|' /opt/issabel/dialer/CampaignProcess.class.php

# Step 2: Update debug log message (English part)
sed -i 's|active calls (Placing/Ringing/OnQueue/OnHold)|active calls (Placing/Ringing/OnQueue/OnHold/Connected)|g' /opt/issabel/dialer/CampaignProcess.class.php

# Step 3: Update debug log message (Spanish part)
sed -i 's|llamadas activas (Placing/Ringing/OnQueue/OnHold)|llamadas activas (Placing/Ringing/OnQueue/OnHold/Conectada)|g' /opt/issabel/dialer/CampaignProcess.class.php

# Step 4: Update the Pass 1 comment
sed -i 's|Count active calls for this campaign (Placing, Ringing, OnQueue, OnHold)|Count active calls for this campaign (Placing, Ringing, OnQueue, OnHold, Connected)|' /opt/issabel/dialer/CampaignProcess.class.php

# Step 5: Restart the dialer
systemctl restart issabeldialer
```

---

## Why This Fix Is Correct

### Call Status Lifecycle (Outgoing)

```
NULL → Placing → Ringing → OnQueue → Success (end_time=NULL) → Success (end_time=SET)
                    ↓                     ↑                           │
                    ↓                  OnHold ←→ Success              │
                    ↓                                                 │
               Failure/NoAnswer                            ShortCall (if duration ≤ threshold)
```

- **Placing/Ringing/OnQueue/OnHold**: Call in progress, NOT yet connected → uses a channel
- **Success + end_time IS NULL**: Agent is actively on the call → uses a channel
- **Success + end_time IS NOT NULL**: Call completed normally → channel freed
- **ShortCall**: Call was too short → channel freed
- **Failure/NoAnswer/Abandoned**: Call failed → channel freed

### Scenario Walkthrough (max_canales=2)

| Time | Event | _countActiveCalls | effective_max | Calls Placed |
|------|-------|------------------|---------------|-------------|
| T+0  | Call A placed | 1 (A=Placing) | 1 | Yes |
| T+10 | Call B placed | 2 (A=Placing, B=Placing) | 0 | No more |
| T+20 | Call A answered | 2 (A=Success+no end_time, B=Placing) | 0 | No more ✓ |
| T+30 | Call B fails | 1 (A=Success+no end_time) | 1 | One more |
| T+60 | Call A hangs up | 0 (A=Success+end_time set) | 2 | Two more |

### Edge Cases Covered

1. **OnHold → Success**: When an agent puts a call on hold (OnHold, already counted) then takes it off hold (back to Success with end_time=NULL, still counted) ✓
2. **Multiple campaigns**: Each campaign counts independently via `id_campaign = ?` ✓
3. **Orphaned Placing calls**: Still cleaned up by `_cleanOrphanedPlacingCalls()` after 5 minutes ✓
4. **No max_canales limit**: When `max_canales=NULL` or `≤0`, set to `PHP_INT_MAX` → subtraction skipped ✓

### No Side Effects

- **Retry queries unaffected**: All retry/data-exhaustion queries use `status NOT IN ("Success", "Placing", ...)` which correctly excludes active calls. No change needed.
- **TWO-BUDGET logic**: Uses `effectiveMaxCanales` from Pass 1, which now includes connected calls in the subtraction. Automatically correct.
- **Agent Budget**: Independent of this change — counts pending originates, not DB status.
- **Performance**: Adding `OR (status = "Success" AND end_time IS NULL)` is negligible — very few calls are in this state at any time.

---

## Rollback Strategy

If the fix causes unexpected behavior:

```bash
# Revert to original query
sed -i 's|SELECT COUNT(\*) FROM calls WHERE id_campaign = ? AND (status IN ("Placing", "Ringing", "OnQueue", "OnHold") OR (status = "Success" AND end_time IS NULL))|SELECT COUNT(*) FROM calls WHERE id_campaign = ? AND status IN ("Placing", "Ringing", "OnQueue", "OnHold")|' /opt/issabel/dialer/CampaignProcess.class.php

# Revert debug messages
sed -i 's|active calls (Placing/Ringing/OnQueue/OnHold/Connected)|active calls (Placing/Ringing/OnQueue/OnHold)|g' /opt/issabel/dialer/CampaignProcess.class.php
sed -i 's|llamadas activas (Placing/Ringing/OnQueue/OnHold/Conectada)|llamadas activas (Placing/Ringing/OnQueue/OnHold)|g' /opt/issabel/dialer/CampaignProcess.class.php
sed -i 's|Count active calls for this campaign (Placing, Ringing, OnQueue, OnHold, Connected)|Count active calls for this campaign (Placing, Ringing, OnQueue, OnHold)|' /opt/issabel/dialer/CampaignProcess.class.php

# Restart dialer
systemctl restart issabeldialer
```

---

## Log Collecting Pattern

### Verify the fix is working

```bash
# Watch active call counting in real-time (should now include Connected calls)
grep -E "_countActiveCalls|active_calls=|TWO-BUDGET|effective_max=" /opt/issabel/dialer/dialerd.log | tail -200
```

### Verify max_canales is respected

```bash
# Check that effective_max never goes negative and total placed calls don't exceed max_canales
grep -E "Pass 1:.*max_canales=|TWO-BUDGET.*channel_budget=" /opt/issabel/dialer/dialerd.log | tail -100
```

### Check for the specific bug scenario (answered call + new placement)

```bash
# Look for Success status changes followed by call placements
grep -E "new_status.*Success|Originat|_countActiveCalls" /opt/issabel/dialer/dialerd.log | tail -200
```

---

## Test Steps

1. Set `max_canales=2` for an outgoing campaign
2. Have 2+ agents logged in
3. Start the campaign and observe:
   - When 2 calls are placing: no more calls should be placed (effective_max=0)
   - When 1 call is answered (Success) and 1 is still placing: still no more calls (effective_max=0, both counted)
   - When 1 call is answered and the other fails: effective_max=1, one more call can be placed
   - Total concurrent channels should NEVER exceed max_canales
4. Verify with: `grep "active calls" /opt/issabel/dialer/dialerd.log | tail -50`
