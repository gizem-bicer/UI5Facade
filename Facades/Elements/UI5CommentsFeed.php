<?php
namespace exface\UI5Facade\Facades\Elements;

use exface\Core\Facades\AbstractAjaxFacade\Elements\JqueryCommentsTrait;
use exface\Core\Interfaces\Actions\ActionInterface;
use exface\Core\Interfaces\Actions\iReadData;
use exface\Core\Widgets\DataButton;
use exface\UI5Facade\Facades\Elements\Traits\UI5DataElementTrait;
use exface\UI5Facade\Facades\Interfaces\UI5ControllerInterface;
use exface\UI5Facade\Facades\Interfaces\UI5DataElementInterface;

/**
 * Renders a CommentsFeed with the local jQuery Comments library inside a lifecycle-managed UI5 HTML control.
 *
 * @method \exface\Core\Widgets\CommentsFeed getWidget()
 * @method UI5ControllerInterface getController()
 */
class UI5CommentsFeed extends UI5AbstractElement implements UI5DataElementInterface
{
    use JqueryCommentsTrait;
    use UI5DataElementTrait {
        init as initDataElement;
    }

    private $linkedFilterHandlersRegistered = false;

    private $linkedColumnValuesJs;

    /**
     * Enables the standard linked-filter refresh before UI5 registers configurator change handlers.
     *
     * @return void
     */
    protected function init()
    {
        $this->initDataElement();
        foreach ($this->getWidget()->getFilters() as $filter) {
            if ($filter->getValueWidgetLink() !== null) {
                $filter->setApplyOnChange(true);
            }
        }
    }

    /**
     * Builds a dimensioned HTML control with isolated typography and teardown because UI5 replaces its content DOM.
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\Traits\UI5DataElementTrait::buildJsConstructorForControl()
     */
    protected function buildJsConstructorForControl($oControllerJs = 'oController') : string
    {
        $this->getConfiguratorElement()->registerFiltersWithApplyOnChange($this);
        $this->registerLinkedFilterStateHandlers();
        $this->registerCommentsFeedButtonHandlers();

        /** @var UI5Button $createButton */
        $createButton = $this->getFacade()->getElement($this->getWidget()->getButtonCreate());
        /** @var UI5Button $editButton */
        $editButton = $this->getFacade()->getElement($this->getWidget()->getButtonEdit());
        /** @var UI5Button $deleteButton */
        $deleteButton = $this->getFacade()->getElement($this->getWidget()->getButtonDelete());

        $createInvoker = $this->buildJsWriteActionInvoker(
            "{$oControllerJs}.{$createButton->buildJsClickFunctionName()}",
            $createButton->getWidget()->getId()
        );
        $editInvoker = $this->buildJsWriteActionInvoker(
            "{$oControllerJs}.{$editButton->buildJsClickFunctionName()}",
            $editButton->getWidget()->getId(),
            true
        );
        $deleteInvoker = $this->buildJsWriteActionInvoker(
            "{$oControllerJs}.{$deleteButton->buildJsClickFunctionName()}",
            $deleteButton->getWidget()->getId()
        );
        $cleanupJs = $this->buildJsCommentsCleanup('jqCommentsFeed');
        $updateReadOnlyJs = $this->buildJsUpdateReadOnlyState();
        $eventNamespace = preg_replace('/[^A-Za-z0-9_]/', '_', $this->getId());
        $windowEventNamespace = $this->escapeString('.comments_' . $eventNamespace);
        $width = $this->getWidth();
        $height = $this->getHeight();
        $styleId = $this->escapeString($this->getId() . '-layout-style');
        $scrollContainerCss = $this->escapeString(
            '#' . $this->getId()
            . '.jquery-comments > .data-container{flex:1 1 auto;min-height:0;overflow-y:auto;}'
        );

        return <<<JS

        (function() {
            var oCommentsStyle = document.getElementById({$styleId});
            if (! oCommentsStyle) {
                oCommentsStyle = document.createElement('style');
                oCommentsStyle.id = {$styleId};
                oCommentsStyle.textContent = {$scrollContainerCss};
                document.head.appendChild(oCommentsStyle);
            }
            var oCommentsHtml = new sap.ui.core.HTML("{$this->getId()}", {
                content: "<div id=\"{$this->getId()}\" class=\"exf-commentsfeed exf-data-widget\" style=\"box-sizing:border-box;display:flex;flex-direction:column;width:{$width};max-width:100%;height:{$height};min-height:6rem;overflow:hidden;font-size:var(--sapFontSize,0.875rem);line-height:var(--sapContent_LineHeight,1.5);\"></div>"
            });
            var fnCommentsCleanup = function() {
                var jqCommentsFeed = $('#{$this->getId()}');
                {$cleanupJs}
            };
            var fnCommentsInit = function() {
                var jqCommentsFeed = $('#{$this->getId()}');
                if (jqCommentsFeed.length === 0
                    || jqCommentsFeed.data('comments')
                    || jqCommentsFeed.data('_exfCommentsInitializing')) {
                    {$updateReadOnlyJs}
                    return;
                }
                jqCommentsFeed.data('_exfCommentsInitializing', true);
                {$this->buildJsJqueryCommentsInit($createInvoker, $editInvoker, $deleteInvoker)}
                $(window)
                    .off({$windowEventNamespace})
                    .on('online' + {$windowEventNamespace} + ' offline' + {$windowEventNamespace}, function() {
                        {$updateReadOnlyJs}
                    });
                setTimeout(function() {
                    jqCommentsFeed.removeData('_exfCommentsInitializing');
                    {$updateReadOnlyJs}
                }, 0);
            };
            var fnHtmlExit = oCommentsHtml.exit;
            oCommentsHtml.addDependent({$createButton->buildJsConstructor($oControllerJs)});
            oCommentsHtml.addDependent({$editButton->buildJsConstructor($oControllerJs)});
            oCommentsHtml.addDependent({$deleteButton->buildJsConstructor($oControllerJs)});
            oCommentsHtml.addEventDelegate({
                onBeforeRendering: fnCommentsCleanup,
                onAfterRendering: fnCommentsInit
            });
            oCommentsHtml.exit = function() {
                fnCommentsCleanup();
                if (oCommentsStyle.parentNode) {
                    oCommentsStyle.parentNode.removeChild(oCommentsStyle);
                }
                if (typeof fnHtmlExit === 'function') {
                    return fnHtmlExit.apply(this, arguments);
                }
            };
            return oCommentsHtml;
        })()

JS;
    }

