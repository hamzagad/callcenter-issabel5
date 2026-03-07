# Centralized Debug Flag for Call Center Web Modules

## 1. Problem Statement

Currently, only `agent_console/index.php` has debug logging via a hardcoded constant `AGENT_CONSOLE_DEBUG_LOG` and a local `_debug()` function. The remaining 27+ call center modules have zero debug logging capability. There is no centralized way to enable/disable debugging across all call center web modules.

### Current State (Before)

**agent_console/index.php (line 34):**
```php
define ('AGENT_CONSOLE_DEBUG_LOG', FALSE);
```

**agent_console/index.php (lines 131-141):**
```php
function _debug($s)
{
    if (! AGENT_CONSOLE_DEBUG_LOG) return;

    $sAgent = '(unset)';
    if (isset($_SESSION['callcenter']) && isset($_SESSION['callcenter']['agente']))
        $sAgent = $_SESSION['callcenter']['agente'];
    file_put_contents('/tmp/debug-callcenter-agentconsole.txt',
        sprintf("%s %s agent=%s %s\n", $_SERVER['REMOTE_ADDR'], date('Y-m-d H:i:s'), $sAgent, $s),
        FILE_APPEND);
}
```

**Issues with current approach:**
- `AGENT_CONSOLE_DEBUG_LOG` is a PHP **constant** (`define`), which cannot be changed at runtime
- Debug function and flag are local to `agent_console/index.php` only
- No other module can use `_debug()` without duplicating the code
- Log file is module-specific (`/tmp/debug-callcenter-agentconsole.txt`)
- No browser console logging support

---

## 2. Architecture: Why `issabel2.lib.php` is the Right Place

### What is `issabel2.lib.php`?

**File:** `/var/www/html/modules/agent_console/libs/issabel2.lib.php`

This is a shared utility library that provides common functions used across call center modules:
- `_tr()` - internationalization/translation
- `getParameter()` - safe request parameter extraction
- `obtenerClaveConocidaMySQL()` - MySQL password retrieval
- `generarDSNSistema()` - database DSN construction
- `load_language_module()` - language file loading
- SSE helper functions (`detectSSEMode`, `setupSSESession`, `initSSE`, `printflush`, `jsonflush`)

### Why it's the right choice:

**It is already included by 20 of 28 call center modules.** Every function in it uses `function_exists()` guards to prevent redeclaration errors. Adding debug functions here gives immediate access to 20 modules with zero additional include statements required.

### Modules that already include `issabel2.lib.php` (20 modules):

| # | Module | Include Location | Include Style |
|---|--------|-----------------|---------------|
| 1 | `agent_console` | `index.php:44` | `require_once "modules/$module_name/libs/issabel2.lib.php"` |
| 2 | `agents` | `index.php:27` | `require_once "modules/agent_console/libs/issabel2.lib.php"` |
| 3 | `break_administrator` | `index.php:29` | `require_once "modules/agent_console/libs/issabel2.lib.php"` |
| 4 | `callcenter_config` | `index.php:24` | `require_once "modules/agent_console/libs/issabel2.lib.php"` |
| 5 | `calls_detail` | `index.php:31` | `require_once "modules/agent_console/libs/issabel2.lib.php"` |
| 6 | `campaign_in` | `index.php:32` | `require_once "modules/agent_console/libs/issabel2.lib.php"` |
| 7 | `campaign_monitoring` | `index.php:25` | `require_once "modules/agent_console/libs/issabel2.lib.php"` |
| 8 | `campaign_out` | `index.php:30` | `require_once "modules/agent_console/libs/issabel2.lib.php"` |
| 9 | `cb_extensions` | `index.php:27` | `require_once "modules/agent_console/libs/issabel2.lib.php"` |
| 10 | `client` | `index.php:25` | `require_once "modules/agent_console/libs/issabel2.lib.php"` |
| 11 | `eccp_users` | `index.php:33` | `require_once "modules/agent_console/libs/issabel2.lib.php"` |
| 12 | `external_url` | `index.php:29` | `require_once "modules/agent_console/libs/issabel2.lib.php"` |
| 13 | `form_designer` | `index.php:28` | `require_once "modules/agent_console/libs/issabel2.lib.php"` |
| 14 | `form_list` | `index.php:28` | `require_once "modules/agent_console/libs/issabel2.lib.php"` |
| 15 | `queues` | `index.php:26` | `require_once "modules/agent_console/libs/issabel2.lib.php"` |
| 16 | `rep_agents_monitoring` | `index.php:31` | `require_once "modules/agent_console/libs/issabel2.lib.php"` |
| 17 | `rep_incoming_calls_monitoring` | `index.php:37` | `require_once "modules/agent_console/libs/issabel2.lib.php"` |
| 18 | `rep_incoming_campaigns_panel` | `index.php:25` | `require_once "modules/agent_console/libs/issabel2.lib.php"` |
| 19 | `rep_outgoing_campaigns_panel` | `index.php:25` | `require_once "modules/agent_console/libs/issabel2.lib.php"` |
| 20 | `reports_break` | `index.php:29` | `require_once "modules/agent_console/libs/issabel2.lib.php"` |

