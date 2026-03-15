# AGENTS.md

Guidelines for AI coding agents working on the Issabel Call Center module.

## Project Overview

Issabel Call Center is a predictive dialer and call center solution built on Asterisk PBX. It consists of:
- **Dialer Daemon**: Multi-process PHP daemon (`/opt/issabel/dialer/`)
- **Web Modules**: 31 modules in `/var/www/html/modules/`

Target environment: Rocky 8, PHP 5.4-8.0, Asterisk 11/13/18, MariaDB.

## Build/Install Commands

```bash
# Install from local repo (run as root)
cd /usr/src/callcenter-issabel5
bash build/5.0/install-issabel-callcenter.sh -l

# Service management
systemctl start issabeldialer
systemctl status issabeldialer
systemctl stop issabeldialer

# Manual dialer start (debug mode, foreground)
su - asterisk -c "/opt/issabel/dialer/dialerd -d"
```

## Testing

No formal test framework exists. Testing is manual:

```bash
# Test dialer syntax (PHP lint)
php -l /opt/issabel/dialer/dialerd
php -l /opt/issabel/dialer/*.class.php

# Test web module syntax
php -l /var/www/html/modules/agent_console/index.php

# Grep for log output during testing
grep -E "(schedule|ERROR|ERR)" /opt/issabel/dialer/dialerd.log | tail -50
```

## Code Style Guidelines

### Formatting

- **Indentation**: 4 spaces (no tabs), expandtab
- **Line endings**: Unix (LF)
- **Encoding**: UTF-8
- **PHP opening tag**: `<?php` (never `<?`)
- **No trailing whitespace**

### Naming Conventions

| Element | Convention | Example |
|---------|-----------|---------|
| Classes | PascalCase | `CampaignProcess`, `PaloSantoConsola` |
| Functions | camelCase | `manejarSesionActiva_schedule()` |
| Private methods | underscore prefix | `_agendarLlamadaAgente()` |
| Variables | camelCase or descriptive | `$sAgente`, `$idCampaign` |
| Constants | UPPER_SNAKE | `MIN_MUESTRAS`, `INTERVALO_REVISION` |
| Database tables | snake_case | `call_entry`, `form_data_recolected` |
| Class files | `*.class.php` | `ECCPConn.class.php` |

### Imports/Requires

```php
// Dialer classes (use spl_autoload)
require_once 'ECCPHelper.lib.php';

// Web modules (explicit paths)
require_once "libs/paloSantoDB.class.php";
require_once "modules/$module_name/configs/default.conf.php";
```

### Database Access

**Dialer (PDO):**
```php
$recordset = $this->_db->prepare($sql);
$recordset->execute($params);
$tupla = $recordset->fetch(PDO::FETCH_ASSOC);
$recordset->closeCursor();
```

**Web Modules (mysqli via paloDB):**
```php
$pDB = new paloDB($arrConf['cadena_dsn']);
$result = $pDB->getFirstRowQuery($sql, TRUE, $params);
```

### Comments and Logging

**Bilingual comments** - Spanish first, then English translation:

```php
// Verificar si el agente está siendo monitoreado
// Verify if agent is being monitored
$infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
```

**Logging format:**
```php
$this->_log->output('ERR: al agendar llamada: fecha inválida | EN: ERR: when scheduling call: invalid date');
```

### Error Handling

```php
// Return FALSE on error, set errMsg property
function agendarLlamada($schedule, $sameagent, $newphone) {
    try {
        $respuesta = $oECCP->schedulecall(...);
        if (isset($respuesta->failure)) {
            $this->errMsg = _tr('Unable to schedule call').' - '.$this->_formatoErrorECCP($respuesta);
            return FALSE;
        }
        return TRUE;
    } catch (Exception $e) {
        $this->errMsg = '(internal) schedulecall: '.$e->getMessage();
        return FALSE;
    }
}
```

### SQL Queries

Use HEREDOC for complex queries with bilingual comments:

```php
$sPeticionAgentesAgendados = <<<PETICION_AGENTES_AGENDADOS
SELECT DISTINCT agent FROM calls, campaign
WHERE calls.id_campaign = ?
    AND (calls.status IS NULL OR calls.status NOT IN ("Success", "Placing", "Ringing"))
    AND calls.dnc = 0
    AND calls.date_init <= ? AND calls.date_end >= ?
PETICION_AGENTES_AGENDADOS;
```

### Smarty Templates

- Use `_tr()` for i18n: `{_tr("Schedule Call")}`
- Assign variables: `$smarty->assign('variable', $value)`
- Template location: `themes/default/*.tpl`

## Important Rules

1. **Modify live system** - Changes go to `/opt/issabel/dialer/` and `/var/www/html/modules/`
2. **Use `/bin/cp`** - Avoid shell alias issues with cp command
3. **Dialer runs as asterisk user** - Never as root
4. **Check impact** - Verify changes don't affect other functionality
5. **No PHP 8+ features** - Must work on PHP 5.4+
6. **English for new code** - Variables, functions, comments in English; logs bilingual

## File Locations

| Component | Location |
|-----------|----------|
| Dialer daemon | `/opt/issabel/dialer/dialerd` |
| Dialer classes | `/opt/issabel/dialer/*.class.php` |
| Dialer logs | `/opt/issabel/dialer/dialerd.log` |
| Web modules | `/var/www/html/modules/*/` |
| Module configs | `/var/www/html/modules/*/configs/default.conf.php` |
| Asterisk config | `/etc/asterisk/` |
| MySQL credentials | `/etc/issabel.conf` (mysqlrootpwd) |