    /**
     * Registers local jquery-comments assets so CSP and component preloading never require a CDN.
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::registerExternalModules()
     */
    public function registerExternalModules(UI5ControllerInterface $controller) : UI5AbstractElement
    {
        $facade = $this->getFacade();
        $controller->addExternalModule(
            'libs.exface.jquery_comments',
            $facade->buildUrlToSource('LIBS.JQUERY_COMMENTS.JS')
        );
        $controller->addExternalCss(
            $facade->buildUrlToSource('LIBS.JQUERY_COMMENTS.CSS'),
            'exf-jquery-comments-css'
        );
        return $this;
    }

    /**
     * Prevents the generic UI5 autoloader from racing jquery-comments, which owns the feed loading lifecycle.
     *
     * @param string $oControlEventJsVar
     * @param string $keepPagePosJsVar
     * @param bool $showWarning
     * @return string
     */
    protected function buildJsDataLoader(
        $oControlEventJsVar = 'oControlEvent',
        $keepPagePosJsVar = 'bKeepPagingPos',
        $showWarning = true
    ) {
        return "return Promise.resolve(sap.ui.getCore().byId('{$this->getId()}').getModel());";
    }

    /**
     * Loads comments through the configured UI5 server adapter and always settles the plugin callback.
     *
     * @param string $oParamsJs
     * @param string $successJs
     * @param string $errorJs
     * @return string
     */
    public function buildJsDataLoadFunctionBody(
        string $oParamsJs = 'oParams',
        string $successJs = 'success',
        string $errorJs = 'error'
    ) : string {
        $widget = $this->getWidget();
        $actionAlias = $this->escapeString($widget->getLazyLoadingActionAlias());
        $pageAlias = $this->escapeString($widget->getPage()->getAliasWithNamespace());
        $widgetId = $this->escapeString($widget->getId());
        $objectId = $this->escapeString($widget->getMetaObject()->getId());
        $commentEditedColumn = $this->escapeString(
            $widget->getCommentEditedDateColumn()->getDataColumnName()
        );
        $configuratorParamsJs = $this->getP13nElement()->buildJsDataLoaderParams('params');
        $onLoadedJs = <<<JS

                    var aRows = oModel.getProperty('/rows') || [];
                    aRows.forEach(function(oRow) {
                        oRow._modified = oRow[{$commentEditedColumn}];
                    });
                    jqCommentsFeed.data('_exfData', {
                        oId: {$objectId},
                        rows: aRows
                    });
                    finishSuccess(aRows);
JS;
        $onErrorJs = 'finishError.apply(null, arguments);';

        return <<<JS

                var jqCommentsFeed = $('#{$this->getId()}');
                var oControl = sap.ui.getCore().byId('{$this->getId()}');
                var oModel = oControl.getModel();
                var oData = oModel.getData();
                var bFinished = false;
                var finishSuccess = function(aRows) {
                    if (bFinished) {
                        return;
                    }
                    bFinished = true;
                    jqCommentsFeed.data('_loading', 0);
                    {$successJs}(aRows || []);
                };
                var finishError = function() {
                    if (bFinished) {
                        return;
                    }
                    bFinished = true;
                    jqCommentsFeed.data('_loading', 0);
                    if (typeof {$errorJs} === 'function') {
                        {$errorJs}.apply(null, arguments);
                    }
                };

                if (jqCommentsFeed.data('_loading')) {
                    finishSuccess([]);
                    return;
                }
                if (! ({$this->buildJsCheckRequiredFilters()})) {
                    oModel.setData({rows: []});
                    jqCommentsFeed.data('_exfData', {
                        oId: {$objectId},
                        rows: []
                    });
                    finishSuccess([]);
                    return;
                }

                jqCommentsFeed.data('_loading', 1);
                try {
                    var aSortItems = [];
                    var params = {
                        action: {$actionAlias},
                        resource: {$pageAlias},
                        element: {$widgetId},
                        object: {$objectId}
                    };
                    {$configuratorParamsJs}
                    params = $.extend(params, ({$oParamsJs} || {}));
                    {$this->getServerAdapter()->buildJsServerRequest(
                        $widget->getLazyLoadingAction(),
                        'oModel',
                        'params',
                        $onLoadedJs,
                        $onErrorJs,
                        $onErrorJs
                    )}
                } catch (err) {
                    console.error(err);
                    finishError(err);
                }

JS;
    }

