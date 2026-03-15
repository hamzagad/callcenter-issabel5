# Investigation: Outgoing Campaign Maximum Channels Logic Issue

**Issue Title**: Outgoing-campaign-maximum-channels-logic-issue
**Date**: 2026-03-10
**Status**: Root Cause Identified

---

## Observation Summary

Outgoing campaigns generate more concurrent calls than the configured "Max. used channels" (max_canales) setting. User reported seeing 3 concurrent calls (2 "Placing" + 1 answered/Busy agent) when max_canales was set to 2.

**Evidence**: `issues/proof.png` shows:
- Call 73270: Placing at 13:46:09
- Call 73271: Placing at 13:46:31
- Agent/4010: Busy (on call 73269 answered at 13:46:21)
- Total: 3 concurrent operations when max_canales=2
---

## Log References

### Key Log File
- `/opt/issabel/dialer/dialerd.log` (lines 6023113-6042347)

### Timeline Analysis

| Time | Event | active_calls | effective_max | Notes |
|------|-------|--------------|---------------|-------|
| 13:46:00 | Call 73269 placed | 1 | 1 | Call 73267 still active |
| 13:46:07 | Call 73267 ends (Failure) | - | - | OriginateResponse Failure |
| 13:46:09 | _countActiveCalls | 1 | 1 | Only 73269 counted (Placing) |
| 13:46:09 | Call 73270 placed | - | - | Allowed because effective_max=1 |
| 13:46:21 | Call 73269 answered | - | - | Status changes to "Success" |
| 13:46:31 | _countActiveCalls | 1 | 1 | **BUG**: 73269 NOT counted (Success) |
| 13:46:31 | Call 73271 placed | - | - | **BUG**: Should not be placed! |
| 13:46:40 | Call 73270 ends (Failure) | - | - | OriginateResponse Failure |

### Critical Log Entries

```
# At 13:46:31 - The problematic cycle
Line 6037643: CampaignProcess::_actualizarCampanias (campaign 22 queue 502) Pass 1:
              wants agents [Agent/4004,Agent/4003], max_canales=2, active_calls=1, effective_max=1

Line 6037652: AMIEventProcess::rpc_contarLlamadasEsperandoRespuesta:
              llamada 55349-22-73270 espera respuesta desde hace 22 segundos.
              (Call 73270 was placing but only 1 active call was counted!)
```

---

## Root Cause Analysis

### The Bug

**Location**: `CampaignProcess.class.php:2410-2418`

```php
private function _countActiveCalls($campaignId)
{
    $sPeticion = 'SELECT COUNT(*) FROM calls WHERE id_campaign = ? AND status IN ("Placing", "Ringing", "OnQueue", "OnHold")';
    // ...
}
```

**Problem**: The function only counts calls with status `("Placing", "Ringing", "OnQueue", "OnHold")`. It does NOT count calls with status "Success" (answered and connected).

### Why This Causes Over-Placement

1. Call 73269 is placed and starts ringing → counted as active
2. Call 73269 is answered by agent → status changes to "Success" → **NO LONGER COUNTED**
3. `_countActiveCalls` now returns a lower number
4. `effective_max = max_canales - active_calls` becomes artificially high
5. System places additional calls even though an agent is already on a call

### The Flow

```
Agent available → Place call → Call rings → Agent answers → Status="Success"
                                                      ↓
                                        _countActiveCalls EXCLUDES this call!
                                                      ↓
                                        effective_max becomes higher
                                                      ↓
                                        MORE calls placed than max_canales allows
```

---

## Suspected Files/Functions

| File | Line | Function | Issue |
|------|------|----------|-------|
| `CampaignProcess.class.php` | 2410-2418 | `_countActiveCalls()` | Excludes "Success" status from count |
| `CampaignProcess.class.php` | 596 | `_actualizarCampanias()` | Uses incorrect active count |
| `CampaignProcess.class.php` | 1044-1069 | `_processCampaignWithAllocation()` | TWO-BUDGET logic uses flawed count |

---

## Suggested Fix Proposal

### Option 1: Include "Success" Status (Simple)

Change line 2412 from:
```php
$sPeticion = 'SELECT COUNT(*) FROM calls WHERE id_campaign = ? AND status IN ("Placing", "Ringing", "OnQueue", "OnHold")';
```

To:
```php
$sPeticion = 'SELECT COUNT(*) FROM calls WHERE id_campaign = ? AND status IN ("Placing", "Ringing", "OnQueue", "OnHold", "Success")';
```

**Pros**: Simple fix, minimal code change
**Cons**: May not cover all edge cases (other connected statuses)

### Option 2: Count All Non-Terminal Calls (Recommended)

Change to count all calls that are NOT in a terminal/final state:
```php
$sPeticion = 'SELECT COUNT(*) FROM calls WHERE id_campaign = ? AND status IS NOT NULL AND status NOT IN ("NoAnswer", "Failure", "Cancelled", "Abandoned")';
```

**Pros**: Covers all connected/in-progress states
**Cons**: Slightly more complex query

### Option 3: Track Connected Calls Separately

Add a separate counter for connected calls and include it in the channel budget calculation:
```php
$iConnectedCalls = $this->_countConnectedCalls($idCampaign);
$effectiveMaxCanales = $maxCanales - $iActiveCalls - $iConnectedCalls;
```

**Pros**: More explicit tracking
**Cons**: More complex implementation

---

## Verification Steps

After applying the fix, verify with:

1. **Grep command to collect logs**:
   ```bash
   grep -E "campaign 22.*active_calls|_countActiveCalls.*campaign 22" /opt/issabel/dialer/dialerd.log | tail -100
   ```

2. **Test scenario**:
   - Set max_canales=2 for a campaign
   - Have 2 agents logged in
   - Verify that when 1 call is answered, no more calls are placed until it ends
   - Total concurrent calls should never exceed max_canales

3. **Expected log behavior after fix**:
   - When 1 call is answered (Success), active_calls should still show 1 or more
   - effective_max should correctly reflect remaining capacity

---

## Related Code Patterns

The same status list is used in multiple places for call selection:
- Line 1166: `OR status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold")`
- Line 1976: `AND (calls.status IS NULL OR calls.status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold"))`

These patterns suggest "Success" is intentionally treated as a "done" status for call selection, but it should still be counted for channel limiting purposes.

---

## Impact Assessment

- **Severity**: High
- **Frequency**: Every time a call is answered while other calls are placing
- **User Impact**: Campaigns exceed configured channel limits, potentially causing:
  - Extra telephony costs
  - More calls than agents can handle
  - Queue congestion