### Modules that do NOT include `issabel2.lib.php` (11 modules):

These modules use the `call_center` database but do not currently include the shared library. They will need a `require_once` line added if they want to use `_cc_debug()` in the future.

| # | Module |
|---|--------|
| 1 | `agent_break` |
| 2 | `agent_journey` |
| 3 | `calls_per_agent` |
| 4 | `calls_per_hour` |
| 5 | `dont_call_list` |
| 6 | `graphic_calls` |
| 7 | `hold_time` |
| 8 | `ingoings_calls_success` |
| 9 | `login_logout` |
| 10 | `rep_agent_information` |
| 11 | `rep_trunks_used_per_hour` |

---

## 3. Existing Debug Infrastructure (Context)

### Dialer Backend Debug (separate system, not modified)

The **dialer daemon** (`/opt/issabel/dialer/`) has its own debug system:
- Flag stored in database: `valor_config` table, key `dialer.debug`
- Toggled via web UI: `callcenter_config` module (checkbox "Enable dialer debug")
- Logs to: `/opt/issabel/dialer/dialerd.log` via `AppLogger` class
- Also has: `dialer.allevents` and `dialer.relatedevents` sub-flags

**This plan does NOT modify the dialer debug system.** The dialer runs as a separate daemon process with its own logging. This plan only addresses the **web module** layer.

### Web Module Output Contexts

Call center web modules produce output in three distinct contexts. The debug strategy differs for each:

| Context | How Modules Produce Output | Browser Logging Strategy |
|---------|---------------------------|-------------------------|
| **HTML page load** | `return $smarty->fetch("template.tpl")` | Append `<script>console.log()</script>` tags to the returned HTML string |
| **JSON/AJAX** | `Header('Content-Type: application/json'); return $json->encode($data)` | Attach `_cc_debug` key to JSON response array for client-side JS to read |
| **SSE/streaming** | `jsonflush($bSSE, $data)` with bare `return;` | Not applicable (output already flushed); file logging only |

---

## 4. Implementation Plan

### Step 1: Add Debug Infrastructure to `issabel2.lib.php`

**File:** `/var/www/html/modules/agent_console/libs/issabel2.lib.php`
**Location:** Before the closing `?>` tag (currently line 316)

#### A) Global Debug Variable

```php
/**
 * Global debug flag for all Call Center web modules.
 * Set to TRUE to enable debug logging (file + browser console).
 * Set to FALSE (default) to suppress all debug output.
 *
 * ES: Flag global de depuracion para todos los modulos web del Call Center.
 * Establecer en TRUE para habilitar el registro de depuracion (archivo + consola del navegador).
 * Establecer en FALSE (por defecto) para suprimir toda la salida de depuracion.
 *
 * Toggle: Edit this file and change to true/false.
 * Runtime toggle: $GLOBALS['CALLCENTER_DEBUG'] = true; from any module.
 */
if (!isset($GLOBALS['CALLCENTER_DEBUG'])) {
    $GLOBALS['CALLCENTER_DEBUG'] = false;
}
```