    /**
     * Supplies CRUD actions from the plugin row while adding hidden linked values such as the parent ticket.
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\Traits\UI5DataElementTrait::buildJsDataGetter()
     */
    public function buildJsDataGetter(ActionInterface $action = null)
    {
        $widget = $this->getWidget();
        $objectId = $this->escapeString($widget->getMetaObject()->getId());
        if ($action !== null
            && $action->isDefinedInWidget()
            && $action->getWidgetDefinedIn() instanceof DataButton) {
            $customMode = $action->getWidgetDefinedIn()->getInputRows();
        } else {
            $customMode = null;
        }

        switch (true) {
            case $customMode === DataButton::INPUT_ROWS_ALL:
            case $action === null:
                return "($('#{$this->getId()}').data('_exfData') || {oId: {$objectId}, rows: []})";
            case $customMode === DataButton::INPUT_ROWS_NONE:
                return '{}';
            case $action instanceof iReadData:
                return $this->getConfiguratorElement()->buildJsDataGetter($action);
            default:
                $linkedColumnValuesJs = $this->getJsLinkedColumnValues();
                return <<<JS

                (function(jqCommentsFeed) {
                    var oInputRow = jqCommentsFeed.data('_exfInput') || {};
                    var oInputModel = new sap.ui.model.json.JSONModel({
                        rows: [oInputRow]
                    });
                    {$linkedColumnValuesJs}
                    return {
                        oId: {$objectId},
                        rows: oInputModel.getData().rows
                    };
                })($('#{$this->getId()}'))
JS;
        }
    }

