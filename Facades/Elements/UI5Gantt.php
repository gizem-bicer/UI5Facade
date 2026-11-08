<?php
namespace exface\UI5Facade\Facades\Elements;

use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Widgets\ButtonGroup;
use exface\Core\Widgets\Parts\DataTimelineThicklines;
use exface\Core\Widgets\Parts\DataTimelineView;
use exface\Core\Widgets\Parts\DataTimelineHeader;
use exface\UI5Facade\Facades\Elements\Traits\UI5DataElementTrait;
use exface\Core\Widgets\Parts\DataTimeline;
use exface\Core\Facades\AbstractAjaxFacade\Elements\JsValueScaleTrait;
use exface\Core\Widgets\Parts\DataCalendarItem;
use exface\UI5Facade\Facades\Interfaces\UI5ControllerInterface;
use exface\UI5Facade\Facades\Elements\Traits\UI5ColorClassesTrait;
use exface\Core\DataTypes\DateDataType;
use exface\Core\Widgets\Parts\ConditionalPropertyConditionGroup;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Interfaces\Widgets\iShowSingleAttribute;
use exface\Core\Interfaces\Widgets\iHaveColumns;
use exface\Core\Exceptions\Facades\FacadeRuntimeError;
use exface\Core\Widgets\Gantt;
use exface\Core\Widgets\Popup;

/**
 * 
 * @method \exface\Core\Widgets\Gantt getWidget()
 * 
 * @author Andrej Kabachnik
 *
 */
class UI5Gantt extends UI5DataTree
{
    use JsValueScaleTrait;
    use UI5ColorClassesTrait;

    private const TABLE_HEADER_HEIGHT_PX = 75;
    private const GANTT_UPPER_HEADER_HEIGHT_PX = 40;
    private const GANTT_LOWER_HEADER_HEIGHT_PX = 25;
    private const GANTT_HEADER_PADDING_PX = 10;
    private const VERTICAL_TABLE_HEADER_HEIGHT_PX = 36;
    private const VERTICAL_GANTT_UPPER_HEADER_HEIGHT_PX = 35;
    private const VERTICAL_GANTT_LOWER_HEADER_HEIGHT_PX = 25;
    private const VERTICAL_GANTT_HEADER_PADDING_PX = 10;
    private const VERTICAL_SPLITTER_BAR_HEIGHT_PX = 16;

    const EVENT_NAME_TIMELINE_SHIFT = 'timeline_shift';
    const EVENT_NAME_ROW_SELECTION_CHANGE = 'row_selection_change';
    
    const CONTROLLER_METHOD_SYNC_TO_GANTT = 'syncTreeToGantt';
    const CONTROLLER_METHOD_CHECK_TABLE_IS_READY = 'checkTableIsReady';
    const CONTROLLER_METHOD_GET_GLOBAL_START_END_DATES = 'getGlobalStartEndDates';
    const CONTROLLER_METHOD_SET_GLOBAL_START_END_DATES_TO_GANTT = 'setGlobalStartEndDatesToGantt';
    const CONTROLLER_METHOD_RESIZE_VERTICAL_LAYOUT = 'resizeVerticalLayout';
    const CONTROLLER_METHOD_FIT_INITIAL_SPLIT_SIZE = 'fitInitialSplitSize';
    
