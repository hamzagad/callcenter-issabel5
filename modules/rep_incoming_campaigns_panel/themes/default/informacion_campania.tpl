{* Error message DIV *}
<div
    id="issabel-callcenter-error-message"
    class="ui-state-error ui-corner-all">
    <p>
        <span class="ui-icon ui-icon-alert" style="float: left; margin-right: .3em;"></span>
        <span id="issabel-callcenter-error-message-text"></span>
    </p>
</div>
<div id="incomingCampaignsPanelApp">

{* Shift filter panel *}
<div id="shiftFilterPanel" style="margin-bottom: 10px; padding: 8px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px;">
    <label for="shiftFromHour" style="font-weight: bold;">{$LABEL_SHIFT_FROM}:</label>
    <select id="shiftFromHour" style="margin-right: 15px;">
        {foreach from=$HOURS_ARRAY item=hour}
        <option value="{$hour}">{$hour}:00</option>
        {/foreach}
    </select>
    <label for="shiftToHour" style="font-weight: bold;">{$LABEL_SHIFT_TO}:</label>
    <select id="shiftToHour" style="margin-right: 15px;">
        {foreach from=$HOURS_ARRAY item=hour}
        <option value="{$hour}">{$hour}:00</option>
        {/foreach}
    </select>
    <button id="applyShiftFilter" type="button" class="ui-button ui-widget ui-state-default ui-corner-all" style="padding: 4px 12px;">{$LABEL_APPLY}</button>
    <span id="shiftRangeIndicator" style="margin-left: 15px; font-style: italic; color: #666;"></span>
</div>

{* Stats counters - incoming campaigns only *}
<table width="100%" id="statsTable">
    <tr>
        <td><b>{$ETIQUETA_TOTAL_LLAMADAS}:</b></td>
        <td id="stat_total">0</td>
        <td><b>{$ETIQUETA_LLAMADAS_COLA}:</b></td>
        <td id="stat_onqueue">0</td>
        <td><b>{$ETIQUETA_LLAMADAS_EXITO}:</b></td>
        <td id="stat_success">0</td>
    </tr>
    <tr>
        <td><b>{$ETIQUETA_LLAMADAS_SINRASTRO}:</b></td>
        <td id="stat_losttrack">0</td>
        <td><b>{$ETIQUETA_LLAMADAS_ABANDONADAS}:</b></td>
        <td id="stat_abandoned">0</td>
        <td><b>{$ETIQUETA_LLAMADAS_TERMINADAS}:</b></td>
        <td id="stat_finished">0</td>
    </tr>
    <tr>
        <td><b>{$ETIQUETA_PROMEDIO_DURAC_LLAM}:</b></td>
        <td id="stat_avgduration">00:00:00</td>
        <td><b>{$ETIQUETA_MAX_DURAC_LLAM}:</b></td>
        <td id="stat_maxduration">00:00:00</td>
        <td></td>
        <td></td>
    </tr>
</table>
<br><br>
{* Active calls and Agents tables *}
<table width="100%"><tr>
    <td width="50%" style="vertical-align: top;">
        <div style="text-align: center;"><b>{$ETIQUETA_LLAMADAS_MARCANDO}:</b></div>
        <br>
        <table class="titulo">
            <tr>
                <td width="20%" nowrap="nowrap">{$ETIQUETA_CAMPANIA}</td>
                <td width="15%" nowrap="nowrap">{$ETIQUETA_ESTADO}</td>
                <td width="25%" nowrap="nowrap">{$ETIQUETA_NUMERO_TELEFONO}</td>
                <td width="25%" nowrap="nowrap">{$ETIQUETA_TRONCAL}</td>
                <td width="15%" nowrap="nowrap">{$ETIQUETA_DESDE}</td>
            </tr>
        </table>
        <div class="llamadas" id="activeCallsContainer" style="height: 400px;">
            <table>
                <tbody id="activeCallsBody">
                </tbody>
            </table>
        </div>
    </td>
    <td width="50%" style="vertical-align: top;">
        <div style="text-align: center;"><b>{$ETIQUETA_AGENTES}:</b></div>
        <br>
        <table class="titulo">
            <tr>
                <td width="18%" nowrap="nowrap">{$ETIQUETA_CAMPANIA}</td>
                <td width="15%" nowrap="nowrap">{$ETIQUETA_AGENTE}</td>
                <td width="12%" nowrap="nowrap">{$ETIQUETA_ESTADO}</td>
                <td width="20%" nowrap="nowrap">{$ETIQUETA_NUMERO_TELEFONO}</td>
                <td width="20%" nowrap="nowrap">{$ETIQUETA_TRONCAL}</td>
                <td width="15%" nowrap="nowrap">{$ETIQUETA_DESDE}</td>
            </tr>
        </table>
        <div class="llamadas" id="agentsContainer" style="height: 400px;">
            <table>
                <tbody id="agentsBody">
                </tbody>
            </table>
        </div>
    </td>
</tr></table>

</div>