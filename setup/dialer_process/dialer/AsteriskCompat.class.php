<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Encoding: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 4.0                                                  |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2019 Issabel Foundation                                |
  +----------------------------------------------------------------------+
  | The contents of this file are subject to the General Public License  |
  | (GPL) Version 2 (the "License"); you may not use this file except in |
  | compliance with the License. You may obtain a copy of the License at |
  | http://www.opensource.org/licenses/gpl-license.php                   |
  |                                                                      |
  | Software distributed under the License is distributed on an "AS IS"  |
  | basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See  |
  | the License for the specific language governing rights and           |
  | limitations under the License.                                       |
  +----------------------------------------------------------------------+
  | The Initial Developer of the Original Code is PaloSanto Solutions    |
  +----------------------------------------------------------------------+
*/

/**
 * AsteriskCompat - Central version-awareness helper for multi-version Asterisk support.
 *
 * Centralizes all version-branching logic so that the rest of the codebase
 * can use a single interface regardless of Asterisk version.
 *
 * Key differences handled:
 *   Asterisk 11 (chan_agent):       Agent/XXXX interface, Agentlogoff AMI cmd
 *   Asterisk 13+ (app_agent_pool): Local/XXXX@callcenter-agents interface, Hangup to logout
 */
class AsteriskCompat
{
    private $_version = array(1, 4, 0, 0);
    private $_hasAppAgentPool;   // Asterisk >= 12
    private $_hasCommaSeparator; // Asterisk >= 1.6

    public function __construct(array $version)
    {
        $this->_version = $version;
        $this->_hasAppAgentPool   = $this->_versionAtLeast(array(12, 0, 0));
        $this->_hasCommaSeparator = $this->_versionAtLeast(array(1, 6, 0));
    }

    /**
     * Compare detected version against a minimum version array.
     */
    private function _versionAtLeast(array $min)
    {
        $len = max(count($this->_version), count($min));
        for ($i = 0; $i < $len; $i++) {
            $v = isset($this->_version[$i]) ? (int)$this->_version[$i] : 0;
            $m = isset($min[$i]) ? (int)$min[$i] : 0;
            if ($v > $m) return TRUE;
            if ($v < $m) return FALSE;
        }
        return TRUE; // equal
    }

    /** TRUE if Asterisk 12+ (app_agent_pool) */
    public function hasAppAgentPool()
    {
        return $this->_hasAppAgentPool;
    }

    /** TRUE if Asterisk 11 or earlier (chan_agent) */
    public function hasChanAgent()
    {
        return !$this->_hasAppAgentPool;
    }

    /**
     * Get queue member interface for an Agent-type agent.
     * Asterisk 11:  Agent/XXXX
     * Asterisk 12+: Local/XXXX@callcenter-agents
     */
    public function getAgentQueueInterface($agentNumber)
    {
        if ($this->_hasAppAgentPool) {
            return 'Local/'.$agentNumber.'@callcenter-agents';
        }
        return 'Agent/'.$agentNumber;
    }

    /**
     * Get StateInterface for QueueAdd.
     * Asterisk 11:  NULL (not used with chan_agent)
     * Asterisk 12+: Agent:XXXX
     */
    public function getAgentStateInterface($agentNumber)
    {
        if ($this->_hasAppAgentPool) {
            return 'Agent:'.$agentNumber;
        }
        return NULL;
    }

    /**
     * Normalize a queue interface string back to canonical Agent/XXXX format.
     * On Asterisk 12+: Local/XXXX@callcenter-agents -> Agent/XXXX
     * On Asterisk 11:  Agent/XXXX is already canonical, returned as-is.
     *
     * Returns NULL if the interface does not match an agent pattern.
     */
    public function normalizeAgentFromInterface($sInterface)
    {
        if ($this->_hasAppAgentPool) {
            if (preg_match('|^Local/(\d+)@callcenter-agents|', $sInterface, $regs)) {
                return 'Agent/'.$regs[1];
            }
        }
        // On chan_agent or if already Agent/XXXX format
        if (preg_match('|^Agent/(\d+)|', $sInterface)) {
            return $sInterface;
        }
        return NULL;
    }

    /**
     * Check if a given interface string is an agent queue interface
     * (either Local/XXXX@callcenter-agents or Agent/XXXX depending on version).
     * Returns the agent number if matched, or NULL.
     */
    public function extractAgentNumberFromInterface($sInterface)
    {
        if ($this->_hasAppAgentPool) {
            if (preg_match('|^Local/(\d+)@callcenter-agents|', $sInterface, $regs)) {
                return $regs[1];
            }
        } else {
            if (preg_match('|^Agent/(\d+)|', $sInterface, $regs)) {
                return $regs[1];
            }
        }
        return NULL;
    }

    /**
     * Get the variable/parameter separator for Originate commands.
     * Asterisk < 1.6: |
     * Asterisk 1.6+:  ,
     */
    public function getVariableSeparator()
    {
        return $this->_hasCommaSeparator ? ',' : '|';
    }

    /**
     * Get the agent module name for reload commands.
     * Asterisk 11:  chan_agent.so
     * Asterisk 12+: app_agent_pool.so
     */
    public function getAgentModuleName()
    {
        if ($this->_hasAppAgentPool) {
            return 'app_agent_pool.so';
        }
        return 'chan_agent.so';
    }

    /**
     * Get human-readable version string.
     */
    public function getVersionString()
    {
        return implode('.', $this->_version);
    }
}
?>