    /**
     * Caches linked-column JavaScript so generating three CRUD buttons does not register duplicate change handlers.
     *
     * @return string
     */
    protected function getJsLinkedColumnValues() : string
    {
        if ($this->linkedColumnValuesJs === null) {
            $this->linkedColumnValuesJs = $this->buildJsDataLoaderOnLoadedHandleWidgetLinks('oInputModel');
        }
        return $this->linkedColumnValuesJs;
    }

    /**
     * Refreshes only the plugin because its callback owns loading and spinner completion.
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\Traits\UI5DataElementTrait::buildJsRefresh()
     */
    public function buildJsRefresh(bool $keepPagingPosition = false)
    {
        return <<<JS

        (function(jqCommentsFeed) {
            var oComments = jqCommentsFeed.data('comments');
            if (oComments) {
                oComments.fetchDataAndRender();
            }
        })($('#{$this->getId()}'))
JS;
    }

    /**
     * Registers linked-filter state updates in addition to the configurator's standard delayed refresh.
     *
     * @return void
     */
    protected function registerLinkedFilterStateHandlers() : void
    {
        if ($this->linkedFilterHandlersRegistered) {
            return;
        }
        $updateStateJs = $this->buildJsUpdateReadOnlyState();
        foreach ($this->getWidget()->getFilters() as $filter) {
            if ($filter->getValueWidgetLink() !== null) {
                $this->getFacade()->getElement($filter)->addOnChangeScript($updateStateJs);
            }
        }
        $this->linkedFilterHandlersRegistered = true;
    }

    /**
     * Hides write controls when the parent filter is invalid or the UI5 application is offline.
     *
     * @return string
     */
    protected function buildJsUpdateReadOnlyState() : string
    {
        $validatorJs = $this->getConfiguratorElement()->buildJsValidator();
        return <<<JS

        (function(jqCommentsFeed) {
            var bReadOnly = true;
            try {
                bReadOnly = ! ({$validatorJs}) || ! exfLauncher.isOnline();
            } catch (err) {
                console.warn('Could not determine CommentsFeed write state.', err);
            }
            jqCommentsFeed.toggleClass('read-only', bReadOnly);
        })($('#{$this->getId()}'));
JS;
    }

    /**
     * Connects hidden button request failures to the plugin callbacks before their controls are constructed.
     *
     * @return void
     */
    protected function registerCommentsFeedButtonHandlers() : void
    {
        foreach ([
            $this->getWidget()->getButtonCreate(),
            $this->getWidget()->getButtonEdit(),
            $this->getWidget()->getButtonDelete()
        ] as $buttonWidget) {
            /** @var UI5Button $buttonElement */
            $buttonElement = $this->getFacade()->getElement($buttonWidget);
            $buttonElement->addOnErrorScript(<<<JS

                (function(jqCommentsFeed) {
                    if (jqCommentsFeed[0] && typeof jqCommentsFeed[0].errorCallback === 'function') {
                        jqCommentsFeed[0].errorCallback();
                    }
                })($('#{$this->getId()}'));
JS
            );
        }
    }

