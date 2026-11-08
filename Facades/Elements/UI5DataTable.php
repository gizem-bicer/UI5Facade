<?php
namespace exface\UI5Facade\Facades\Elements;

use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Interfaces\Actions\ActionInterface;
use exface\Core\Interfaces\Actions\iReadData;
use exface\Core\Facades\AbstractAjaxFacade\Elements\JqueryDataTableTrait;
use exface\Core\Interfaces\Widgets\iSupportMultiSelect;
use exface\Core\Widgets\DataTableResponsive;
use exface\UI5Facade\Facades\Elements\Traits\UI5DataElementTrait;
use exface\Core\Widgets\DataColumn;
use exface\Core\Widgets\DataButton;
use exface\Core\Facades\AbstractAjaxFacade\Elements\JsConditionalPropertyTrait;
use exface\Core\Exceptions\Widgets\WidgetConfigurationError;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\Exceptions\Widgets\WidgetLogicError;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\Actions\iModifyData;
use exface\Core\Interfaces\Actions\iCallOtherActions;
use exface\UI5Facade\Facades\Interfaces\UI5DataElementInterface;
use exface\Core\Widgets\Parts\DataRowGrouper;
use exface\Core\Widgets\DataTable;
use exface\Core\DataTypes\NumberDataType;
use exface\Core\Widgets\DisplayTemplate;
use exface\Core\Widgets\Popup;

/**
 *
 * @method \exface\Core\Widgets\DataTable getWidget()
 *
 * @author Andrej Kabachnik
 *
 */
class UI5DataTable extends UI5AbstractElement implements UI5DataElementInterface
{    
    use JsConditionalPropertyTrait;
    
    use UI5DataElementTrait, JqueryDataTableTrait {
       buildJsDataLoaderOnLoaded as buildJsDataLoaderOnLoadedViaTrait;
       buildJsConstructor as buildJsConstructorViaTrait;
       getCaption as getCaptionViaTrait;
       init as initViaTrait;
       UI5DataElementTrait::buildJsResetter insteadof JqueryDataTableTrait;
       UI5DataElementTrait::buildJsDataResetter as buildJsDataResetterViaTrait;
    }
    
    const EVENT_NAME_FIRST_VISIBLE_ROW_CHANGED = 'firstVisibleRowChanged';
    
    const CONTROLLER_METHOD_RESIZE_COLUMNS = 'resizeColumns';

    /**
     * This JS controller property will hold an object of optional column instances
     * with data_column_name as key and sap.ui.table.Column or sap.m.Column as value.
     * @var string
     */
    const CONTROLLER_VAR_OPTIONAL_COLS = 'optionalCols';

    /**
     * This JS controller property will hold an object of pre-built, correctly formatted
     * cell controls for optional columns of a responsive sap.m.Table (data_column_name as key).
     * @var string
     */
    const CONTROLLER_VAR_OPTIONAL_CELLS = 'optionalCells';
    
    protected function init()
    {
        $this->initViaTrait();
        $this->getConfiguratorElement()->setIncludeColumnsTab(true);
    }
    
    protected function buildJsConstructorForControl($oControllerJs = 'oController') : string
    {
        $widget = $this->getWidget();
        $controller = $this->getController();

        // Initialize optional column from the configurator when the
        // JS controller is initialized.
        // IDEA maybe just initialize the controller var here and run the
        // constructors of the columns only on-demand in buildJsRefreshPersonalization()?
        if ($widget->getConfiguratorWidget()->hasOptionalColumns()) {
            $colsOptional = $widget->getConfiguratorWidget()->getOptionalColumns();
            $colsOptionalInitJs = '';
            if (! empty($colsOptional)) {
                foreach ($colsOptional as $col) {
                    $colEl = $this->getFacade()->getElement($col);
                    $colsOptionalInitJs .= <<<JS
                
                        var oCol = sap.ui.getCore().byId({$this->escapeString($colEl->getId())});
                        if (! oCol) {
                            oCol = {$colEl->buildJsConstructor()};
                        }
                        oColsOptional['{$col->getDataColumnName()}'] = oCol;
JS;
                    // For sap.m.Table (responsive), the cell template is a separate aggregation from
                    // the column header, so it needs to be pre-built explicitly here too. Otherwise
                    // optional columns toggled on later via personalization would fall back to a plain,
                    // unformatted binding (e.g. showing raw internal date/time values instead of the
                    // properly formatted ones).
                    if ($this->isMTable()) {
                        $colsOptionalInitJs .= <<<JS
                
                        oCellsOptional['{$col->getDataColumnName()}'] = {$this->getFacade()->getElement($col)->buildJsConstructorForCell()};
JS;
                    }
                }
            }
            $controller->addOnInitScript(<<<JS
            
                (function(){
                    var oColsOptional = {};
                    var oCellsOptional = {};
                    {$colsOptionalInitJs}
                    {$controller->buildJsDependentObjectGetter(self::CONTROLLER_VAR_OPTIONAL_COLS, $this, $oControllerJs)} = oColsOptional;
                    {$controller->buildJsDependentObjectGetter(self::CONTROLLER_VAR_OPTIONAL_CELLS, $this, $oControllerJs)} = oCellsOptional;
                })();
JS
            );
        }

        if ($this->isMTable()) {
            $js = $this->buildJsConstructorForMTable($oControllerJs);
        } else {
            $js = $this->buildJsConstructorForUiTable($oControllerJs);
        }
        
        if (($syncAttributeAlias = $widget->getMultiSelectSyncAttributeAlias()) !== null)
        {
            if (($syncDataColumn = $widget->getColumnByAttributeAlias($syncAttributeAlias)) !== null) {
                $this->addOnChangeScript($this->buildJsMultiSelectSync($syncDataColumn, $oControllerJs));
            } else {
                throw new WidgetConfigurationError($widget, "The attribute alias '{$syncAttributeAlias}' for multi select synchronisation was not found in the column attribute aliases for the widget '{$widget->getId()}'!");
            }
        }
        
        // Clear selection every time the prefill data changes. Otherwise in a table within
        // a dialog if the first row was selected when the dialog was opened for object 1,
        // the first row will also be selected if the dialog will be opened for object 2, etc.
        // TODO it would be even better to check if previously selected UIDs are still there
        // and select their rows again like we do in EuiData::buildJsonOnLoadSuccessSelectionFix()
        if ($this->isUiTable()) {
            $clearSelectionJs = "sap.ui.getCore().byId('{$this->getId()}').clearSelection();";
        } else {
            $clearSelectionJs = "sap.ui.getCore().byId('{$this->getId()}').removeSelections(true);";
        }
        $controller->addOnPrefillDataChangedScript($clearSelectionJs);
        
        return $js;
    }
    
    protected function registerUiTableFixedColumns() : int
    {
        // re-calculate frozen columns for sap.ui.table (might change due to optional columns/setups/mutations)
        $this->getController()->addOnShowViewScript(<<<JS
            
                setTimeout(() => {
                    let oDataTable = sap.ui.getCore().byId("{$this->getId()}"); 
                    let bHasDirtyColumn = {$this->escapeBool($this->hasDirtyColumn())};

                    // attach listener for changes 
                    if (oDataTable && oDataTable instanceof sap.ui.table.Table) {
                        exfSetupManager.datatable.attachFrozenColumnChangeListener('{$this->getP13nElement()->getId()}', '{$this->getConfiguratorElement()->getModelNameForConfig()}', '{$this->getId()}', {$this->getWidget()->getFreezeColumns()}, bHasDirtyColumn);
                    }
                }, 0);

                
                    
JS, false);

        $widget = $this->getWidget();
        $freezeColumnsCount = $widget->getFreezeColumns();
        if ($freezeColumnsCount > 0) {
            $columns = $widget->getColumns();
            for ($i = 0; $i < $freezeColumnsCount; $i++) {
                if ($columns[$i]->isHidden()) {
                    $freezeColumnsCount++;
                }
            }
            // increase the count if the DirtyFlag column is added as the first column in the table
            if ($this->hasDirtyColumn()) {
                $freezeColumnsCount++;
            }
        }
        return $freezeColumnsCount;
    }

    public function isMList() : bool
    {
        return $this->isMTable();
    }
    
    public function isMTable()
    {
        return $this->getWidget() instanceof DataTableResponsive;
    }
    
    public function isUiTable()
    {
        return ! ($this->getWidget() instanceof DataTableResponsive);
    }
    
