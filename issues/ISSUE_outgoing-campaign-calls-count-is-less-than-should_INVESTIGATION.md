# Investigation: Outgoing Campaign Calls Count Less Than Expected

## Observation Summary

**User Report:**
- 1 enabled outgoing campaign (OutC1)
- 6 Max. used channels configured
- 4 logged-in agents
- **Observed:** Only 2 calls generated concurrently
- **Expected:** 4 calls (matching number of agents)

## Root Cause Analysis

### Immediate Cause
The `_countActiveCalls()` function reports 6 active calls when only 2 are actually in progress. This causes the channel budget calculation to result in `effective_max = 0`, preventing new calls from being placed.

### Evidence from Logs
```
2026-03-19 12:58:24 PID=235949 : (CampaignProcess) DEBUG: CampaignProcess::_countActiveCalls campaign 1 has 6 active calls (Placing/Ringing/OnQueue/OnHold/Connected)

2026-03-19 12:58:24 PID=235949 : (CampaignProcess) DEBUG: CampaignProcess::_processCampaignWithAllocation (campaign 1 queue 502) TWO-BUDGET: allocated=4 [Agent/4007,Agent/4004,Agent/4002,Agent/4003], predictive_boost=0, pending_originate=2, scheduled_this_cycle=0, agent_budget=2, channel_budget=0, effective_max=0, calls_to_place=0
```

### Database Evidence
```sql
SELECT id, status, phone, datetime_originate, start_time, end_time,
       TIMESTAMPDIFF(HOUR, start_time, NOW()) as hours_ago
FROM calls WHERE id_campaign = 1
  AND (status IN ('Placing', 'Ringing', 'OnQueue', 'OnHold')
       OR (status = 'Success' AND end_time IS NULL))
ORDER BY id DESC;
```

| id | status | phone | datetime_originate | start_time | end_time | hours_ago |
|----|--------|-------|-------------------|------------|----------|-----------|
| 15384 | Placing | 01006603236 | 2026-03-19 12:58:18 | NULL | NULL | NULL (active) |
| 15383 | Placing | 01029986043 | 2026-03-19 12:58:15 | NULL | NULL | NULL (active) |
| 11775 | Success | 01090496422 | 2026-02-25 14:15:30 | 2026-02-25 14:15:46 | NULL | **526** |
| 11691 | Success | 01066696719 | 2026-02-25 14:01:43 | 2026-02-25 14:02:03 | NULL | **526** |
| 11528 | Success | 01050118191 | 2026-02-25 13:39:06 | 2026-02-25 13:39:23 | NULL | **527** |
| 11466 | Success | 01030077338 | 2026-02-25 13:35:56 | 2026-02-25 13:36:11 | NULL | **527** |

**4 orphaned calls from February 25th (22+ days old) are stuck in `Success` status with `end_time = NULL`.**

## Log References

### Key Files and Line Numbers

| File | Line | Function | Description |
|------|------|----------|-------------|
| `/opt/issabel/dialer/CampaignProcess.class.php` | 2410-2426 | `_countActiveCalls()` | Counts active calls including stale `Success` records |
| `/opt/issabel/dialer/CampaignProcess.class.php` | 1057-1065 | `_processCampaignWithAllocation()` | Calculates channel_budget from effective_max |
| `/opt/issabel/dialer/CampaignProcess.class.php` | 1067-1069 | `_processCampaignWithAllocation()` | Final calls = min(agent_budget, channel_budget) |
| `/opt/issabel/dialer/Llamada.class.php` | 1223-1228 | `llamadaFinalizaSeguimiento()` | Sets `end_time` when call is finalized |

### SQL Query Causing the Issue
```php
// CampaignProcess.class.php:2412
$sPeticion = 'SELECT COUNT(*) FROM calls WHERE id_campaign = ? AND (status IN ("Placing", "Ringing", "OnQueue", "OnHold") OR (status = "Success" AND end_time IS NULL))';
```

## Related Previous Fixes

### Change #46 (2026-03-08): Orphaned "Placing" Call Cleanup
- Added startup cleanup: Mark all "Placing" calls as "Failure" at dialer startup
- Added runtime cleanup: Auto-expire "Placing" calls >5 minutes
- **Gap:** Did NOT address orphaned "Success" calls with NULL end_time

### Change #47 (2026-03-10): Maximum Channels Logic Fix
- Modified `_countActiveCalls()` to include `Success` calls with NULL end_time
- **This exposed the gap:** Now orphaned "Success" calls are counted as active, blocking channel budget

## Suspected Root Cause