    // Default Gantt ViewModes: hours, days, weeks, months, years
    // The defaults are written in the simple view mode config structure.
    // Read more about the simple view mode config here: https://www.npmjs.com/package/riel-gantt?activeTab=readme
    // Keep in mind to add "TRANSLATE:" prefix to the name of the view
    // and make sure it gets translated bevor usage.
    private array $viewModeDefaults = [
        DataTimeline::GRANULARITY_HOURS => [
            'name' => 'TRANSLATE:WIDGET.GANTT_CHARD.VIEW_MODE_HOUR',
            'step' => '1h',
            'date_format' => 'YYYY-MM-dd HH:',
            'column_width' => null,
            'padding' => '7d',
            'snap_at' => null,
            'upper_text_frequency' => 24,
            'header' => [
                'upper' => [
                    'interval' => 'Date',
                    'date_format' => 'dd',
                    'date_format_at_border' => null,
                ],
                'lower' => [
                    'interval' => 'Date',
                    'date_format' => 'HH',
                    'date_format_at_border' => null,
                ]
            ]
        ],
        DataTimeline::GRANULARITY_DAYS => [
            'name' => 'TRANSLATE:WIDGET.GANTT_CHARD.VIEW_MODE_DAY', 
            'step' => '1d',
            'date_format' => 'yyyy-MM-dd',
            'column_width' => null,
            'padding' => '7d',
            'snap_at' => null,
            'upper_text_frequency' => null,
            'header' => [
                'upper' => [
                    'interval' => 'Month',
                    'date_format' => '',
                    'date_format_at_border' => 'MMMM'
                ], 
                'lower' => [
                    'interval' => 'Date',
                    'date_format' => 'dd',
                    'date_format_at_border' => null,
                ]
            ],
            'thick_line' => [
                'from' => null,
                'to' => null,
                'interval' => 'week',
                'value' => 1
            ],
            'thick_line_color' => null,
        ],
        DataTimeline::GRANULARITY_WEEKS => [
            'name' => 'TRANSLATE:WIDGET.GANTT_CHARD.VIEW_MODE_WEEK',
            'step' => '7d',
            'date_format' => 'yyyy-MM-dd',
            'column_width' => 140,
            'padding' => '1m',
            'snap_at' => null,
            'upper_text_frequency' => 4,
            'header' => [
                'upper' => [
                    'interval' => 'Month',
                    'date_format' => '',
                    'date_format_at_border' => 'MMMM'
                ],
                'lower' => [
                    'interval' => null,
                    'date_format' => '~weekRange',
                    'date_format_at_border' => null,
                ]
            ],
            'thick_line' => [
                'interval' => 'month_range_in_days',
                'from' => 1,
                'to' => 7,
                'value' => null
            ],
            'thick_line_color' => null,
        ],
        DataTimeline::GRANULARITY_MONTHS => [
            'name' => 'TRANSLATE:WIDGET.GANTT_CHARD.VIEW_MODE_MONTH',
            'step' => '1m',
            'date_format' => 'yyyy-MM',
            'column_width' => 120,
            'padding' => '2m',
            'snap_at' => '7d',
            'upper_text_frequency' => null,
            'header' => [
                'upper' => [
                    'interval' => 'Year',
                    'date_format' => '',
                    'date_format_at_border' => 'YYYY',
                ],
                'lower' => [
                    'interval' => null,
                    'date_format' => 'MMMM',
                    'date_format_at_border' => null,
                ]
            ],
            'thick_line' => [
                'interval' => 'month_range_in_days',
                'from' => 1,
                'to' => 7,
                'value' => null
            ],
            'thick_line_color' => null,
        ],
        DataTimeline::GRANULARITY_YEARS => [
            'name' => 'TRANSLATE:WIDGET.GANTT_CHARD.VIEW_MODE_YEAR',
            'step' => '1y',
            'date_format' => 'YYYY',
            'column_width' => 120,
            'padding' => '2y',
            'snap_at' => '30d',
            'upper_text_frequency' => null,
            'header' => [
                'upper' => [
                    'interval' => 'Decade',
                    'date_format' => '',
                    'date_format_at_border' => '~decade',
                ],
                'lower' => [
                    'interval' => 'Year',
                    'date_format' => 'YYYY',
                    'date_format_at_border' => null,
                ]
            ],
        ],
    ];
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::buildJsConstructor()
     */
    public function buildJsConstructorForControl($oControllerJs = 'oController') : string
    {
        $widget = $this->getWidget();
        $heightInRows = $widget->getHeightInRows();
        $autoSizeVertical = $widget->getOrientation() === Gantt::ORIENTATION_VERTICAL && $heightInRows !== null
            && $widget->getTableSplitSize()->isUndefined() && $widget->getGanttSplitSize()->isUndefined();
        $calItem = $widget->getTasksConfig();
        $controller = $this->getController();
        $controller->addMethod(self::CONTROLLER_METHOD_SYNC_TO_GANTT, $this, 'oTable, scrollToToday', $this->buildJsSyncTreeToGantt('oTable', 'scrollToToday'));
        $controller->addMethod(self::CONTROLLER_METHOD_CHECK_TABLE_IS_READY, $this,'oTable', $this->buildJsCheckTableIsReady('oTable'));
        $controller->addMethod(self::CONTROLLER_METHOD_GET_GLOBAL_START_END_DATES, $this,'', $this->buildJsGetGlobalStartEndDates());
        $controller->addMethod(self::CONTROLLER_METHOD_SET_GLOBAL_START_END_DATES_TO_GANTT, $this,'gantt', $this->buildJsSetGlobalStartEndDatesToGantt('gantt'));
        if ($autoSizeVertical) {
            $controller->addMethod(
                self::CONTROLLER_METHOD_RESIZE_VERTICAL_LAYOUT,
                $this,
                'oTable',
                $this->buildJsResizeVerticalLayout('oTable')
            );
            $resizeVerticalLayoutJs = $controller->buildJsMethodCallFromController(
                self::CONTROLLER_METHOD_RESIZE_VERTICAL_LAYOUT,
                $this,
                'oTable'
            ) . ';';
        } else {
            $resizeVerticalLayoutJs = '';
        }
        $tableResizeEventsJs = $autoSizeVertical
            ? 'oTable.attachRowsUpdated(fnResizeGantt); oTable.attachColumnResize(fnResizeGantt);'
            : '';
        $fitInitialSplitSizeJs = '';
        if ($this->getExpandableSplitPane() !== null) {
            $controller->addMethod(self::CONTROLLER_METHOD_FIT_INITIAL_SPLIT_SIZE, $this, 'oTable', $this->buildJsFitInitialSplitSize('oTable'));
            $fitInitialSplitSizeJs = $controller->buildJsMethodCallFromController(self::CONTROLLER_METHOD_FIT_INITIAL_SPLIT_SIZE, $this, 'oTable') . ';';
        }
        $orientation = ucfirst($widget->getOrientation());
        $splitterResizableJs = $widget->getHideSplitBar() ? 'false' : 'true';
        $splitterStyleClassJs = $widget->getHideSplitBar() ? ".addStyleClass('exf-gantt-no-splitbar')" : '';
        $popupEventsJs = '';
        if ($this->isInHorizontalPopup()) {
            $popupIdJs = $this->escapeString($this->getFacade()->getElement($widget->getParent())->getId());
            $popupEventsJs = <<<JS

                                var oPopover = sap.ui.getCore().byId({$popupIdJs});
                                oCtrl.gantt.options.scroll_to = 'today';
                                var fnSyncPopup = function() {
                                    cancelAnimationFrame(oTable._exfPopupGanttFrame);
                                    oTable._exfPopupGanttFrame = requestAnimationFrame(function() {
                                        oTable._exfPopupGanttFrame = null;
                                        if (oPopover.isOpen() && oTable.getDomRef()) {
                                            {$controller->buildJsMethodCallFromController(self::CONTROLLER_METHOD_SYNC_TO_GANTT, $this, 'oTable')};
                                        }
                                    });
                                };
                                oTable.attachRowsUpdated(fnSyncPopup);
                                oPopover.attachBeforeOpen(function() {
                                    oTable.setFirstVisibleRow(0);
                                });
                                oPopover.attachAfterOpen(function() {
                                    window.addEventListener('resize', fnSyncPopup);
                                    {$controller->buildJsMethodCallFromController(self::CONTROLLER_METHOD_SET_GLOBAL_START_END_DATES_TO_GANTT, $this, 'oCtrl.gantt')};
                                    fnSyncPopup();
                                });
                                oPopover.attachBeforeClose(function() {
                                    window.removeEventListener('resize', fnSyncPopup);
                                    cancelAnimationFrame(oTable._exfPopupGanttFrame);
                                    clearTimeout(oTable._exfPopupGanttSyncTimer);
                                    oTable._exfPopupGanttScrollToToday = false;
                                });
JS;
        }
        
        if ($calItem->hasColorScale()) {
            $this->registerColorClasses($calItem->getColorScale());
        }
        
        // adds the Gantt buttons to the toolbar as separate group.
        $this->addGanttButtons();

        if ($autoSizeVertical) {
            $tableHeaderHeight = $this->getTableHeaderHeightPx();
            $ganttHeaderHeight = $this->getGanttUpperHeaderHeightPx()
                + $this->getGanttLowerHeaderHeightPx()
                + $this->getGanttHeaderPaddingPx();
            $rowHeight = $this->getTableRowHeightPx();
            $splitterPreparationJs = <<<JS

                (function(){
                    var domScrollbarTest = document.createElement('div');
                    domScrollbarTest.style.cssText = 'position:absolute;visibility:hidden;width:100px;height:100px;overflow:scroll;';
                    document.body.appendChild(domScrollbarTest);
                    var iScrollbarHeight = domScrollbarTest.offsetHeight - domScrollbarTest.clientHeight;
                    domScrollbarTest.remove();
                    var iRowsHeight = {$heightInRows} * {$rowHeight};
                    var iTableAreaHeight = {$tableHeaderHeight} + iScrollbarHeight + iRowsHeight;
                    var iGanttAreaHeight = {$ganttHeaderHeight} + iScrollbarHeight + iRowsHeight;
                    return (
JS;
            $splitterCompletionJs = ')})()';
            $tableLayoutDataJs = ".setLayoutData(new sap.ui.layout.SplitterLayoutData({size: iTableAreaHeight + 'px', resizable: {$splitterResizableJs}}))";
            $ganttLayoutDataJs = "layoutData: new sap.ui.layout.SplitterLayoutData({size: iGanttAreaHeight + 'px', resizable: {$splitterResizableJs}}),";
        } else {
            $splitterPreparationJs = '';
            $splitterCompletionJs = '';
            $tableSize = $widget->getTableSplitSize();
            $ganttSize = $widget->getGanttSplitSize();
            $tableSizeJs = $tableSize->isUndefined() || $tableSize->isMax() ? 'auto' : $tableSize->getValue();
            $ganttSizeJs = $ganttSize->isUndefined() || $ganttSize->isMax() ? 'auto' : $ganttSize->getValue();
            if ($tableSizeJs === 'auto' && $ganttSizeJs === 'auto') {
                $tableSizeJs = '50%';
            }
            $tableSizeJs = $this->escapeString($tableSizeJs);
            $ganttSizeJs = $this->escapeString($ganttSizeJs);
            $tableLayoutDataJs = ".setLayoutData(new sap.ui.layout.SplitterLayoutData({size: {$tableSizeJs}, resizable: {$splitterResizableJs}}))";
            $ganttLayoutDataJs = "layoutData: new sap.ui.layout.SplitterLayoutData({size: {$ganttSizeJs}, resizable: {$splitterResizableJs}}),";
        }
        
        // reloads the gantt task data at navigation return
        $controller->addOnShowViewScript(
            <<<JS

               setTimeout(function(){
                 const oTableReload = sap.ui.getCore().getElementById('{$this->getId()}');
                 var oCtrl = sap.ui.getCore().byId('{$this->getId()}');
                  var oTable = oTableReload;
                  {$fitInitialSplitSizeJs}
                 
                 {$controller->buildJsMethodCallFromController(self::CONTROLLER_METHOD_SYNC_TO_GANTT, $this, 'oTableReload')};
                 
                 let toolbarOffsetHeight = sap.ui.getCore().byId('{$this->getId()}').$().parents('.sapMPanel').children('.exf-datatoolbar')[0]?.offsetHeight
                 if (toolbarOffsetHeight !== undefined) {
                   sap.ui.getCore().byId('{$this->getId()}').$().parents('.sapMPanelContent').css("height", "calc(100% - " + toolbarOffsetHeight + "px)");
                 }
               },0);
JS
            ,false);
        
        $tableFlatModeJs = $widget->getTreeParentRelationAlias() ? '' : '.setUseFlatMode(true)';

        $gantt = <<<JS

        {$splitterPreparationJs}
        new sap.ui.layout.Splitter({
            orientation: '{$orientation}',
            {$this->buildJsProperties()}
            contentAreas: [
                {$this->buildJsConstructorForTreeTable($oControllerJs)}
                {$tableFlatModeJs}
                {$tableLayoutDataJs}
                ,
                new sap.ui.core.HTML("{$this->getId()}_wrapper", {
                    content: "<div id=\"{$this->getId()}_gantt\" class=\"exf-gantt\" style=\"height:100%; min-height: 100px; overflow: hidden;\"></div>",
                    {$ganttLayoutDataJs}
                    afterRendering: function(oEvent) {
                        setTimeout(function() {
                            var oCtrl = sap.ui.getCore().byId('{$this->getId()}');
                            var oTable = sap.ui.getCore().getElementById('{$this->getId()}');
                             
                            if (oCtrl.gantt === undefined) {
                                oCtrl.gantt = {$this->buildJsGanttInit($oControllerJs)}
                                {$popupEventsJs}
                                
                                var oRowsBinding = new sap.ui.model.Binding(sap.ui.getCore().byId('{$this->getId()}').getModel(), '/rows', sap.ui.getCore().byId('{$this->getId()}').getModel().getContext('/rows'));
                                
                                // This code part is only executed if the TreeTable data has changed (also on filter) and not by scroll or Tab switch:
                                oRowsBinding.attachChange(function(oEvent){
                                    var oBinding = oEvent.getSource();
                                    
                                    // Calculating the interval of the Gantt view (start and end):
                                    {$controller->buildJsMethodCallFromController(self::CONTROLLER_METHOD_SET_GLOBAL_START_END_DATES_TO_GANTT, $this, 'oCtrl.gantt')};
                                    // Re-rendering the Gantt with the new data:
                                    {$controller->buildJsMethodCallFromController(self::CONTROLLER_METHOD_SYNC_TO_GANTT, $this, 'oTable, true')};
                                });
                                
                                /**
                                 * Coalesces splitter and table changes without invalidating the Gantt.
                                 */
                                var fnResizeGantt = function(){
                                    cancelAnimationFrame(oTable._exfVerticalGanttResizeFrame);
                                    oTable._exfVerticalGanttResizeFrame = requestAnimationFrame(function(){
                                        oTable._exfVerticalGanttResizeFrame = null;
                                        {$resizeVerticalLayoutJs}
                                        {$fitInitialSplitSizeJs}
                                        {$controller->buildJsMethodCallFromController(self::CONTROLLER_METHOD_SYNC_TO_GANTT, $this, 'oTable')};
                                    });
                                };
                                sap.ui.core.ResizeHandler.register(oTable.getParent(), fnResizeGantt);
                                {$tableResizeEventsJs}
                            }
                            
                            // If there are a Tab with another initialized Gantt, 
                            // it will not calculate the global_min_view values because the data is not loaded there yet.
                            // This code part is executed on each render, also on each Tab switch so we check if the global_min_view values are initialized.
                            if (oCtrl.gantt.options.global_min_view_start === null && oCtrl.gantt.options.global_min_view_end === null) {
                              {$controller->buildJsMethodCallFromController(self::CONTROLLER_METHOD_SET_GLOBAL_START_END_DATES_TO_GANTT, $this, 'oCtrl.gantt')};
                            }
                            {$controller->buildJsMethodCallFromController(self::CONTROLLER_METHOD_SYNC_TO_GANTT, $this, 'oTable, true')};
                            {$resizeVerticalLayoutJs}
                            {$fitInitialSplitSizeJs}
                        },0);
                    }
                })
            ]
        })
        {$splitterStyleClassJs}
        {$splitterCompletionJs}

JS;
        return $this->buildJsPanelWrapper($gantt, $oControllerJs) . ".addStyleClass('sapUiNoContentPadding')";
    }

    /**
     * Expand a max pane only after the inherited asynchronous column optimizer has finished.
     *
     * @param string $oTableJs
     * @param string $oModelJs
     * @return string
     */
    protected function buildJsUiTableColumnResize(string $oTableJs, string $oModelJs) : string
    {
        $resizeJs = parent::buildJsUiTableColumnResize($oTableJs, $oModelJs);
        if ($this->getExpandableSplitPane() === null) {
            return $resizeJs;
        }

        return <<<JS

            if (! {$oTableJs}.getDomRef() || {$oTableJs}.getDomRef().getBoundingClientRect().width <= 0) {
                return;
            }
            if ({$oTableJs}._exfGanttColumnsSizing) {
                return;
            }
            {$oTableJs}._exfGanttColumnsSizing = true;
            {$oTableJs}._exfGanttColumnsSized = false;
            (function(){
                {$resizeJs}
            })();
            (function(){
                var sPreviousWidths;
                var iStableFrames = 0;
                var iFrames = 0;
                var fnWaitForColumns = function(){
                    if (! {$oTableJs}.getDomRef() || {$oTableJs}.getDomRef().getBoundingClientRect().width <= 0) {
                        {$oTableJs}._exfGanttColumnsSizing = false;
                        return;
                    }
                    // The optimizer can enqueue further header/min/max width updates. Wait for
                    // both the column properties and the rendered headers to settle, not a timer count.
                    var sWidths = JSON.stringify({$oTableJs}.getColumns().filter(function(oColumn){
                        return oColumn.getVisible();
                    }).map(function(oColumn){
                        return [oColumn.getWidth(), oColumn.getDomRef()?.getBoundingClientRect().width || 0];
                    }));
                    iStableFrames = sWidths === sPreviousWidths && ! {$oTableJs}.data('_exfIsAutoResizing')
                        ? iStableFrames + 1 : 0;
                    sPreviousWidths = sWidths;
                    if (iStableFrames >= 3) {
                        {$oTableJs}._exfGanttColumnsSizing = false;
                        {$oTableJs}._exfGanttColumnsSized = true;
                        {$this->getController()->buildJsMethodCallFromController(self::CONTROLLER_METHOD_FIT_INITIAL_SPLIT_SIZE, $this, $oTableJs)};
                    } else if (++iFrames < 60) {
                        requestAnimationFrame(fnWaitForColumns);
                    } else {
                        // Leave the initial split intact; rendering/show/resize can retry later.
                        {$oTableJs}._exfGanttColumnsSizing = false;
                    }
                };
                requestAnimationFrame(fnWaitForColumns);
            })();
JS;
    }

    /**
     * Select the pane allowed to expand after rendering. Without configured split sizes,
     * the diagram expands by default, except when vertical height_in_rows sizes both panes.
     *
     * @return string|null
     */
    protected function getExpandableSplitPane() : ?string
    {
        $widget = $this->getWidget();
        $tableSize = $widget->getTableSplitSize();
        $ganttSize = $widget->getGanttSplitSize();
        if ($tableSize->isUndefined() && $ganttSize->isUndefined()) {
            return $widget->getOrientation() === Gantt::ORIENTATION_VERTICAL && $widget->getHeightInRows() !== null
                ? null : 'gantt';
        }
        if ($tableSize->isMax() === $ganttSize->isMax()) {
            return null;
        }
        return $ganttSize->isMax() ? 'gantt' : 'table';
    }

    /**
     * Expand the max pane by shrinking the other pane to its content, never above its initial size.
     *
     * Hidden tables are retried on rendering/show/resize. Once applied, the splitter is left alone
     * so data refreshes and navigation do not overwrite a user's manually chosen split.
     * Unchanged split sizes skip resizing and synchronization to preserve ongoing scroll animations.
     *
     * @param string $oTableJs
     * @return string
     */
    protected function buildJsFitInitialSplitSize(string $oTableJs) : string
    {
        $widget = $this->getWidget();
        $horizontalJs = $this->escapeBool($widget->getOrientation() === Gantt::ORIENTATION_HORIZONTAL);
        $expandGantt = $this->getExpandableSplitPane() === 'gantt';
        $expandGanttJs = $this->escapeBool($expandGantt);
        $otherSize = $expandGantt ? $widget->getTableSplitSize() : $widget->getGanttSplitSize();
        $initialSizeJs = $this->escapeString($otherSize->isUndefined() ? '50%' : $otherSize->getValue());
        $tableHeaderHeight = $this->getTableHeaderHeightPx();
        $ganttHeaderHeight = $this->getGanttUpperHeaderHeightPx()
            + $this->getGanttLowerHeaderHeightPx() + $this->getGanttHeaderPaddingPx();
        $rowHeight = $this->getTableRowHeightPx();
        return <<<JS

            if (! {$oTableJs} || {$oTableJs}._exfGanttInitialSplitSizeApplied) {
                return;
            }
            var oSplitter = {$oTableJs}.getParent();
            var domSplitter = oSplitter?.getDomRef();
            var domTable = {$oTableJs}.getDomRef();
            if (! domSplitter || ! domTable || domTable.getBoundingClientRect().width <= 0) {
                return;
            }
            if (! {$oTableJs}._exfGanttColumnsSized) {
                // A data load in a hidden tab skipped optimization. Run it once visible.
                if ({$oTableJs}.getModel()?.getProperty('/rows')?.length > 0) {
                    {$this->getController()->buildJsMethodCallFromController(self::CONTROLLER_METHOD_RESIZE_COLUMNS, $this, $oTableJs . ', ' . $oTableJs . '.getModel()')};
                }
                return;
            }
            var bHorizontal = {$horizontalJs};
            var bExpandGantt = {$expandGanttJs};
            var domSplitBar = oSplitter.getDomRef('splitbar-0');
            var iAvailableSize = bHorizontal
                ? domSplitter.clientWidth - (domSplitBar?.offsetWidth || 0)
                : domSplitter.clientHeight - (domSplitBar?.offsetHeight || 0);
            if (iAvailableSize <= 0 || ! {$oTableJs}.gantt) {
                return;
            }
            var iRequiredSize;
            if (bHorizontal && bExpandGantt) {
                var aColumns = {$oTableJs}.getColumns().filter(function(oColumn){ return oColumn.getVisible(); });
                var iColumnsWidth = 0;
                for (var oColumn of aColumns) {
                    // Include fixed columns but not UI5's trailing empty column.
                    var iWidth = oColumn.getDomRef()?.getBoundingClientRect().width || 0;
                    // UI5 may not yet reflect an optimized/custom pixel width in its header DOM.
                    // Never shrink the pane below the actual width assigned to the column.
                    var sColumnWidth = oColumn.getWidth();
                    if (sColumnWidth.endsWith('px')) {
                        iWidth = Math.max(iWidth, parseFloat(sColumnWidth) || 0);
                    }
                    if (iWidth <= 0) {
                        return;
                    }
                    iColumnsWidth += iWidth;
                }
                if (iColumnsWidth <= 0) {
                    return;
                }
                iRequiredSize = iColumnsWidth
                    + ({$oTableJs}.$().find('.sapUiTableRowHdrScr').first().outerWidth() || 0)
                    + ({$oTableJs}.$().find('.sapUiTableVSb').first().outerWidth() || 0)
                    + (domTable.offsetWidth - domTable.clientWidth);
            } else if (bHorizontal) {
                var domSvg = {$oTableJs}.gantt.\$svg;
                if (! domSvg) {
                    return;
                }
                iRequiredSize = Math.max(parseFloat(domSvg.getAttribute('width')) || 0, domSvg.getBBox().width);
            } else {
                var iRows = {$oTableJs}.getBinding('rows')?.getLength();
                if (! Number.isFinite(iRows) || iRows < 0) {
                    return;
                }
                if (bExpandGantt) {
                    var iRowHeight = {$oTableJs}.getRows()[0]?.getDomRef()?.offsetHeight || {$rowHeight};
                    var iHeaderHeight = {$oTableJs}.$().find('.sapUiTableColHdrCnt').first().outerHeight() || {$tableHeaderHeight};
                    iRequiredSize = iHeaderHeight + iRows * iRowHeight
                        + ({$oTableJs}.$().find('.sapUiTableHSb').first().outerHeight() || 0)
                        + (domTable.offsetHeight - domTable.clientHeight);
                } else {
                    var domGanttContainer = {$oTableJs}.gantt.\$container;
                    var iScrollbarHeight = domGanttContainer
                        ? Math.max(0, domGanttContainer.offsetHeight - domGanttContainer.clientHeight) : 0;
                    iRequiredSize = {$ganttHeaderHeight} + iRows * ({$oTableJs}.gantt.options.row_height || {$rowHeight}) + iScrollbarHeight;
                }
            }
            var sInitialSize = {$initialSizeJs};
            var bPixels = sInitialSize.endsWith('px');
            var iInitialSize = bPixels ? parseFloat(sInitialSize) : iAvailableSize * parseFloat(sInitialSize) / 100;
            var iOtherSize = Math.min(iAvailableSize, iInitialSize, Math.ceil(iRequiredSize) + 1);
            var aContentAreas = oSplitter.getContentAreas();
            var iOtherPane = bExpandGantt ? 0 : 1;
            var sOtherSize = bPixels ? iOtherSize + 'px' : (iOtherSize / iAvailableSize * 100) + '%';
            // Unchanged split sizes skip resizing and synchronization to preserve ongoing scroll animations.
            {$oTableJs}._exfGanttInitialSplitSizeApplied = true;
            if (aContentAreas[iOtherPane].getLayoutData().getSize() === sOtherSize
                && aContentAreas[1 - iOtherPane].getLayoutData().getSize() === 'auto') {
                return;
            }
            // Suppress invalidation: rerendering would detach the Gantt DOM from its instance.
            aContentAreas[iOtherPane].getLayoutData().setProperty('size', sOtherSize, true);
            aContentAreas[1 - iOtherPane].getLayoutData().setProperty('size', 'auto', true);
            oSplitter.triggerResize(true);
            requestAnimationFrame(function(){
                if ({$oTableJs}.getDomRef()) {
                    {$this->getController()->buildJsMethodCallFromController(self::CONTROLLER_METHOD_SYNC_TO_GANTT, $this, $oTableJs)};
                }
            });
JS;
    }
    
    /**
     *
     * @see UI5DataElementTrait::isWrappedInPanel()
     */
    protected function isWrappedInPanel() : bool
    {
        return true;
    }

    /**
     * Derives the panel height of a vertical Gantt from the configured number of visible rows.
     *
     * Toolbar and scrollbar heights are measured at runtime so the result follows the active UI5
     * theme and browser. Automatic panes are sized independently to avoid blank space above the chart.
     * Explicit split sizes retain the existing panel height calculation.
     *
     * @return string
     * @see UI5DataElementTrait::buildJsPanelHeight()
     */
    protected function buildJsPanelHeight() : string
    {
        $widget = $this->getWidget();
        $heightInRows = $widget->getHeightInRows();

        if ($widget->getOrientation() !== Gantt::ORIENTATION_VERTICAL || $heightInRows === null) {
            return parent::buildJsPanelHeight();
        }

        $tableHeaderHeight = $this->getTableHeaderHeightPx();
        $ganttHeaderHeight = $this->getGanttUpperHeaderHeightPx()
            + $this->getGanttLowerHeaderHeightPx()
            + $this->getGanttHeaderPaddingPx();
        $rowHeight = $this->getTableRowHeightPx();
        $splitterBarHeight = $widget->getHideSplitBar() ? 0 : self::VERTICAL_SPLITTER_BAR_HEIGHT_PX;
        $paneAreaHeightJs = $widget->getTableSplitSize()->isUndefined() && $widget->getGanttSplitSize()->isUndefined()
            ? 'iTableAreaHeight + iGanttAreaHeight'
            : '2 * Math.max(iTableAreaHeight, iGanttAreaHeight)';

        return <<<JS

                (function(){
                    var jqToolbarTest = $('<div class="sapMTB sapMTBHeader-CTX"></div>').appendTo('body');
                    var iToolbarHeight = jqToolbarTest.outerHeight();
                    var domScrollbarTest = document.createElement('div');
                    domScrollbarTest.style.cssText = 'position:absolute;visibility:hidden;width:100px;height:100px;overflow:scroll;';
                    document.body.appendChild(domScrollbarTest);
                    var iScrollbarHeight = domScrollbarTest.offsetHeight - domScrollbarTest.clientHeight;
                    jqToolbarTest.remove();
                    domScrollbarTest.remove();

                    var iRowsHeight = {$heightInRows} * {$rowHeight};
                    var iTableAreaHeight = {$tableHeaderHeight} + iScrollbarHeight + iRowsHeight;
                    var iGanttAreaHeight = {$ganttHeaderHeight} + iScrollbarHeight + iRowsHeight;
                    return (iToolbarHeight + {$paneAreaHeightJs} + {$splitterBarHeight}) + 'px';
                })()
JS;
    }

    /**
     * Recalculates vertical splitter sizes without invalidating the rendered Gantt.
     *
     * The table chrome is measured like UI5's automatic row mode, including the scrollbar space
     * UI5 reserves even when no horizontal scrollbar is visible. Splitter and panel properties are
     * updated without rerendering because rerendering would detach the Gantt DOM from its instance.
     * Cached scrollbar measurements are renewed when browser zoom changes the device pixel ratio.
     * Each pane keeps its own required height; table row and column updates rerun this calculation.
     *
     * @param string $oTableJs
     * @return string
     */
    protected function buildJsResizeVerticalLayout(string $oTableJs) : string
    {
        $heightInRows = $this->getWidget()->getHeightInRows();
        $tableHeaderHeight = $this->getTableHeaderHeightPx();
        $ganttHeaderHeight = $this->getGanttUpperHeaderHeightPx()
            + $this->getGanttLowerHeaderHeightPx()
            + $this->getGanttHeaderPaddingPx();
        $rowHeight = $this->getTableRowHeightPx();
        $splitterBarHeight = $this->getWidget()->getHideSplitBar() ? 0 : self::VERTICAL_SPLITTER_BAR_HEIGHT_PX;

        return <<<JS

            var oSplitter = {$oTableJs}.getParent();
            var aContentAreas = oSplitter?.getContentAreas() ?? [];
            var domTable = {$oTableJs}.getDomRef();
            if (aContentAreas.length < 2 || ! domTable) {
                return;
            }

            var iDefaultScrollbarHeight = {$oTableJs}._exfDefaultScrollbarHeight;
            if (! Number.isFinite(iDefaultScrollbarHeight)
                || {$oTableJs}._exfDefaultScrollbarPixelRatio !== window.devicePixelRatio) {
                var domScrollbarTest = document.createElement('div');
                domScrollbarTest.style.cssText = 'position:absolute;visibility:hidden;width:100px;height:100px;overflow:scroll;';
                document.body.appendChild(domScrollbarTest);
                iDefaultScrollbarHeight = domScrollbarTest.offsetHeight - domScrollbarTest.clientHeight;
                domScrollbarTest.remove();
                {$oTableJs}._exfDefaultScrollbarHeight = iDefaultScrollbarHeight;
                {$oTableJs}._exfDefaultScrollbarPixelRatio = window.devicePixelRatio;
            }
            
            var jqTable = {$oTableJs}.$();
            var jqTableScrollbar = jqTable.find('.sapUiTableHSb:visible').first();
            var domTableRows = {$oTableJs}.getDomRef('tableCCnt');
            var domTablePlaceholder = {$oTableJs}.getDomRef('placeholder-bottom');
            var iTablePlaceholderHeight = domTablePlaceholder?.clientHeight || 0;
            var iTableChromeHeight = domTableRows
                ? Math.max(0, domTable.scrollHeight - domTableRows.clientHeight - iTablePlaceholderHeight)
                : {$tableHeaderHeight} + (jqTableScrollbar.outerHeight() || 0);
            if (jqTableScrollbar.length === 0) {
                iTableChromeHeight += iDefaultScrollbarHeight;
            }
            
            var domSplitter = oSplitter.getDomRef();
            var domGanttContainer = domSplitter?.querySelector('#{$this->getId()}_gantt .gantt-container');
            var bGanttScrollbarVisible = domGanttContainer
                ? domGanttContainer.scrollWidth > domGanttContainer.clientWidth
                : false;
            var iGanttScrollbarHeight = bGanttScrollbarVisible
                ? Math.max(iDefaultScrollbarHeight, domGanttContainer.offsetHeight - domGanttContainer.clientHeight)
                : 0;
            var domFirstTableRow = {$oTableJs}.getRows()[0]?.getDomRef();
            var iTableRowHeight = domFirstTableRow?.offsetHeight || {$rowHeight};
            var oGantt = {$oTableJs}.gantt;
            var iGanttRowHeight = oGantt?.options?.row_height || {$rowHeight};
            var iTableAreaHeight = iTableChromeHeight + ({$heightInRows} * iTableRowHeight);
            var iGanttAreaHeight = {$ganttHeaderHeight} + iGanttScrollbarHeight + ({$heightInRows} * iGanttRowHeight);
            var sTableAreaHeight = iTableAreaHeight + 'px';
            var sGanttAreaHeight = iGanttAreaHeight + 'px';
            var oTableLayoutData = aContentAreas[0].getLayoutData();
            var oGanttLayoutData = aContentAreas[1].getLayoutData();
            var bLayoutChanged = false;
            
            if (oTableLayoutData.getSize() !== sTableAreaHeight) {
                oTableLayoutData.setProperty('size', sTableAreaHeight, true);
                bLayoutChanged = true;
            }
            if (oGanttLayoutData.getSize() !== sGanttAreaHeight) {
                oGanttLayoutData.setProperty('size', sGanttAreaHeight, true);
                bLayoutChanged = true;
            }
            
            var oPanel = oSplitter.getParent();
            var oToolbar = oPanel?.getHeaderToolbar();
            var iToolbarHeight = oToolbar?.getDomRef() ? oToolbar.$().outerHeight() : 0;
            var domSplitterBar = oSplitter.getDomRef('splitbar-0');
            var iSplitterBarHeight = domSplitterBar ? domSplitterBar.offsetHeight : {$splitterBarHeight};
            var iPanelContentHeight = iTableAreaHeight + iSplitterBarHeight + iGanttAreaHeight;
            var sPanelHeight = (iToolbarHeight + iPanelContentHeight) + 'px';
            if (oPanel?.getHeight() !== sPanelHeight) {
                oPanel.setProperty('height', sPanelHeight, true);
                bLayoutChanged = true;
            }
            var jqPanel = oPanel?.$();
            jqPanel?.css('height', sPanelHeight);
            jqPanel?.children('.sapMPanelContent').css('height', iPanelContentHeight + 'px');
            
            if (bLayoutChanged) {
                oSplitter.triggerResize(true);
            }
JS;
    }
    
    /**
     * This method builds the JS property for column header height of the left table.
     * 
     * @return string
     */
    protected function buildJsPropertyColumnHeaderHeight() : string
    {
        return 'columnHeaderHeight: ' . $this->getTableHeaderHeightPx() . ',';
    }

    /**
     * Returns the table header height for the configured Gantt orientation.
     *
     * @return int
     */
    protected function getTableHeaderHeightPx() : int
    {
        return $this->getWidget()->getOrientation() === Gantt::ORIENTATION_VERTICAL
            ? self::VERTICAL_TABLE_HEADER_HEIGHT_PX
            : self::TABLE_HEADER_HEIGHT_PX;
    }

    /**
     * Returns the upper Gantt header height for the configured orientation.
     *
     * @return int
     */
    protected function getGanttUpperHeaderHeightPx() : int
    {
        return $this->getWidget()->getOrientation() === Gantt::ORIENTATION_VERTICAL
            ? self::VERTICAL_GANTT_UPPER_HEADER_HEIGHT_PX
            : self::GANTT_UPPER_HEADER_HEIGHT_PX;
    }

    /**
     * Returns the lower Gantt header height for the configured orientation.
     *
     * @return int
     */
    protected function getGanttLowerHeaderHeightPx() : int
    {
        return $this->getWidget()->getOrientation() === Gantt::ORIENTATION_VERTICAL
            ? self::VERTICAL_GANTT_LOWER_HEADER_HEIGHT_PX
            : self::GANTT_LOWER_HEADER_HEIGHT_PX;
    }

    /**
     * Returns the Gantt header padding for the configured orientation.
     *
     * @return int
     */
    protected function getGanttHeaderPaddingPx() : int
    {
        return $this->getWidget()->getOrientation() === Gantt::ORIENTATION_VERTICAL
            ? self::VERTICAL_GANTT_HEADER_PADDING_PX
            : self::GANTT_HEADER_PADDING_PX;
    }
    
    /**
     *
     * @param string $oEventJs
     * @return string
     */
    protected function buildJsOnOpenScript(string $oEventJs) : string
    {
        return <<<JS

                var oTable = oEvent.getSource();
                setTimeout(function(){
                    {$this->getController()->buildJsMethodCallFromController(self::CONTROLLER_METHOD_SYNC_TO_GANTT, $this, 'oTable')};
                },10);
JS;
    }
    
    /**
     *
     * @param string $oEventJs
     * @return string
     */
    protected function buildJsOnCloseScript(string $oEventJs) : string
    {
        return <<<JS
        
                var oTable = oEvent.getSource();
                var domGanttContainer = $('#{$this->getId()}_gantt .gantt-container')[0];
                var iScrollLeft = domGanttContainer.scrollLeft;
                setTimeout(function(){
                    {$this->getController()->buildJsMethodCallFromController(self::CONTROLLER_METHOD_SYNC_TO_GANTT, $this, 'oTable')};
                    domGanttContainer.scrollTo(iScrollLeft, 0);
                },10);
JS;
    }
    
    /**
     * 
     * @return string
     */
    protected function buildJsGanttGetInstance() : string
    {
        return $this->getController()->buildJsDependentObjectGetter('gantt', $this);
    }
		
    /**
     * 
     * @return string
     */
    protected function buildJsGanttInit(string $oControllerJs = 'oController') : string
    {
        $widget = $this->getWidget();
        $upperHeaderHeight = $this->getGanttUpperHeaderHeightPx();
        $lowerHeaderHeight = $this->getGanttLowerHeaderHeightPx();
        $rowHeight = $this->getTableRowHeightPx();
        // Do not start a smooth scroll on the popup's loading placeholder.
        $initialScrollJs = $this->isInHorizontalPopup() ? "scroll_to: 'start'," : '';
        
        $calItem = $widget->getTasksConfig();
        $startCol = $calItem->getStartTimeColumn();
        $startFormatter = $this->getFacade()->getDataTypeFormatter($startCol->getDataType());
        $endCol = $calItem->getEndTimeColumn();
        $endFormatter = $this->getFacade()->getDataTypeFormatter($endCol->getDataType());
        $titleOverflow = $calItem->getTitleOverflow() ?? 'outside';
        $defaultDurationHours = $calItem->getDefaultDurationHours(48);
        $viewModesConfig = $this->getViewModesConfig();
        $editableJs = ($calItem->getStartTimeColumn()->isEditable() && $calItem->getEndTimeColumn()->isEditable()) ? 'true' : 'false';
        $initialViewName = $widget->getTimelineConfig()->getInitialViewName();
        $fillPaddingToBorder = $widget->getTimelineConfig()->getFillPaddingToBorder();
        $showOn = $widget->getHidePopup() ? Popup::SHOW_ON_CLICK : $calItem->getPopup()->getShowOn();
        
        $translator = $this->getWorkbench()->getCoreApp()->getTranslator();
        
        $viewModesConfigJson = json_encode($viewModesConfig, JSON_UNESCAPED_SLASHES);
                
        if ($startCol->getDataType() instanceof DateDataType) {
            $dateFormat = $startFormatter->getFormat();
        } else {
            $dateFormat = $translator->translate('LOCALIZATION.DATE.DATE_FORMAT');
        }
        
        // see if this particular child(oChildRow)is to be moved along with its parent if the parent is moved
        // check if there is a condition set to adjust which children are to be moved along with its parent
        /* @var $condProp \exface\Core\Widgets\Parts\ConditionalProperty */
        if (null !== $condProp = $widget->getChildrenMoveWithParentIf()) {
            $rowMoveFilterJs = <<<JS

                        // check if the set condition is matching the property of the child. If not, continue with the next item
                        if (false === Boolean({$this->buildJsConditionalPropertyRowCheck($condProp->getConditionGroup(), 'oChildRow')})) {
                            return;
                        }
JS;
            // if the set condition is matching the property of the child, continue with moving the child
        } else {
            $rowMoveFilterJs = '';
        }
        
        return <<<JS

(function() {
  
    return new Gantt("#{$this->getId()}_gantt", [
      {
        id: 1,
        name: 'Loading...',
        start: (() => {
          const d = new Date();
          return "" + d.getFullYear() + "-" + d.getMonth() + "-" + d.getDate() + "";
        })(),
        duration: '3d',
      }
    ], {
        view_mode_select: false, // Keep at false, we use here UI5 MenuButton for view mode selection instead.
        today_button: false,
        upper_header_height: {$upperHeaderHeight},
        lower_header_height: {$lowerHeaderHeight},
        auto_move_label: true,
        view_modes: {$viewModesConfigJson},
        view_mode: '{$initialViewName}',
        {$initialScrollJs}
        infinite_padding: false, //TODO SR: This triggers at side wheel scrolling and expands the Gantt view interval. Currently unstable and breaks the scroll fix. Let it on "FALSE" until fixed.
        // <<< New properties-----------------------------------------------------------------------
        popup_on: {$this->escapeString($showOn)},
        // TODO SR: Build uxon properties if ready:
        holidays: null, // { 'var(--g-weekend-highlight-color)': 'weekend' }
        stripe_rows: true,
        date_formatter: exfTools.date.format, // Uses or exfTools formatter
        date_format_default: 'yyyy-MM-dd HH:mm:ss.SSS',
        row_height: {$rowHeight}, // Initial value. Row zoom can modify this.
        row_lanes: 2, // Initial value. Row zoom can modify this.
        include_today_in_padding: false, //TODO SR: @experimental: If the padding is added to the right side, the "today" is currently also at the right side and not an the left.
        window_fill_padding_to_border: '{$fillPaddingToBorder}',
        // ATTENTION: the "popup_aggregate" prefixed properties are @experimental and not ready for production. 
        // They only change the default aggregated popup.
        // Currently, the default popups are not visible because the DataCalendarItem.getPopup() always gives us a custom PowerUI popup. 
        popup_aggregate_expand_tasks: false, // @experimental: Shows a compact Gantt next to the aggregation popup task list.
        popup_aggregate_gantt_width: 550, // @experimental: Width in px for the Gantt shown inside aggregation popups..
        popup_aggregate_style: 'table', // @experimental: 'list' | 'table'
        popup_aggregate_include_upper_row_tasks: 'true', // @experimental: Includes tasks that are in the top lane of the row in the aggregate popup. Set to false to only include tasks inside the aggregation block.
        //
        popup: {$this->escapeBool(! $widget->getHidePopup())},
        on_popup_request: {$this->buildJsRenderPopup($oControllerJs)},
        start_of_week: 'monday', // 'monday' | 'sunday' TODO SR: 'sunday' currentlly dont work properly.
        //
        readonly: !($editableJs),
        //column_width: 30,
        //step: 24,
        bar_height: 19,
        //bar_corner_radius: 3,
        //arrow_curve: 5,
        padding: 14,
        label_overflow: '$titleOverflow',
        default_duration: Math.ceil('$defaultDurationHours' / 24), //TODO SR: default_duration is currently only available in days. maybe add support for hours in the future.
        language: 'en', // or 'es', 'it', 'ru', 'ptBr', 'fr', 'tr', 'zh', 'de', 'hu'
        on_today_missing: function() {
          // Called if all given tasks are entirely in the past or future and does not include today's date.
          const todayMissingPopupText = {$this->escapeString($translator->translate('WIDGET.GANTT_CHARD.TODAY_MISSING_POPUP_TEXT'))};
          const todayMissingPopupTitle = {$this->escapeString($translator->translate('WIDGET.GANTT_CHARD.TODAY_MISSING_POPUP_TITLE'))};
          {$this->buildJsShowMessageError('todayMissingPopupText', 'todayMissingPopupTitle')}
        },
    	on_date_change: function(oTask, dStart, dEnd) {
            // TODO: riel-gantt lib supports editing, but currently this PowerUI part dont work yet:
            //  Test and fix this code, if the Gantt should be editable again:
    		var oTable = sap.ui.getCore().byId('{$this->getId()}');
            var oModel = oTable.getModel();
            var oGantt = sap.ui.getCore().byId('{$this->getId()}').gantt;
            var iRow = oGantt.tasks.indexOf(oTask);
            var oCtxt = oTable.getRows()[iRow].getBindingContext();
            var oRow = oTable.getModel().getProperty(oCtxt.sPath);
            var sColNameStart = '{$startCol->getDataColumnName()}';
            var sColNameEnd = '{$endCol->getDataColumnName()}';
            var bMoveChildrenWithParent = Boolean({$this->getWidget()->getChildrenMoveWithParent()});

            var oldStart = moment(oGantt.date_utils.parse(oRow[sColNameStart])); // string '10.04.2024' -> date object 10.04.2024 02:00:00 GMT+2 
            var oldEnd = moment(oGantt.date_utils.parse(oRow[sColNameEnd])); // string '11.04.2024' -> date object 11.04.2024 02:00:00 GMT+2 
            var newStart = moment(dStart);
            var newEnd = moment(dEnd);
            
            // Update the table in any case
            oModel.setProperty(oCtxt.sPath + '/' + sColNameStart, {$startFormatter->buildJsFormatDateObjectToInternal('newStart.toDate()')});
            oModel.setProperty(oCtxt.sPath + '/' + sColNameEnd, {$endFormatter->buildJsFormatDateObjectToInternal('newEnd.toDate()')});

            // Move children with parent when parent is dragged along the timeline
            // Only move children if UXON children_move_with_parent is set to true. True is the default value.
            if (bMoveChildrenWithParent === true) {
            
                // Check if the parent has been moved without the duration changing
                var iDurationNewMoment = newEnd.diff(newStart, 'hours');
                var iDurationOldMoment = oldEnd.diff(oldStart, 'hours');

                // Compare hour difference of old & new task dates, if they are same the children tasks will also be moved
                if (iDurationNewMoment === iDurationOldMoment) {
                    var moveDiffInHours = newStart.diff(oldStart, 'hours')
                    function processChildrenRecursively(oRow, moveDiffInHours, sColNameStart, sColNameEnd) {
                        oRow._children.forEach(function(oChildRow, iIdx) {
                            // check if there is a condition that enables/disables the moving of a child along with its parent
                            {$rowMoveFilterJs}
                            // move dates of oChildRow as far as the parent row was moved
                            var startDateChild = moment(new Date(oChildRow['date_start_plan'])).add(moveDiffInHours, 'hours');
                            var endDateChild = moment(new Date(oChildRow['date_end_plan'])).add(moveDiffInHours, 'hours');
                            oRow._children[iIdx][sColNameStart] = {$startFormatter->buildJsFormatDateObjectToInternal('startDateChild')};
                            oRow._children[iIdx][sColNameEnd] = {$endFormatter->buildJsFormatDateObjectToInternal('endDateChild')};

                            // if the child row has children too, call the function recursively
                            if (oChildRow._children && oChildRow._children.length > 0) {
                                processChildrenRecursively(oChildRow, moveDiffInHours, sColNameStart, sColNameEnd);
                            }
                        });
                    }
                    processChildrenRecursively(oRow, moveDiffInHours, sColNameStart, sColNameEnd);
                }
            }
    	}
    });
})();

JS;
    }

    /**
     * Builds the Gantt tasks and re-renders it.
     * 
     * @param string $oTableJs
     * @param string $scrollToToday // If set to TRUE, gantt scrolls to todays date.
     * @return string
     */
    protected function buildJsSyncTreeToGantt(string $oTableJs, string $scrollToToday) : string
    {
        $widget = $this->getWidget();
        $calItem = $widget->getTasksConfig();
        $draggableJs = ($calItem->getStartTimeColumn()->isEditable() && $calItem->getEndTimeColumn()->isEditable()) ? 'true' : 'false';
        $colorResolversJs = $this->buildJsColorResolver($calItem, 'oRow');
        $controller = $this->getController();
        $colorPreference = $this->getFacade()->getConfig()->getOption('WIDGET.OBJECT_STATUS.TEXT_COLOR_PREFERENCE');
        $popupLayoutJs = $this->isInHorizontalPopup() ? $this->buildJsResizePopupLayout('oTable') : '';
        $refreshJs = $this->isInHorizontalPopup()
            ? $this->buildJsRefreshPopupGantt()
            : 'oGantt.refresh(aTasks, scrollToToday);';
        $scheduleJs = $this->isInHorizontalPopup()
            ? $this->buildJsSchedulePopupGanttSync($oTableJs, $scrollToToday)
            : <<<JS

                if (isTableReady) {
                  setTimeout(function(){
                    syncTreeToGantt($oTableJs, $scrollToToday);
                  },0);
                } else {
                  setTimeout(function(){
                    syncTreeToGantt($oTableJs, $scrollToToday);
                  },200);
                }
JS;
        
        if ($calItem->getNestedDataColumn() !== null) {
            $nestedDataColName = $this->escapeString($calItem->getNestedDataColumn()->getDataColumnName());
        } else {
            $nestedDataColName = 'null';
        }
        return <<<JS

            const syncTreeToGantt = function(oTable, scrollToToday) {
                var oGantt = sap.ui.getCore().byId('{$this->getId()}').gantt;
                if (oGantt === undefined) return;
                {$popupLayoutJs}
                
                var aTasks = [];
                var sNestedColName = {$nestedDataColName}
                let lineIndex = 0;
                const rowKeys = [];
                
                oTable.getRows().forEach(function(oTreeRow) {
                    var oCtxt = oTreeRow.getBindingContext();
                    var oRow, sColor;
                    
                    function fnRowToTask(oRow) {
                        sColor = {$colorResolversJs};
                        sColor = sColor ?? '#b8c2cc'; // Default color.
                        var oTask = {
                            id: oRow['{$widget->getUidColumn()->getDataColumnName()}'],
                            name: oRow['{$calItem->getTitleColumn()->getDataColumnName()}'],
                            start: oRow["{$calItem->getStartTimeColumn()->getDataColumnName()}"],
                            end: oRow["{$calItem->getEndTimeColumn()->getDataColumnName()}"],
                            progress: 0,
                            dependencies: '',
                            lineIndex: lineIndex,
                            draggable: $draggableJs,
                            color: sColor,
                            colorHover: exfColorTools.shadeCssColor(sColor, -0.08),    // slightly darker
                            progressColor: exfColorTools.shadeCssColor(sColor, -0.28), // significantly darker
                            textColor: exfColorTools.pickTextColorForBackgroundColor(sColor, {$colorPreference}),
                            dataRow: oRow
                        };
        
                        if(oRow?._children?.length > 0 && oTask.start && oTask.end) {
                            oTask.custom_class += ' bar-folder';
                        }
                        
                        // Exludes tasks with no start and end date.
                        if (oTask.start || oTask.end) {
                          aTasks.push(oTask);
                        }
                    }
                    
                    if (!oCtxt) return;
                    
                    oRow = oTable.getModel().getProperty(oCtxt.sPath);
                    rowKeys.push(lineIndex);
                    
                    if (sNestedColName !== null) {
                        var oNestedData = oRow[sNestedColName];
                        oNestedData.rows.forEach(function(oNestedRow) {
                            fnRowToTask(oNestedRow)
                        })
                    } else {
                        fnRowToTask(oRow);
                    }
                    
                    lineIndex++
                });
                
                // filling the rowKeys array with the remaining visible empty rows from the table, 
                // so the Gantt has always the same number of rows as the table:
                while (lineIndex < oTable.getVisibleRowCount()) {
                    rowKeys.push(lineIndex++);
                }
                
                oGantt.options.row_keys = rowKeys;
                oGantt.tasks = aTasks;
                {$refreshJs}
            };

            let isTableReady = {$controller->buildJsMethodCallFromController(self::CONTROLLER_METHOD_CHECK_TABLE_IS_READY, $this, $oTableJs)};
            {$scheduleJs}
            
JS;
    }

    /**
     * Coalesces popup refreshes without losing a pending request to scroll to today.
     *
     * Uses the existing table-readiness delay; closing the popup cancels the outstanding timer.
     *
     * @param string $oTableJs
     * @param string $scrollToTodayJs
     * @return string
     */
    protected function buildJsSchedulePopupGanttSync(string $oTableJs, string $scrollToTodayJs) : string
    {
        return <<<JS

            (function(oPopupTable, bScrollToToday, bTableReady, fnSyncTreeToGantt) {
                oPopupTable._exfPopupGanttScrollToToday = oPopupTable._exfPopupGanttScrollToToday === true
                    || bScrollToToday === true;
                clearTimeout(oPopupTable._exfPopupGanttSyncTimer);
                oPopupTable._exfPopupGanttSyncTimer = setTimeout(function() {
                    oPopupTable._exfPopupGanttSyncTimer = null;
                    var bPendingScrollToToday = oPopupTable._exfPopupGanttScrollToToday;
                    oPopupTable._exfPopupGanttScrollToToday = false;
                    fnSyncTreeToGantt(oPopupTable, bPendingScrollToToday);
                }, bTableReady ? 0 : 200);
            })({$oTableJs}, {$scrollToTodayJs}, isTableReady, syncTreeToGantt);
JS;
    }

    /**
     * Renders popup tasks and finishes an automatic today scroll before subsequent layout refreshes.
     *
     * Assign the target without animation: restoring scrollLeft during a later positions-preserving
     * refresh would otherwise cancel a smooth scroll at an arbitrary intermediate position.
     *
     * @return string
     */
    protected function buildJsRefreshPopupGantt() : string
    {
        return <<<JS

                oGantt.refresh(aTasks, false);
                if (scrollToToday === true) {
                    oGantt.set_scroll_position(oGantt.options.scroll_to, false);
                }
JS;
    }

    /**
     * Returns whether this Gantt needs side-by-side layout sizing inside a popup.
     *
     * @return bool
     */
    protected function isInHorizontalPopup() : bool
    {
        return $this->getWidget()->getParent() instanceof Popup
            && $this->getWidget()->getOrientation() === Gantt::ORIENTATION_HORIZONTAL;
    }

    /**
     * Fits popup rows to their data and available viewport, keeping both pane scrollbars visible.
     *
     * Explicit widget heights and row counts are retained as upper bounds. Layout changes suppress
     * invalidation of the splitter and panel so the existing Gantt instance keeps its SVG.
     * Before Gantt initialization, configured header dimensions allow sizing its first popup frame.
     * Cached scrollbar measurements are renewed when browser zoom changes the device pixel ratio.
     *
     * @param string $oTableJs
     * @return string
     */
    protected function buildJsResizePopupLayout(string $oTableJs) : string
    {
        $widget = $this->getWidget();
        $autoHeightJs = $this->escapeBool($widget->getHeight()->isUndefined() || $widget->getHeight()->isAuto());
        $rowLimitJs = $widget->getHeightInRows() ?? 'null';
        $rowHeight = $this->getTableRowHeightPx();
        $headerHeight = $this->getTableHeaderHeightPx();
        $ganttChrome = $this->getGanttUpperHeaderHeightPx() + $this->getGanttLowerHeaderHeightPx()
            + $this->getGanttHeaderPaddingPx() + 14;
        $popoverIdJs = $this->escapeString($this->getFacade()->getElement($widget->getParent())->getId());

        return <<<JS

                (function(oTable) {
                    var oPopover = sap.ui.getCore().byId({$popoverIdJs});
                    var oSplitter = oTable.getParent();
                    var oPanel = oSplitter.getParent();
                    var domTable = oTable.getDomRef();
                    var domPanel = oPanel.getDomRef();
                    var domContent = oPopover.getDomRef('cont');
                    var domPopover = oPopover.getDomRef();
                    var oGantt = oTable.gantt;
                    if (!domTable || !domPanel || !domContent || !domPopover
                        || domPanel.getBoundingClientRect().width <= 0) {
                        return;
                    }

                    var iScrollbarHeight = oTable._exfDefaultScrollbarHeight;
                    if (!Number.isFinite(iScrollbarHeight)
                        || oTable._exfDefaultScrollbarPixelRatio !== window.devicePixelRatio) {
                        var domTest = document.createElement('div');
                        domTest.style.cssText = 'position:absolute;visibility:hidden;width:100px;height:100px;overflow:scroll;';
                        document.body.appendChild(domTest);
                        iScrollbarHeight = domTest.offsetHeight - domTest.clientHeight;
                        domTest.remove();
                        oTable._exfDefaultScrollbarHeight = iScrollbarHeight;
                        oTable._exfDefaultScrollbarPixelRatio = window.devicePixelRatio;
                    }
                    var iRowHeight = oTable.getRows()[0]?.getDomRef()?.offsetHeight || {$rowHeight};
                    var domRows = oTable.getDomRef('tableCCnt');
                    var domPlaceholder = oTable.getDomRef('placeholder-bottom');
                    var iTableChrome = domRows
                        ? Math.max(0, domTable.offsetHeight - domRows.offsetHeight - (domPlaceholder?.offsetHeight || 0))
                        : {$headerHeight};
                    var iGanttChrome = oGantt ? oGantt.config.header_height + oGantt.options.padding : {$ganttChrome};
                    var iChrome = Math.max(iTableChrome, iGanttChrome) + iScrollbarHeight;
                    var iToolbarHeight = oPanel.getHeaderToolbar()?.getDomRef()?.offsetHeight || 0;
                    var iZoom = domPanel.getBoundingClientRect().height / domPanel.offsetHeight || 1;
                    var iPopupChrome = domPopover.getBoundingClientRect().height - domContent.getBoundingClientRect().height;
                    var iViewportHeight = window.visualViewport?.height || window.innerHeight;
                    var oPopoverStyle = getComputedStyle(domPopover);
                    var iMargins = (parseFloat(oPopoverStyle.marginTop) || 0) + (parseFloat(oPopoverStyle.marginBottom) || 0);
                    var iAvailable = (iViewportHeight - iPopupChrome - iMargins) / iZoom;
                    var iContentMax = parseFloat(getComputedStyle(domContent).maxHeight);
                    var bFullscreen = domPopover.classList?.contains('exfPopupFullscreen') === true;
                    if (Number.isFinite(iContentMax) && iContentMax > 0) {
                        iAvailable = Math.min(iAvailable, iContentMax);
                    }
                    if (bFullscreen || oPopover.getContentHeight() !== 'auto') {
                        iAvailable = Math.min(iAvailable, domContent.clientHeight);
                    }
                    if (bFullscreen) {
                        var oContentStyle = getComputedStyle(domContent);
                        iAvailable -= (parseFloat(oContentStyle.paddingTop) || 0) + (parseFloat(oContentStyle.paddingBottom) || 0);
                    }
                    if (!bFullscreen && !{$autoHeightJs}) {
                        if (oTable._exfPopupGanttConfiguredHeight === undefined) {
                            oTable._exfPopupGanttConfiguredHeight = domPanel.offsetHeight;
                        }
                        iAvailable = Math.min(iAvailable, oTable._exfPopupGanttConfiguredHeight);
                    }
                    var iMaxRows = Math.max(1, Math.floor((iAvailable - iToolbarHeight - iChrome) / iRowHeight));
                    var iRows = Math.max(1, Math.min(oTable.getBinding('rows').getLength(), bFullscreen ? Infinity : ({$rowLimitJs} ?? Infinity), iMaxRows));
                    if (oTable.getVisibleRowCountMode() !== 'Fixed') {
                        oTable.setVisibleRowCountMode('Fixed');
                    }
                    if (oTable.getVisibleRowCount() !== iRows) {
                        oTable.setVisibleRowCount(iRows);
                    }
                    var iPaneHeight = !bFullscreen && {$autoHeightJs}
                        ? Math.ceil(iChrome + iRows * iRowHeight)
                        : Math.max(iChrome + iRowHeight, Math.floor(iAvailable - iToolbarHeight));
                    var sPanelHeight = (iPaneHeight + iToolbarHeight) + 'px';
                    var bChanged = oPanel.getHeight() !== sPanelHeight;
                    if (bChanged) {
                        oPanel.setProperty('height', sPanelHeight, true);
                    }
                    oPanel.$().css('height', sPanelHeight).children('.sapMPanelContent').css('height', iPaneHeight + 'px');
                    oSplitter.getContentAreas().forEach(function(oArea) {
                        var domArea = oArea.getDomRef()?.parentElement;
                        if (domArea) {
                            domArea.style.overflowY = 'hidden';
                            domArea.scrollTop = 0;
                        }
                    });
                    // Numeric height prevents refresh() from replacing the viewport with SVG content height.
                    if (oGantt) {
                        oGantt.options.container_height = iPaneHeight - iScrollbarHeight;
                        oGantt.\$container.style.height = iPaneHeight + 'px';
                        oGantt.\$container.style.boxSizing = 'border-box';
                    }
                    if (bChanged) {
                        oSplitter.triggerResize(true);
                    }
                })({$oTableJs});
JS;
    }

    /**
     * Renders the current popup rows and fits its geometry synchronously before the browser can paint it.
     *
     * Call immediately after openBy(), when UI5 has measurable popup DOM but may still be opening.
     * The second layout pass uses the updated table DOM before UI5 positions the final popup and arrow.
     *
     * @return string
     */
    protected function buildJsPreparePopupLayout() : string
    {
        $layoutJs = $this->buildJsResizePopupLayout('oTable');

        return <<<JS

                (function() {
                    var oTable = sap.ui.getCore().byId({$this->escapeString($this->getId())});
                    sap.ui.getCore().applyChanges();
                    {$layoutJs}
                    sap.ui.getCore().applyChanges();
                    {$layoutJs}
                    // Unlike _repositionOffset(), these hooks also position an OPENING popover.
                    oPopover._calcPlacement();
                    oPopover._adjustPositionAndArrow();
                })();
JS;
    }

    /**
     * This function checks if the oTable with relevant data is ready.
     * 
     * @param string $oTableJs
     * @return string
     */
    public function buildJsCheckTableIsReady(string $oTableJs) : string
    {
        return <<<JS

            return (function checkTableIsReady(oTable) {
              const oTableRows = oTable.getRows();
              return oTableRows.some(row => !!row.getBindingContext());
            })($oTableJs);
JS;

    }

    /**
     * Takes all the rows and gives the earliest "start" and latest "end" of all rows.
     * If only start or end is missing, then we add / subtracts the default duration to it. 
     * 
     * @param string $oAllRows
     * @return string
     */
    public function buildJsGetGlobalStartEndDates() : string
    {
        $widget = $this->getWidget();
        $calItem = $widget->getTasksConfig();

        if ($calItem->getNestedDataColumn() !== null) {
            $nestedDataColName = $this->escapeString($calItem->getNestedDataColumn()->getDataColumnName());
        } else {
            $nestedDataColName = 'null';
        }

        $defaultDurationHours = $calItem->getDefaultDurationHours(48);
        
        return <<<JS

            return (function getGlobalStartEndDates() {
                let oAllRows = sap.ui.getCore().byId('{$this->getId()}').getModel().getData().rows;
                if (!oAllRows) return;
                let globalStartDate = null;
                let globalEndDate = null;
                let sNestedColName = {$nestedDataColName}
                let defaultDuration = Math.ceil('$defaultDurationHours' / 24);

                function fnCheckRowsDates(oRow) {
                    const startDateValue = oRow['{$calItem->getStartTimeColumn()->getDataColumnName()}'];
                    const endDateValue = oRow['{$calItem->getEndTimeColumn()->getDataColumnName()}'];
                    let startDate = startDateValue ? new Date(startDateValue) : null;
                    let endDate = endDateValue ? new Date(endDateValue) : null;

                    if (startDate !== null && isNaN(startDate.getTime())) {
                      startDate = null;
                    }
                    
                    if (endDate !== null && isNaN(endDate.getTime())) {
                      endDate = null;
                    }
                    
                    if (startDate === null && endDate === null) return;

                    if (startDate === null) {
                        startDate = exfTools.date.add(endDate, -defaultDuration);
                    }
                    if (endDate === null) {
                        endDate = exfTools.date.add(startDate, defaultDuration);
                    }

                    if (!globalStartDate || startDate < globalStartDate) {
                        globalStartDate = startDate;
                    }
                    if (!globalEndDate || endDate > globalEndDate) {
                        globalEndDate = endDate;
                    }
                }

                oAllRows.forEach(function(oRow) {
                  
                     if (sNestedColName !== null) {
                        let oNestedData = oRow[sNestedColName];
                        oNestedData.rows.forEach(function(oNestedRow) {
                            fnCheckRowsDates(oNestedRow);
                        })
                    } else {
                        fnCheckRowsDates(oRow);
                    }
                });
                
                return { start: globalStartDate, end: globalEndDate };
            })();
JS;

    }

    /**
     * It calls the getGlobalStartEndDates function that takes all the rows and gives 
     * the earliest "start" and latest "end" of all rows + default duration.
     * Then it sets the global Gantt view start and end.
     * 
     * The Gantt itself only gets the tasks that are currently visible on the left table.
     * So witch each scroll the set of tasks changes and the Gantt automatically resizes to the new set of tasks. 
     * This causes the "scroll jumps" on each scroll, 
     * so we set a global start and end date for the Gantt view so the Gantt does not resize on each scroll.
     * 
     * @param string $ganttJs
     * @return string
     */
    public function buildJsSetGlobalStartEndDatesToGantt(string $ganttJs) : string
    {
        $controller = $this->getController();
        
        return <<<JS

          return (function setGlobalStartEndDatesToGantt(gantt) {
                let globalStartEndDates = {$controller->buildJsMethodCallFromController(self::CONTROLLER_METHOD_GET_GLOBAL_START_END_DATES, $this, '')};
                if (!!globalStartEndDates) {
                          gantt.options.global_min_view_start = exfTools.date.format( globalStartEndDates.start, 'yyyy-MM-dd', 'en');
                          gantt.options.global_min_view_end = exfTools.date.format( globalStartEndDates.end, 'yyyy-MM-dd', 'en');
                }
          })($ganttJs);
JS;
    }
    
    /**
     * {@inheritdoc}
     * @see UI5DataElementTrait::isEditable()
     */
    public function isEditable()
    {
        return $this->getWidget()->isEditable();
    }
    
    /**
     * {@inheritdoc}
     * @see UI5DataElementTrait::hasPaginator()
     */
    protected function hasPaginator() : bool
    {
        // do not use the hasPaginator implementation form UI5DataTree, instead use the one form UI5DataTable,
        // which uses the implementation from the UI5DataElementTrait right now
        return UI5DataTable::hasPaginator();
    }
    
    public function registerExternalModules(UI5ControllerInterface $controller) : UI5AbstractElement
    {
        $f = $this->getFacade();
        $controller->addExternalModule('libs.moment.moment', $f->buildUrlToSource("LIBS.MOMENT.JS"), null, 'moment');
        
        $controller->addExternalModule('libs.exface.gantt.Gantt', $f->buildUrlToSource("LIBS.RIEL_GANTT.JS"), null, 'Gantt');
        $controller->addExternalCss($f->buildUrlToSource("LIBS.RIEL_GANTT.CSS"));
        // UI5 specific riel-gantt CSS:
        $controller->addExternalCss($f->buildUrlToSource("LIBS.RIEL_GANTT.EXF.CSS"));
        // additional tools for color manipulation and view mode generation
        $controller->addExternalModule('libs.exface.exfColorTools', $f->buildUrlToSource("LIBS.EXFCOLORTOOLS.JS"), null, 'exfColorTools');
        
        return $this;
    }
    
    /**
     * 
     * @param DataCalendarItem $calItem
     * @param string $oRowJs
     * @return string
     */
    protected function buildJsColorResolver(DataCalendarItem $calItem, string $oRowJs) : string
    {
        switch (true) {
            case $colorCol = $calItem->getColorColumn();
                $semanticColors = $this->getFacade()->getSemanticColors();
                $semanticColorsJs = json_encode(empty($semanticColors) ? new \stdClass() : $semanticColors);
                if ($calItem->hasColorScale()) {
                    return <<<JS

                        (function(oRow){
                            var value = {$oRowJs}['{$colorCol->getDataColumnName()}']
                            var sColor = {$this->buildJsScaleResolver('value', $calItem->getColorScale(), $calItem->isColorScaleRangeBased())};
                            var sCssColor = '';
                            var oSemanticColors = $semanticColorsJs;
                            if (sColor.startsWith('~')) {
                                sCssColor = oSemanticColors[sColor] || '';
                            } else if (sColor) {
                                sCssColor = sColor;
                            }
                            return sCssColor;
                        })(oRow)
JS;
                } else {
                    return "oRow['{$colorCol->getDataColumnName()}']";
                }
            case null !== $colorVal = $calItem->getColor():
                return $this->escapeString($colorVal);
        }
        return 'null';
    }
    
    /**
     *
     * @return string
     */
    protected function buildJsDataResetter() : string
    {
        return parent::buildJsDataResetter() . "; sap.ui.getCore().byId('{$this->getId()}').data('_exfStartDate', {$this->escapeString($this->getWidget()->getStartDate())});";
    }
    
    /**
     * 
     * @return string
     */
    protected function buildJsFullscreenContainerGetter() : string
    {
        return $this->isWrappedInDynamicPage() ? "$('#{$this->getId()}').parents('.sapMPanel').first().parent()" : "$('#{$this->getId()}').parents('.sapMPanel').first()";
    }

    /**
     * Uses shared popup fullscreen behavior with additional sizing for horizontal Gantts.
     *
     * {@inheritDoc}
     * @see UI5DataElementTrait::buildJsFullscreenButtonConstructor()
     */
    protected function buildJsFullscreenButtonConstructor(string $oControllerJs = 'oController', string $buttonType = 'Default') : string
    {
        if (! $this->isInHorizontalPopup()) {
            return parent::buildJsFullscreenButtonConstructor($oControllerJs, $buttonType);
        }

        $priorityJs = $this->getDynamicPageShowToolbar() ? '"AlwaysOverflow"' : '"High"';
        $resizeJs = $this->getController()->buildJsMethodCallFromController(
            self::CONTROLLER_METHOD_SYNC_TO_GANTT,
            $this,
            'oTable'
        ) . '; oTable.getParent().triggerResize(true);';
        $captureJs = <<<JS
                            var oPanel = oTable.getParent().getParent();
                            var sPanelHeight = oPanel.getHeight();
JS;
        $restoreJs = <<<JS
                                oPanel.setProperty('height', sPanelHeight, true);
                                oPanel.getDomRef().style.height = sPanelHeight;
JS;
        return $this->getFullscreenPopup()->buildJsFullscreenButtonForContent(
            $this->getId(),
            $buttonType,
            $priorityJs,
            $resizeJs,
            $captureJs,
            $restoreJs
        );
    }
    
    /**
     * Returns a JS snippet, that yields TRUE if the provided JS data row matches the condition group and FALSE otherwise
     * 
     * This is a modified version of `JsConditionalPropertyTrait::buildJsConditionalPropertyIf()`
     * for checking the conditions for each row of an object instead of just the conditions for the object itself.
     * 
     * @see \exface\Core\Facades\AbstractAjaxFacade\Elements\JsConditionalPropertyTrait::buildJsConditionalPropertyIf()
     * 
     * @param ConditionalPropertyConditionGroup $conditionGroup
     * @param string $oRowJs
     * @throws FacadeRuntimeError
     * @return string
     */
    protected function buildJsConditionalPropertyRowCheck(ConditionalPropertyConditionGroup $conditionGroup, string $oRowJs) : string
    {
        $widget = $this->getWidget();
        $jsConditions = [];
        
        // First evaluate the conditions
        // Make sure that the widget link can be entered in the right or in the left expression
        foreach ($conditionGroup->getConditions() as $condition) {
            $leftJs = null;
            $leftExpr = $condition->getValueLeftExpression();
            if ($leftExpr->isReference() === true) {
                $leftLink = $leftExpr->getWidgetLink($widget);
                if ($leftLink->getTargetWidget() === $widget) {
                    $leftJs = "{$oRowJs}['{$leftLink->getTargetColumnId()}']";
                }
            }
            if ($leftJs === null) {
                $leftJs = $this->buildJsConditionalPropertyValue($condition->getValueLeftExpression(), $conditionGroup->getConditionalProperty());
            }
            
            $rightJs = null;
            $rightExpr = $condition->getValueRightExpression();
            if ($rightExpr->isReference() === true) {
                $rightLink = $rightExpr->getWidgetLink($widget);
                if ($rightLink->getTargetWidget() === $widget) {
                    $rightJs = "{$oRowJs}['{$rightLink->getTargetColumnId()}']";
                }
            }
            if ($rightJs === null) {
                $rightJs = $this->buildJsConditionalPropertyValue($condition->getValueRightExpression(), $conditionGroup->getConditionalProperty());
            }
            
            $delim = EXF_LIST_SEPARATOR;
            // Try to get the possibly customized delimiter from the right side of the
            // condition if it is an IN-condition
            if ($condition->getComparator() === ComparatorDataType::IN || $condition->getComparator() === ComparatorDataType::NOT_IN) {
                $rightExpr = $condition->getValueRightExpression();
                if ($rightExpr->isReference() === true) {
                    $targetWidget = $rightExpr->getWidgetLink()->getTargetWidget();
                    if (($targetWidget instanceof iShowSingleAttribute) && $targetWidget->isBoundToAttribute()) {
                        $delim = $targetWidget->getAttribute()->getValueListDelimiter();
                    } elseif ($targetWidget instanceof iHaveColumns && $colName = $rightExpr->getWidgetLink()->getTargetColumnId()) {
                        $targetCol = $targetWidget->getColumnByDataColumnName($colName);
                        if ($targetCol->isBoundToAttribute() === true) {
                            $delim = $targetCol->getAttribute()->getValueListDelimiter();
                        }
                    }
                }
            }
            $jsConditions[] = "exfTools.data.compareValues($leftJs, $rightJs, '{$condition->getComparator()}', '$delim')";
        }
        
        // Then just append condition groups evaluated by a recursive call to this method
        foreach ($conditionGroup->getConditionGroups() as $nestedGrp) {
            $jsConditions[] = '(' . $this->buildJsConditionalPropertyRowCheck($nestedGrp, $oRowJs) . ')';
        }
        
        // Now glue everything together using the logical operator
        switch ($conditionGroup->getOperator()) {
            case EXF_LOGICAL_AND: $op = ' && '; break;
            case EXF_LOGICAL_OR: $op = ' || '; break;
            default:
                throw new FacadeRuntimeError('Unsupported logical operator for conditional property "' . $conditionGroup->getPropertyName() . '" in widget "' . $this->getWidget()->getWidgetType() . ' with id "' . $this->getWidget()->getId() . '"');
        }
        
        // cond1 && cond2 && (grp1cond1 || grp1cond2) && ...
        return implode($op, $jsConditions);
    }

    /**
     * Adds following Gantt buttons as separate group to the toolbar:
     * - scroll buttons "<< today >>>"
     * - row zoom buttons "+ -"
     * - view mode button "Months"
     * 
     * Read more on each button in the respective methods.
     * 
     * @return void
     */
    protected function addGanttButtons() : void
    {
        $widget = $this->getWidget();
        $ganttBtnGroup = $widget->getToolbarMain()->createButtonGroup();
        $aViewModes = $widget->getTimelineConfig()->getViews();
        
        
        $this->addGanttScrollButtons($ganttBtnGroup);
        
        if ($widget->getRowZoom()) {
            $this->addGanttRowsZoomButtons($ganttBtnGroup);
        }
        
        $this->addGanttViewModeButtons($ganttBtnGroup, $aViewModes);
        $this->getWidget()->getToolbarMain()->addButtonGroup($ganttBtnGroup);
    }


    /**
     * Adds gantt view mode selection buttons to the toolbar
     *
     * @param ButtonGroup $btnGrp
     * @param int $index
     * @param array $viewModes
     * @return void
     */
    public function addGanttViewModeButtons(ButtonGroup $btnGrp, array $viewModes) : void 
    {
        if (empty($viewModes)) {
            return;
        }

        $initialViewName = $this->getWidget()->getTimelineConfig()->getInitialViewName();
        $popupViewSyncJs = $this->isInHorizontalPopup()
            ? $this->getController()->buildJsMethodCallFromController(
                self::CONTROLLER_METHOD_SYNC_TO_GANTT,
                $this,
                "sap.ui.getCore().byId('[#element_id:~input#]'), true"
            ) . ';'
            : '';

        $buttons = [];

        foreach ($viewModes as $viewMode) {
            $viewName = $viewMode->getName();
            $viewIcon = $viewMode->getIcon() ?? '';
            
            $buttons[] = [
                'caption' => $viewName,
                'action'  => [
                    'alias'  => 'exface.Core.CustomFacadeScript',
                    'icon' => $viewIcon,
                    'script' => <<<JS

                        (function() {
                          // Setting the caption of the MenuButton:
                          let oControl = sap.ui.getCore().byId('[#element_id:~self#]');
                          // element_id:~parent gives only the group inside the menu, 
                          // so we iterate the parents until the MenuButton is found:
                          while (oControl && !(oControl.isA && oControl.isA('sap.m.MenuButton'))) {
                              oControl = oControl.getParent && oControl.getParent();
                          }
                          
                          if (oControl) {
                              oControl.setText('$viewName');
                          }
                          // Sets the view:
                          sap.ui.getCore().byId('[#element_id:~input#]').gantt.change_view_mode('$viewName');
                          {$popupViewSyncJs}
                      })();
JS
                ],
            ];
        }

        $btnGrp->addButton($btnGrp->createButton(new UxonObject([
            'widget_type' => 'MenuButton',
            'icon' => 'calendar',
            'caption' => $initialViewName,
            'buttons' => $buttons
        ])));
    }

    /**
     * Adds the scroll navigation buttons to the button group at the toolbar:
     * "<<":    navigates to the start of chard
     * "Today": navigates to today
     * ">>":    navigates to the end of the chard
     * 
     * @param ButtonGroup $btnGrp
     * @param int $index
     * @return void
     */
    protected function addGanttScrollButtons(ButtonGroup $btnGrp, int $index = 0) : void 
    {
        $sToday = $this->getWorkbench()->getCoreApp()->getTranslator()->translate('LOCALIZATION.DATE.TODAY');
        
        $buttons = [
            [
                'caption' => '',     
                'icon' => 'angle-double-left',
                'script' => <<<JS
                        sap.ui.getCore().byId('[#element_id:~input#]').gantt.set_scroll_position("start");
JS
            ],
            [
                'caption' => $sToday,
                'icon' => '',
                'script' => <<<JS
                        sap.ui.getCore().byId('[#element_id:~input#]').gantt.scroll_current(true, true);
JS
            ],
            [
                'caption' => '',     
                'icon' => 'angle-double-right',
                'script' => <<<JS
                        sap.ui.getCore().byId('[#element_id:~input#]').gantt.set_scroll_position("end");
JS
            ],
        ];

        $this->createButtonsFromButtonArrayAndAddToButtonGroup($buttons, $btnGrp, $index);
    }

    /**
     * Adds the rows zoom buttons to the button group at the toolbar:
     * "+": Increases the Gantt row height and the number of lanes per row.
     *      For horizontal Gantts, it also adjusts the table row height to match.
     * 
     * "-": Decreases the Gantt row height and the number of lanes per row.
     *      For horizontal Gantts, it also adjusts the table row height to match.
     * 
     * Height Calculation formula: rowHeight = (baseRowHeight / minRowLanes) * newRowLanesCount;
     * 
     * @param ButtonGroup $btnGrp
     * @param int $index
     * @return void
     */
    protected function addGanttRowsZoomButtons(ButtonGroup $btnGrp, int $index = 0) : void
    {
        $widget = $this->getWidget();
        if ($widget->getOrientation() === Gantt::ORIENTATION_VERTICAL && $widget->getHeightInRows() !== null) {
            $resizeVerticalLayoutJs = $this->getController()->buildJsMethodCallFromController(
                self::CONTROLLER_METHOD_RESIZE_VERTICAL_LAYOUT,
                $this,
                'oTable'
            ) . ';';
        } else {
            $resizeVerticalLayoutJs = '';
        }
        if ($widget->getOrientation() === Gantt::ORIENTATION_HORIZONTAL) {
            $setTableRowHeightJs = 'oTable.setRowHeight(Math.floor(rowHeight - 1));';
        } else {
            $setTableRowHeightJs = '';
        }
        
        $buttons = [
            [
                'caption' => '',
                'icon' => 'plus',
                'script' => <<<JS

                    let oGantt = sap.ui.getCore().byId('{$this->getId()}').gantt;
                    if (oGantt === undefined) return;
                    let oTable = sap.ui.getCore().getElementById('{$this->getId()}')
                    let newLanesPerRowNumber = oGantt.options.row_lanes + 1;
                  
                    // TODO SR: create Uxon properties "baseRowHeight" and "minRowLanes" (minRowLanes can not be lower than 2). 
                    //  Use this values to set the row_lanes and row_height at the Gantt init and here:
                    const baseRowHeight = 33; // TODO: Use this value as default for the baseRowHeight property!
                    const minRowLanes = 2; // TODO: Use this value as default for the minRowLanes property!
                    
                    let rowHeight = (baseRowHeight / minRowLanes) * newLanesPerRowNumber;
                    
                    {$setTableRowHeightJs}
                    
                    // Setting the height to the Gantt (right)
                    oGantt.options.row_lanes = newLanesPerRowNumber;
                    oGantt.options.row_height = Math.floor(rowHeight);
                    
                    setTimeout(function(){
                               {$this->getController()->buildJsMethodCallFromController(self::CONTROLLER_METHOD_SYNC_TO_GANTT, $this, 'oTable')};
                               {$resizeVerticalLayoutJs}
                    },100);
                
JS
            ],
            [
                'caption' => '',
                'icon' => 'minus',
                'script' => <<<JS

                    let oGantt = sap.ui.getCore().byId('{$this->getId()}').gantt;
                    if (oGantt === undefined) return;
                    let oTable = sap.ui.getCore().getElementById('{$this->getId()}')
                    let newLanesPerRowNumber = oGantt.options.row_lanes - 1;
                    if (newLanesPerRowNumber < 2) return;
  
                    // TODO SR: create Uxon properties "baseRowHeight" and "minRowLanes" (minRowLanes can not be lower than 2). 
                    //  Use this values to set the row_lanes and row_height at the Gantt init and here:
                    const baseRowHeight = 33; // TODO: Use this value as default for the baseRowHeight property!
                    const minRowLanes = 2; // TODO: Use this value as default for the minRowLanes property!
                    
                    let rowHeight = (baseRowHeight / minRowLanes) * newLanesPerRowNumber;
                  
                    {$setTableRowHeightJs}
                    
                    // Setting the height to the Gantt (right)
                    oGantt.options.row_lanes = newLanesPerRowNumber;
                    oGantt.options.row_height = Math.floor(rowHeight);
                    
                    setTimeout(function(){
                               {$this->getController()->buildJsMethodCallFromController(self::CONTROLLER_METHOD_SYNC_TO_GANTT, $this, 'oTable')};
                               {$resizeVerticalLayoutJs}
                    },100);
                    
JS
            ],
        ];
        
        $this->createButtonsFromButtonArrayAndAddToButtonGroup($buttons, $btnGrp, $index);
    }

    /**
     * Creates buttons from the given string array and adds it to the given ButtonGroup ant the given index.
     * 
     * @param array $buttons
     * @param ButtonGroup $btnGrp
     * @param int $index
     * @return void
     */
    protected function createButtonsFromButtonArrayAndAddToButtonGroup(array $buttons, ButtonGroup $btnGrp, int $index = 0): void
    {
        
        foreach ($buttons as $i => $button) {
            $btnGrp->addButton($btnGrp->createButton(new UxonObject([
                'widget_type' => 'Button',
                'caption' => $button['caption'],
                'icon' => $button['icon'],
                'action'  => [
                    'alias'  => 'exface.Core.CustomFacadeScript',
                    'icon' => '',
                    'script' => $button['script'],
                ],
            ])), $index + $i);
            
        }
    }
    
    protected function convertDataTimelineGranularityToGanttStep($granularity) : string 
    {
        return match ($granularity) {
            DataTimeline::GRANULARITY_HOURS => '1h',
            DataTimeline::GRANULARITY_QUARTER_DAYS => '6h',
            DataTimeline::GRANULARITY_HALF_DAYS => '12h',
            DataTimeline::GRANULARITY_DAYS, 
            DataTimeline::GRANULARITY_DAYS_PER_WEEK, 
            DataTimeline::GRANULARITY_DAYS_PER_MONTH => '1d',
            DataTimeline::GRANULARITY_WEEKS => '7d',
            DataTimeline::GRANULARITY_MONTHS => '1m',
            DataTimeline::GRANULARITY_YEARS => '1y',
            default => throw new InvalidArgumentException('The Gantt chart only supports the following granularities for the view modes: "hours", "quarter_days", "half_days", "days", "weeks", "months" and "years".'),
        };
    }
    
    protected function convertDataTimelineSnapToGanttSnap($snapString)
    {
        return match ($snapString) {
            DataTimelineView::SNAP_AT_DAILY => '1d',
            DataTimelineView::SNAP_AT_WEEKLY => '7d',
            DataTimelineView::SNAP_AT_MONTHLY => '30d', // "30d" is the original value of the gantt library
            null => null,
            default => throw new InvalidArgumentException('The Gantt chart only supports the following snap strings: "daily", "weekly" and "monthly".'),
        };
    }
    
    protected function convertDataTimeLineIntervalToGanttInterval($value) : string
    {
        return match ($value) {
            DataTimeline::INTERVAL_DAY => 'Date',
            DataTimeline::INTERVAL_MONTH => 'Month',
            DataTimeline::INTERVAL_YEAR => 'Year',
            DataTimeline::INTERVAL_DECADE => 'Decade',
            default => throw new InvalidArgumentException('The Gantt chard only supports the following intervals for the header lines: "day", "month", "year" and "decade".'),
        };
    }
    
    /**
     * It maps uxon DataTimelineView views to a simplified array structure, 
     * that the riel-gantt uses to render the gantt chart.
     * 
     * Example output:
     * ```
     * Week: {
     *      padding: '1m',
     *      step: '7d',
     *      date_format: 'YYYY-MM-dd',
     *      column_width: 140,
     *      upper_text_frequency: 4,
     *      
     *      header: {
     *          upper: {
     *              interval: 'Month',
     *              date_format: '',
     *              date_format_at_border: 'MMMM',
     *          },
     *
     *      lower: {
     *          interval: null,
     *          date_format: '~weekRange',
     *          },
     *      },
     *
     *      thick_line: {
     *          interval: 'month_range_in_days',
     *          from: 1,
     *          to: 7
     *      },
     * },
     * ```
     * 
     * @return array
     */
    protected function getViewModesConfig(): array
    {
        $widget = $this->getWidget();
        $viewModes = $widget->getTimelineConfig()->getViews();
        $simple_view_modes = [];
        
        foreach ($viewModes as $viewMode) {
            $simple_view_mode = [];
            $name = $viewMode->getName();
            $headerLines = $viewMode->getHeaderLines() ?? [];
            $thickLines = $viewMode->getThickLines() ?? [];

            if (null !== $val = $viewMode->getName()) {
                $simple_view_mode['name'] = $val;
            }

            if (null !== $val = $this->convertDataTimelineGranularityToGanttStep($viewMode->getGranularity())) {
                $simple_view_mode['step'] = $val;
            }

            if (null !== $val = $viewMode->getDateFormat()) {
                $simple_view_mode['date_format'] = $val;
            }

            if (null !== $dim = $viewMode->getColumnWidth()) {
                if (!is_numeric($dim->getValue())) {
                    throw new FacadeRuntimeError('Only numbers are supported in column_width for Gantt timeline views');
                }
                $simple_view_mode['column_width'] = (int) $dim->getValue();
            }

            if (null !== $val = $viewMode->getPadding()) {
                $simple_view_mode['padding'] = $val;
            }

            if (null !== $val = $this->convertDataTimelineSnapToGanttSnap($viewMode->getSnapAt())) {
                $simple_view_mode['snap_at'] = $val;
            }

            if (null !== $val = $viewMode->getUpperTextFrequency()) {
                if (!is_numeric($val)) {
                    throw new FacadeRuntimeError('Only numbers are supported in column_width for Gantt timeline views');
                }
                $simple_view_mode['upper_text_frequency'] = (int) $val;
            }
            
            if (null !== $val = $viewMode->getTodayButtonLeftScrollPadding()) {
                $simple_view_mode['today_button_left_scroll_padding'] = $val;
            }

            // Gantt only supports 2 header lines, so we just take the first 2.
            /** @var DataTimelineHeader|null $upper */
            $upper = $headerLines[0] ?? null;
            /** @var DataTimelineHeader|null $lower */
            $lower = $headerLines[1] ?? null;
            /** @var DataTimelineThicklines|null $thickLine */
            $thickLine = $thickLines[0] ?? null;

            if ($upper !== null) {
                if (null !== $val = $upper->getDateFormat()) {
                    $simple_view_mode['header']['upper']['date_format'] = $val;
                }

                if (null !== $val = $upper->getDateFormatAtBorder()) {
                    $simple_view_mode['header']['upper']['date_format_at_border'] = $val;
                }

                if (null !== $val = $upper->getInterval()) {
                    $simple_view_mode['header']['upper']['interval'] = $this->convertDataTimeLineIntervalToGanttInterval($val);
                }
            }

            if ($lower !== null) {
                if (null !== $val = $lower->getDateFormat()) {
                    $simple_view_mode['header']['lower']['date_format'] = $val;
                }

                if (null !== $val = $lower->getDateFormatAtBorder()) {
                    $simple_view_mode['header']['lower']['date_format_at_border'] = $val;
                }

                if (null !== $val = $lower->getInterval()) {
                    $simple_view_mode['header']['lower']['interval'] = $this->convertDataTimeLineIntervalToGanttInterval($val);
                }
            }
            
            if ($thickLine !== null) {
                if (null !== $val = $thickLine->getColor()) {
                    $simple_view_mode['thick_line_color'] = $val;
                }

                if (null !== $val = $thickLine->getFrom()) {
                    $simple_view_mode['thick_line']['from'] = $val;
                }

                if (null !== $val = $thickLine->getTo()) {
                    $simple_view_mode['thick_line']['to'] = $val;
                }

                if (null !== $val = $thickLine->getValue()) {
                    $simple_view_mode['thick_line']['value'] = $val;
                }

                if (null !== $val = $thickLine->getInterval()) {
                    $simple_view_mode['thick_line']['interval'] = $val;
                }
            }
            
            $baseViewMode = $this->getViewModeDefault($viewMode->getGranularity());
            $simple_view_mode = array_replace_recursive($baseViewMode, $simple_view_mode);
            
            if (str_starts_with($simple_view_mode['name'],'TRANSLATE:'))
            {
                $translator = $this->getWorkbench()->getCoreApp()->getTranslator();
                $untranslatedName = substr(
                    $simple_view_mode['name'],
                    strlen('TRANSLATE:')
                );
                $simple_view_mode['name'] = $translator->translate($untranslatedName);
            }
            
            $simple_view_modes[$name] = $simple_view_mode;
        }
        return $simple_view_modes;
    }

    /**
     * Returns an array with default values for each granularity
     * 
     * @param string $granularity
     * @return array
     */
    protected function getViewModeDefault(string $granularity) : array
    {
        $default = $this->viewModeDefaults[$granularity] ?? null;
        if ($default === null) {
            // If no specific defaults for the granularity are defined,
            // we take the defaults of the 'days' granularity,
            // as it is the most common one and also the default granularity for the gantt chart.
            return $this->viewModeDefaults['days'];
        }
        return $default;
    }

    /**
     * Builds the external popup request handler for task and aggregation bars.
     *
     * Reuses a UI5 popover, fills it with overlapping upper-lane task data and
     * opens it at the pointer position without initializing a native Gantt popup.
     * Requests without pointer coordinates remain anchored to the requested bar.
     * 
     * @param string $oControllerJs
     * @return string
     */
    protected function buildJsRenderPopup(string $oControllerJs) : string
    {
        if ($this->getWidget()->getHidePopup()) {
            return 'null';
        }

        $popover = $this->getWidget()->getTasksConfig()->getPopup();
        $popoverEl = $this->getFacade()->getElement($popover);
        $dataWidget = $popover->getWidgetFirst();
        $dataEl = $this->getFacade()->getElement($dataWidget);
        $controller = $this->getController();
        $hoverEnabled = $popover->getShowOn() === Popup::SHOW_ON_HOVER;
        $hoverHandlersJs = $hoverEnabled ? $this->buildJsPopupHoverHandlers() : '';
        // Create the popup during controller initialization so its table exists before prefill.
        if (! $controller->hasDependent('editorPopup', $this)) {
            $controller->addDependentControl('editorPopup', $this, $popoverEl);
            if ($hoverEnabled) {
                $controller->addOnHideViewScript(<<<JS

                    (function() {
                        var oController = {$controller->buildJsControllerGetter($this)};
                        var oPopover = {$controller->buildJsDependentControlSelector('editorPopup', $this, 'oController')};
                        oPopover.data('pendingGanttPopupOpen', null);
                        oPopover.close();
                    })();
JS);
            }
        }
        $initialFocusJs = '';
        $preparePopupJs = $dataEl instanceof self && $dataEl->isInHorizontalPopup()
            ? $dataEl->buildJsPreparePopupLayout()
            : '';
        if ($dataEl instanceof UI5DataTable && $dataEl->isMList()) {
            $initialFocusJs = <<<JS

                var oTable = sap.ui.getCore().byId({$this->escapeString($dataEl->getId())});
                oTable.removeSelections(true);
                if (!oTask._isAggregate && !oTask._aggMembers?.length) {
                    var iTaskRow = aAllTasks.findIndex(function(oCandidate) {
                        return oCandidate.id === oTask.id;
                    });
                    var oTaskItem = oTable.getItems().find(function(oItem) {
                        var oContext = oItem.getBindingContext();
                        return oContext && oContext.getPath() === '/rows/' + iTaskRow;
                    });
                    if (oTaskItem) {
                        oTable.setSelectedItem(oTaskItem, true);
                        oTable.fireSelectionChange({
                            listItem: oTaskItem,
                            selected: true
                        });
                        oPopover.setInitialFocus(oTaskItem);
                    }
                }
JS;
        } elseif ($dataEl instanceof UI5DataTable && $dataEl->isUiTable()) {
            $initialFocusJs = <<<JS

                var oTable = sap.ui.getCore().byId({$this->escapeString($dataEl->getId())});
                oTable.clearSelection();
                if (!oTask._isAggregate && !oTask._aggMembers?.length) {
                    var iTaskRow = aAllTasks.findIndex(function(oCandidate) {
                        return oCandidate.id === oTask.id;
                    });
                    if (iTaskRow !== -1) {
                        //oPopover.setInitialFocus(oTable);
                        // Opening the inner Gantt resets its scroll position beforeOpen.
                        oPopover.attachEventOnce('afterOpen', function() {
                            {$dataEl->buildJsSelectRowByIndex('oTable', 'iTaskRow')}
                        });
                    }
                }
JS;
        }
        return <<<JS

        (ctx) => {
            var oPopover = {$controller->buildJsDependentControlSelector('editorPopup', $this, $oControllerJs)}.addStyleClass("exfGanttPopup");
            var domBar = ctx.target && (ctx.target.closest('.bar-wrapper') || ctx.target);
            if ({$this->escapeBool($hoverEnabled)} && oPopover.data('ganttPopupBar') === domBar) {
                return;
            }
            var oTask = ctx.task;
            var oPrefillData = {rows: []};
            var aAllTasks = ctx.chart.get_overlapping_tasks(oTask);

            for (var oSelectedTask of aAllTasks) {
                oPrefillData.rows.push(oSelectedTask.dataRow);
            }
            var fnOpenPopup = function() {
                // A queued hover request may outlive the pointer's stay on its bar.
                if ({$this->escapeBool($hoverEnabled)} && ctx.trigger === 'hover' && !domBar.matches(':hover')) {
                    return;
                }
                var domAnchor = ctx.target;
                if (Number.isFinite(ctx.x) && Number.isFinite(ctx.y)) {
                    // Convert viewport coordinates to SVG coordinates so the anchor follows Gantt scrolling.
                    var domSvg = ctx.chart.\$svg;
                    var oPointer = new DOMPoint(ctx.x, ctx.y).matrixTransform(domSvg.getScreenCTM().inverse());
                    domAnchor = document.createElementNS(domSvg.namespaceURI, 'rect');
                    domAnchor.setAttribute('x', oPointer.x);
                    domAnchor.setAttribute('y', oPointer.y);
                    domAnchor.setAttribute('width', '1');
                    domAnchor.setAttribute('height', '1');
                    domAnchor.setAttribute('fill', 'none');
                    domAnchor.setAttribute('pointer-events', 'none');
                    domAnchor.setAttribute('aria-hidden', 'true');
                    domAnchor.setAttribute('focusable', 'false');
                    domSvg.appendChild(domAnchor);
                    var oPopupAnchor = {node: domAnchor};
                    oPopover.data('ganttPopupAnchor', oPopupAnchor);
                    oPopover.attachEventOnce('afterClose', function() {
                        domAnchor.remove();
                        oPopupAnchor.fullscreenSvg?.remove();
                        oPopover.data('ganttPopupAnchor', null);
                    });
                }
                oPopover.setModel(new sap.ui.model.json.JSONModel());
                {$dataEl->buildJsDataSetter("oPrefillData")}
                // Keep aggregate popups from automatically focusing the first row.
                oPopover.setInitialFocus(oPopover);
                {$initialFocusJs}
                {$hoverHandlersJs}
                oPopover.openBy(domAnchor);
                {$preparePopupJs}
            };
            if (oPopover.isOpen()) {
                if (!oPopover.data('pendingGanttPopupOpen')) {
                    oPopover.attachEventOnce('afterClose', function() {
                        var fnOpen = oPopover.data('pendingGanttPopupOpen');
                        oPopover.data('pendingGanttPopupOpen', null);
                        if (fnOpen) {
                            fnOpen();
                        }
                    });
                }
                // Fill the next popup only after the previous close/reset has finished.
                oPopover.data('pendingGanttPopupOpen', fnOpenPopup);
                oPopover.close();
            } else {
                fnOpenPopup();
            }
        }
JS;
    }

    /**
     * Builds hover tracking for the current bar and popover, removed before closing.
     *
     * A short close delay lets the pointer cross the gap between bar and popup.
     * UI5 menus and other child overlays belong to the popup through their control parents,
     * even when rendered separately in the static area. Track their pointer and close events too.
     * Captures domBar and oPopover from the external popup request handler in an isolated IIFE.
     *
     * @return string
     */
    protected function buildJsPopupHoverHandlers() : string
    {
        return <<<JS

                (function(domBar, oPopover) {
                    var iCloseTimer = null;
                    var domPopup = null;
                    var domPointerTarget = null;
                    var aChildOverlays = [];
                    var fnCancelClose = function() {
                        clearTimeout(iCloseTimer);
                        iCloseTimer = null;
                    };
                    var fnIsPopupTarget = function(domTarget) {
                        if (domTarget?.nodeType !== 1 || !domTarget.isConnected || !domTarget.getClientRects().length) {
                            return false;
                        }
                        var oControl = sap.ui.core.Element.closestTo(domTarget);
                        var oChildOverlay = null;
                        while (oControl) {
                            if (oControl === oPopover) {
                                if (oChildOverlay && !aChildOverlays.includes(oChildOverlay)) {
                                    oChildOverlay.attachAfterClose(fnScheduleClose);
                                    aChildOverlays.push(oChildOverlay);
                                }
                                return true;
                            }
                            if (!oChildOverlay && oControl.attachAfterClose && oControl.detachAfterClose) {
                                oChildOverlay = oControl;
                            }
                            oControl = oControl.getParent();
                        }
                        return false;
                    };
                    var fnScheduleClose = function() {
                        fnCancelClose();
                        iCloseTimer = setTimeout(function() {
                            iCloseTimer = null;
                            var domCurrentPopup = oPopover.getDomRef();
                            if (!domCurrentPopup?.classList?.contains('exfPopupFullscreen') && !domBar.matches(':hover') && !(domCurrentPopup && domCurrentPopup.matches(':hover')) && !fnIsPopupTarget(domPointerTarget)) {
                                oPopover.close();
                            }
                        }, 250);
                    };
                    var fnTrackPointer = function(oEvent) {
                        domPointerTarget = oEvent.type === 'mouseout' ? oEvent.relatedTarget : oEvent.target;
                        if (fnIsPopupTarget(domPointerTarget) || domBar.matches(':hover')) {
                            fnCancelClose();
                        } else if (iCloseTimer === null) {
                            fnScheduleClose();
                        }
                    };
                    var fnAfterOpen = function() {
                        domPopup = oPopover.getDomRef();
                        domPopup.addEventListener('mouseenter', fnCancelClose);
                        domPopup.addEventListener('mouseleave', fnScheduleClose);
                        if (domBar.matches(':hover') || domPopup.matches(':hover')) {
                            fnCancelClose();
                        } else {
                            fnScheduleClose();
                        }
                    };
                    var fnCleanup = function() {
                        fnCancelClose();
                        document.removeEventListener('mouseover', fnTrackPointer, true);
                        document.removeEventListener('mouseout', fnTrackPointer, true);
                        aChildOverlays.forEach(function(oOverlay) {
                            oOverlay.detachAfterClose(fnScheduleClose);
                        });
                        aChildOverlays = [];
                        domPointerTarget = null;
                        domBar.removeEventListener('mouseenter', fnCancelClose);
                        domBar.removeEventListener('mouseleave', fnScheduleClose);
                        if (domPopup) {
                            domPopup.removeEventListener('mouseenter', fnCancelClose);
                            domPopup.removeEventListener('mouseleave', fnScheduleClose);
                        }
                        oPopover.detachAfterOpen(fnAfterOpen);
                        oPopover.data('ganttPopupBar', null);
                    };
                    domBar.addEventListener('mouseenter', fnCancelClose);
                    domBar.addEventListener('mouseleave', fnScheduleClose);
                    document.addEventListener('mouseover', fnTrackPointer, true);
                    document.addEventListener('mouseout', fnTrackPointer, true);
                    oPopover.data('ganttPopupBar', domBar);
                    oPopover.attachEventOnce('afterOpen', fnAfterOpen);
                    oPopover.attachEventOnce('beforeClose', fnCleanup);
                })(domBar, oPopover);
JS;
    }
}