     /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractAjaxFacade\Elements\AbstractJqueryElement::buildJsCallFunction()
     */
    public function buildJsCallFunction(string $functionName = null, array $parameters = [], ?string $jsRequestData = null) : string
    {
        // passed parameters
        $passedParameters = json_encode($parameters ?? null);
        if ($jsRequestData === null){
            $jsRequestData = 'null';
        }

        // get required setup info/Ids
        $dataWidget = $this->getDataWidget();
        $screenSlug = $this->escapeString($dataWidget->getUiScreen()->getUrlSlug());
        $widgetId = $this->escapeString($dataWidget->getIdInScreen());
        $objectId = $this->escapeString($dataWidget->getMetaObject()->getId());
  
        switch (true) {
            case $functionName === DataTable::FUNCTION_CLEAR_APPLIED_SETUP:
                return <<<JS
                /*
                    check if the deleted setup is the one currently applied. If so, clear it from the dexie db and reset the table

                    Parameters: None
                */

                (function () {
                    if ({$jsRequestData} !== null && {$jsRequestData}.rows[0] === undefined){
                        return;
                    }

                    // if the deleted setup is the one currently saved in dexie for this page and widget,
                    // delete it and reset the table to original state
                    exfSetupManager.dexie.getCurrentSetup({$screenSlug}, {$widgetId}, {$objectId})
                    .then(entry => {
                        if (entry && entry.setup_uid === {$jsRequestData}.rows[0]['UID']) {
                            // delete from dexie db
                            exfSetupManager.dexie.deleteCurrentSetup({$screenSlug}, {$widgetId}, {$objectId});

                            // reset table
                            let oP13nDialogResetBtn = sap.ui.getCore().byId('{$this->getP13nElement()->getId()}'+'-reset');
                            if (oP13nDialogResetBtn){
                                oP13nDialogResetBtn.firePress();
                            }
                        }
                    });
                })();
                
JS;
            case $functionName === DataTable::FUNCTION_RESET_CHANGE_TRACKING:
                return <<<JS
                /*
                    Function to reset tracking of changes in the column configuration (sorting/filtering/columns) 
                        - resets the custom data property of the ui5 table .data('_exfConfigChanged')
                        - resets the change indicator of the quick select menu (if button exists)
                        - also resets the frozen columns count

                    Parameters: None
                */

                (function () {
                    // reset frozen columns as well
                    setTimeout(() => {
                        let oDataTable = sap.ui.getCore().byId("{$this->getId()}"); 
                        let bHasDirtyColumn = {$this->escapeBool($this->hasDirtyColumn())};

                        if (oDataTable && oDataTable instanceof sap.ui.table.Table) {
                            exfSetupManager.datatable.attachFrozenColumnChangeListener('{$this->getP13nElement()->getId()}', '{$this->getConfiguratorElement()->getModelNameForConfig()}', '{$this->getId()}', {$this->getWidget()->getFreezeColumns()}, bHasDirtyColumn);
                        }
                    }, 0);

                    exfSetupManager.resetChangeTracking('{$this->getId()}');
                })();
                
JS;
            case $functionName === DataTable::FUNCTION_TRACK_CHANGES:
                return <<<JS
                /*
                    Function to track changes in the column configuration (sorting/filtering/columns) and
                        - mark them in a custom data property of the ui5 table .data('_exfConfigChanged')
                        - indicate the changes visually with an asterisk (*) in the quick select menu (if button exists)

                    Parameters: None
                */

                (function () {

                    exfSetupManager.datatable.trackConfigChanges(
                        '{$this->getId()}',
                        '{$this->getP13nElement()->getId()}',
                        '{$this->getConfiguratorElement()->getModelNameForConfig()}',
                        '{$this->getP13nElement()->getIdOfSearchPanel()}'
                    );
                })();
                
JS;
            case $functionName === DataTable::FUNCTION_DUMP_SETUP:
                
                /*
                    Parameters/column names: dump_setup(SETUP_UXON, SLUG, WIDGET_ID, PROTOTYPE_FILE, OBJECT, PRIVATE_FOR_USER, true/false)

                    - SETUP_UXON:
                        The name of the column where the setup UXON will be stored
                    - SLUG:
                        the name of the column for the current screen slug
                    - WIDGET_ID:
                        the name of the column for the current widget ID
                    - PROTOTYPE_FILE:
                        the name of the column for the prototype file to use
                        e.g. 'exface/core/Mutations/Prototypes/DataTableSetup.php'
                    - OBJECT:
                        the name of the column for the object of the datatable
                    - PRIVATE_FOR_USER:
                        the name of the column for the current user UID
                    - true/false: (optional)
                        auto apply after dumping the data (only works for updating an existing setup, otherwise the setup UID is missing)
                */

                return <<<JS

                // Dump current table setup into inputData of the action

                // get column name parameters, remove leading/trailing spaces; return if not all params provided
                let aParams = {$passedParameters};
                if (!Array.isArray(aParams) || aParams.length < 6) {
                    console.warn('dump_setup() called with invalid parameters:', aParams);
                    return;
                }
                let [sColNameCol, sSlugCol, sWidgetIdCol, sPrototypeFileCol, sObjectCol, sUserIdCol] = aParams.map(p => typeof p === 'string' ? p.trim() : p);
                let bAutoApply = (aParams[6] !== undefined && aParams[6] !== null) ? (aParams[6].trim() === 'true' || aParams[6].trim() === true) : false;

                // get the current setup as json in widget_setup format
                let oSetupJson = exfSetupManager.datatable.getConfiguration(
                    '{$this->getId()}',
                    '{$this->getP13nElement()->getId()}',
                    '{$this->getConfiguratorElement()->getModelNameForConfig()}',
                    '{$this->getP13nElement()->getIdOfSearchPanel()}'
                );

                // if input data is empty, initialize it
                if ({$jsRequestData}.rows[0] === undefined){
                    {$jsRequestData}.rows[0] = {};
                }

                // only add current user to input data if we are creating a new setup
                // otherwise we would set public setups (no private_for_user entry) to private when updating them
                if ({$jsRequestData}.rows[0][sColNameCol] === undefined){
                    {$jsRequestData}.rows[0][sUserIdCol] = '{$this->getWorkbench()->getSecurity()->getAuthenticatedUser()->getUid()}';
                }

                // write the current setup and info into to the input data
                {$jsRequestData}.rows[0][sColNameCol] = JSON.stringify(oSetupJson);
                {$jsRequestData}.rows[0][sSlugCol] = {$screenSlug};
                {$jsRequestData}.rows[0][sWidgetIdCol] = {$widgetId};
                {$jsRequestData}.rows[0][sPrototypeFileCol] = 'exface/core/Mutations/Prototypes/DataTableSetup.php';
                {$jsRequestData}.rows[0][sObjectCol] = {$objectId};

                if (bAutoApply === true){
                    {$this->buildJsCallFunction(DataTable::FUNCTION_APPLY_SETUP, [ '[#' . $parameters[0] . '#]' ], $jsRequestData)}
                }
                
JS;

            case $functionName === DataTable::FUNCTION_APPLY_SETUP:
                // parameter: apply_setup([#SETUP_UXON#]) -> column in which the setup is stored
                // alternatively, apply_setup(['localStorage']) -> to retrieve saved setup from indexedDb
                
                return <<<JS

                // get currently selected data from request
                let oResultData = {$jsRequestData};
                let oSetupUxon = null;
                let sSlug = {$screenSlug};
                let sWidgetId = {$widgetId};
                let sObjectId = {$objectId};

                // if the function is not called with 'localStorage' parameter,
                // and there is data in the request, get the setup Uxon from the request data
                // (this is the case, when the user selects a setup from the setups tab and applies it)
                if ( oResultData !== null && {$passedParameters}[0] !== 'localStorage'){
                    if (oResultData.rows.length === 0) {
                        return;
                    }

                    // get setup UXON from request data and parse it
                    let sUxonCol = {$passedParameters}[0];
                    sUxonCol = sUxonCol.match(/\[#(.*?)#\]/)[1]; // strip the placeholder syntax
                    oSetupUxon = JSON.parse(oResultData.rows[0][sUxonCol]);
                }

                // either use the passed oSetupUxon, or try and load the data from IndexedDB (onLoad)
                // then apply the setup and update the related ui elements (quick select caption, active column in setups table, reset the change tracking)
                exfSetupManager.getSetupProperty(sSlug, sWidgetId, sObjectId, oSetupUxon, 'setup_uxon')
                .then(oSetupUxon => {
                    if (oSetupUxon) {

                        // apply setup configuration
                        exfSetupManager.datatable.applyConfiguration(
                            '{$this->getId()}',
                            '{$this->getConfiguratorElement()->getModelNameForConfig()}',
                            '{$this->getP13nElement()->getIdOfColumnsPanel()}',
                            '{$this->getP13nElement()->getIdOfSortPanel()}',
                            '{$this->getP13nElement()->getIdOfSearchPanel()}',
                            oSetupUxon
                        );

                        // store the last applied setup in session storage 
                        // do this only if it was actively applied (not when loading from indexedDb)
                        if ({$passedParameters}[0] !== 'localStorage'){
                            exfSetupManager.dexie.saveLastAppliedSetup(
                                oResultData.rows[0]['SLUG'],
                                oResultData.rows[0]['WIDGET_ID'],
                                oResultData.rows[0]['OBJECT'],
                                oResultData.rows[0]['UID'],
                                oResultData.rows[0]['SETUP_UXON'],
                                oResultData.rows[0]['NAME']
                            );
                        }
                       
                        // apply the changes immediately 
                        // otherwise the p13n dialog does not apply the filters until OK is pressed
                        let oP13nDialog = sap.ui.getCore().byId('{$this->getP13nElement()->getId()}'); 
                        if (oP13nDialog) {
                            oP13nDialog.fireOk();
                        }

                        // reset change tracking (since a new setup is now applied)
                        exfSetupManager.resetChangeTracking('{$this->getId()}');
                    } 
                    else {
                        // return if no setup was passed or found
                        return;
                    }
                })
                .then(() => {
                    // fire event to let other elements know that a new setup was applied
                    // (currently used to update the quick select button indicator/caption in UI5DataElementTrait)
                    exfSetupManager.fireWidgetSetupChangedEvent('{$this->getId()}');
                });
JS;
        }

        return parent::buildJsCallFunction($functionName, $parameters, $jsRequestData);
    }
    

    /**
     * Returns the javascript constructor for a sap.m.Table
     *
     * @return string
     */
    protected function buildJsConstructorForMTable(string $oControllerJs = 'oController')
    {
        $mode = $this->getWidget()->getMultiSelect() ? 'sap.m.ListMode.MultiSelect' : 'sap.m.ListMode.SingleSelectMaster';
        $striped = $this->getWidget()->getStriped() ? 'true' : 'false';
        $contextualWidth = 'Auto';
        $popup = $this->getWidget()->getParentByClass(Popup::class);
        if ($popup !== null && ($popup->getWidth()->isUndefined() || $popup->getWidth()->isAuto())) {
            // Do not let popins determine the width used to decide whether those popins are needed.
            $contextualWidth = 'Inherit';
        }
        
        if ($this->getDynamicPageShowToolbar() === false) {
            $toolbar = $this->buildJsToolbar($oControllerJs);
        } else {
            $toolbar = '';
        }
        
        $controller = $this->getController();
        return <<<JS
        new sap.m.VBox({
            {$this->buildJsPropertyVisibile()}
            width: "{$this->getWidth()}",
    		items: [
                new sap.m.Table("{$this->getId()}", {
            		fixedLayout: false,
                    contextualWidth: {$this->escapeString($contextualWidth)},
                    sticky: [sap.m.Sticky.ColumnHeaders, sap.m.Sticky.HeaderToolbar],
                    alternateRowColors: {$striped},
                    noDataText: {$this->escapeString($this->getWidget()->getEmptyText())},
                    // Select a row on left-click (row body), mirroring sap.ui.table.Table's SelectionBehavior.Row
                    includeItemInSelection: true,
            		itemPress: {$controller->buildJsEventHandler($this, self::EVENT_NAME_CHANGE, true)},
                    selectionChange: function (oEvent) { {$this->buildJsPropertySelectionChange('oEvent')} },
                    updateFinished: function(oEvent) { {$this->buildJsColumnStylers()} },
                    mode: {$mode},
                    headerToolbar: [
                        {$toolbar}
            		],
            		columns: [
                        {$this->buildJsColumnsForMTable()}
            		],
            		items: {
            			path: '/rows',
                        {$this->buildJsBindingOptionsForGrouping()}
                        template: new sap.m.ColumnListItem({
                            type: "Active",
                            cells: [
                                {$this->buildJsCellsForMTable()}
                            ]
                        }),
            		},
                    contextMenu: [
                        // A context menu is required for the contextmenu browser event to fire!
                        new sap.ui.unified.Menu()
                    ]
                })
                {$this->buildJsHeaderFilterFunctions()}
                {$this->buildJsClickHandlers('oController')}
                {$this->buildJsPseudoEventHandlers()}
                ,
                {$this->buildJsConstructorForMTableFooter()}
            ]
        })
        
JS;
    }

    /**
     * Adds functions to reset and set header filters as data attributes to an object (constructor)
     * @return string
     */
    protected function buildJsHeaderFilterFunctions(){
        return <<<JS
            .data('fnSetVisibleHeaderFilters', {$this->getConfiguratorElement()->buildJsVisibleFilterValueSetter()})
            .data('fnResetVisibleHeaderFilters', {$this->getConfiguratorElement()->buildJsResetVisibleFilters()})
JS;
    }

    /**
     * Prevents a text selection in a group header from toggling the group.
     *
     * @return string
     */
    protected function buildJsPreserveGroupHeaderSelection() : string
    {
        if (! $this->getWidget()->hasRowGroups()) {
            return '';
        }
        return <<<JS

            .addEventDelegate({
                onAfterRendering: function(oEvent) {
                    var oTableDom = oEvent.srcControl.getDomRef();
                    if (!oTableDom) {
                        return;
                    }
                    oTableDom.addEventListener("click", function(oEvent) {
                        var oGroupHeader = oEvent.target.closest(".sapUiTableGroupIcon");
                        var oSelection = window.getSelection();
                        if (
                            oGroupHeader
                            && oSelection
                            && ! oSelection.isCollapsed
                            && oSelection.toString().length > 0
                            && oGroupHeader.contains(oSelection.anchorNode)
                            && oGroupHeader.contains(oSelection.focusNode)
                        ) {
                            oEvent.stopPropagation();
                        }
                    }, true);
                }
            })
JS;
    }

    
    /**
     * 
     * @param string $oControllerJs
     * @return string
     */
    protected function buildJsConstructorForMTableFooter(string $oControllerJs = 'oController') : string
    {
        $visible = $this->getWidget()->isPaged() === false || $this->getWidget()->getHideFooter() === true ? 'false' : 'true';
        return <<<JS
                new sap.m.OverflowToolbar({
                    visible: {$visible},
    				content: [
                        {$this->getPaginatorElement()->buildJsConstructor($oControllerJs)},
                        new sap.m.ToolbarSpacer(),
                        {$this->buildJsConfiguratorButtonConstructor($oControllerJs, 'Transparent')}
                    ]
                })
                
JS;
    }



    /**
     * On every selection change event save the selection and perform on-change scripts
     * 
     * This method also ensures, that previously selected rows are selected again when
     * following the pagination back and fourth or using filters. That is, 
     * - if you select a row on one page, go to the next one and return back, the initially 
     * selected row will still be selected
     * - if you select a row on one page, go to another one and press a button, the (invisible)
     * selected row on the first page will be among the action data
     * 
     * @return string
     */
    protected function buildJsPropertySelectionChange(string $oEventJs)
    {
        $controller = $this->getController();
        $widget = $this->getWidget();
        $uidColJs = $widget->hasUidColumn() ? $this->escapeString($widget->getUidColumn()->getDataColumnName()) : 'null';
        if ($widget->getMultiSelect() === false) {
            return <<<JS

            const oTable = $oEventJs.getSource();
            const oModelSelected = oTable.getModel('{$this->getModelNameForSelections()}');
            var aRowsSelectedVisible = {$this->buildJsGetRowsSelected('oTable')};
            
            // Persist the selected row so it can be restored after a reload (e.g. the refresh
            // performed after an Edit/Save action) - see buildJsDataLoaderOnLoadedRestoreSelection().
            // Ignore programmatic selection clears, which fire with userInteraction=false and an empty
            // selection while data is reloaded: keeping the stored row lets it be re-selected afterwards.
            // A genuine user deselect always reports userInteraction=true and still updates the model.
            var bUserInteraction = (typeof $oEventJs.getParameter === 'function' && $oEventJs.getParameter('userInteraction') !== undefined) ? $oEventJs.getParameter('userInteraction') : true;
            if (oModelSelected && ! (bUserInteraction === false && aRowsSelectedVisible.length === 0 && (oModelSelected.getProperty('/rows') || []).length > 0)) {
                oModelSelected.setProperty('/rows', aRowsSelectedVisible);
            }
            
            {$controller->buildJsEventHandler($this, self::EVENT_NAME_CHANGE, false)};
JS;
            
        }
        
        if($this->isMList()) {
            $deselectJs = <<<JS
            
            oTable.setSelectedItem(oDeselect, false);
JS;

        } else {
            $deselectJs = <<<JS

            oTable.__modifyingSelection = true;
            oTable.removeSelectionInterval(iDeselect, iDeselect);
            oTable.__modifyingSelection = false;
JS;

        }
        
        return <<<JS
        
            const oTable = $oEventJs.getSource();
            
            if (oTable.__modifyingSelection) {
                return;
            }
            
            const oModelSelected = oTable.getModel('{$this->getModelNameForSelections()}');
            const bMultiSelect = oTable.getMode !== undefined ? oTable.getMode() === sap.m.ListMode.MultiSelect : {$this->escapeBool($widget->getMultiSelect())};
            const bMultiSelectSave = {$this->escapeBool(($widget instanceof DataTable) && $widget->isMultiSelectSavedOnNavigation())}
            const sUidCol = {$uidColJs};
            var aRowsVisible = [];
            var aRowsMerged = [];
            var aRowsSelectedVisible = {$this->buildJsGetRowsSelected('oTable')};
            var aSelected = null;
            
            // Ignore programmatic selection clears caused by data reloads (e.g. the refresh
            // performed after an Edit/Save action). Such events fire with userInteraction=false
            // and an empty selection. Overwriting the selection model here would erase the stored
            // selection before buildJsDataLoaderOnLoadedRestoreSelection() can restore it, so the
            // user's row selection would be lost on every action. Keep the stored selection instead
            // - a genuine user "deselect all" always reports userInteraction=true and is unaffected.
            var bUserInteraction = (typeof $oEventJs.getParameter === 'function' && $oEventJs.getParameter('userInteraction') !== undefined) ? $oEventJs.getParameter('userInteraction') : true;
            if (bUserInteraction === false && aRowsSelectedVisible.length === 0 && (oModelSelected.getProperty('/rows') || []).length > 0) {
                return;
            }
            
            // Exclude footers from selections.
            if (typeof oTable.getFixedBottomRowCount === 'function' && oTable.getFixedBottomRowCount() > 0) {
                var aSelectedIndices = oTable.getSelectedIndices();
                aRowsVisible = {$this->buildJsGetRowsAll('oTable')};
                bAllRowsSelected = aSelectedIndices.length >= aRowsVisible.length - oTable.getFixedBottomRowCount();
                
                // Restoration also reports a full selection. Only a user event may turn
                // that state into "deselect all"; otherwise a reload would undo restoration.
                if (bAllRowsSelected && oTable._allRowsSelected && bUserInteraction !== false) {
                    // Our little hack to exclude footers breaks the "Deselect all" function,
                    // so we need to emulate it.
                    oTable.__modifyingSelection = true;
                    oTable.clearSelection();
                    oTable.__modifyingSelection = false;
                    
                    oTable._allRowsSelected = false;
                    aRowsSelectedVisible = [];
                } else {
                    for(var i = 1; i <= oTable.getFixedBottomRowCount(); i++) {
                        var iDeselect = aRowsVisible.length - i;
                        // To exclude footers, we assume they are always the last indices in our model
                        // and simply deselect those indices, whenever they are in a selection.
                        if (!aSelectedIndices.includes(iDeselect)) {
                            continue;
                        }
                        
                        // This line excludes footers from the selectionModel.
                        var oDeselect = aRowsSelectedVisible.pop();
                        {$deselectJs}
                    }
                    
                    oTable._allRowsSelected = bAllRowsSelected;
                }
            }

            // Keep selections in collapsed groups even when saving across navigation is disabled.
            const bGroupedTable = typeof oTable.getEnableGrouping === 'function' && oTable.getEnableGrouping();
            if (bMultiSelect === true && (bMultiSelectSave === true || bGroupedTable)) {
                aRowsVisible = {$this->buildJsGetRowsAll('oTable')};
                const aRowsOnPage = aRowsVisible;
                // Let a user "deselect all" clear collapsed rows too. A collapse/expand event
                // or selecting group placeholders must not be mistaken for that user action.
                const bDeselectAll = bGroupedTable && bUserInteraction === true
                    && $oEventJs.getParameter('rowIndex') === -1
                    && $oEventJs.getParameter('selectAll') !== true
                    && aRowsSelectedVisible.length === 0;
                if (bGroupedTable && !bDeselectAll) {
                    // Only rows reachable through the binding can be deselected individually.
                    // Excluding collapsed rows here prevents visible selection changes from losing them.
                    aRowsVisible = [];
                    const oBinding = oTable.getBinding('rows');
                    for (let i = 0; i < oBinding.getLength(); i++) {
                        const oContext = oTable.getContextByIndex(i);
                        const oRow = oContext && oContext.getObject();
                        if (oRow) {
                            aRowsVisible.push(oRow);
                        }
                    }
                }
                // Preserve selected rows hidden in collapsed groups on this page.
                // Rows absent after filtering/paging survive only if navigation saving is enabled.
                oModelSelected.getProperty('/rows').forEach(oRowOld => {
                    if (exfTools.data.indexOfRow(aRowsVisible, oRowOld, sUidCol) === -1
                        && (bMultiSelectSave === true || exfTools.data.indexOfRow(aRowsOnPage, oRowOld, sUidCol) !== -1)) {
                        aRowsMerged.push(oRowOld);
                    }
                });
                // Add all currently visible selected rows
                aRowsMerged.push(...aRowsSelectedVisible);
                aSelected = aRowsMerged;
            } else {
                aSelected = aRowsSelectedVisible;
            }
            
            oModelSelected.setProperty('/rows', aSelected);
            
            {$controller->buildJsEventHandler($this, self::EVENT_NAME_CHANGE, false)};
JS;
    }


    
    /**
     * 
     * @return string
     */
    protected function buildJsBindingOptionsForGrouping()
    {
        $widget = $this->getWidget();
        
        if (! $widget->hasRowGroups()) {
            return '';
        }
        
        $grouper = $widget->getRowGrouper();
        
        $sorterDir = 'true';
        foreach ($this->getWidget()->getSorters() as $sorterUxon) {
            if ($sorterUxon->getProperty('attribute_alias') === $grouper->getGroupByColumn()->getAttributeAlias()) {
                if ($sorterUxon->getProperty('direction') === SortingDirectionsDataType::DESC) {
                    $sorterDir = 'true';
                } else {
                    $sorterDir = 'false';
                }
                break;
            }
        }
        
        $caption = $grouper->getHideCaption() ? '' : $this->escapeJsTextValue($grouper->getCaption());
        $caption .= $caption ? ': ' : '';
        
        // Row grouping is defined inside a sorter, so we must add a client-side sorter to have the
        // groups. Since the actual sorting is normally done elsewhere (in the server or by the data,
        // loader) we use a sorter with a custom compare function here, that does not really do anything.
        // This is important, as the built-in sorter yielded very strage result for some data types like
        // dates.
        return <<<JS
        
                sorter: new sap.ui.model.Sorter(
    				'{$grouper->getGroupByColumn()->getDataColumnName()}', // sPath
    				{$sorterDir}, // bDescending
    				true, // vGroup
                    function(a, b) { // fnComparator
                        return 0;
                    }
    			),
    			groupHeaderFactory: function(oGroup) {
                    // TODO add support for counters
                    return new sap.m.GroupHeaderListItem({
    					title: "{$caption}" + (oGroup.key !== null && oGroup.key !== undefined && oGroup.key !== '' ? oGroup.key : "{$this->escapeJsTextValue($grouper->getEmptyText())}"),
                        type: "Active",
                        press: function(oEvent) {
                            var oHeaderItem = oEvent.getSource();
                            var oList = oHeaderItem.getParent();
                            var iHeaderIdx = oList.indexOfItem(oHeaderItem);
                            var aItems = oList.getItems();
                            var oItem;

                            for (var i=0; i<aItems.length; i++) {
                                if (i <= iHeaderIdx) continue;
                                oItem = aItems[i];
                                if (oItem instanceof sap.m.GroupHeaderListItem) break;
                                if (oItem.getVisible()) {
                                    oItem.setVisible(false);
                                    oHeaderItem.setType('Navigation');
                                } else {
                                    oItem.setVisible(true);
                                    oHeaderItem.setType('Active');
                                }
                            }
                        }
        			});
                },
JS;
    }
    
    /**
     * Returns the javascript constructor for a sap.ui.table.Table
     *
     * @return string
     */
    protected function buildJsConstructorForUiTable(string $oControllerJs = 'oController')
    {
        $widget = $this->getWidget();
        $controller = $this->getController();
        
        $selection_mode = $widget->getMultiSelect() ? 'sap.ui.table.SelectionMode.MultiToggle' : 'sap.ui.table.SelectionMode.Single';
        $selection_behavior = $widget->getMultiSelect() ? 'sap.ui.table.SelectionBehavior.Row' : 'sap.ui.table.SelectionBehavior.RowOnly';
        $striped = $widget->getStriped() ? 'true' : 'false';
        
        
        if ($this->getDynamicPageShowToolbar() === false) {
            $toolbar = $this->buildJsToolbar($oControllerJs, $this->getPaginatorElement()->buildJsConstructor($oControllerJs));
        } else {
            $toolbar = '';
        }
        $enableGrouping = $widget->hasRowGroups() ? 'enableGrouping: true,' : '';
        
        if ($widget->getDragToOtherWidgets() === true) {
            $initDnDJs = <<<JS

                dragDropConfig: [
                    new sap.ui.core.dnd.DragInfo({
                        sourceAggregation: "rows",
                        dragStart: function(oEvent) {
                            var oDraggedRow = oEvent.getParameter("target");
                            var oModel = oDraggedRow.getModel();
                            var oRow = oModel.getProperty(oDraggedRow.getBindingContext().getPath());
                            var oDataSheet = {
                                oId: '{$this->getMetaObject()->getId()}',
                                rows: (oRow ? [oRow] : [])
                            };
                            oEvent.getParameter('browserEvent').dataTransfer.setData("dataSheet", JSON.stringify(oDataSheet));
                        }
                    }),
                ],
JS;
        } else {
            $initDnDJs = '';
        }
        
        $js = <<<JS
            new sap.ui.table.Table("{$this->getId()}", {
                width: "{$this->getWidth()}",
                visibleRowCountMode: sap.ui.table.VisibleRowCountMode.Auto,
                {$this->buildJsPropertyMinAutoRowCount()}
                selectionMode: {$selection_mode},
        		selectionBehavior: {$selection_behavior},
                enableColumnReordering: true,
                fixedColumnCount: {$this->registerUiTableFixedColumns()},
                enableColumnFreeze: true,
                {$enableGrouping}
        		filter: {$controller->buildJsMethodCallFromView('onLoadData', $this)},
        		sort: {$controller->buildJsMethodCallFromView('onLoadData', $this)},
                rowSelectionChange: function (oEvent) { {$this->buildJsPropertySelectionChange('oEvent')} },
                firstVisibleRowChanged: {$controller->buildJsEventHandler($this, self::EVENT_NAME_FIRST_VISIBLE_ROW_CHANGED, true)},
                columnMove: function (oEvent) {
                    // store drag and drop column re-ordering in the model/p13n dialogue, so it can be saved in a setup
                    setTimeout(() => {
                        let oDialog = sap.ui.getCore().byId('{$this->getP13nElement()->getId()}');
                        if (!oDialog) return;
                        let oP13nModel = oDialog.getModel('{$this->getConfiguratorElement()->getModelNameForConfig()}');
                        if (!oP13nModel) return;
                        let aModelCols = oP13nModel.getProperty('/columns') || [];
                        if (!aModelCols.length) return;

                        // Build new /columns order directly from the table (which already reflects the drag/drop).
                        // Map column configs by their ID for quick lookup
                        let oColumnConfigById = {};
                        aModelCols.forEach(o => { if (o && o.column_id) oColumnConfigById[o.column_id] = o; });
                        
                        // Iterate through visible table columns and rebuild the model in their current order
                        let mSeen = {}, aNewModelCols = [];
                        (this.getColumns ? this.getColumns() : []).forEach(oCol => {
                            let oColConfig = oColumnConfigById[oCol.getId()];
                            if (oColConfig && !mSeen[oCol.getId()]) { aNewModelCols.push(oColConfig); mSeen[oCol.getId()] = true; }
                        });
                        
                        // Append model-only entries (hidden/optional columns not rendered in the table) at the end
                        aModelCols.forEach(o => { if (o && o.column_id && !mSeen[o.column_id]) aNewModelCols.push(o); });

                        // update the model
                        oP13nModel.setProperty('/columns', aNewModelCols);
                        oP13nModel.refresh(true);

                        // update the UI of the columns panel in the configurator, to refelect the new column order
                        var oColumnsPanel = sap.ui.getCore().byId('{$this->getP13nElement()->getIdOfColumnsPanel()}');
                        var fnUpdateColumns = oColumnsPanel && oColumnsPanel.data('_exfTabColumnsUpdate');
                        if (typeof fnUpdateColumns === 'function') {
                            fnUpdateColumns.call(oColumnsPanel, false);
                        }
                    }, 0);
                },
        		columnResize: function (oEvent) {
                    // skip if the table is currently auto-resizing
                    if (this.data("_exfIsAutoResizing")) {
                        return;
                    }

                    // otherwise assume its a manual resize, and save in custom width property
                    var sNewWidth = oEvent.getParameter("width");
                    var oColumn = oEvent.getParameter("column");
                    oColumn.data("_exfCustomColWidth", sNewWidth);
                },
                {$this->buildJsPropertyVisibile()}
                {$initDnDJs}
                toolbar: [
        			{$toolbar}
        		],
        		columns: [
        			{$this->buildJsColumnsForUiTable()}
        		],
                noData: [
                    new sap.m.FlexBox({
                        height: "100%",
                        width: "100%",
                        justifyContent: "Center",
                        alignItems: "Center",
                        items: [
                            new sap.m.Text("{$this->getIdOfNoDataOverlay()}", {text: {$this->escapeString($widget->getEmptyText())}, textAlign: 'Center'}).addStyleClass('sapUiResponsiveMargin'),
                        ]
                    })
                ],
                rows: "{/rows}"
        	}).addStyleClass('rowAlternate-'+{$striped})
            {$this->buildJsPreserveGroupHeaderSelection()}
            {$this->buildJsHeaderFilterFunctions()}
            {$this->buildJsClickHandlers('oController')}
            {$this->buildJsPseudoEventHandlers()}

JS;
            
            return $js;
    }
    
    /**
     * 
     * @return string
     */
    protected function getIdOfNoDataOverlay() : string
    {
        return $this->getId() . '_noData';
    }
    
    /**
     * 
     * @return string
     */
    protected function buildJsPropertyMinAutoRowCount() : string
    {
        $widget = $this->getWidget();
        $heightInRows = $widget instanceof DataTable ? $widget->getHeightInRows() : null;
        $heightInRowsDefault = $this->getFacade()->getConfig()->getOption('WIDGET.DATATABLE.ROWS_SHOWN_BY_DEFAULT');
        $height = $widget->getHeight();
        $singleRowHeightPx = $this->getTableRowHeightPx();

        switch (true) {
            case $heightInRows !== null:
                $minAutoRowCount = $heightInRows;
                break;
            case $height->isRelative():
            case $height->isFacadeSpecific() && StringDataType::endsWith($height->getValue(), 'px', false):
                // TODO determine the height elements via JS
                // iRowHeight = oTable.getRowHeight() // but oTable is not there yet. Maybe on-resize?
                $heightPx = StringDataType::substringBefore($height->getValue(), 'px', $height->getValue(), true);
                $heightPx = NumberDataType::cast($heightPx);
                $minAutoRowCount = <<<JS
                function(){
                    var iRowHeight = {$singleRowHeightPx};
                    var jqTest = $('<div class="sapMTB sapMTBHeader-CTX"></div>').appendTo('body');
                    var iToolbarHeight = jqTest.height();
                    var iTableHeight = {$heightPx};
                    jqTest.remove();

                    // relative values, such as 1,2,3 etc. might lead to negative values here, which isnt allowed. 
                    // the minimum must be 1, otherwise js errors are thrown
                    return Math.max(1, Math.floor((iTableHeight - iRowHeight - iToolbarHeight) / iRowHeight));
                }()

JS;
                break;
            // Calculate the max. number of rows, that will fit the given percentage of the
            // height of the container. Note, the immediate container might be simply the
            // FormElement, that will always have the same height as the table, so look for
            // the Form further up the hierarchy
            // TODO add other container types in case the table is not part of the form
            case $height->isPercentual():
            case $height->isMax():
                if ($height->isPercentual()) {
                    $heightPercent = StringDataType::substringBefore($height->getValue(), '%', $height->getValue());
                    $heightPercent = NumberDataType::cast($heightPercent);
                } else {
                    $heightPercent = 100;
                }
                // NOTE: this JS code makes use of the oController variable. Since this is called inside the
                // table constructor, oController should always be defined as 
                $minAutoRowCount = <<<JS
                function(){
                    var iRowHeight = iHeaderHeight = {$singleRowHeightPx};
                    var iRowsDefault = {$heightInRowsDefault};
                    var jqTest = $('<div class="sapMTB sapMTBHeader-CTX"></div>').appendTo('body');
                    var iToolbarHeight = jqTest.height();
                    var fnCalcHeight = function(oContainer){
                        var jqContainer = oContainer.$();
                        var iContainerHeight = jqContainer ? jqContainer.innerHeight() : null;
                        var iTargetHeight;
                        if (iContainerHeight) {
                            iTargetHeight = iContainerHeight / 100 * {$heightPercent};
                            return Math.floor((iTargetHeight - iHeaderHeight - iToolbarHeight) / iRowHeight);
                        }
                        return null;
                    };
                    jqTest.remove();

                    setTimeout(function(){
                        var oTable = sap.ui.getCore().byId('{$this->getId()}');
                        var oContainer = oController.findParentOfType(oTable, sap.ui.layout.form.Form);
                        var bResizing;
                        if (! oContainer) {
                            return;
                        }

                        sap.ui.core.ResizeHandler.register(oContainer, function(){
                            var iRows;
                            if (bResizing === true) {
                                bResizing = false;
                                return;
                            }
                            bResizing = true;
                            setTimeout(function(){
                                iRows = fnCalcHeight(oContainer);
                                if (iRows !== null) {
                                    oTable.setMinAutoRowCount(iRows);
                                }
                            }, 10);
                        });
                    }, 0);
                    
                    return iRowsDefault;
                }()

JS;
                break;
            //case $height->isUndefined():
            //case $height->isAuto():
            default:
                $minAutoRowCount = $heightInRowsDefault;
                break;            
        }
        
        return "minAutoRowCount: {$minAutoRowCount},";
    }

    /**
     * Returns the table row height used to calculate automatic row counts.
     *
     * @return int
     */
    protected function getTableRowHeightPx() : int
    {
        return 33;
    }
    
    /**
     * Returns a comma separated list of column constructors for sap.ui.table.Table
     *
     * @return string
     */
    protected function buildJsColumnsForUiTable()
    {
        $widget = $this->getWidget();
        $column_defs = '';
        
        // Add dirty-column for offline actions
        if ($this->hasDirtyColumn()) {
            $column_defs .= <<<JS
            
        new sap.ui.table.Column('{$this->getDirtyFlagAlias()}',{
            hAlign: "Center",
            autoResizable: true,
            width: "48px",
            minWidth: 48,
            visible: true,
            template: new sap.m.Button({
                icon: "sap-icon://time-entry-request",
                visible: "{= \$\{{$this->getDirtyFlagAlias()}\}  === true}",
                tooltip: "{i18n>WEBAPP.SHELL.NETWORK.OFFLINE_CHANGES_PENDING}",
                type: sap.m.ButtonType.Transparent,
                press: function(oEvent) {
                    var oBtn = oEvent.getSource();
                    exfLauncher.showOfflineQueuePopoverForItem(
                        "{$widget->getMetaObject()->getAliasWithNamespace()}",
                        "{$widget->getUidColumn()->getDataColumnName()}",
                        oBtn.getModel().getProperty(oBtn.getBindingContext().getPath() + '/{$widget->getUidColumn()->getDataColumnName()}'),
                        oBtn
                    );
                }
            })
        }),
JS;
        }
        
        foreach ($widget->getColumns() as $column) {
            $column_defs .= $this->getFacade()->getElement($column)->buildJsConstructorForUiColumn() . ',';
        }
        
        return $column_defs;
    }
    
    protected function buildJsCellsForMTable()
    {
        $widget = $this->getWidget();
        $cells = '';
        
        
        // Add dirty-column for offline actions
        // NOTE: in the case of sap.m.Table it is important to place the dirty column
        // first because it checks for the UID column and eventually adds it. This MUST
        // happen before columns are rendered as there is no explicit link between columns
        // and cells and having more columns than cells (because of adding the UID column
        // at some point) causes very strange behavior!
        if ($this->hasDirtyColumn()) {
            $cells .= <<<JS
        new sap.m.Button({
            icon: "sap-icon://time-entry-request",
            visible: "{= \$\{{$this->getDirtyFlagAlias()}\}  === true}",
            tooltip: "{i18n>WEBAPP.SHELL.NETWORK.OFFLINE_CHANGES_PENDING}",
            type: sap.m.ButtonType.Transparent,
            press: function(oEvent) {
                var oBtn = oEvent.getSource();
                exfLauncher.showOfflineQueuePopoverForItem(
                    "{$widget->getMetaObject()->getAliasWithNamespace()}",
                    "{$widget->getUidColumn()->getDataColumnName()}",
                    oBtn.getModel().getProperty(oBtn.getBindingContext().getPath() + '/{$widget->getUidColumn()->getDataColumnName()}'),
                    oBtn
                );
            }
        }),
JS;
        }
        
        foreach ($widget->getColumns() as $column) {
            $cells .= $this->getFacade()->getElement($column)->buildJsConstructorForCell() . ",";
        }
        
        return $cells;
    }
    
    /**
     * Returns a comma-separated list of column constructors for sap.m.Table
     *
     * @return string
     */
    protected function buildJsColumnsForMTable()
    {
        $widget = $this->getWidget();
        
        // See if there are promoted columns. If not, make the first visible column promoted,
        // because sap.m.table would otherwise have no column headers at all.
        $promotedFound = false;
        $first_col = null;
        foreach ($widget->getColumns() as $col) {
            if (is_null($first_col) && ! $col->isHidden()) {
                $first_col = $col;
            }
            if ($col->getVisibility() === EXF_WIDGET_VISIBILITY_PROMOTED && ! $col->isHidden()) {
                $promotedFound = true;
                break;
            }
        }
        
        if (! $promotedFound && $first_col !== null) {
            $first_col->setVisibility(EXF_WIDGET_VISIBILITY_PROMOTED);
        }
        
        $column_defs = '';
        
        // Add dirty-column for offline actions
        if ($this->hasDirtyColumn()) {
            $column_defs .= <<<JS
            
                    new sap.m.Column('{$this->getDirtyFlagAlias()}',{
                        hAlign: "Center",
                        importance: "High",
                        visible: false,
                        popinDisplay: sap.m.PopinDisplay.Inline,
						demandPopin: true,
                    }),
JS;
        }
        
        foreach ($this->getWidget()->getColumns() as $column) {
            $column_defs .= $this->getFacade()->getElement($column)->buildJsConstructorForMColumn() . ",";
        }
        
        return $column_defs;
    }
    
    /**
     * {@inheritdoc}
     * @see UI5DataElementTrait::buildJsDataLoaderParams()
     */
    protected function buildJsDataLoaderParams(string $oControlEventJsVar = 'oControlEvent', string $oParamsJs = 'params', $keepPagePosJsVar = 'bKeepPagingPos') : string
    {
        $commonParams = $this->buildJsDataLoaderParamsPaging($oParamsJs, $keepPagePosJsVar);
                  
        if ($this->isUiTable() === true) {       
            $between = ComparatorDataType::BETWEEN;
            $tableParams = <<<JS
          
            // If filtering just now, make sure the filter from the event is set too (eventually overwriting the previous one)
    		// NOTE: adding filters to the P13nDialog works strage: the value of the filter does not change
    		// immediately. So while the first-time filter on a column always works, changing the value via
    		// header menu will not change the value in the configurator, so the table will read with the old
    		// value once, then it will read with the new filter value immediately - ultimately sending two
    		// requests instead of one. As a workaround, we stop the current request (via `if(false==`) and
    		// press "OK" on the configurator instead.
    		if ({$oControlEventJsVar} && {$oControlEventJsVar}.getId() == 'filter'){
                if(
                    false === (function(oEvent) {
                        var oColumn = oEvent.getParameters().column;
                        var sFltrProp = oColumn.getFilterProperty();
                        var sFltrVal = oEvent.getParameters().value;
                        var fnParser = oColumn.data('_exfFilterParser'); 
                        var oParsedInput = exfTools.data.filterComparator.extract(String(sFltrVal));
                        var oParsedValue = exfTools.data.filterComparator.parseValue(oParsedInput, fnParser);
                        var mFltrParsed = oParsedValue.value;
                        var bHasFilter = oParsedValue.hasValue;
    
                        {$oParamsJs}['{$this->getFacade()->getUrlFilterPrefix()}' + sFltrProp] = mFltrParsed;
                        
                        if (bHasFilter) {
                            oColumn.setFiltered(true).setFilterValue(sFltrVal);
                        } else {
                            oColumn.setFiltered(false).setFilterValue('');
                        }  
    
                        // Also synchronize the filter with its explicitly linked advanced-search condition.
                        let oFilterPanel = sap.ui.getCore().byId('{$this->getP13nElement()->getIdOfSearchPanel()}');
                        if (bHasFilter) {
                            oFilterPanel.upsertHeaderCondition({
                                expression: sFltrProp,
                                comparator: oParsedInput.comparator || '=',
                                value: oParsedInput.comparator === '{$between}' ? '' : oParsedInput.value,
                                value_from: oParsedInput.value_from || '',
                                value_to: oParsedInput.value_to || ''
                            });
                        } else {
                            oFilterPanel.removeHeaderCondition(sFltrProp);
                        }
    
                        // Also make sure the built-in UI5-filtering is not applied.
                        oEvent.cancelBubble();
                        oEvent.preventDefault();
    
                        // apply the changes from the p13n dialogue 
                        let oP13nDialog = sap.ui.getCore().byId('{$this->getP13nElement()->getId()}'); 
                        if (oP13nDialog) {
                            // Cancel the current read request and press "OK" on the configurator instead
                            // See more comments above in PHP
                            oP13nDialog.fireOk();
                            return false;
                        }
                        return true;
                    })($oControlEventJsVar)
                ) {
                    return Promise.resolve(oTable.getModel());
                }
            }
    		
    		// If sorting just now, overwrite the sort string and make sure the sorter in the configurator is set too
    		if ({$oControlEventJsVar} && {$oControlEventJsVar}.getId() == 'sort'){
                (function(oEvent) {
                    {$oParamsJs}.sort = oEvent.getParameters().column.getSortProperty();
                    {$oParamsJs}.order = oEvent.getParameters().sortOrder === 'Descending' ? 'desc' : 'asc';
                    
                    // get p13n model 
                    let oSortPanel = sap.ui.getCore().byId('{$this->getP13nElement()->getIdOfSortPanel()}');
                    let oConfModel = oSortPanel.getModel('{$this->getConfiguratorElement()->getModelNameForConfig()}');
    
                    // new sorter object
                    let oNewSorter = {
                        attribute_alias: oEvent.getParameters().column.getSortProperty(),
                        direction: oEvent.getParameters().sortOrder
                    };
                    
                    // update the model/refresh
                    oConfModel.setProperty('/sorters', [oNewSorter]);
                    oConfModel.refresh(true);
    
                    // Also make sure, the built-in UI5-sorting is not applied.
                    oEvent.cancelBubble();
                    oEvent.preventDefault();
                })($oControlEventJsVar)
    		}

            // Set sorting indicators for columns
            var aSortProperties = ({$oParamsJs}.sort ? {$oParamsJs}.sort.split(',') : []);
            var aSortOrders = ({$oParamsJs}.sort ? {$oParamsJs}.order.split(',') : []);
            var iIdx = -1;
            sap.ui.getCore().byId('{$this->getId()}').getColumns().forEach(function(oColumn){
                iIdx = aSortProperties.indexOf(oColumn.getSortProperty());
                if (iIdx > -1) {
                    oColumn.setSorted(true);
                    oColumn.setSortOrder(aSortOrders[iIdx] === 'desc' ? sap.ui.table.SortOrder.Descending : sap.ui.table.SortOrder.Ascending);
                } else {
                    oColumn.setSorted(false);
                }
            });
            
            // Make sure, the column filter indicator is ON if the column is filtered over via advanced search 
            (function(){
                var oSearchPanel = sap.ui.getCore().byId('{$this->getConfiguratorElement()->getIdOfSearchPanel()}');
                var aSearchFItems = oSearchPanel.getConditions();
                var aColumns = oTable.getColumns();
                aColumns.forEach(function(oColumn) {
                    var sFilterVal = oColumn.getFilterValue();
                    var bFiltered = sFilterVal !== '' && sFilterVal !== null && sFilterVal !== undefined;
                    if (bFiltered) {
                        return;
                    }
                    aSearchFItems.forEach(function(oItem){
                        if (oItem.expression === oColumn.data('_exfAttributeAlias') && oSearchPanel.hasConditionValue(oItem)) {
                            bFiltered = true;
                        }
                    });
                    oColumn.setFiltered(bFiltered);
                });
            })();
		
JS;
        } elseif ($this->isMTable()) {
            $tableParams = <<<JS
            // visible columns are now added to request data in UI5DataConfigurator->buildJsDataGetter  

            // Set sorting indicators for columns
            var aSortProperties = ({$oParamsJs}.sort ? {$oParamsJs}.sort.split(',') : []);
            var aSortOrders = ({$oParamsJs}.sort ? {$oParamsJs}.order.split(',') : []);
            var iIdx = -1;
            sap.ui.getCore().byId('{$this->getId()}').getColumns().forEach(function(oColumn){
                iIdx = aSortProperties.indexOf(oColumn.data('_exfAttributeAlias'));
                if (iIdx > -1) {
                    oColumn.setSortIndicator(aSortOrders[iIdx] === 'desc' ? 'Descending' : 'Ascending');
                } else {
                    oColumn.setSortIndicator(sap.ui.core.SortOrder.None);
                }
            });

JS;
        }
		
        return $commonParams . $tableParams;
    }

    /**
     * Returns inline JS code to refresh the table.
     *
     * If the code snippet is to be used somewhere, where the controller is directly accessible, you can pass the
     * name of the controller variable to $oControllerJsVar to increase performance.
     *
     * @see \exface\Core\Facades\AbstractAjaxFacade\Elements\AbstractJqueryElement::buildJsRefresh()
     *
     * @param bool $keepPagingPos
     * @param string $oControllerJsVar
     *
     * @return UI5DataTable
     */
    public function buildJsRefresh(bool $keepPagingPos = false, string $oControllerJsVar = null)
    {
        $params = "undefined, " . ($keepPagingPos ? 'true' : 'false');
        if ($oControllerJsVar === null) {
            return $this->getController()->buildJsMethodCallFromController('onLoadData', $this, $params);
        } else {
            return $this->getController()->buildJsMethodCallFromController('onLoadData', $this, $params, $oControllerJsVar);
        }
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractAjaxFacade\Elements\AbstractJqueryElement::buildJsDataGetter()
     */
    public function buildJsDataGetter(ActionInterface $action = null)
    {
        $widget = $this->getWidget();
        $dataObj = $this->getMetaObjectForDataGetter($action);
        
        $data = <<<JS
{
            oId: '{$this->getWidget()->getMetaObject()->getId()}',
            rows: aRows
        }
JS;
        if ($action !== null && $action->isDefinedInWidget() && $action->getWidgetDefinedIn() instanceof DataButton) {
            $customMode = $action->getWidgetDefinedIn()->getInputRows();
        } else {
            $customMode = null;
        }
        
        switch (true) {
            // If no action is specified, return the entire row model
            case $customMode === DataButton::INPUT_ROWS_ALL:
            case $action === null:
                $aRowsJs = "{$this->buildJsGetRowsAll('oTable')} || []";
                break;
            
            // If the button requires none of the rows explicitly
            case $customMode === DataButton::INPUT_ROWS_NONE:
                return '{}';
                
            // If we are reading, than we need the special data from the configurator
            // widget: filters, sorters, etc.
            case $action instanceof iReadData:
                $oDataJs = $this->getConfiguratorElement()->buildJsDataGetter($action);
                return $oDataJs;
                
            // Editable tables with modifying actions return all rows either directly or as subsheet
            case $customMode === DataButton::INPUT_ROWS_ALL_AS_SUBSHEET:
            case $this->isEditable() && ($action instanceof iModifyData):
            case $this->isEditable() && ($action instanceof iCallOtherActions) && $action->containsActionClass(iModifyData::class):
                $aRowsJs = "{$this->buildJsGetRowsAll('oTable')} || []";
                switch (true) {
                    case $dataObj->is($widget->getMetaObject()) && $customMode !== DataButton::INPUT_ROWS_ALL_AS_SUBSHEET:
                    case $action->getInputMapper($widget->getMetaObject()) !== null && $customMode !== DataButton::INPUT_ROWS_ALL_AS_SUBSHEET:
                        break;
                    default:
                        // If the data is intended for another object, make it a nested data sheet
                        // If the action is based on the same object as the widget's parent, use the widget's
                        // logic to find the relation to the parent. Otherwise try to find a relation to the
                        // action's object and throw an error if this fails.
                        if ($widget->hasParent() && $dataObj->is($widget->getParent()->getMetaObject()) && $relPath = $widget->getObjectRelationPathFromParent()) {
                            $relAlias = $relPath->toString();
                        } elseif ($relPath = $dataObj->findRelationPath($widget->getMetaObject())) {
                            $relAlias = $relPath->toString();
                        }
                        
                        if ($relAlias === null || $relAlias === '') {
                            throw new WidgetLogicError($widget, 'Cannot use editable table with object "' . $widget->getMetaObject()->getName() . '" (alias ' . $widget->getMetaObject()->getAliasWithNamespace() . ') as input widget for action "' . $action->getName() . '" with object "' . $dataObj->getName() . '" (alias ' . $dataObj->getAliasWithNamespace() . '): no forward relation could be found from action object to widget object!', '7B7KU9Q');
                        }
                        $data = <<<JS
({$this->buildJsIsDataPending()} ? {} : {
            oId: '{$dataObj->getId()}',
            rows: [
                {
                    '{$relAlias}': {
                        oId: '{$widget->getMetaObject()->getId()}',
                        rows: aRows
                    }
                }
            ]
        })
            
JS;
                }
                break;
                
            // In all other cases the data are the selected rows
            default:
                if (($widget instanceof iSupportMultiSelect) && $widget->getMultiSelect() === true) {
                    $aRowsJs = "oTable.getModel('{$this->getModelNameForSelections()}').getProperty('/rows')";
                } else {
                    $aRowsJs = $this->buildJsGetRowsSelected('oTable');
                }
                
        }
        
        // Determine the columns we need in the actions data
        $colNamesList = implode(',', $widget->getActionDataColumnNames());
        
        return <<<JS
    function() {
        var oTable = sap.ui.getCore().byId('{$this->getId()}');
        var oDirtyColumn = sap.ui.getCore().byId('{$this->getDirtyFlagAlias()}');
        var aRows = {$aRowsJs};
        
        // Remove any keys, that are not in the columns of the widget
        aRows = aRows.map(({ $colNamesList }) => ({ $colNamesList }));

        return $data;
    }()
JS;
    }

    /**
     * Restores saved selections after loading data, batching UI table ranges into one change notification.
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\Traits\UI5DataElementTrait::buildJsDataLoaderOnLoadedRestoreSelection()
     */
    protected function buildJsDataLoaderOnLoadedRestoreSelection(string $oTableJs) : string
    {
        $widget = $this->getWidget();
        if ($widget->getMultiSelect() === true ) {
            $uidColJs = $widget->hasUidColumn() ? $this->escapeString($widget->getUidColumn()->getDataColumnName()) : 'null';
            if ($this->isUiTable()) {
                // Prevent the tab from freezing on reload after "select all":
                // collect target binding indices once and restore them as a guarded batch
                // to avoid repeated selection resets and excessive change notifications.
                return <<<JS
                setTimeout(function(oTable) {
                    const aPrevSelectedRows = oTable.getModel('{$this->getModelNameForSelections()}').getProperty('/rows');
                    if (aPrevSelectedRows === undefined) {
                        return;
                    }
                    const oBinding = oTable.getBinding('rows');
                    const aSelectedIndices = oTable.getSelectedIndices();
                    const aRestoreIndices = [];
                    const aGroupIndices = [];
                    const bGroupedTable = typeof oTable.getEnableGrouping === 'function' && oTable.getEnableGrouping();
                    let bAllRowsSelected = true;
                    // Match saved row identities against the current binding to prevent selecting
                    // the wrong rows after filtering/reordering. Match only business rows, not
                    // group headers; exclude footers from selection altogether.
                    const iSelectableLength = oBinding.getLength() - oTable.getFixedBottomRowCount();
                    for (let i = 0; i < iSelectableLength; i++) {
                        if (typeof oBinding.isGroupHeader === 'function' && oBinding.isGroupHeader(i)) {
                            if (bGroupedTable) {
                                aGroupIndices.push(i);
                            }
                            continue;
                        }
                        const oContext = oTable.getContextByIndex(i);
                        const oRow = oContext && oContext.getObject();
                        if (oRow && exfTools.data.indexOfRow(aPrevSelectedRows, oRow, {$uidColJs}) !== -1) {
                            aRestoreIndices.push(i);
                        } else if (bGroupedTable && oContext && !oRow) {
                            // Grouped JSON bindings can expose header contexts without a business row.
                            aGroupIndices.push(i);
                        } else {
                            bAllRowsSelected = false;
                        }
                    }
                    // Include synthetic headers only when all reachable business rows are selected.
                    // This keeps "select all" checked after restoration so the next click deselects,
                    // rather than merely selecting missing headers. Saved rows remain header-free.
                    if (bAllRowsSelected && aRestoreIndices.length > 0 && aGroupIndices.length > 0) {
                        aRestoreIndices.push(...aGroupIndices);
                        aRestoreIndices.sort((iLeft, iRight) => iLeft - iRight);
                    }
                    if (aPrevSelectedRows.length === 0 && aSelectedIndices.length === 0) {
                        return;
                    }
                    // Leave an already-correct selection untouched to avoid redundant UI5 events.
                    const bSelectionChanged = aSelectedIndices.length !== aRestoreIndices.length
                        || aSelectedIndices.some((iIndex, i) => iIndex !== aRestoreIndices[i]);
                    // Ignore intermediate clear/add events so they cannot overwrite saved rows
                    // or trigger dependent widgets before the complete selection is restored.
                    const bModifyingSelection = oTable.__modifyingSelection;
                    oTable.__modifyingSelection = true;
                    try {
                        if (bSelectionChanged) {
                            oTable.clearSelection();
                            // Limit selection mutations by clearing once and adding contiguous ranges.
                            // A full selection needs only one interval, keeping select-all reloads responsive.
                            for (let i = 0; i < aRestoreIndices.length; i++) {
                                const iFrom = aRestoreIndices[i];
                                let iTo = iFrom;
                                while (i + 1 < aRestoreIndices.length && aRestoreIndices[i + 1] === iTo + 1) {
                                    iTo = aRestoreIndices[++i];
                                }
                                oTable.addSelectionInterval(iFrom, iTo);
                            }
                        }
                    } finally {
                        oTable.__modifyingSelection = bModifyingSelection;
                    }
                    // Reconcile saved rows and notify dependents once, as a programmatic change.
                    oTable.fireRowSelectionChange({
                        rowIndex: -1,
                        rowIndices: aRestoreIndices,
                        selectAll: false,
                        userInteraction: false
                    });
                }, 0, {$oTableJs});

JS;
            }

            // Restore previous selection
            return <<<JS
                setTimeout(function(oTable) {
                    const oModelSelected = oTable.getModel('{$this->getModelNameForSelections()}');
                    const aPrevSelectedRows = oModelSelected.getProperty('/rows');
                    const aNowSelectedRows = {$this->buildJsGetRowsSelected($oTableJs, true)};
                    const aRows = {$this->buildJsGetRowsAll($oTableJs)};
                    const sUidCol = $uidColJs;
                    const fnSelect = function(iRowIdx) {
                        {$this->buildJsSelectRowByIndex($oTableJs, 'iRowIdx', false, 'false')}
                    };
                    const fnDeselect = function(iRowIdx) {
                        {$this->buildJsSelectRowByIndex($oTableJs, 'iRowIdx', true, 'false')}
                    }
                    if (aPrevSelectedRows === undefined) {
                        return;
                    }
                    aNowSelectedRows.forEach(function (oRow) {
                        var bSelected = exfTools.data.indexOfRow(aPrevSelectedRows, oRow, sUidCol) > -1;
                        var iRowIdx = exfTools.data.indexOfRow(aRows, oRow, sUidCol);
                        if (iRowIdx === -1) {
                            return;
                        }
                        if (bSelected) {
                            fnSelect(iRowIdx);
                        } else {
                            fnDeselect(iRowIdx);
                        }
                    });
                    aPrevSelectedRows.forEach(function (oRow) {
                        var iRowIdx = exfTools.data.indexOfRow(aRows, oRow, sUidCol);
                        if (iRowIdx === -1) {
                            return;
                        } else {
                            fnSelect(iRowIdx);
                        }
                    });
                }, 0, {$oTableJs});

JS;
        } else {
            // Single-select: restore the previously selected row by UID after a reload (e.g. the
            // refresh following an Edit/Save). The row is persisted in the selections model by
            // buildJsPropertySelectionChange(). Without a UID column the row cannot be matched
            // reliably after its data changed, so restoration is skipped.
            if (! $widget->hasUidColumn()) {
                return '';
            }
            $uidColJs = $this->escapeString($widget->getUidColumn()->getDataColumnName());
            return <<<JS
                setTimeout(function(oTable) {
                    const oModelSelected = oTable.getModel('{$this->getModelNameForSelections()}');
                    if (! oModelSelected) {
                        return;
                    }
                    const aPrevSelectedRows = oModelSelected.getProperty('/rows') || [];
                    if (aPrevSelectedRows.length === 0) {
                        return;
                    }
                    const aRows = {$this->buildJsGetRowsAll($oTableJs)};
                    const sUidCol = $uidColJs;
                    var iRowIdx = exfTools.data.indexOfRow(aRows, aPrevSelectedRows[0], sUidCol);
                    if (iRowIdx === -1) {
                        return;
                    }
                    {$this->buildJsSelectRowByIndex($oTableJs, 'iRowIdx', false, 'false')}
                }, 0, {$oTableJs});

JS;
        }
    }
    
    /**
     * 
     * @see UI5DataElementTrait::buildJsGetRowsSelected()
     */
    protected function buildJsGetRowsSelected(string $oTableJs) : string
    {
        if ($this->isUiTable()) {
            if($this->getWidget()->getMultiSelect() === false) {
                $rows = "($oTableJs && $oTableJs.getSelectedIndex() !== -1 && $oTableJs.getContextByIndex($oTableJs.getSelectedIndex()) !== undefined ? [$oTableJs.getContextByIndex($oTableJs.getSelectedIndex()).getObject()] : [])";
            } else {
                $rows = <<<JS
                    (function(oTable){
                        var selectedIdx = oTable.getSelectedIndices(); 
                        var aRows = []; 
                        selectedIdx.forEach(function(i) {
                            var oCtxt = oTable.getContextByIndex(i);
                            if (oCtxt && oCtxt.getObject()) {
                                aRows.push(oCtxt.getObject());
                            }
                        }); 
                        return aRows;
                    })($oTableJs)
JS;
            }
        } else {
            if($this->getWidget()->getMultiSelect() === false) {
                $rows = "($oTableJs && $oTableJs.getSelectedItem() ? [$oTableJs.getSelectedItem().getBindingContext().getObject()] : [])";
            } else {
                $rows = <<<JS
                    $oTableJs.getSelectedContexts().reduce(
                        function(aRows, oCtxt) {
                            aRows.push(oCtxt.getObject()); 
                            return aRows;
                        },
                        []
                    )
JS;
            }
        }
        return $rows;
    }
        
    /**
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::buildJsValueGetter()
     */
    public function buildJsValueSetter($value, $dataColumnName = null, $rowNr = null)
    {
        if ($rowNr === null) {
            if ($this->isUiTable()) {
                $rowNr = "oTable.getSelectedIndex()";
            } else {
                $rowNr = "oTable.indexOfItem(oTable.getSelectedItem())";
            }
        }
        
        if ($dataColumnName === null) {
            $dataColumnName = $this->getWidget()->getUidColumn()->getDataColumnName();
        }
        
        return <<<JS
        
function(){
    var oTable = sap.ui.getCore().byId('{$this->getId()}');
    var oModel = oTable.getModel();
    var iRowIdx = {$rowNr};
    
    if (iRowIdx !== undefined && iRowIdx >= 0) {
        var aData = oModel.getData().rows;
        aData[iRowIdx]["{$dataColumnName}"] = $value;
        oModel.setProperty("/rows", aData);
        // TODO why does the code below not work????
        // oModel.setProperty("/rows(" + iRowIdx + ")/{$dataColumnName}", {$value});
    }
}()

JS;
    }
        
    /**
     * Returns an inline JS-condition, that evaluates to TRUE if the given oTargetDom JS expression
     * is a DOM element inside a list item or table row.
     * 
     * This is important for handling browser events like dblclick. They can only be attached to
     * the entire control via attachBrowserEvent, while we actually only need to react to events
     * on the items, not on headers, footers, etc.
     * 
     * @param string $oTargetDomJs
     * @return string
     */
    protected function buildJsClickIsTargetRowCheck(string $oTargetDomJs = 'oTargetDom') : string
    {
        if ($this->isUiTable()) {
            return "{$oTargetDomJs} !== undefined && $({$oTargetDomJs}).parents('.sapUiTableCCnt').length > 0";
        }
        
        if ($this->isMTable()) {
            return "{$oTargetDomJs} !== undefined && ($({$oTargetDomJs}).parents('tr.sapMListTblRow:not(.sapMListTblHeader)').length > 0 || $({$oTargetDomJs}).parents('tr.sapMListTblSubRow').length > 0)";
        }
        
        if ($this->isMList()) {
            return "{$oTargetDomJs} !== undefined && $({$oTargetDomJs}).parents('li.sapMSLI').length > 0";
        }
        
        return 'true';
    }
    
    /**
     * 
     * @param string $oDomElementClickedJs
     * @return string
     */
    protected function buildJsClickGetRowIndex(string $oDomElementClickedJs) : string
    {
        if ($this->isUiTable()) {
            return <<<JS
(function(domEl){
    var oTable = sap.ui.getCore().byId('{$this->getId()}');
    var jqTr = $(domEl).parents('tr');
    var oRow;
    var oContext;
    var sPath;
    var iRowIdx;

    // In grouped tables, the DOM row index includes group headers. The binding path
    // (/rows/N) points to the actual model row and is safer to use for selection.
    if (jqTr.length > 0) {
        oRow = sap.ui.getCore().byId(jqTr[0].id);
        if (oRow && typeof oRow.getBindingContext === 'function') {
            oContext = oRow.getBindingContext();
            sPath = oContext ? oContext.getPath() : '';
            if (sPath.indexOf('/rows/') === 0) {
                iRowIdx = parseInt(sPath.substring('/rows/'.length), 10);
                if (! Number.isNaN(iRowIdx)) {
                    return iRowIdx;
                }
            }
        }
    }

    // (old) fallback for unexpected rendering variants
    return oTable.getFirstVisibleRow() + $(domEl).parents('tr').index();
})({$oDomElementClickedJs})
JS;
        } 
        
        if ($this->isMTable()) {
            // NOTE: sap.m.Table with row groups will count group headers as "items" - same as the actual rows.
            // So we can't call `indexOfItem()` here. Instead, to get the index of the real row, we need to filter 
            // away the group headers explicitly
            return <<<JS
(function(){
    var jqTr = $({$oDomElementClickedJs}).parents('tr');
    var oItem;
    var iIdx = -1;
    if (jqTr.hasClass('sapMListTblSubRow')) {
        jqTr = jqTr.prev();
    }
    oItem = sap.ui.getCore().byId(jqTr[0].id);
    if (oItem) {
        iIdx = sap.ui.getCore().byId('{$this->getId()}')
            .getItems()
            .filter(function(oItem){
                return oItem.getBindingContext() !== undefined
            })
            .indexOf(oItem);
    }
    return iIdx;
})()
JS;
        }
           
        if ($this->isMList()) {
            return "$({$oDomElementClickedJs}).parents('li.sapMSLI').length";
        }
        
        return "-1";
    }
    
    /**
     * 
     * @see UI5DataElementTrait::buildJsClickGetColumnAttributeAlias()
     */
    protected function buildJsClickGetColumnAttributeAlias(string $oDomElementClickedJs) : string
    {
        if ($this->isUiTable()) {
            return "(function(domEl){var oCell = sap.ui.getCore().byId($(domEl).closest('[data-sap-ui-colid]').data('sap-ui-colid')); return oCell ? oCell.data('_exfAttributeAlias') : null;})($oDomElementClickedJs)";
        }
        if ($this->isMTable()) {
            return "(function(domEl){var oCell = sap.ui.getCore().byId($(domEl).closest('[data-sap-ui-column]').data('sap-ui-column')); return oCell ? oCell.data('_exfAttributeAlias') : null;})($oDomElementClickedJs)";
        }
        return "null";
    }
    
    /**
     * 
     * {@inheritdoc}
     * @see UI5DataElementTrait::buildJsClickHandlerLeftClick()
     */
    protected function buildJsClickHandlerLeftClick($oControllerJsVar = 'oController') : string
    {
        // IDEA Theoretically the sap.m.ListBase has it's own support for a context menu, but that triggers
        // the browser context menu too. Could not find a way to avoid it, so we use a custom context
        // menu here. This requires an empty menu in the contextMenu property of the list control - 
        // see. buildJsConstructorForMTable()
        
        // Single click. Currently only supports one click action - the first one in the list of buttons
        if ($leftclick_button = $this->getWidget()->getButtonsBoundToMouseAction(EXF_MOUSE_ACTION_LEFT_CLICK)[0]) {
            if ($this->isUiTable()) {
                return <<<JS
                
            .attachBrowserEvent("click", function(oEvent) {
        		var oTargetDom = oEvent.target;
                if(! ({$this->buildJsClickIsTargetRowCheck('oTargetDom')})) return;

                {$this->getFacade()->getElement($leftclick_button)->buildJsClickEventHandlerCall($oControllerJsVar)};
            })
JS;
            } else {
                return <<<JS
                
            .attachItemPress(function(oEvent) {
                var oListItem = oEvent.getParameters().listItem;
                if (oListItem === undefined || oListItem.getMetadata().getName() === 'sap.m.GroupHeaderListItem') {
                    return;
                }

                {$this->getFacade()->getElement($leftclick_button)->buildJsClickEventHandlerCall($oControllerJsVar)};
            })
JS;
            }
        }
        
        return '';
    }
    
    /**
     * 
     * {@inheritdoc}
     * @see UI5DataElementTrait::buildJsDataLoaderOnLoaded()
     */
    protected function buildJsDataLoaderOnLoaded(string $oModelJs = 'oModel') : string
    {
        $paginator = $this->getPaginatorElement();
        $uidColNameJs = $this->getDataWidget()->hasUidColumn() ? "'{$this->getDataWidget()->getUidColumn()->getDataColumnName()}'" : 'undefined';
        
        // Add single-result action to onLoadSuccess. Make sure it is only fired once as
        // long as the same UID is selected. This means, if the row itself changes (e.g.
        // being saved from the single-select-action) it is still regarded as the same row
        // as long as it has the same UID. Thus the action will not get called repeatedly.
        if (($singleResultButton = $this->getWidget()->getButtons(function($btn) {return ($btn instanceof DataButton) && $btn->isBoundToSingleResult() === true;})[0]) || $this->getWidget()->getSelectSingleResult()) {
            $buttonClickJs = '';
            if ($singleResultButton) {
                $buttonClickJs = <<<JS

                    if (lastRow === undefined || exfTools.data.compareRows(curRow, lastRow, sUidCol) === false) {
                        oTable._singleResultActionPerformedFor = curRow;
                        {$this->getFacade()->getElement($singleResultButton)->buildJsClickEventHandlerCall('oController')};
                    }
JS;
            }
            $singleResultJs = <<<JS

            (function(oTable, oModel){
                if (oModel.getData().rows.length === 1) {
                    var sUidCol = $uidColNameJs;
                    var curRow = {$oModelJs}.getData().rows[0];
                    var lastRow = oTable._singleResultActionPerformedFor;
                    {$this->buildJsSelectRowByIndex('oTable', '0')}
                    {$buttonClickJs}                
                } else {
                    oTable._singleResultActionPerformedFor = {};
                }
            })(oTable, {$oModelJs});            
JS;
        }
                    
        // For some reason, the sorting indicators on the column are changed to the opposite after
        // the model is refreshed. This hack fixes it by forcing sorted columns to keep their
        // indicator.
        $uiTablePostprocessing = '';
        $uiTableSetFooterRows = '';
        if ($this->isUiTable() === true) {
            $this->getController()->addMethod(self::CONTROLLER_METHOD_RESIZE_COLUMNS, $this, 'oTable, oModel', $this->buildJsUiTableColumnResize('oTable', 'oModel'));
            
            $uiTablePostprocessing .= <<<JS

            oTable.getColumns().forEach(function(oColumn){
                if (oColumn.getSorted() === true) {
                    var order = oColumn.getSortOrder()
                    setTimeout(function(){
                        oColumn.setSortOrder(order);
                    }, 0);
                }
            });
JS;
            $uiTableSetFooterRows = <<<JS

            if (footerRows){
				oTable.setFixedBottomRowCount(parseInt(footerRows));
			}
JS;
            
            // Weird code to make the table fill it's container. If not done, tables within
            // sap.f.Card will not be high enough. 
            $uiTablePostprocessing .= 'oTable.setVisibleRowCountMode("Fixed").setVisibleRowCountMode("Auto");';
            
            // Make sure, row grouping works
            $uiTablePostprocessing .= $this->buildJsUiTableInitRowGrouping('oTable', 'oModel');
            
            // Optimize column width AFTER all columns are rendered
            // TODO #ui5-update mode column width optimization to rowsUpdated event of sap.ui.table.Table (available since 1.86)
            $uiTablePostprocessing .= <<<JS

            setTimeout(function(){
                {$this->getController()->buildJsMethodCallFromController(self::CONTROLLER_METHOD_RESIZE_COLUMNS, $this, 'oTable, ' . $oModelJs)}
            }, 100);
JS;
            
            // TODO #ui5-update move stylers to rowsUpdated event of sap.ui.table.Table (available since 1.86)
            if ($stylersJs = $this->buildJsColumnStylers()) {
                $this->getController()->addOnEventScript($this, self::EVENT_NAME_FIRST_VISIBLE_ROW_CHANGED, "setTimeout(function(){ $stylersJs }, 0);");
                $uiTablePostprocessing .= <<<JS

            setTimeout(function(){
                $stylersJs
            }, 500);
JS;
            }
        }
        
        $updatePaginator = '';
        if ($this->hasPaginator()) {
            $updatePaginator = <<<JS
            
            {$paginator->buildJsSetTotal($oModelJs . '.getProperty("/recordsFiltered")', 'oController')};
            {$paginator->buildJsRefresh('oController')};
JS;
        }
        return $this->buildJsDataLoaderOnLoadedViaTrait($oModelJs) . <<<JS

			var footerRows = {$oModelJs}.getProperty("/footerRows");
            {$uiTableSetFooterRows}
            {$updatePaginator}
            {$this->getController()->buildJsEventHandler($this, self::EVENT_NAME_CHANGE, false)};
            {$singleResultJs};
            {$uiTablePostprocessing};
            {$this->buildJsCellConditionalDisablers()};           
JS;
    }
    
    /**
     * To get the experimental row grouping of the ui.table working, we need to
     * 
     * 1. set `enableGrouping` of the table (see `buildJsConstructorForUiTable()`)
     * 2. set the `grouped` flag on the column (see `UI5DataColumn::buildJsConstructorForUiColumn()`)
     * 3. pass the column or its id to the table via `setGroupBy` which is done here
     * 
     * Strangely `.setGroupBy()` fails if the column has no model data, so we
     * must do it here after the model was loaded.
     * 
     * Also note, that group names are cached in the context of each row binding context,
     * so we must call resetExperimentalGrouping() every time data is replaced.
     * See `sap.ui.table.utils._GroupingUtils` in UI5 for more details.
     * 
     * In contrast to the sap.m.Table, there is no official way to influence the group names,
     * so we use a hack here to explicitly set them in the group info of the contexts, which
     * is used by UI5 internally.
     * 
     * @param string $oTableJs
     * @param string $oModelJs
     * @return string
     */
    protected function buildJsUiTableInitRowGrouping(string $oTableJs, string $oModelJs) : string
    {
        if (! $this->getWidget()->hasRowGroups()) {
            return '';
        }
        $grouper = $this->getWidget()->getRowGrouper();
        $groupFormatterJs = $this->getFacade()->getDataTypeFormatter($grouper->getGroupByColumn()->getDataType())->buildJsFormatter('mVal');
        $groupCaption = $grouper->getHideCaption() ? '' : $this->escapeJsTextValue($grouper->getCaption());
        $groupCaption .= $groupCaption ? ': ' : '';
        switch ($grouper->getExpandGroups()) {
            case DataRowGrouper::EXPAND_NO_GROUPS: $expandGroupJs = 'false'; break;
            case DataRowGrouper::EXPAND_FIRST_GROUP: $expandGroupJs = '1'; break;
            default: $expandGroupJs = 'true';
            
        }
        
        $groupCol = $grouper->getGroupByColumn();
        $groupColName = $groupCol->getDataColumnName();
        $groupColId = $this->getFacade()->getElement($groupCol)->getId();
        $restoreSelectionJs = $this->getWidget()->getMultiSelect() === true
            ? $this->buildJsDataLoaderOnLoadedRestoreSelection('oTable')
            : '';
        
        // Determine the desired group order. The native experimental grouping can only sort the
        // group column ascending (it does `new Sorter(sortProperty)` without a descending flag),
        // so we detect a descending sorter on the group-by attribute here and reproduce the order
        // via a synthetic ordering key (see $groupOrderSetupJs below).
        $groupDesc = false;
        foreach ($this->getWidget()->getSorters() as $sorterUxon) {
            if ($sorterUxon->getProperty('attribute_alias') === $groupCol->getAttributeAlias()) {
                $groupDesc = (strtoupper($sorterUxon->getProperty('direction') ?? '') === SortingDirectionsDataType::DESC);
                break;
            }
        }
        
        // Compare distinct group values the way their data type would be ordered: numerically for
        // numbers, locale-aware for everything else. Null/empty values sort to the top (and end up
        // at the bottom once the order is reversed for descending grouping).
        if ($groupCol->getDataType() instanceof NumberDataType) {
            $comparatorJs = 'function(a, b) { var fA = (a === null || a === undefined || a === "") ? -Infinity : parseFloat(a); var fB = (b === null || b === undefined || b === "") ? -Infinity : parseFloat(b); return fA - fB; }';
        } else {
            $comparatorJs = 'function(a, b) { return String(a === null || a === undefined ? "" : a).localeCompare(String(b === null || b === undefined ? "" : b)); }';
        }
        $reverseJs = $groupDesc ? 'aValues.reverse();' : '';
        
        // Build a synthetic numeric ordering key per distinct group value and group by that key
        // instead of the raw group column. Native grouping sorts the key ascending, so by assigning
        // the keys in the desired order we fully control the order of the groups - including
        // descending, which the native experimental grouping cannot do on its own. The visible group
        // label is still taken from the real group value (see the label formatter below), so the
        // synthetic key stays invisible to the user.
        $groupOrderSetupJs = <<<JS

                (function(oModel) {
                    var aRows = oModel.getProperty('/rows') || [];
                    if (aRows.length === 0) {
                        return;
                    }
                    var sCol = "{$groupColName}";
                    var aValues = [];
                    var oSeen = {};
                    // Traverse all rows and extract unique group headers.
                    aRows.forEach(function(oRow) {
                        var mVal = oRow[sCol];
                        var sKey = (mVal === null || mVal === undefined) ? '' : String(mVal);
                        // Process each header only once.
                        if (oSeen[sKey] !== true) {
                            oSeen[sKey] = true;
                            aValues.push(mVal);
                        }
                    });
                    // Sort the group headers.
                    aValues.sort({$comparatorJs});
                    // Reverse, if needed (generated snippet from UI5DataTable::buildJsUiTableInitRowGrouping). 
                    {$reverseJs}
                    // Associate headers with their sorting ranks.
                    var oRank = {};
                    aValues.forEach(function(mVal, iRank) {
                        var sKey = (mVal === null || mVal === undefined) ? '' : String(mVal);
                        oRank[sKey] = iRank;
                    });
                    // Write header rankings into the new (virtual) column "__exfGroupSortKey".
                    aRows.forEach(function(oRow) {
                        var mVal = oRow[sCol];
                        var sKey = (mVal === null || mVal === undefined) ? '' : String(mVal);
                        oRow.__exfGroupSortKey = oRank[sKey];
                    });
                    // Apply changes.
                    oModel.setProperty('/rows', aRows);
                })({$oModelJs});
                // Set "__exfGroupSortKey" as the sort property. We have now functionally overridden the native sorting.
                var oGroupColumn = sap.ui.getCore().byId('{$groupColId}');
                if (oGroupColumn) {
                    oGroupColumn.setSortProperty('__exfGroupSortKey');
                }
JS;
        
        // NOTE: sap.ui.table.utils._GroupingUtils.resetExperimentalGrouping($oTableJs) did not work: it produced
        // empty group titles whenever their content was to change
        return  <<<JS
            
            (function(oTable, oModel) {
                if (! oModel.getData().rows || oModel.getData().rows.length === 0) {
                    return;
                }
                oTable.setEnableGrouping(true);
                {$groupOrderSetupJs}
                // Force the experimental grouping to rebuild on every (re)load. Native grouping
                // guards its one-time sort/group pass with `oBinding._modified` and never re-runs it
                // on the same binding. Since `setGroupBy()` is a no-op when the group column does not
                // change, after a header-sort reload the binding would keep its stale group structure
                // (all rows collapsed into a single, wrong group). Toggling `groupBy` via null forces
                // a fresh row binding, so the grouping is rebuilt against the freshly computed
                // __exfGroupSortKey order.
                oTable.setGroupBy(null);
                oTable.setGroupBy('{$groupColId}');
                
                var oBinding = oTable.getBinding('rows');
                var iRowCnt = oTable._getTotalRowCount();
                var iHeaderIdx = -1;
                var mExpand = $expandGroupJs;
                var aCtxts = oBinding.getContexts(0, iRowCnt);
                var iExpanded = 0;
                let iFirstGroupLength = 0;

                // In order to use the _experimentalGroupingCollapse, we need to pass the actual row object to the function, not an index.
                // However, getRows() only returns the currently visible rows, so we need to temporarily set the visible row count to the total count of rows, in order to collapse everything 
                // even if its not inside the viewport. The original settings are saved and then reset afterwards.
                var iTotalLength = oBinding.getLength();
                var iOldVisibleRowCount = oTable.getVisibleRowCount();
                var sOldMode = oTable.getVisibleRowCountMode();
                
                // temporarily set visible rows to total count and set mode to fixed to get all rows on page.
                oTable.setVisibleRowCountMode("Fixed");
                oTable.setVisibleRowCount(iTotalLength);
                sap.ui.getCore().applyChanges();

                for (var i = 0; i < iRowCnt; i++) {
                    if (aCtxts[i].__groupInfo) {
                        aCtxts[i].__groupInfo.name = (function(mVal) {
                            if (mVal === null || mVal === undefined || mVal === '') {
                                return '{$groupCaption}{$this->escapeJsTextValue($grouper->getEmptyText())}';
                            }
                            return '{$groupCaption}' + {$groupFormatterJs}
                        })(aCtxts[i].__groupInfo.oContext.getProperty("{$groupColName}"));
                    }

                    // collapse headers according to configuration: (first, all, none)
                    // UI5-Upgrade -> oBinding.isGroupHeader() and oBinding.collapse() dont exist anymore, so we now need to check and expand/collapse this differently
                    // the workaround we use now is a bit hacky, and might break in future versions, if there are changes to the _experimentalGrouping api
                    // TODO: In general, the grouping APIs of ui and responsive table have mostly been moved to https://sdk.openui5.org/1.144.0/#/api/sap.ui.table.AnalyticalTable
                    if (aCtxts[i].__groupInfo && aCtxts[i].__groupInfo.groupHeader === true) {
                        iHeaderIdx++;

                        // if we want to collapse all groups, or the number of to be collapsed groups in not reached yet, collapse it
                        if (mExpand === false || (Number.isInteger(mExpand) && iHeaderIdx > (mExpand - 1))) {
                            
                            // if we only want to expand the first group, we need to add the length of the group (minus group header) 
                            // to all indices that come after that group, in order to collapse them.
                            // So, if we have 5 rows in the first group, we collapse all group headers from index 6 (5 + 1 group header) onwards, and leave the first group as is.
                            let iRowIdx = iHeaderIdx;
                            if (mExpand === 1 && iHeaderIdx === 1){
                                iFirstGroupLength = i-1;
                            }

                            iRowIdx += iFirstGroupLength;

                            // collapse the group header 
                            var oRow = oTable.getRows()[iRowIdx];
                            if (oRow) {
                                oTable._experimentalGroupingCollapse(oRow);
                            }
                        }
                    }
                }

                // reset to original visible row count and mode
                oTable.setVisibleRowCount(iOldVisibleRowCount);
                oTable.setVisibleRowCountMode(sOldMode);
                sap.ui.getCore().applyChanges();

                // Keep selection and column widths in sync when groups are expanded or collapsed.
                oBinding.attachChange(function(oEvent) {
                    
                    // Change-events on expand/collapse do not have a reason. Ignore others
                    if (oEvent.getParameters().reason !== undefined) {
                        return;
                    }

                    // Group toggles can clear native selection without a user deselection.
                    // Restore saved row identities at the updated binding indices so checkboxes
                    // and the selection counter agree, and expanded rows regain their selection.
                    {$restoreSelectionJs}

                    // resize on collapse/expand
                    setTimeout(function(){
                        {$this->getController()->buildJsMethodCallFromController(self::CONTROLLER_METHOD_RESIZE_COLUMNS, $this, 'oTable, ' . $oModelJs)}
                    }, 100);
                });
            })($oTableJs, $oModelJs);
JS;
    }
    
    /**
     * Optimize column width. This is not easy with sap.ui.table.Table :(
     * 
     * 1. oTable.autoResizeColumn() sets the focus to the column, so it is scrolled into
     * view. After a number of workaround attempts, a hack of UI5 solves the problem now. 
     * See Docs/UI5_modifications.md for details.
     * 2. The optimizer only works AFTER all column were populated, so we need a setTimeout().
     * TODO would be better to have an event, but none seemed suitable... Perhaps rowsUpdated? #ui5-update
     * 3. Since the optimizer works asynchronously, it will break if while it is running the
     * underlying data changes. In an attempt to avoid this, we do not optimize empty data.
     * 4. It seems, that the empty space on the right side of the table (if it is not occupied
     * with columns completely) is a column too. Optimizing that column will stretch some of
     * the others again as it does not have any data. So we check if each column has a DOM
     * element (that special column does not) and only optimize it then.
     * 4. The optimizer does not take the column header into account, so on narrow columns
     * the header gets truncated. We need to double-check this after all columns are resized
     * 5. Also need to make sure, the maximum width of the column is not exceeded
     * 6. TODO might need to check for minimum width too!
     * 
     * @param string $oTableJs
     * @param string $oModelJs
     * @return string
     */
    protected function buildJsUiTableColumnResize(string $oTableJs, string $oModelJs) : string
    {
        if (($this->getWidget() instanceof DataTable) && $this->getWidget()->getAutoColumnWidth() === false) {
            return '';
        }
        return <<<JS

                // do not optimize collapsed tables (width 0), as they would lead to very squished columns
                // this might happen if we are in a (full-size) detail dialogue of a table, 
                // and perform an action that causes the table to re-load while its not shown
                let jqTable = oTable ? oTable.$() : null;
                let bVisible = jqTable && jqTable.length > 0 && jqTable.innerWidth() > 0;
                if (bVisible === false) {
                    return;
                }

                $oTableJs.data("_exfIsAutoResizing", true);  // set auto resize flag

                var bResized = false;
                var oInitWidths = {};
                
                if (! $oModelJs.getData().rows || $oModelJs.getData().rows.length === 0) {
                    return;
                }
                
                $oTableJs.getColumns().reverse().forEach(function(oCol, i) {
                    var oWidth = oCol.data('_exfWidth');
                    if (! oWidth || $('#'+oCol.getId()).length === 0) {
                        return;
                    }
                    oInitWidths[$oTableJs.indexOfColumn(oCol)] = $('#'+oCol.getId()).width();
                    if (oCol.getVisible() === true && oWidth.auto === true) {
                        // UI5-Upgrade: autoResizeColumn() was replaced by column.autoResize()
                        // https://sdk.openui5.org/1.136.0/#api/sap.ui.table.Column%23methods/autoResize 
                        bResized = true;
                        oCol.autoResize();
                    }
                    if (oWidth.fixed) {
                        oCol.setWidth(oWidth.fixed);
                    }
                });

                if (bResized) {
                    var fResize = function(oCol){
                        var oWidth = oCol.data('_exfWidth');
                        var jqCol = $('#'+oCol.getId());
                        var jqLabel = jqCol.find('label');
                        var iWidth = null;
                        var iColIdx = $oTableJs.indexOfColumn(oCol);
                        if (! oWidth) {
                            return;
                        }
                        if (! oCol.getWidth() && oWidth.auto === true && oInitWidths[iColIdx] !== undefined) {
                            oCol.setWidth(oInitWidths[iColIdx] + 'px');
                        }
                        if (oCol.getVisible() === true && oWidth.auto === true) {
                            if (! jqLabel[0]) {
                                return;
                            }
                            var sAbbrev = oCol.data('_exfAbbreviation');
                            // We compare with 95% width to avoid browser truncation.
                            var fLabelWidth = jqLabel.width()  * 0.95; 
                            // If the caption overflows and isn't abbreviated, use the abbreviation instead.
                            if (jqLabel[0].scrollWidth > fLabelWidth && oCol.getLabel().getText() !== sAbbrev) {
                                oCol.getLabel()?.setText(sAbbrev);
                                // Reiterate the resize function for this column, but with a delay to account for async results. 
                                setTimeout(function () { fResize(oCol); }, 0);
                                return;
                            }
                            if (jqLabel[0].scrollWidth > fLabelWidth) {
                                oCol.setWidth((jqLabel[0].scrollWidth + (jqCol.outerWidth()-jqLabel.width()) + 1).toString() + 'px');
                            }
                            if (oWidth.min) {
                                iWidth = $('<div style="width: ' + oWidth.min + '"></div>').width();
                                if (jqCol.outerWidth() < iWidth) {
                                    oCol.setWidth(oWidth.min);
                                }
                            }
                            if (oWidth.max) {
                                iWidth = $('<div style="width: ' + oWidth.max + '"></div>').width();
                                if (jqCol.outerWidth() > iWidth) {
                                    oCol.setWidth(oWidth.max);
                                }
                            }
                        }
                    };
                    
                    setTimeout(function(){
                        $oTableJs.getColumns().forEach(function (oCol){
                            fResize(oCol);
                        });
                    }, 0);
                }
                
                // manually resized columns should always keep their width
                // (only skipping them didnt work, so we set the saved value then return)
                setTimeout(function(){
                    $oTableJs.getColumns().forEach(function(oCol){
                        if (oCol.data('_exfCustomColWidth')){
                            oCol.setWidth(oCol.data('_exfCustomColWidth'));
                            return;
                        } 
                    });
                }, 0);

                setTimeout(function(){
                    {$this->buildJsFixRowHeight($oTableJs)}
                    $oTableJs.data("_exfIsAutoResizing", false);  // done auto resizing
                }, 0);
JS;
    }
    
    /**
     *
     * {@inheritdoc}
     * @see UI5DataElementTrait::buildJsDataLoaderPrepare()
     */
    protected function buildJsDataLoaderPrepare() : string
    {
        return $this->buildJsShowMessageOverlay($this->getWidget()->getEmptyText());
    }
    
    /**
     *
     * {@inheritdoc}
     * @see UI5DataElementTrait::buildJsOfflineHint()
     */
    protected function buildJsOfflineHint(string $oTableJs = 'oTable') : string
    {
        $hint = $this->escapeJsTextValue($this->translate('WIDGET.DATATABLE.OFFLINE_HINT'));
        if ($this->isMList() || $this->isMTable()) {
            return $oTableJs . '.setNoDataText("' . $hint . '");';
        } else {
            return "sap.ui.getCore().byId('{$this->getIdOfNoDataOverlay()}').setText(\"{$hint}\")";
        }
        return '';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see UI5DataElementTrait::getCaption()
     */
    public function getCaption() : string
    {
        if ($caption = $this->getCaptionViaTrait()) {
            $caption .= ($this->isUiTable() && $this->hasPaginator() ? ': ' : '');
        }
        return $caption;
    }
    
    /**
     * Returns the JS code to select the row with the zero-based index $iRowIdxJs and scroll it into view.
     * 
     * @param string $oTableJs
     * @param string $iRowIdxJs
     * @param bool $deSelect
     * @return string
     */
    public function buildJsSelectRowByIndex(string $oTableJs = 'oTable', string $iRowIdxJs = 'iRowIdx', bool $deSelect = false, string $bScrollToJs = 'true') : string
    {
        if ($this->isMList() === true) {
            $setSelectJs = ($deSelect === true) ? 'false' : 'true';
            //filter items to only get items with binding context
            //necessary as row groupers add item without binding to table
            return <<<JS

                var oItem = {$oTableJs}.getItems().filter(function(oItem){
                    return oItem.getBindingContext() !== undefined
                })[{$iRowIdxJs}];
                {$oTableJs}.setSelectedItem(oItem, {$setSelectJs});
                {$oTableJs}.fireSelectionChange({
                    listItem: oItem, 
                    selected: $setSelectJs
                });
                if ($bScrollToJs && oItem !== undefined) {
                    oItem.focus();
                }

JS;

                
        } else {
            $deSelectJs = $deSelect ? 'true' : 'false';
            $singleSelectJs = $this->escapeBool($this->getWidget()->getMultiSelect() === false);
            // Cannot use the row index directly here because row group headers
            // are also part of the row numbering. In any case, it is much more
            // reliable to check each binding and compare its path to the row
            // number inside the rows array of the model.
            return <<<JS
                (function(oTable, iRowIdx, bDeselect, bScrollTo) {
                    var iTableIdx = iRowIdx;
                    var oBinding = oTable.getBinding("rows");
                    var fnFindTableIdx = function(iRowIdx) {
                        // iRowIdx is a model index (/rows/N). The rendered table index can differ
                        // when grouping inserts additional header rows, so we map via context path.
                        for (var i = 0; i < oBinding.getLength(); i++) {
                            var context = oBinding.getContexts(i, 1)[0]; // Get context for each row
                            if (context && context.getPath() === `/rows/` + iRowIdx) {
                                return i;
                            }
                        }
                    };
                    
                    iTableIdx = fnFindTableIdx(iRowIdx);
                    if (iTableIdx === undefined || iTableIdx < 0) {
                        return;
                    }
                    // Selection APIs need the binding index found above, not the model index.
                    // Remove only the requested row (e.g. from the selection popup).
                    if (bDeselect === true) {
                        oTable.removeSelectionInterval(iTableIdx, iTableIdx);
                    } else if ($singleSelectJs) {
                        // Replacing the selection is intentional only in single-select mode.
                        oTable.setSelectedIndex(iTableIdx);
                    } else if (!oTable.getSelectedIndices().includes(iTableIdx)) {
                        // Preserve other selected rows and avoid duplicate selection events
                        // when an already-selected row is selected again (e.g. on right-click).
                        oTable.addSelectionInterval(iTableIdx, iTableIdx);
                    }
                    
                    if (bScrollTo) {
                        oTable.setFirstVisibleRow(iTableIdx);
                    }
                })($oTableJs, $iRowIdxJs, $deSelectJs, $bScrollToJs)

JS;
        }
    }
    
    /**
     * 
     * @see UI5DataElementTrait::buildJsShowMessageOverlay()
     */
    protected function buildJsShowMessageOverlay(string $message) : string
    {
        $hint = $this->escapeJsTextValue($message);
        if ($this->isMList() || $this->isMTable()) {
            $setNoData = "sap.ui.getCore().byId('{$this->getId()}').setNoDataText({$this->escapeString($hint)})";
        } elseif ($this->isUiTable()) {
            $setNoData = "sap.ui.getCore().byId('{$this->getIdOfNoDataOverlay()}').setText({$this->escapeString($hint)})";
        }
        return $this->buildJsDataResetter() . ';' . $setNoData . ';';
    }
    
    /**
     * Returns the JS defining `var fnEffVisible = function(oColConfig, oColumn){...}` used by
     * buildJsRefreshPersonalization() in both table variants.
     * 
     * A hidden_if column must stay hidden if its condition currently resolves to hidden, even when
     * the (server-side) personalization config marks it visible. This helper ANDs config.visible with
     * the client-side hidden_if evaluator (`_exfHiddenIfEval`).
     * 
     * @return string
     */
    protected function buildJsColumnEffectiveVisibleFunction() : string
    {
        return <<<JS

                        var fnEffVisible = function(oColConfig, oColumn){
                            var bVisible = oColConfig.visible;
                            if (bVisible === true && oColConfig.has_hidden_if && oColumn && typeof oColumn.data === 'function') {
                                var fnEval = oColumn.data('_exfHiddenIfEval');
                                if (typeof fnEval === 'function') {
                                    try { if (fnEval() === true) bVisible = false; } catch (e) {}
                                }
                            }
                            return bVisible;
                        };
JS;
    }

    public function buildJsRefreshPersonalization() : string
    {
        $widget = $this->getWidget();
        $uidColName = $widget->hasUidColumn() ? $widget->getUidColumn()->getDataColumnName() : "''";
        $colsOptional = $widget->getConfiguratorWidget()->getOptionalColumns();
        $colsOptionalJs = "var oColsOptional = {};";
        $cellsOptionalJs = "var oCellsOptional = {};";
        if (! empty($colsOptional)) {
            $colsOptionalJs = "var oColsOptional = {$this->getController()->buildJsDependentObjectGetter(self::CONTROLLER_VAR_OPTIONAL_COLS, $this, 'oController')};";
            $cellsOptionalJs = "var oCellsOptional = {$this->getController()->buildJsDependentObjectGetter(self::CONTROLLER_VAR_OPTIONAL_CELLS, $this, 'oController')};";
        }
        if ($this->isUiTable() === true) {
            return <<<JS

                        var oController = {$this->getController()->buildJsControllerGetter($this)};
                        var aColsConfig = {$this->getConfiguratorElement()->buildJsP13nColumnConfig()};
                        var oTable = sap.ui.getCore().byId('{$this->getId()}');
                        var aColumns = oTable.getColumns();
                        {$colsOptionalJs}
                        var aColumnsNew = [];
                        var bOrderChanged = false;
                        var iConfOffset = 0;
                        var oDirtyColumn = aColumns.filter(oColumn => oColumn.getId() === "{$this->getDirtyFlagAlias()}")[0];
                        var oUidCol = aColumns.filter(oColumn => oColumn.data('data_column_name') === {$this->escapeString($uidColName)})[0];

                        if (oDirtyColumn !== undefined) {
                            iConfOffset += 1;
                            aColumnsNew.push(oDirtyColumn);  
                        }

                        {$this->buildJsColumnEffectiveVisibleFunction()}
                        
                        aColsConfig.forEach(function(oColConfig, iConfIdx) {
                            var bFoundCol = false;
                            var oColumn;
                            // See if the column is part of the table right now
                            for (var iColIdx = 0; iColIdx < aColumns.length; iColIdx++) {
                                oColumn = aColumns[iColIdx];
                                if (oColumn.getId() === oColConfig.column_id) {
                                    if (iColIdx !== iConfIdx + iConfOffset) bOrderChanged = true;
                                    oColumn.setVisible(fnEffVisible(oColConfig, oColumn));
                                    aColumnsNew.push(oColumn);
                                    bFoundCol = true;
                                    return;
                                }
                            }
                            // If it is not AND it is an optional column, add it to the table
                            if (oColConfig.visible === true) {
                                oColumn = oColsOptional[oColConfig.column_name];
                                if (oColumn !== undefined) {
                                    oColumn.setVisible(fnEffVisible(oColConfig, oColumn));
                                    aColumnsNew.push(oColumn); 
                                    bOrderChanged = true;
                                }   
                            }  
                        });

                        // TODO what if the column was part of the table, but is not in the config?
                        // e.g. the UID column, that is always added automatically. It seems to be
                        // added at the end, so we handle it here. But that does not feel good!
                        // UPDATE: Doesnt seem to work??
                        if (oUidCol !== undefined) {
                            aColumnsNew.push(oUidCol); 
                        }

                        if (bOrderChanged === true) {
                            oTable.removeAllColumns();
                            aColumnsNew.forEach(oColumn => {
                                oTable.addColumn(oColumn);
                            });
                        }

JS;
        } else {
            return <<<JS
                        // Responsive table
                        var aColsConfig = {$this->getConfiguratorElement()->buildJsP13nColumnConfig()};
                        var oTable = sap.ui.getCore().byId('{$this->getId()}');
                        var aColumns = oTable.getColumns();
                        var aColumnsNew = [];
                        var oController = {$this->getController()->buildJsControllerGetter($this)};
                        {$colsOptionalJs}
                        {$cellsOptionalJs}

                        var bOrderChanged = false;

                        {$this->buildJsColumnEffectiveVisibleFunction()}

                        // add dirty column first
                        var oDirtyColumn = aColumns.find(col => col.getId() === "{$this->getDirtyFlagAlias()}");
                        if (oDirtyColumn) {
                            aColumnsNew.push(oDirtyColumn);
                        }

                        aColsConfig.forEach(function(oColConfig, iConfIdx) {
                            // table columns
                            aColumns.forEach(function(oColumn, iColIdx) {
                                if (oColumn.getId() === oColConfig.column_id) {
                                    var bEff = fnEffVisible(oColConfig, oColumn);
                                    if (oColumn.getVisible() !== bEff) {
                                        oColumn.setVisible(bEff);
                                    }
                                    aColumnsNew.push(oColumn);                                    
                                    return;
                                }  
                            });
                            // optional columns
                            if (oColConfig.visible === true && oColsOptional !== null) {
                                var oColumn = oColsOptional[oColConfig.column_name];
                                if (oColumn !== undefined) {
                                    oColumn.setVisible(fnEffVisible(oColConfig, oColumn));
                                    aColumnsNew.push(oColumn); 
                                    return;
                                }   
                            }
                        });

                        // compare cols if re-order is needed
                        var aOldOrderIds = aColumns.filter(col => col.getVisible()).map(col => col.getId());
                        var aNewOrderIds = aColumnsNew.map(col => col.getId());

                        if (JSON.stringify(aOldOrderIds) !== JSON.stringify(aNewOrderIds)) {
                            bOrderChanged = true;
                        }

                        //if order/content changed, apply new columns and rebuild template
                        if (bOrderChanged) {
                            var oTemplate = oTable.getBindingInfo("items").template;
                            var aOldCells = oTemplate.getCells();
                            var aNewCells = [];

                            // map data by column id for re-ordering
                            var mColumnIdToCell = {};
                            aColumns.forEach((col, idx) => {
                                var colId = col.getId();
                                if (aOldCells[idx]) {
                                    mColumnIdToCell[colId] = aOldCells[idx];
                                }
                            });

                            // remove all columns
                            oTable.removeAllColumns();

                            // re-add columns in new order and update their cells
                            aColumnsNew.forEach((col, idx) => {
                                oTable.addColumn(col);

                                var colId = col.getId();
                                if (mColumnIdToCell[colId]) {
                                    // if is existing column, reuse cell from old template
                                    aNewCells.push(mColumnIdToCell[colId]);
                                } 
                                else {
                                    // if is new/optional column, use the pre-built, correctly formatted
                                    // cell control for it. Falling back to a plain sap.m.Text bound
                                    // directly to the raw property would show unformatted values
                                    // (e.g. raw internal date/time strings instead of properly
                                    // formatted dates).
                                    var oColConfig = aColsConfig.find(c => c.column_id === colId);
                                    var sProperty = oColConfig.column_name;
                                    var oCell = oCellsOptional[sProperty];
                                    
                                    aNewCells.push(oCell !== undefined ? oCell : new sap.m.Text({
                                        text: '{' + sProperty + '}'
                                    }));
                                }
                            });

                            //update template
                            oTemplate.removeAllAggregation("cells");
                            aNewCells.forEach(cell => oTemplate.addCell(cell));
                        }

JS;
        }
    }

    /**
     * 
     * @return string
     */
    protected function buildJsCellConditionalDisablers() : string
    {
        foreach ($this->getWidget()->getColumns() as $col) {
            if ($conditionalProperty = $col->getCellWidget()->getDisabledIf()) {
                // TODO how to implement on-true/false widget functions here?
                foreach ($conditionalProperty->getConditions() as $condition) {
                    $leftExpressionIsRef = $condition->getValueLeftExpression()->isReference();
                    $rightExpressionIsRef = $condition->getValueRightExpression()->isReference();
                    if ($leftExpressionIsRef === true || $rightExpressionIsRef === true) {
                        $cellWidget = $col->getCellWidget();
                        $cellControlJs = 'oCellCtrl';
                        $cellElement =  $this->getFacade()->getElement($cellWidget);
                        $disablerJS = $cellElement->buildJsSetDisabled(true);
                        $disablerJS = str_replace("sap.ui.getCore().byId('{$cellElement->getId()}')", $cellControlJs, $disablerJS);
                        $enablerJS = $cellElement->buildJsSetDisabled(false);
                        $enablerJS = str_replace("sap.ui.getCore().byId('{$cellElement->getId()}')", $cellControlJs, $enablerJS);
                        $conditionalPropertyJs = $this->buildJsConditionalProperty($conditionalProperty, $disablerJS, $enablerJS);
                        
                        $selfRefOnTheLeft = ($leftExpressionIsRef && $this->getFacade()->getElement($condition->getValueLeftExpression()->getWidgetLink($cellWidget)->getTargetWidget()) === $this);
                        $selfRefOnTheRight = ($rightExpressionIsRef && $this->getFacade()->getElement($condition->getValueRightExpression()->getWidgetLink($cellWidget)->getTargetWidget()) === $this);
                        
                        if ($this->isUiTable() === true) {
                            return $this->buildJsCellConditionalDisablerForUiTable($col, $cellControlJs, $conditionalPropertyJs, ($selfRefOnTheLeft || $selfRefOnTheRight));
                        } elseif ($this->isMTable() === true) {
                            return $this->buildJsCellConditionalDisablerForMTable($col, $cellControlJs, $conditionalPropertyJs, ($selfRefOnTheLeft || $selfRefOnTheRight));
                        }
                    }
                }
            }
        }
        
        return '';
    }
    
    /**
     * Performs the $conditionalLogicJs for every current row and makes sure the cell control is available via $cellControlJs.
     * 
     * While iterating, every row is selected for a fraction of a second to make sure, that if
     * the conditional logic includes a call to the value-getter of the table itself, that getter
     * will return the value of processed row. This makes it possible to disable cell widget
     * depending on the value of other cells of the same row. E.g.:
     * 
     * ```
        {
          "widget_type": "DataTable",
          "object_alias": "exface.Core.ATTRIBUTE",
          "id": "tabelle",
          "filters": [
            {
              "attribute_alias": "OBJECT"
            }
          ],
          "columns": [
            {
              "attribute_alias": "NAME",
              "editable": false
            },
            {
              "attribute_alias": "RELATED_OBJ__LABEL",
              "editable": false
            },
            {
              "attribute_alias": "DELETE_WITH_RELATED_OBJECT",
              "cell_widget": {
                "widget_type": "InputCheckBox",
                "disabled_if": {
                  "operator": "AND",
                  "conditions": [
                    {
                      "value_left": "=tabelle!RELATED_OBJ__LABEL",
                      "comparator": "==",
                      "value_right": ""
                    }
                  ]
                }
              }
            }
          ]
        }
     * ```
     * 
     * TODO will this cause on-change-events to fire for every row selection???
     * 
     * @param DataColumn $col
     * @param string $cellControlJs
     * @param string $conditionalLogicJs
     * @param bool $logicDependsOnTable
     * @return string
     */
    protected function buildJsCellConditionalDisablerForMTable(DataColumn $col, string $cellControlJs, string $conditionalLogicJs, bool $logicDependsOnTable = false) : string
    {
        $colName = $col->getDataColumnName();
        
        // If the logic depends on the table itself, select the current row before executing it
        // and unselect it afterwards. Restore the selection after going through all rows
        if ($logicDependsOnTable === true) {
            $saveSelectionJs = 'var oldSelection = tbl.getSelectedItems(); tbl.removeSelections();';
            $conditionalLogicJs = <<<JS

            tbl.setSelectedItem(r);
            {$conditionalLogicJs}
            tbl.setSelectedItem(r, false);
JS;
            $restoreSelectionJs = <<<JS

    if (Array.isArray(oldSelection) && oldSelection.length > 0) {
        for (var i = 0; i < oldSelection.length; i++) {
            tbl.setSelectedItem(oldSelection[i]);
        }
    }
JS;
        } else {
            $saveSelectionJs = '';
            $restoreSelectionJs = '';
        }
        
        return <<<JS
        
setTimeout(function(){
    var tbl = sap.ui.getCore().byId('{$this->getId()}');
    {$saveSelectionJs}
    var iColIdx = 1;
    if (tbl.getMode() == sap.m.ListMode.MultiSelect) {
        iColIdx++;
    }
    tbl.getColumns().some(function(oColumn){
        if (oColumn.data('_exfDataColumnName') === '$colName') {
            return true; // stop iterating! .some() stops if a callback returns TRUE.
        }
        if (oColumn.getVisible() === true) {
            iColIdx++;
        }
    });
    
    tbl.getItems().forEach(function(r) {
        var cb = r.$().children('td').eq(iColIdx).children().first();
        var {$cellControlJs} = sap.ui.getCore().byId(cb.attr('id'));
        if ({$cellControlJs} != undefined) {
            {$conditionalLogicJs}
        }
    });
    {$restoreSelectionJs}
},0);

JS;
    }
    
    /**
     * @see buildJsCellConditionalDisablerForMTable()
     * @param DataColumn $col
     * @param string $cellControlJs
     * @param string $conditionalLogicJs
     * @param bool $logicDependsOnTable
     * @return string
     */
    protected function buildJsCellConditionalDisablerForUiTable(DataColumn $col, string $cellControlJs, string $conditionalLogicJs, bool $logicDependsOnTable = false) : string
    {
        $colName = $col->getDataColumnName();
        
        // If the logic depends on the table itself, select the current row before executing it
        // and unselect it afterwards. Restore the selection after going through all rows
        if ($logicDependsOnTable === true) {
            $saveSelectionJs = 'var oldSelection = tbl.getSelectedIndices().slice(); tbl.clearSelection();';
            $conditionalLogicJs = <<<JS
            
            tbl.addSelectionInterval(r.getIndex(), r.getIndex());
            {$conditionalLogicJs}
JS;
            $clearSelectionJs = 'tbl.clearSelection();';
            $restoreSelectionJs = <<<JS
            
    if (Array.isArray(oldSelection) && oldSelection.length > 0) {
        for (var i = 0; i < oldSelection.length; i++) {
            tbl.addSelectionInterval(oldSelection[i], oldSelection[i]);
        }
    }
JS;
        } else {
            $saveSelectionJs = '';
            $restoreSelectionJs = '';
            $clearSelectionJs = '';
        }
        
        $conditionalPropertiesJs = <<<JS
        
(function() {
    var tbl = sap.ui.getCore().byId('{$this->getId()}');
    {$saveSelectionJs}
    var iColIdx = 0;
    tbl.getColumns().some(function(oColumn){
        if (oColumn.data('_exfDataColumnName') === '$colName') {
            return true;
        }
        if (oColumn.getVisible() === true) {
            iColIdx++;
        }
    });
    tbl.getRows().forEach(function(r) {
        var cb = r.$().find('.sapUiTableCellInner').eq(iColIdx).children().first();
        var {$cellControlJs} = sap.ui.getCore().byId(cb.attr('id'));
        if ({$cellControlJs} != undefined) {
            {$conditionalLogicJs}
        }
        {$clearSelectionJs}
    });
    {$restoreSelectionJs}
})();

JS;
            
        $this->getController()->addOnEventScript($this, self::EVENT_NAME_FIRST_VISIBLE_ROW_CHANGED, $conditionalPropertiesJs);
        return $conditionalPropertiesJs;
    }
    
    /**
     * Builds the javascript to select all rows with the same value in the DataColumn as the selected row
     * 
     * @param DataColumn $column
     * @return string
     */
    protected function buildJsMultiSelectSync(DataColumn $column) : string
    {
        $widget = $this->getWidget();
        $syncDataColumnName = $column->getDataColumnName();
        if ($this->isMList() === true) {
            
            return <<<JS
            
                var oTable = sap.ui.getCore().byId('{$this->getId()}');
                if (oTable.getModel()._syncChanges === undefined) {
                    oTable.getModel()._syncChanges = false;
                }
                var selected = false;
                var selectedItems = [];
                if (oEvent !== undefined) {
                    selected = oEvent.getParameters().selected;
                    selectedItems = oEvent.getParameter("listItems");
                }
                
                if (oTable.getModel()._syncChanges === false && oEvent !== undefined && selectedItems.length !== 0) {
                    oTable.getModel()._syncChanges = true;
                    var itemValues = selectedItems[0].getBindingContext().getObject();
                    var value = itemValues['{$syncDataColumnName}'];
                    if (value !== undefined) {
                        var aData = oTable.getModel().getData().rows;
                        for (var i in aData) {
                            if (value === aData[i]['{$syncDataColumnName}']) {
                                var index = parseInt(i);
                                var oItem = oTable.getItems()[index];
                                oTable.setSelectedItem(oItem, selected);
                            }
                        }                        
                        var exfSelection = {$this->buildJsGetRowsSelected('oTable')};
                        oTable.data('exfPreviousSelection', exfSelection);
                    } else {
                        var error = "Data Column '{$syncDataColumnName}' not found in data columns for widget '{$widget->getId()}'!";
                        {$this->buildJsShowMessageError('error', '"ERROR"')}
                    }
                
                    oTable.getModel()._syncChanges = false;
                }
                
JS;
        } else {
            
            return <<<JS
            
                var oTable = sap.ui.getCore().byId('{$this->getId()}');
                if (oTable.getModel()._syncChanges === undefined) {
                    oTable.getModel()._syncChanges = false;
                }
                var rowIdx = -1;
                if (oEvent !== undefined) {
                    rowIdx = oEvent.getParameters().rowIndex;
                }
                var selectedRowsIdx = [];
                selectedRowsIdx = oTable.getSelectedIndices();
                var selected = false; 
                if (selectedRowsIdx.includes(rowIdx)) {
                    selected = true;    
                }
                
                if (oTable.getModel()._syncChanges === false && oEvent !== undefined) {
                    oTable.getModel()._syncChanges = true;
                    var rowValues = oEvent.getParameters().rowContext.getObject();
                    var value = rowValues['{$syncDataColumnName}'];
                    if (value !== undefined) {
                            var aData = oTable.getModel().getData().rows;
                            for (var i in aData) {
                                if (value === aData[i]['{$syncDataColumnName}']) {
                                    var index = parseInt(i);
                                    if (selected === true) {
                                        oTable.addSelectionInterval(index, index);
                                    } else {
                                        oTable.removeSelectionInterval(index, index);
                                    }
                                }
                            }
                    } else {
                        var error = "Data Column '{$syncDataColumnName}' not found in data columns for widget '{$widget->getId()}'!";
                        {$this->buildJsShowMessageError('error', '"ERROR"')}
                    }

                    oTable.getModel()._syncChanges = false;
                }
                
JS;
        }
    }
    
    public function needsContainerHeight() : bool
    {
        return $this->isWrappedInDynamicPage() || $this->isUiTable();
    }
    
    protected function buildJsColumnStylers() : string
    {
        $js = '';
        foreach ($this->getWidget()->getColumns() as $col) {
            $js .= StringDataType::replacePlaceholders(($col->getCellStylerScript() ?? ''), ['table_id' => $this->getId()]);
        }
        return $js;
    }
    
    /**
     * 
     * @see UI5DataElementTrait::buildJsDataResetter()
     */
    protected function buildJsDataResetter() : string
    {
        $resetTableJs = '';
        if ($this->isUiTable()) {
            $resetTableJs = ! $this->isUiTable() ? '' : <<<JS

            if (sap.ui.getCore().byId('{$this->getId()}').getEnableGrouping() === true) {
                sap.ui.getCore().byId('{$this->getId()}').setEnableGrouping(false);
            }   
JS;
        } elseif ($this->isMTable()) {
            /* TODO clear selectios or not??? Not sure, why we removed this, but it caused trouble
            $resetTableJs = <<<JS

            //sap.ui.getCore().byId('{$this->getId()}').removeSelections();
JS;*/
        }
        return $resetTableJs . $this->buildJsDataResetterViaTrait();
    }
    
    protected function buildJsFixRowHeight(string $oTableJs) : string
    {
        if ($this->hasFixedRowHeight() === true) {
            return '';
        }
        
        return <<<JS

                    var jqTable = $('#{$this->getId()}');
                    var iRowCntOrig = jqTable.data('_exfMinRows');
                    var iHeaderHeight = jqTable.find('.sapUiTableHeaderRow').height() - 1;
                    var iRowHeightMax = iHeaderHeight;
                    var fnCalcRowHeight = function() {
                        var iNewVisibleRowCount;
                        var iRowCntCur = $oTableJs.getMinAutoRowCount();
                        // On first run, just remember the curent min row count
                        // On subsequent runs, check if min row count was decreased. If so, restore
                        // it, wait for rerender and repeat the optimization
                        if (iRowCntOrig === undefined) {
                            iRowCntOrig = iRowCntCur;
                            jqTable.data('_exfMinRows', iRowCntOrig);
                        } else if (iRowCntCur < iRowCntOrig) {
                            $oTableJs.setMinAutoRowCount(iRowCntOrig);
                            setTimeout(fnCalcRowHeight, 0);
                            return;
                        }
                        // Find the maximum height of immediate children of table cells
                        iRowHeightMax = Math.max.apply(null, jqTable.find('.sapUiTableRow > td > *').map(
                                function () {
                                    return $(this).height();
                                }
                            ).get()
                        );
                        // If the maximum height is greater, than the default height, increase row
                        // row height and decrease the minimum number of rows shown
                        if (iRowHeightMax > iHeaderHeight) {
                            iNewVisibleRowCount = Math.round(iRowCntOrig / (iRowHeightMax / iHeaderHeight));
                            $oTableJs
                                .setColumnHeaderHeight(iHeaderHeight)
                                .setMinAutoRowCount(iNewVisibleRowCount)
                                .setRowHeight(iRowHeightMax);
                        }
                    };
                    $oTableJs.setRowHeight(0);
                    fnCalcRowHeight();
JS;
    }
    
    protected function hasFixedRowHeight() : bool
    {
        foreach ($this->getWidget()->getColumns() as $col) {
            $cellWidget = $col->getCellWidget();
            switch (true) {
                case $col->isHidden() === true:
                    continue 2;
                case $cellWidget instanceof DisplayTemplate:
                    // DisplayTemplate can render variable-height HTML, so force row-height recalculation.
                    return false;
                case $col->getCellWidget()->getHeight()->isUndefined() === false:
                    continue 2;
                case $col->getNowrap() === false:
                    return false;
            }
        }
        return true;
    }
}