**Orphaned Success Records:** The 4 calls from February 25th were never properly finalized. The `llamadaFinalizaSeguimiento()` method should set `end_time`, but these calls were orphaned before that could happen. Possible causes:

1. Dialer daemon crashed/restarted while calls were in `Success` status
2. Asterisk restart without proper dialer shutdown
3. Network issue causing Hangup event to be missed
4. Bug in `msg_Hangup` handler for specific call scenarios

The fix in #46 only handles orphaned "Placing" calls, not orphaned "Success" calls with NULL end_time.

## Algorithm Flow

```
_actualizarCampanias()
    ↓
_countActiveCalls() → returns 6 (2 Placing + 4 orphaned Success)
    ↓
effective_max = max_canales - active_calls = 6 - 6 = 0
    ↓
_processCampaignWithAllocation()
    ↓
channel_budget = effective_max = 0
    ↓
calls_to_place = min(agent_budget, channel_budget) = min(2, 0) = 0
    ↓
NO NEW CALLS PLACED
```

## Suggested Fix Proposal

### Immediate Fix (Clear Current Orphaned Records)
```sql
-- Fix existing orphaned Success records older than 1 hour
UPDATE calls
SET end_time = start_time, status = 'Hangup'
WHERE status = 'Success'
  AND end_time IS NULL
  AND start_time < DATE_SUB(NOW(), INTERVAL 1 HOUR);
```

### Long-term Fix: Add Orphaned Success Call Cleanup

Add to `CampaignProcess.class.php` similar to the existing `_cleanOrphanedPlacingCalls()`:

```php
/**
 * Clean up orphaned Success calls that never got end_time set.
 * These are calls that were connected but the hangup event was missed.
 */
private function _cleanOrphanedSuccessCalls()
{
    // Success calls older than 12 hours with no end_time are definitely orphaned
    $sPeticion = "UPDATE calls SET end_time = start_time, status = 'Hangup'
                  WHERE status = 'Success' AND end_time IS NULL
                  AND start_time IS NOT NULL
                  AND start_time < DATE_SUB(NOW(), INTERVAL 12 HOUR)";
    $sth = $this->_db->prepare($sPeticion);
    $sth->execute();
    $iCleaned = $sth->rowCount();

    if ($iCleaned > 0) {
        $this->_log->output("WARN: cleaned $iCleaned orphaned Success calls "
            . "(older than 12 hours) | "
            . "limpiadas $iCleaned llamadas Success huérfanas "
            . "(más antiguas de 12 horas)");
    }
}
```

Call this method at the start of `_actualizarCampanias()` alongside the existing Placing cleanup.

### Alternative: Startup Cleanup Only

Add to the startup cleanup in `CampaignProcess` constructor (around line 150-165):

```php
// Clean up orphaned Success calls from previous runs
$sPeticion = "UPDATE calls SET end_time = start_time, status = 'Hangup'
              WHERE status = 'Success' AND end_time IS NULL
              AND start_time IS NOT NULL";
$sth = $this->_db->prepare($sPeticion);
$sth->execute();
```

## Test Steps

1. **Apply immediate fix:**
   ```bash
   mysql -u root -pPrimasoft call_center -e "UPDATE calls SET end_time = start_time, status = 'Hangup' WHERE status = 'Success' AND end_time IS NULL AND start_time < DATE_SUB(NOW(), INTERVAL 1 HOUR);"
   ```

2. **Verify fix:**
   ```bash
   mysql -u root -pPrimasoft call_center -e "SELECT COUNT(*) as orphaned FROM calls WHERE id_campaign = 1 AND status = 'Success' AND end_time IS NULL;"
   ```

3. **Monitor logs for new call generation:**
   ```bash
   tail -f /opt/issabel/dialer/dialerd.log | grep -E "(calls_to_place|channel_budget)"
   ```

4. **Verify expected behavior:**
   - With 4 agents and 6 max channels, expect 4 concurrent calls (limited by agent count)

## Grep Commands for Log Collection

```bash
# Check active call counts
grep -E "_countActiveCalls|channel_budget|calls_to_place" /opt/issabel/dialer/dialerd.log | tail -50

# Check for orphaned Success calls in database
mysql -u root -pPrimasoft call_center -e "SELECT id, status, datetime_originate, start_time, end_time FROM calls WHERE status = 'Success' AND end_time IS NULL;"

# Monitor campaign processing
grep "CAMPAIGN REVIEW CYCLE" /opt/issabel/dialer/dialerd.log | tail -10

# Check if cleanup is running
grep -E "orphaned Success|limpiadas.*Success" /opt/issabel/dialer/dialerd.log | tail -10
```

---
*Investigation Date: 2026-03-19*
*Investigator: Claude Code Troubleshooter*