**Why `$GLOBALS` instead of `define()`:**
- PHP constants (`define()`) cannot be changed at runtime once set
- `$GLOBALS['CALLCENTER_DEBUG']` can be toggled from any function scope without `global` declarations
- Can be overridden per-request if needed (e.g., for a specific user or session)

**Why `if (!isset(...))` guard:**
- Allows a module to set the flag **before** including `issabel2.lib.php` if needed
- Prevents overwriting a value that was intentionally set earlier in the request lifecycle

#### B) Centralized Debug Function: `_cc_debug()`

```php
/**
 * Centralized debug logging for Call Center web modules.
 * Logs to file /tmp/debug-callcenter.txt with module name prefix.
 * Also collects messages for browser console output via _cc_debug_flush_html().
 *
 * ES: Registro centralizado de depuracion para los modulos web del Call Center.
 * Registra en archivo /tmp/debug-callcenter.txt con prefijo del nombre del modulo.
 * Tambien recolecta mensajes para salida en consola del navegador via _cc_debug_flush_html().
 *
 * @param string $message     Debug message
 * @param string $module_name Module identifier (e.g. 'agent_console', 'campaign_out')
 */
if (!function_exists('_cc_debug')) {
function _cc_debug($message, $module_name = 'unknown')
{
    if (empty($GLOBALS['CALLCENTER_DEBUG'])) return;

    $sAgent = '(unset)';
    if (isset($_SESSION['callcenter']) && isset($_SESSION['callcenter']['agente']))
        $sAgent = $_SESSION['callcenter']['agente'];
    $sIP = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '(cli)';

    $logLine = sprintf("%s %s [%s] agent=%s %s\n",
        $sIP, date('Y-m-d H:i:s'), $module_name, $sAgent, $message);

    // File logging
    file_put_contents('/tmp/debug-callcenter.txt', $logLine, FILE_APPEND);

    // Collect for browser console output
    if (!isset($GLOBALS['_CC_DEBUG_MESSAGES'])) {
        $GLOBALS['_CC_DEBUG_MESSAGES'] = array();
    }
    $GLOBALS['_CC_DEBUG_MESSAGES'][] = sprintf('[%s] agent=%s %s',
        $module_name, $sAgent, $message);
}
}
```

**Key design decisions:**
- Uses same context info as old `_debug()`: IP, timestamp, agent name
- Adds `[module_name]` prefix so you can grep for a specific module's output
- All modules write to ONE file: `/tmp/debug-callcenter.txt` (centralized)
- Collects messages in `$GLOBALS['_CC_DEBUG_MESSAGES']` for browser output later
- Returns immediately (no overhead) when debug is disabled

#### C) Browser Console Flush Function: `_cc_debug_flush_html()`

```php
/**
 * Flush collected debug messages as browser console.log() statements.
 * Call this at the end of HTML-returning functions by appending the result
 * to the HTML string before returning it.
 * Returns empty string if debug is disabled or no messages were collected.
 *
 * ES: Vaciar los mensajes de depuracion recolectados como sentencias console.log() del navegador.
 * Llamar al final de funciones que retornan HTML, agregando el resultado
 * a la cadena HTML antes de retornarla.
 *
 * Usage: return $smarty->fetch("template.tpl") . _cc_debug_flush_html();
 *
 * @return string HTML script tags with console.log calls, or empty string
 */
if (!function_exists('_cc_debug_flush_html')) {
function _cc_debug_flush_html()
{
    if (empty($GLOBALS['CALLCENTER_DEBUG'])) return '';
    if (empty($GLOBALS['_CC_DEBUG_MESSAGES'])) return '';

    $output = "<script>\n";
    foreach ($GLOBALS['_CC_DEBUG_MESSAGES'] as $msg) {
        $escaped = str_replace(array("\\", "'", "\n", "\r", "</"),
                               array("\\\\", "\\'", "\\n", "\\r", "<\\/"), $msg);
        $output .= "console.log('[CC_DEBUG] ' + '" . $escaped . "');\n";
    }
    $output .= "</script>\n";

    $GLOBALS['_CC_DEBUG_MESSAGES'] = array();
    return $output;
}
}
```