    /**
     * Guards writes and settles plugin callbacks when UI5 action effects rerender the feed before its action event.
     *
     * @param string $buttonFunctionJs
     * @param string $buttonWidgetId
     * @param bool $requiresExistingComment
     * @return string
     */
    protected function buildJsWriteActionInvoker(
        string $buttonFunctionJs,
        string $buttonWidgetId,
        bool $requiresExistingComment = false
    ) : string {
        $updateReadOnlyJs = $this->buildJsUpdateReadOnlyState();
        $buttonWidgetIdJs = $this->escapeString($buttonWidgetId);
        $commentIdColumnJs = $this->escapeString(
            $this->getWidget()->getCommentIdColumn()->getDataColumnName()
        );
        $requiresExistingCommentJs = $this->escapeBool($requiresExistingComment);
        $eventNamespace = preg_replace('/[^A-Za-z0-9_]/', '_', $buttonWidgetId);
        $fallbackEventJs = $this->escapeString(
            'actionperformed.comments_settle_' . $eventNamespace
        );
        return <<<JS
(function() {
                    var jqCommentsFeed = $('#{$this->getId()}');
                    if (! exfLauncher.isOnline()) {
                        {$updateReadOnlyJs}
                        if (jqCommentsFeed[0] && typeof jqCommentsFeed[0].errorCallback === 'function') {
                            jqCommentsFeed[0].errorCallback();
                        }
                        return;
                    }
                    var fnSuccess = jqCommentsFeed[0].successCallback;
                    var fnError = jqCommentsFeed[0].errorCallback;
                    var bSettled = false;
                    jqCommentsFeed[0].successCallback = function(oComment) {
                        if (bSettled) {
                            return;
                        }
                        var oComments = jqCommentsFeed.data('comments');
                        var sCommentId = oComment && oComment[{$commentIdColumnJs}];
                        if ({$requiresExistingCommentJs}
                            && (! oComments || ! oComments.commentsById[sCommentId])) {
                            bSettled = true;
                            if (typeof fnError === 'function') {
                                fnError();
                            }
                            return;
                        }
                        bSettled = true;
                        if (typeof fnSuccess === 'function') {
                            fnSuccess.apply(null, arguments);
                        }
                    };
                    jqCommentsFeed[0].errorCallback = function() {
                        if (bSettled) {
                            return;
                        }
                        bSettled = true;
                        if (typeof fnError === 'function') {
                            fnError.apply(null, arguments);
                        }
                    };
                    $(document)
                        .off({$fallbackEventJs})
                        .one({$fallbackEventJs}, function(event, params) {
                            if (! params || params.trigger_widget_id !== {$buttonWidgetIdJs}) {
                                return;
                            }
                            setTimeout(function() {
                                if (! bSettled) {
                                    jqCommentsFeed[0].errorCallback();
                                    var oComments = jqCommentsFeed.data('comments');
                                    if (oComments) {
                                        oComments.fetchDataAndRender();
                                    }
                                }
                            }, 0);
                        });
                    {$buttonFunctionJs}();
                })
JS;
    }

    /**
     * Removes global handlers and plugin-owned DOM/data so rerendering cannot leave duplicate feeds behind.
     *
     * @param string $jqCommentsFeedJs
     * @return string
     */
    protected function buildJsCommentsCleanup(string $jqCommentsFeedJs) : string
    {
        $eventNamespace = preg_replace('/[^A-Za-z0-9_]/', '_', $this->getId());
        $actionEvent = $this->escapeString('actionperformed.comments_' . $eventNamespace);
        $windowNamespace = $this->escapeString('.comments_' . $eventNamespace);
        return <<<JS

                $(document).off({$actionEvent});
                $(window).off({$windowNamespace});
                if ({$jqCommentsFeedJs}.length) {
                    {$jqCommentsFeedJs}.off().empty();
                    {$jqCommentsFeedJs}.removeData('comments');
                    {$jqCommentsFeedJs}.removeData('_exfInput');
                    {$jqCommentsFeedJs}.removeData('_exfData');
                    {$jqCommentsFeedJs}.removeData('_loading');
                    {$jqCommentsFeedJs}.removeData('_exfCommentsInitializing');
                    if ({$jqCommentsFeedJs}[0]) {
                        delete {$jqCommentsFeedJs}[0].successCallback;
                        delete {$jqCommentsFeedJs}[0].errorCallback;
                    }
                }
JS;
    }

    /**
     * Reports no row selection because jquery-comments stores the active CRUD row separately.
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\Traits\UI5DataElementTrait::buildJsGetRowsSelected()
     */
    protected function buildJsGetRowsSelected(string $oControlJs) : string
    {
        return '[]';
    }

    /**
     * Disables the unused UI5 paginator because jquery-comments renders the complete feed itself.
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\Traits\UI5DataElementTrait::hasPaginator()
     */
    protected function hasPaginator() : bool
    {
        return false;
    }

    /**
     * Keeps the data trait's editability contract aligned with the CommentsFeed widget.
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\Traits\UI5DataElementTrait::isEditable()
     */
    protected function isEditable() : bool
    {
        return $this->getWidget()->isEditable();
    }
}