**Why a separate flush function (not auto-output in `_cc_debug()`):**
- PHP modules don't always output HTML (some return JSON, some use SSE)
- Injecting `<script>` tags into a JSON response would break parsers
- The module author decides where/if to flush browser output
- Calling `_cc_debug()` is always safe in any context; only the flush is context-dependent

#### D) JSON Debug Attachment Function: `_cc_debug_attach_json()`

```php
/**
 * Attach collected debug messages to a JSON response array.
 * Call this before encoding the response to JSON.
 * Client-side JavaScript can optionally read response._cc_debug
 * and log each entry via console.log().
 *
 * ES: Adjuntar los mensajes de depuracion recolectados a un arreglo de respuesta JSON.
 * Llamar antes de codificar la respuesta a JSON.
 *
 * Usage: _cc_debug_attach_json($respuesta); return $json->encode($respuesta);
 *
 * @param array &$response The response array to attach debug messages to
 */
if (!function_exists('_cc_debug_attach_json')) {
function _cc_debug_attach_json(&$response)
{
    if (empty($GLOBALS['CALLCENTER_DEBUG'])) return;
    if (empty($GLOBALS['_CC_DEBUG_MESSAGES'])) return;

    $response['_cc_debug'] = $GLOBALS['_CC_DEBUG_MESSAGES'];
    $GLOBALS['_CC_DEBUG_MESSAGES'] = array();
}
}
```

---

### Step 2: Migrate `agent_console/index.php`

**File:** `/var/www/html/modules/agent_console/index.php`

#### A) Remove the old constant (line 34)

**Before:**
```php
define ('AGENT_CONSOLE_DEBUG_LOG', FALSE);
```

**After:** (line removed entirely)

#### B) Replace `_debug()` function (lines 131-141) with backward-compatible wrapper

**Before:**
```php
function _debug($s)
{
    if (! AGENT_CONSOLE_DEBUG_LOG) return;

    $sAgent = '(unset)';
    if (isset($_SESSION['callcenter']) && isset($_SESSION['callcenter']['agente']))
        $sAgent = $_SESSION['callcenter']['agente'];
    file_put_contents('/tmp/debug-callcenter-agentconsole.txt',
        sprintf("%s %s agent=%s %s\n", $_SERVER['REMOTE_ADDR'], date('Y-m-d H:i:s'), $sAgent, $s),
        FILE_APPEND);
}
```

**After:**
```php
function _debug($s)
{
    _cc_debug($s, 'agent_console');
}
```

**Why a wrapper instead of renaming all call sites:**
- There are **18 existing `_debug()` calls** throughout `agent_console/index.php` (lines 93, 1477, 1487, 1509, 1515, 1516, 1525, 1550, 1554, 1561, 1565, 1602, 1606, 1612, 1615, 1618, 1830, 1835)
- A wrapper avoids touching 18 call sites and risking regressions
- The wrapper is a simple one-liner that delegates to the centralized function

#### C) Add `_cc_debug_flush_html()` to HTML return points

There are **4 places** in `agent_console/index.php` that return HTML (via Smarty templates). Each needs the browser debug flush appended:

| Line | Current Code | Updated Code |
|------|-------------|--------------|
| 327 | `return $sContenido;` | `return $sContenido . _cc_debug_flush_html();` |
| 981 | `return $smarty->fetch("$sDirLocalPlantillas/agent_console.tpl");` | `return $smarty->fetch("$sDirLocalPlantillas/agent_console.tpl") . _cc_debug_flush_html();` |
| 1091 | `return $smarty->fetch("$sDirLocalPlantillas/agent_console_atributos.tpl");` | `return $smarty->fetch("$sDirLocalPlantillas/agent_console_atributos.tpl") . _cc_debug_flush_html();` |
| 1114 | `return $smarty->fetch("$sDirLocalPlantillas/agent_console_formulario.tpl");` | `return $smarty->fetch("$sDirLocalPlantillas/agent_console_formulario.tpl") . _cc_debug_flush_html();` |

**JSON/AJAX return points (lines 222, 636, 643, 1128, 1155, etc.):** These are NOT modified. File logging via `_cc_debug()` works automatically in these contexts. Browser console logging for AJAX would require client-side JS changes (out of scope for initial implementation).

**SSE/streaming contexts (checkStatus function):** File logging works automatically. Browser output not applicable.

---

## 5. How to Toggle the Debug Flag

### Method 1: Edit the source file (persistent)

Edit `/var/www/html/modules/agent_console/libs/issabel2.lib.php` and change:

```php
// Enable debugging
$GLOBALS['CALLCENTER_DEBUG'] = true;

// Disable debugging (default)
$GLOBALS['CALLCENTER_DEBUG'] = false;
```

### Method 2: Runtime toggle from any module (per-request)

Any call center module can enable debugging at runtime:

```php
$GLOBALS['CALLCENTER_DEBUG'] = true;
_cc_debug('Starting debug session for this request', 'my_module');
```

---

## 6. Log File Format

**File:** `/tmp/debug-callcenter.txt` (single centralized file)

**Format:**
```
192.168.1.100 2026-03-07 14:23:45 [agent_console] agent=Agent/8000 manejarSesionActiva_checkStatus start
192.168.1.100 2026-03-07 14:23:45 [agent_console] agent=Agent/8000 manejarSesionActiva_checkStatus before sanitizing clientstate=Array(...)
10.0.0.5 2026-03-07 14:24:01 [campaign_out] agent=(unset) Loading campaign list for user admin
```

**Filtering by module:**
```bash
grep '\[agent_console\]' /tmp/debug-callcenter.txt
grep '\[campaign_out\]' /tmp/debug-callcenter.txt
grep '\[campaign_monitoring\]' /tmp/debug-callcenter.txt
```

**Filtering by agent:**
```bash
grep 'agent=Agent/8000' /tmp/debug-callcenter.txt
```

**Live monitoring:**
```bash
tail -f /tmp/debug-callcenter.txt
```

**vs. Old log file:** `/tmp/debug-callcenter-agentconsole.txt` (agent_console only, no longer used)

---

## 7. Browser Console Output

When debug is enabled and a module returns HTML, the browser's Developer Tools Console will show:

```
[CC_DEBUG] [agent_console] agent=Agent/8000 manejarSesionActiva_checkStatus start
[CC_DEBUG] [agent_console] agent=Agent/8000 server state for agent=Array(...)
```

The `<script>` block is appended to the HTML response, so it executes when the page loads. This is visible in the browser's Developer Tools > Console tab.

---

## 8. Usage in Other Modules (for future debug calls)

Any of the 20 modules that already include `issabel2.lib.php` can immediately use `_cc_debug()`. Example in `campaign_out/index.php`:

```php
function _moduleContent(&$smarty, $module_name)
{
    require_once "modules/agent_console/libs/issabel2.lib.php";  // already exists

    _cc_debug('Module loaded, action=' . getParameter('action'), $module_name);
    // ... rest of module code ...

    $content = $smarty->fetch("$local_templates_dir/campaign_list.tpl");
    return $content . _cc_debug_flush_html();
}
```

For the 11 modules that do NOT include `issabel2.lib.php`, add this line to their `index.php`:
```php
require_once "modules/agent_console/libs/issabel2.lib.php";
```

---

## 9. Relationship to Dialer Debug (Clarification)

| Aspect | Web Module Debug (this plan) | Dialer Debug (existing, untouched) |
|--------|------------------------------|-----------------------------------|
| **Flag location** | `$GLOBALS['CALLCENTER_DEBUG']` in `issabel2.lib.php` | `dialer.debug` in `valor_config` DB table |
| **Toggle method** | Edit PHP file or set at runtime | Web UI checkbox in `callcenter_config` module |
| **Log file** | `/tmp/debug-callcenter.txt` | `/opt/issabel/dialer/dialerd.log` |
| **Scope** | PHP web modules (Apache/PHP-FPM) | Dialer daemon processes (CLI PHP) |
| **Browser output** | Yes (console.log via script tags) | No (daemon has no browser context) |
| **Process** | Apache/PHP-FPM worker per request | Long-running daemon with child processes |

These are **two completely independent debug systems**. Enabling one does not affect the other.

---

## 10. Files Modified (Summary)

| # | File | Change |
|---|------|--------|
| 1 | `/var/www/html/modules/agent_console/libs/issabel2.lib.php` | Add `$GLOBALS['CALLCENTER_DEBUG']`, `_cc_debug()`, `_cc_debug_flush_html()`, `_cc_debug_attach_json()` before closing `?>` |
| 2 | `/var/www/html/modules/agent_console/index.php` | Remove `define('AGENT_CONSOLE_DEBUG_LOG', FALSE)` at line 34; Replace `_debug()` function (lines 131-141) with one-line wrapper; Append `_cc_debug_flush_html()` at 4 HTML return points (lines 327, 981, 1091, 1114) |

**No other module files are modified.** All 20 modules that include `issabel2.lib.php` gain access to `_cc_debug()` automatically.

---

## 11. Risk Assessment

| Risk | Mitigation |
|------|-----------|
| Non-call-center modules affected | Impossible - only call center modules include `issabel2.lib.php` |
| Performance impact when disabled | `_cc_debug()` returns on line 1 when `$GLOBALS['CALLCENTER_DEBUG']` is falsy - negligible overhead |
| Breaking existing `_debug()` calls | Backward-compatible wrapper preserves all 18 existing call sites |
| SSE/JSON corruption | `_cc_debug_flush_html()` is never called in SSE/JSON paths; only appended to HTML returns |
| Log file growth | Only active when explicitly enabled; file is in `/tmp/` (cleared on reboot) |

---

## 12. Verification & Testing

### Test 1: File Logging Works

```bash
# Enable debug
sed -i "s/CALLCENTER_DEBUG'] = false/CALLCENTER_DEBUG'] = true/" /var/www/html/modules/agent_console/libs/issabel2.lib.php

# Open agent console in browser, perform login
# Check file output:
tail -f /tmp/debug-callcenter.txt

# Expected: lines with [agent_console] prefix
grep '\[agent_console\]' /tmp/debug-callcenter.txt
```

### Test 2: Browser Console Logging Works

```
1. With debug enabled, load agent console login page in browser
2. Open Developer Tools > Console (F12)
3. Expected: console.log lines prefixed with [CC_DEBUG]
```

### Test 3: Debug Disabled (Default Behavior)

```bash
# Disable debug
sed -i "s/CALLCENTER_DEBUG'] = true/CALLCENTER_DEBUG'] = false/" /var/www/html/modules/agent_console/libs/issabel2.lib.php

# Load any call center module
# Verify no new output appears:
ls -la /tmp/debug-callcenter.txt  # should not grow

# Verify no <script> tags in page source (View Source in browser)
```

### Test 4: Other Modules Unaffected

```
1. With debug enabled, load campaign_out, calls_detail, queues modules
2. These modules don't call _cc_debug() yet, so no debug output expected
3. Verify: modules load and function normally, no errors
```

### Test 5: SSE/Long-Polling Not Broken

```bash
# With debug enabled, open agent console and login
# Verify SSE polling continues (agent status updates in real-time)
# Check debug file for checkStatus entries:
grep 'checkStatus' /tmp/debug-callcenter.txt
```

### Collect Logs

```bash
# All debug output:
cat /tmp/debug-callcenter.txt

# Agent console only:
grep '\[agent_console\]' /tmp/debug-callcenter.txt

# Apache error log (for PHP errors, if any):
tail -50 /var/log/httpd/ssl_error_log
```
