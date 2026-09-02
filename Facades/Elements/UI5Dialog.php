<?php
namespace exface\UI5Facade\Facades\Elements;

use exface\Core\Actions\GoBack;
use exface\Core\Exceptions\Facades\FacadeRuntimeError;
use exface\Core\Widgets\Dashboard;
use exface\Core\Widgets\Tabs;
use exface\Core\Widgets\Tab;
use exface\Core\Widgets\Image;
use exface\Core\Interfaces\Widgets\iHaveValue;
use exface\Core\Interfaces\Actions\iShowWidget;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Factories\WidgetFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Interfaces\Widgets\iFillEntireContainer;
use exface\Core\Factories\ActionFactory;
use exface\Core\Widgets\Split;
use exface\UI5Facade\Facades\Interfaces\UI5ConfirmationElementInterface;

/**
 * In OpenUI5 dialog widgets are either rendered as sap.m.Page (if maximized) or as sap.m.Dialog.
 * 
 * A non-maximized `Dialog` widget will be rendered as a sap.m.Dialog. If the widget includes
 * tabs, they will be rendered normally (sap.m.IconTabBar)
 * 
 * A maximized `Dialog` will be rendered as a sap.m.Page with the following content:
 * - if the `Dialog` contains the `Tabs` widget, the `sap.uxap.ObjectPageLayout` will be used with a
 * `sap.uxap.ObjectPageSection` and a single `sap.uxap.ObjectPageSubsection` for every `Tab` widget.
 * - if the `Dialog` contains multiple widgets, they will all be placed in a single section and 
 * subsection of a `sap.uxap.ObjectPageLayout`.
 * - if the `Dialog` contains a single visible widget with `iFillEntireContainer` and
 *  - if the `Dialog` has no header, the child widget will be placed directly into int `sap.m.Page`
 *  without the ObjectPageLayout. This is important, because most of these widget will have their
 *  own layouts. Also, the ObjectPageLayout canno stretch it's content to full height and filling
 *  widgets must be stretched.
 *  - if the `Dialog` has a header, the child widget will be placed into a single section and
 *  subsection of the `sap.uxap.ObjectPageLayout` - this might look strange, but it seems the only
 *  way, to make the header look similar to multi-widget dialogs.
 * 
 * @method \exface\Core\Widgets\Dialog getWidget()
 *        
 * @author Andrej Kabachnik
 *        
 */
class UI5Dialog extends UI5Form
{    
    const PREFILL_WITH_INPUT = 'input';
    const PREFILL_WITH_PREFILL = 'prefill';
    const PREFILL_WITH_CONTEXT = 'context';
    const PREFILL_WITH_ANY = 'any';
    
    const PREFILL_ERROR_CLOSE_DELAY = 100;
    
    const CONTROLLER_METHOD_FIX_HEIGHT = 'fixHeight';
    const CONTROLLER_METHOD_CLOSE_DIALOG = 'closeDialog';
    const CONTROLLER_METHOD_PREFILL = 'prefill';
    const CONTROLLER_METHOD_GET_VISIBLE_CHANGES = 'getVisibleChanges';
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5Form::buildJsConstructor()
     */   
    public function buildJsConstructor($oControllerJs = 'oController') : string
    {
        $widget = $this->getWidget();
        $controller = $this->getController();
                
        // If we need a prefill, we need to let the view model know this, so all the wigdget built
        // for this dialog can see, that a prefill will be done. This is especially important for
        // widget with lazy loading (like tables), that should postpone loading until the prefill data
        // is there.
        if ($this->needsPrefill()) {
            $controller->addOnInitScript('this.getView().getModel("view").setProperty("/_prefill/pending", true);');
            $controller->addMethod(self::CONTROLLER_METHOD_PREFILL, $this, 'oView, bForceReload', $this->buildJsPrefillLoader('oView', 'bForceReload'));
        }
        
        // Submit on enter
        $this->registerSubmitOnEnter($oControllerJs);
        
        $dialogOpenerBtnEl = $this->getFacade()->getElement($widget->getOpenButton());
        $dialogOpenerAction = $widget->getOpenAction();
        
        // Mark dialog as not closed initially
        $controller->addOnShowViewScript("{$controller->getView()->buildJsViewGetter($this)}.getModel('view').setProperty('/_closed', false);");
        
        // Fire on-change when prefilled
        $controller->addOnPrefillDataChangedScript($this->getOnChangeScript());
        
        // Focus the first editable control when the dialog is opened
        $controller->addOnShowViewScript($this->buildJsFocusFirstInput());

        // Register changes getter as a controller method to avoid printing it to JS every time
        $controller->addMethod(self::CONTROLLER_METHOD_GET_VISIBLE_CHANGES, $this, '', 'return ' . parent::buildJsChangesGetter(true));
        
        // Reload the dialog after it is shown if prefill refresh is needed (e.g. because of action effects)
        // Use setTimeout() to make sure all controls are rendered when refreshing. Otherwise, some required
        // filters may not resolve - e.g. in Charts inside the dialog
        $controller->addOnShowViewScript("(function(oCtrl){
            if(oCtrl.getModel('view').getProperty('/_prefill/refresh_needed') === true) {
                // TODO Do not refresh silently if there are changes as they will be lost
                oCtrl.getModel('view').setProperty('/_prefill/refresh_needed', false);
                setTimeout(function(){
                    {$this->buildJsRefresh(true)};
                }, 0);
            }
        })(sap.ui.getCore().byId('{$this->getId()}'));", false);
        
        // Listen to action affecting the data in this dialog
        $controller->addOnInitScript($this->buildJsRegisterOnActionPerformed(<<<JS

            (function(oController, oEventParams){
                var oCtrl = sap.ui.getCore().byId('{$this->getId()}');
                var jqCtrl;
                // Avoid errors if the view/dialog is closed
                if (oCtrl === undefined || oCtrl.getModel('view').getProperty('/_closed') === true) {
                    return;
                }
                jqCtrl = oCtrl.$();
                // If the dialog is not visible, wait till it is - just mark the prefill to be refreshed
                if (jqCtrl.length === 0 || jqCtrl.is(':visible') === false) {
                    oCtrl.getModel('view').setProperty('/_prefill/refresh_needed', true);
                } else {
                    {$this->buildJsRefresh(true)};
                }
            })($oControllerJs, oEventParams);
JS, false));
        
        // Add a controller method to close the dialog
        if ($this->isMaximized() === false) {
            $closeDialogJs = "sap.ui.getCore().byId('{$this->getFacade()->getElement($widget)->getId()}').close();";
        } else {
            $closeDialogJs = "this.navBack(oEvent);";
        } 
        
        
        $closeAction = ActionFactory::createFromString($this->getWorkbench(), GoBack::class, $widget);
        $closeConfirmationEl = $this->getFacade()->getElement($closeAction->getConfirmations()->getConfirmationsForUnsavedChanges()->getFirst());
        if (! $closeConfirmationEl instanceof UI5ConfirmationElementInterface) {
            throw new FacadeRuntimeError('Cannot use widget "' . $closeConfirmationEl->getWidget()->getWidgetType() . '" for confirmations in UI5 facade: UI5 element does not implement required UI5ConfirmationElementInterface!');
        }
        $controller->addMethod(self::CONTROLLER_METHOD_CLOSE_DIALOG, $this, 'oEvent', <<<JS
            
                try {
                    var oViewModel = this.getView().getModel('view');
                    var aChanges = [];
                    var bCheckChanges = ! (oEvent !== undefined && oEvent.getParameters().bCheckChanges === false);
                    var fnClose = function(){
                        oViewModel.setProperty('/_prefill/current_data_hash', null);
                        oViewModel.setProperty('/_prefill/refresh_needed', false);
                        oViewModel.setProperty('/_closed', true);
                        {$closeDialogJs}
                        {$dialogOpenerBtnEl->buildJsTriggerActionEffects($dialogOpenerAction)}
                    }.bind(this);
                    
                    // Check for unsaved changes if required.
                    if (bCheckChanges === true) {
                        aChanges = {$this->buildJsChangesGetter(true)};
                        if (aChanges && aChanges.length > 0) {
                            {$closeConfirmationEl->buildJsConfirmation('{}', 'fnClose()')}
                            return;
                        }
                    }
                } catch (e) {
                    console.error('Error while closing dialog: ', e);
                }
                fnClose();
JS
        );
        
        // Build the dialog and return its JS constructor
        if ($this->isMaximized() === false) {
            return $this->buildJsDialog($oControllerJs);
        } else {
            // Controller method to apply height-fix for inner controls with virtual scrolling
            if ($this->isObjectPageLayout()) {
                $controller->addMethod(self::CONTROLLER_METHOD_FIX_HEIGHT, $this, '', $this->buildJsObjectPageLayouHeightFix());
                $fixInnerPanelHeightJs = $this->getController()->buildJsMethodCallFromController(self::CONTROLLER_METHOD_FIX_HEIGHT, $this, '', $oControllerJs);
                // Adjust the height every time the view is shown
                $this->getController()->addOnShowViewScript($fixInnerPanelHeightJs, false);
                // Adjust the height every time the size of the dialog or the sap.uxap.ObjectPageHeaderContent changes
                $this->getController()->addOnInitScript(<<<JS
                    
                        sap.ui.core.ResizeHandler.register(sap.ui.getCore().byId('{$this->getId()}'), function(){
                            {$fixInnerPanelHeightJs}
                        });
                        
                        /*sap.ui.core.ResizeHandler.register(sap.ui.getCore().byId('{$this->getId()}').getContent()[0]._getHeaderContent(), function(){
                            {$fixInnerPanelHeightJs}
                        });*/
JS
                );
            }
            
            if ($this->isObjectPageLayout()) {
                return $this->buildJsPage($this->buildJsObjectPageLayout($oControllerJs), $oControllerJs);
            } else {
                return $this->buildJsPage($this->buildJsChildrenConstructors(), $oControllerJs);
            }
        }        
    }
    
    /**
     * 
     * @return Tabs|NULL
     */
    protected function getObjectPageTabs() : ?Tabs
    {
        if ($this->isObjectPageLayout()) {
            foreach ($this->getWidget()->getWidgets() as $child) {
                if ($child instanceof Tabs) {
                    return $child;
                }
            }
        }
        return null;
    }
    
    /**
     * 
     * @return bool
     */
    public function hasHeader() : bool
    {
        return $this->getWidget()->hasHeader();
    }
    
    /**
     * 
     * @return bool
     */
    public function isObjectPageLayout() : bool
    {
        $widget = $this->getWidget();
        $visibleChildren = $widget->getWidgets(function(WidgetInterface $widget) {
            return $widget->isHidden() === false;
        });
        return ! (
            $widget->hasHeader() === false 
            && count($visibleChildren) === 1 
            && $visibleChildren[0] instanceof iFillEntireContainer 
            && ! $visibleChildren[0] instanceof Tabs
        );
    }
    
    /**
     * Returns an inline JS snippet, that returns `true` if the dialog is currently closed and `false`/`undefined` otherwise.
     * 
     * @return string
     */
    public function buildJsCheckDialogClosed() : string
    {
        return "{$this->getController()->getView()->buildJsViewGetter($this)}.getModel('view').getProperty('/_closed')";
    }
    
    /**
     * Returns TRUE if the dialog is maximized (i.e. should be rendered as a page) and FALSE otherwise (i.e. rendering as dialog).
     * @return boolean
     */
    public function isMaximized() : bool
    {
        $widget = $this->getWidget();
        $widget_setting = $widget->isMaximized();
        if (is_null($widget_setting)) {
            $width = $widget->getWidth();
            if ($width->isRelative()) {
                return false;
            }
            if ($width->isMax()) {
                return true;
            }
            if ($width->isPercentual() && $width->getValue() === '100%') {
                return true;
            }
            if ($action = $widget->getOpenAction()) {
                $action_setting = $this->getFacade()->getConfigMaximizeDialogByDefault($action);
                return $action_setting;
            }
            return false;
        }
        return $widget_setting;
    }
    
    protected function buildJsObjectPageLayout(string $oControllerJs = 'oController')
    {
        // useIconTabBar: true did not work for some reason as tables were not shown when
        // entering a tab for the first time - just at the second time. There was also no
        // difference between creating tables with new sap.ui.table.table or function(){ ... }()
        $js = <<<JS
   
        new sap.uxap.ObjectPageLayout('{$this->getIdOfObjectPageLayout()}', {
            useIconTabBar: false,
            upperCaseAnchorBar: false,
            enableLazyLoading: false,
			{$this->buildJsHeader($oControllerJs)},
			sections: [
				{$this->buildJsObjectPageSections($oControllerJs)}
			]
		})

JS;
        if ($this->getWidget()->hasSidebar()) {
            $sidebarEl = $this->getFacade()->getElement($this->getWidget()->getSidebar());
            if ($sidebarEl instanceof UI5Sidebar) {
                $js = $sidebarEl->buildJsConstructorForDynamicSideContent($js, $oControllerJs);
            }
        }
        return $js;
    }

    /**
     * @param string $oControllerJs
     * @return string
     */
    protected function buildJsPageHeaderContent(string $oControllerJs = 'oController') : string
    {
        $widget = $this->getWidget();
        if ($widget->hasHeader()) {
            $header = $widget->getHeader();
            $headerButtonsJs = '';
            foreach ($header->getButtons() as $button) {
                $headerButtonsJs .= $this->getFacade()->getElement($button)->buildJsConstructor($oControllerJs) . ',';
            }
        }
        return <<<JS
                
                {$this->buildJsTourGuideDropdown($widget, $this->getController())}
                {$headerButtonsJs}
                {$this->buildJsHelpButtonConstructor($oControllerJs)}
                {$this->buildJsSidebarToggleButton()}
JS;
    }
        
    protected function buildJsHeader(string $oControllerJs = 'oController')
    {
        $widget = $this->getWidget();
        $alwaysShowContentHeader = 'false';

        if ($widget->hasHeader()) {
            $header = $widget->getHeader();
            foreach ($header->getChildren() as $child) {
                if ($child instanceof Image) {
                    $imageElement = $this->getFacade()->getElement($child);
                    $image = <<<JS

                    objectImageURI: {$imageElement->buildJsValue()},
			        objectImageShape: "Circle",
JS;
                    $child->setHidden(true);
                    break;
                }
            }
            
            $alwaysShowContentHeader = $header->isCollapsible(true) ? 'false' : 'true';
            $header_content = $this->getFacade()->getElement($widget->getHeader())->buildJsConstructor();
        }
        
        return <<<JS

            showTitleInHeaderContent: true,
            alwaysShowContentHeader: {$alwaysShowContentHeader},
            headerTitle:
				new sap.uxap.ObjectPageHeader({
					objectTitle: {$this->buildJsObjectTitle()},
				    showMarkers: false,
				    isObjectIconAlwaysVisible: false,
				    isObjectTitleAlwaysVisible: false,
				    isObjectSubtitleAlwaysVisible: false,
                    isActionAreaAlwaysVisible: false,
                    {$image}
					actions: [
					]
				}),
			headerContent:[
                {$header_content}
                new sap.m.Button({
                    icon: "sap-icon://slim-arrow-up", 
                    type: "Transparent",
                    tooltip: "{i18n>WIDGET.DIALOG.COLLAPSE_HEADER}",
                    press: function(){
                        sap.ui.getCore().byId('{$this->getIdOfObjectPageLayout()}')._snapHeader()
                    }
                }).addStyleClass('exf-dialog-btn-header-collapse'),
			]
JS;
    }
				
    protected function buildJsObjectTitle() : string
    {
        $widget = $this->getWidget();

        if ($widget->getHideCaption()) {
            return '""';
        }
        
        // If the dialog has a header and it has a fixed or prefilled title, take it as is.
        if ($widget->hasHeader()) {
            $header = $widget->getHeader();
            if ($header->getHideCaption() === true) {
                return '""';
            }
            if (! $header->isTitleBoundToAttribute()) {
                $caption = $header->getCaption() ? $header->getCaption() : $widget->getCaption();
                return '"' . $this->escapeJsTextValue($caption) . '"';
            }
        }
        
        // Otherwise try to find a good title
        $object = $widget->getMetaObject();
        if ($widget->hasHeader()) {
            $title_attr = $widget->getHeader()->getTitleAttribute();
        } elseif ($object->hasLabelAttribute()) {
            $title_attr = $object->getLabelAttribute();
        } elseif ($object->hasUidAttribute()) {
            $title_attr = $object->getUidAttribute();
        } else {
            // If no suitable attribute can be found, use the object name as static title
            return '"' . $this->escapeJsTextValue($widget->getCaption() ? $widget->getCaption() : $object->getName()) . '"';
        }
        
        // Once a title attribute is found, create an invisible display widget and
        // let it's element produce a binding.
        /* @var $titleElement \exface\UI5Facade\Facades\Elements\UI5Display */
        $titleWidget = WidgetFactory::createFromUxon($widget->getPage(), new UxonObject([
            'widget_type' => 'Display',
            'hidden' => true,
            'attribute_alias' => $title_attr->getAliasWithRelationPath()
        ]), $widget);
        $titleElement = $this->getFacade()->getElement($titleWidget);
        
        // If there is a caption binding in the view model, use it in the title element
        if ($header !== null) {
            $model = $this->getView()->getModel();
            if ($model->hasBinding($header, 'caption')) {
                $titleElement->setValueBindingPath($model->getBindingPath($header, 'caption'));
            }
        }
        
        return $titleElement->buildJsValue();
    }
    
    /**
     * 
     * @param MetaAttributeInterface $attribute
     * @return iHaveValue|NULL
     */
    protected function findWidgetByAttribute(MetaAttributeInterface $attribute) : ?iHaveValue
    {
        $widget = $this->getWidget();
        $found = null;
        
        $found = $widget->findChildrenByAttribute($attribute)[0];
        if ($found === null) {
            if ($widget->hasHeader()) {
                $found = $widget->getHeader()->findChildrenByAttribute($attribute)[0];
            }
        }
        
        return $found;
    }
				
    protected function buildJsDialog(string $oControllerJs) : string
    {
        $widget = $this->getWidget();
        $icon = $widget->getIcon() ? 'icon: "' . $this->getIconSrc($widget->getIcon()) . '",' : '';
        
        $contentJs = $this->buildJsLayoutConstructor();
        $headersJs = '';
        if ($widget->hasHeader()) {
            $headersJs = <<<JS

                new sap.uxap.ObjectPageHeader({
					objectTitle: {$this->buildJsObjectTitle()},
				    showMarkers: false,
				    isObjectIconAlwaysVisible: false,
				    isObjectTitleAlwaysVisible: false,
				    isObjectSubtitleAlwaysVisible: false,
                    isActionAreaAlwaysVisible: false,
					actions: [
						
					]
				}),
                new sap.uxap.ObjectPageHeaderContent({
                    content: [ {$this->getFacade()->getElement($widget->getHeader())->buildJsConstructor()} ]
                }),

JS;
        }
        
        $cacheableJs = $this->getWidget()->isCacheable() ? 'true' : 'false';
        $toolbarEl = $this->getFacade()->getElement($this->getWidget()->getToolbarMain());
        // Finally, instantiate the dialog
        return <<<JS

        new sap.m.Dialog("{$this->getId()}", {
			{$icon}
            {$this->buildJsPropertyContentHeight()}
            {$this->buildJsPropertyContentWidth()}
            stretch: jQuery.device.is.phone,
            title: {$this->escapeString($this->getCaption())},
			content : [ {$headersJs}{$contentJs} ],
            buttons : [new sap.m.Button()],
            beforeOpen: function(oEvent) {
                var oDialog = oEvent.getSource();
                {$this->buildJsRefresh()}                    
            },
            afterOpen: function(oEvent) {
                var oView = {$this->getController()->getView()->buildJsViewGetter($this)};
                var oDialog = oEvent.getSource();
                var oToolbar = oDialog._getToolbar();
                ids = {$toolbarEl->buildJsButtonsIdArray()};
                ids.forEach(function(id) {
                    const control = sap.ui.getCore().byId(id);
                    if (control) control.destroy();
                });
                var aContent = [{$this->buildJsDialogButtons()}];
                oToolbar.removeAllContent();
                aContent.forEach(function(oElem) {                
                    oToolbar.addContent(oElem);
                    oView.addDependent(oElem);
                });
            },
            afterClose: function(oEvent) { 
                var oDialog = oEvent.getSource();
                var oToolbar = oDialog._getToolbar();
                var aContent = oToolbar.getContent();
                var bCacheable = $cacheableJs;
                if (bCacheable && aContent.length && aContent.length > 0) {
                    aContent.forEach(function(oElem) {
                        oElem.destroy();
                    });
                } else {
                    {$this->getController()->getView()->buildJsViewGetter($this)}.destroy();
                    oDialog.destroy();
                }
            }
		}).addStyleClass('{$this->buildCssElementClass()} {$this->buildCssWidgetClass()}')
        {$this->buildJsPseudoEventHandlers()}
JS;
    }

    /**
     * 
     * @return string
     */
    protected function buildJsPropertyContentHeight() : string
    {
        $height = '';
        
        $dim = $this->getWidget()->getHeight();
        switch (true) {
            case $dim->isPercentual():
            case $dim->isFacadeSpecific() && strtolower($dim->getValue()) !== 'auto':
                $height = json_encode($dim->getValue());
                break;
            case $dim->isRelative():
                $height = json_encode(($dim->getValue() * $this->getHeightRelativeUnit()) . 'px');
                break;
            default:
                if ($this->isLargeDialog()) {
                    $height = '"70%"';
                }
        }
        
        return $height ? 'contentHeight: ' . $height . ',' : '';
    }
    
    /**
     * 
     * @return string
     */
    protected function buildJsPropertyContentWidth() : string
    {
        $width = '';
        
        $dim = $this->getWidget()->getWidth();
        switch (true) {
            case $dim->isPercentual():
            case $dim->isFacadeSpecific():
                $width = json_encode($dim->getValue());
                break;
            case $dim->isRelative():
                $width = json_encode(($dim->getValue() * $this->getWidthRelativeUnit()) . 'px');
                break;
            default:
                if ($this->isLargeDialog()) {
                    $width = '"65rem"'; // This is the size of a P13nDialog used for data configurator
                }
        }
        
        return $width ? 'contentWidth: ' . $width . ',' : '';
    }
    
    /**
     * Returns TRUE if the dialog is non-maximized, but should be "large" - e.g. to house a table.
     * 
     * @return bool
     */
    protected function isLargeDialog() : bool
    {
        $widget = $this->getWidget();
        $filterCallback = function(WidgetInterface $w) {
            return $w->isHidden() === false;
        };
        if ($widget->countWidgets($filterCallback) === 1) {
            $firstEl = $this->getFacade()->getElement($widget->getWidgetFirst($filterCallback));
            switch (true) {
                case $firstEl instanceof UI5Tabs:
                    // TODO Replace with interface (e.g. UI5PageControlInterface)
                case $firstEl instanceof UI5DataTable:
                case $firstEl instanceof UI5Chart:
                    return true;
            }
        }
        return false;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractAjaxFacade\Elements\AbstractJqueryElement::getCaption()
     */
    protected function getCaption() : string
    {
        $caption = parent::getCaption();
        $widget = $this->getWidget();
        $objectName = $widget->getMetaObject()->getName();
        $buttonCaption = $widget->hasParent() ? $widget->getParent()->getCaption() : null;
        
        // Append the object name to the caption unless
        // - The dialog has a custom caption (= not qual to the button caption)
        // - The object name is already part of the caption (having it there twice would look stupid)
        $buttonHasCustomCaption = $caption !== $buttonCaption;
        if (! $buttonHasCustomCaption && mb_stripos($caption, $objectName) === false) {
            $caption .= ': ' . $objectName;
        }
        return $caption;
    }
    
    /**
     * Returns the JS constructor for the sap.m.Page used as the top-level control when rendering
     * the dialog as an object page layout. 
     * 
     * The page will have a floating toolbar with all dialog buttons and a header with a title and
     * the close/back button.
     * 
     * @param string $content_js
     * @return string
     */
    protected function buildJsPage($content_js, string $oControllerJs = 'oController')
    {
        $this->getController()->addOnRouteMatchedScript($this->buildJsRefresh(false), 'loadPrefill');
        if ($this->getWidget()->isCacheable() === false) {
            $this->getController()->addOnHideViewScript("sap.ui.getCore().byId('{$this->getId()}').destroy()");
        }
        
        return <<<JS
        
        new sap.m.Page("{$this->getId()}", {
            title: "{$this->getCaption()}",
            showNavButton: true,
            navButtonPress: {$this->getController()->buildJsMethodCallFromView(self::CONTROLLER_METHOD_CLOSE_DIALOG, $this, $oControllerJs)},
            content: [
                {$content_js}
            ],
            headerContent: [
                {$this->buildJsPageHeaderContent($oControllerJs)}
            ],
            footer: {$this->buildJsFloatingToolbar()}
        }).addStyleClass('{$this->buildCssElementClass()} {$this->buildCssWidgetClass()}')
        {$this->buildJsPseudoEventHandlers()}

JS;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5Form::buildCssElementClass()
     */
    public function buildCssElementClass()
    {
        return 'exf-dialog-page' .  ($this->getWidget()->isFilledBySingleWidget() ? ' exf-dialog-filled' : '') . ($this->getWidget()->hasHeader() ? ' exf-dialog-with-header' : '');
    }
        
    /**
     * Returns TRUE if the dialog needs to be prefilled and FALSE otherwise.
     * 
     * @param string $prefillType
     * @return bool
     */
    protected function needsPrefill(string $prefillType = self::PREFILL_WITH_ANY) : bool
    {
        if (($action = $this->getWidget()->getOpenAction()) instanceof iShowWidget) {
            switch (true) {
                case $action->getPrefillWithInputData() && ($prefillType === self::PREFILL_WITH_ANY || $prefillType === self::PREFILL_WITH_INPUT):
                    return true;
                case $action->getPrefillWithPrefillData() && ($prefillType === self::PREFILL_WITH_ANY || $prefillType === self::PREFILL_WITH_PREFILL):
                    return true;
            }
        }
        
        return false;
    }
          
    /**
     * Returns the JS code to load prefill data for the dialog. 
     * 
     * TODO will this work with with explicit prefill data too? 
     * 
     * @param string $oViewJs
     * @return string
     */
    protected function buildJsPrefillLoader(string $oViewJs = 'oView', string $bForceReloadJs = 'bForceReload') : string
    {
        $widget = $this->getWidget();
        $triggerWidget = $widget->getOpenButton() ?? $widget;
        
        // If the prefill cannot be fetched due to being offline, show the offline message view
        // (if the dialog is a page) or an error-popup (if the dialog is a regular dialog).
        if ($this->isMaximized()) {
            // In case of the offline-view, make sure to close this dialog first and switch to
            // the offline view then. Otherwise, the back-button of the offline view would 
            // return to this dialog (because it is the one that navigated to the offline view)
            // and trigger prefill again and open the offline view recursively
            $showOfflineMsgJs = <<<JS

            {$this->buildJsCloseDialog(false)}
            setTimeout(function(){
                {$oViewJs}.getController().getRouter().getTargets().display("offline");
            }, 10);
JS;
        } else {
            $showOfflineMsgJs = <<<JS
            
            {$this->getController()->buildJsComponentGetter()}.showDialog('{$this->translate('WIDGET.DATATABLE.OFFLINE_ERROR_TITLE')}', '{$this->translate('WIDGET.DATATABLE.OFFLINE_ERROR')}', 'Error');
            {$this->buildJsCloseDialog()}  
JS;
        }
        
        $action = ActionFactory::createFromString($this->getWorkbench(), 'exface.Core.ReadPrefill', $widget);
        
        switch (true) {
            case ! $this->needsPrefill(self::PREFILL_WITH_INPUT):
                $filterRequestParams = "if (data.data !== undefined) {delete data.data}";
                break;
            case ! $this->needsPrefill(self::PREFILL_WITH_PREFILL):
                $filterRequestParams = "if (data.prefill !== undefined) {delete data.prefill}";
                break;
            default: $filterRequestParams = '';  
        }
        
        $hideBusyJs = <<<JS

                {$this->buildJsBusyIconHide()}; 
                oViewModel.setProperty('/_prefill/pending', false);

JS;
        $onErrorJs = '';
        // Close the dialog on error, but only if it is not a view. Closing the view
        // would also close the actual error dialog.
        if ($this->isMaximized() === false) {
            $closeDelay = self::PREFILL_ERROR_CLOSE_DELAY;
            $onErrorJs .=  <<<JS
                // Before closing the dialog in prefill state, we should wait and guarantee that afterOpen lifcycle
                // method was executed completely. We do not check for changes, because the user should have no control over shutdowns.
                setTimeout(function(){ {$this->buildJsCloseDialog(false)}}, {$closeDelay});
JS;
        } else {
            // If the dialog is maximized we have to display the error in the dialog instead. This is less appealing
            // but at least reliable.
            $widget->getOpenAction()?->isAuthorized(null, false);
        }
        
        // FIXME use buildJsPrefillLoaderSuccess here somewhere?
        
        // TODO #ui5-model-everywhere make sure the data model is always created - even if no prefill request is done
        
        // The prefill will fill two UI5 models:
        // - the data model - `oView.getModel()` - holding the loaded prefill data
        // - the view model - `oView.getModel('view')` - holding all sorts of metadata about like the prefill url,
        // pending state, etc.
        return <<<JS

            //FIXME for some reason the prefill is called multiple times for a EditDialog with a spreadsheet
			//and the params are empty for the first calls. Right now it's unclear why that happens, so a quick fix was added
			{$this->buildJsBusyIconShow()}
            var oViewModel = {$oViewJs}.getModel('view');
            var oResultModel = {$oViewJs}.getModel();

            var oRouteParams = oViewModel.getProperty('/_route');
            //Skip if no params are found, sometimes happens if EditDialog with spreadsheet is opened for the second time
			if (oRouteParams == undefined) {
                console.warn('Prefill skipped because params are undefined');
                return;
            }
            var data = $.extend({}, {
                action: "exface.Core.ReadPrefill",
				resource: "{$widget->getPage()->getAliasWithNamespace()}",
				element: "{$triggerWidget->getId()}",
            }, oRouteParams.params);
            
            var oLastRouteString = oViewModel.getProperty('/_prefill/current_data_hash');
            var oCurrentRouteString = JSON.stringify(data);
            
            oViewModel.setProperty('/_prefill/pending', true);
            
            {$filterRequestParams}
            
            if (bForceReload === false && oLastRouteString === oCurrentRouteString) {
                {$this->buildJsBusyIconHide()}
                oViewModel.setProperty('/_prefill/pending', false);
                return;
            } else {
                oViewModel.setProperty('/_prefill/current_data_hash', oCurrentRouteString);    
            }

            oViewModel.setProperty('/_prefill/started', true);
            oViewModel.setProperty('/_prefill/data', {});

            oResultModel.setData({});
            
            {$this->getServerAdapter()->buildJsServerRequest(
                $action,
                'oResultModel',
                'data',
                $hideBusyJs . <<<JS

                setTimeout(function(){ 
                    oViewModel.setProperty('/_prefill/refresh_needed', false);
                    oViewModel.setProperty('/_prefill/data', JSON.parse(oResultModel.getJSON()));
                }, 0);
JS,
                $hideBusyJs . $onErrorJs,
                $showOfflineMsgJs
            )}
			
JS;
    }
       
    /**
     * 
     * @param string $responseJs
     * @param string $oViewJs
     * @param string $oViewModelJs
     * @return string
     */
    protected function buildJsPrefillLoaderSuccess(string $responseJs = 'response', string $oViewJs = 'oView', string $oViewModelJs = 'oViewModel') : string
    {
        // IMPORTANT: We must ensure, ther is no model data before replacing it with the prefill! 
        // Otherwise the model will not fire binding changes properly: InputComboTables will loose 
        // their values! But only reset the model if it has data, because the reset will trigger
        // an update of all bindings.
        return <<<JS

                    {$oViewModelJs}.setProperty('/_prefill/pending', false);
                    if (Object.keys(oDataModel.getData()).length !== 0) {
                        oDataModel.setData({});
                    }
                    if (Array.isArray({$responseJs}.rows)) {
                        if ({$responseJs}.rows.length === 1) {
                            oDataModel.setData({$responseJs}.rows[0]);
                        } else if ({$responseJs}.rows.length > 1) {
                            {$this->buildJsShowMessageError('"Error prefilling view with data: received " + {$responseJs}.rows.length + " rows instead of 0 or 1! Only the first data row is visible!"')};
                        }
                    }
                    {$this->buildJsBusyIconHide()}

JS;
    }
    
    /**
     * Returns JS constructors for page sections of the object page layout.
     * 
     * If the dialog contains tabs, page sections will be generated automatically for
     * every tab. Otherwise all widgets will be placed in a single page section.
     * 
     * @return string
     */
    protected function buildJsObjectPageSections(string $oControllerJs = 'oController')
    {
        $widget = $this->getWidget();
        $js = '';
        $nonTabHiddenWidgets = [];
        $nonTabChildrenWidgets = [];
        $hasSingleVisibleChild = false;
        $paddingNeeded = false;
        
        foreach ($widget->getWidgets() as $child) {
            switch (true) {
                // Tabs are transformed to PageSections and all other widgets are collected and put into a separate page section
                // lager on.
                case $child instanceof Tabs:
                    foreach ($child->getTabs() as $tab) {
                        $js .= $this->buildJsObjectPageSectionFromTab($tab);
                    }
                    continue 2;
                // Most dialogs will have hidden system fields at top level. They need to be placed at the very end - otherwise
                // they break the SimpleForm generated for the non-tab PageSection. If they come first, the SimpleForm will allocate
                // space for them (even though not visible) and put the actual content way in the back.
                case $child->isHidden() === true:
                    $nonTabHiddenWidgets[] = $child;
                    break;
                // Large widgets need to be handled differently if the fill the entire dialog (i.e. being
                // the only visible widget). In this case, we don't need any layout - just the big filling
                // widget.
                case (! $this->getFacade()->getElement($child)->needsContainerContentPadding()):
                    if ($widget->countWidgetsVisible() === 1) {
                        $hasSingleVisibleChild = true;
                    }
                    $nonTabChildrenWidgets[] = $child;
                    break;
                default:
                    $paddingNeeded = true;
                    $nonTabChildrenWidgets[] = $child;
            }
        }
        
        // Append hidden non-tab elements after the visible ones
        if (! empty($nonTabHiddenWidgets)) {
            $nonTabChildrenWidgets = array_merge($nonTabChildrenWidgets, $nonTabHiddenWidgets);
        }
        
        // Build an ObjectPageSection for the non-tab elements
        if (! empty($nonTabChildrenWidgets)) {
            $sectionContent = '';
            $fullHeight = false;
            if ($hasSingleVisibleChild || $paddingNeeded === false) {
                foreach ($nonTabChildrenWidgets as $child) {
                    $sectionContent .= $this->getFacade()->getElement($child)->buildJsConstructor() . ',';
                }
                $sectionCssClass = 'sapUiNoContentPadding';
            } else {
                $sectionContent = $this->buildJsLayoutConstructor($nonTabChildrenWidgets);
                $sectionCssClass = 'sapUiTinyMarginTop';
            }
            
            
            if ($widget->isFilledBySingleWidget()) {
                $fillerWidget = $widget->getFillerWidget();
                $fillerHeight = $widget->getHeight();
                $fillerEl = $this->getFacade()->getElement($fillerWidget);
                if ($fillerHeight->isMax() || ($fillerHeight->isPercentual() && $fillerHeight->getValue() === '100%') || $fillerEl->needsContainerHeight()) {
                    $fullHeight = true;
                }
            }
            
            $js .= $this->buildJsObjectPageSection($sectionContent, $sectionCssClass, $fullHeight);
        }
        
        return $js;
    }
    
    /**
     * Returns the JS constructor for a general page section with no title and a single subsection.
     * 
     * The passed content is placed in the blocks aggregation of the subsection.
     * 
     * @param string $content_js
     * @return string
     */
    protected function buildJsObjectPageSection($content_js, $cssClass = null, bool $fullHeight = false)
    {
        $suffix = $cssClass !== null ? '.addStyleClass("' . $cssClass . '")' : '';
        if ($fullHeight) {
            $content_js = <<<JS

                            new sap.m.Panel({
                                height: '100%',
                                content: [
                                    {$content_js}
                                ]
                            }).addStyleClass("sapUiNoContentPadding exf-section-fullheight")

JS;
        }
        return <<<JS

                // BOF ObjectPageSection
                new sap.uxap.ObjectPageSection({
                    showTitle: false,
                    subSections: new sap.uxap.ObjectPageSubSection({
						blocks: [
                            {$content_js}
                        ]
					})
				}){$suffix}
                // EOF ObjectPageSection

JS;
    }
    
    /**
     * Returns the JS constructor for a page section representing the given tab widget.
     * 
     * @param Tab $tab
     * @return string
     */
    protected function buildJsObjectPageSectionFromTab(Tab $tab) 
    {
        if ($tab->isFilledBySingleWidget()) {
            $cssClass = 'sapUiNoContentPadding';
            // Some controls do not play nice with object page sections, so we need to set custom height
            // for them if it is not done by the user.
            $fillerWidget = $tab->getFillerWidget();
            switch (true) {
                case $fillerWidget instanceof Split:
                    $cssClass .= ' exf-tab-split';
                    if ($fillerWidget->getHeight()->isUndefined() || $fillerWidget->getHeight()->isMax()) {
                        $fillerWidget->setHeight('70vh');
                    }
                    break;
                case $fillerWidget instanceof Dashboard:
                    $cssClass .= ' exf-tab-dashboard';
                    break;
            }
        } else {
            $cssClass = null;
            foreach ($tab->getWidgets() as $child) {
                if ($this->getFacade()->getElement($child)->needsContainerContentPadding() === true) {
                    $cssClass = '';
                    break;
                }
            }
            if ($cssClass === null) {
                $cssClass = 'sapUiNoContentPadding';
            }
        }
        
        $showTitleJs = $tab->getTabIndex() === 0 ? 'showTitle: false,' : '';
        $tabElement = $this->getFacade()->getElement($tab);
        return <<<JS

                // BOF ObjectPageSection
                new sap.uxap.ObjectPageSection('{$tabElement->getId()}', {
					title: {$this->escapeString($tabElement->getCaption())},
                    titleUppercase: false,
                    {$showTitleJs}
					subSections: new sap.uxap.ObjectPageSubSection({
						blocks: [
                            {$tabElement->buildJsLayoutConstructor()}
                        ]
					})
				}).addStyleClass('{$cssClass} {$tabElement->buildCssElementClass()} {$tabElement->buildCssWidgetClass()}'),
                // EOF ObjectPageSection
                
JS;
    }
    
    /**
     * Returns the button constructors for the sap.m.Dialog buttons.
     * 
     * @return string
     */
    protected function buildJsDialogButtons(bool $addSpacer = true)
    {
        $widget = $this->getWidget();
        $toolbarEl = $this->getFacade()->getElement($widget->getToolbarMain());
        
        $js = $toolbarEl->buildJsConstructorsForLeftButtons();
        $js .= $this->buildJsTourGuideDropdown($widget, $this->getController());
        if ($addSpacer === true) {
            $js .= 'new sap.m.ToolbarSpacer(),';
        }
        $js .= $toolbarEl->buildJsConstructorsForRightButtons();
        return $js;
    }
    
    /**
     * returns javascript to close a dialog
     * 
     * @return string
     */
    public function buildJsCloseDialog(bool $checkChanges = true) : string
    {
        $checkChangesJs = $checkChanges ? 'true' : 'false';
        return $this->getController()->buildJsMethodCallFromController(self::CONTROLLER_METHOD_CLOSE_DIALOG, $this, "(new sap.ui.base.Event('navButtonPress', sap.ui.getCore().byId('{$this->getId()}'), {bCheckChanges: {$checkChangesJs}}))") . ';';
    }
    
    /**
     * Set the content height of the ObjectPageLayout to maximum if inner control has virtual scrolling
     * 
     * E.g. for Splits or any other control with `exf-section-fullheight` CSS class
     * 
     * This piece of JS code will calculate the available vertical space (by subtracting
     * any header height from the total page height) and apply it to the section marked
     * with the CSS class `exf-section-fullheight` (see buildJsObjectPageSection()).   
     * 
     * @return string
     */
    protected function buildJsObjectPageLayouHeightFix() : string
    {
        return <<<JS
        
                    var oPage = sap.ui.getCore().byId('{$this->getid()}');
                    var jqPageCont = $('#{$this->getid()}-cont');
                    var iHeightContent = jqPageCont.outerHeight();
                    var iHeightHeaderTitle = 0;
                    var iHeightHeaderDetails = 0;
                    iHeightHeaderTitle = jqPageCont.find('.sapUxAPObjectPageHeaderTitle:visible').toArray().reduce(function(iSum, oEl) {
                        return iSum + $(oEl).outerHeight();
                    }, 0);
                    iHeightHeaderDetails = jqPageCont.find('.sapUxAPObjectPageHeaderDetails:visible').toArray().reduce(function(iSum, oEl) {
                        return iSum + $(oEl).outerHeight();
                    }, 0);
                    jqPageCont.find('.exf-section-fullheight').each(function(){
                        var sId = $(this).attr('id');
                        var oPanel;
                        if (! sId) return;
                        oPanel = sap.ui.getCore().byId(sId);
                        if (! oPanel) return;
                        // After collapsing and expanding the header again both keep their height for some reason
                        if (iHeightHeaderDetails === iHeightHeaderTitle) {
                            iHeightHeaderTitle = 0;
                        }
                        oPanel.setHeight((iHeightContent - iHeightHeaderTitle - iHeightHeaderDetails) + 'px');
                    });
                    {$this->getOnResizeScript()}
JS;
    }
    
    /**
     *
     * @return string
     */
    protected function buildJsLayoutFormFixes() : string
    {
        $fixContainerQueryJs = <<<JS
        
                    var oGrid = sap.ui.getCore().byId($("#{$this->getId()}-scrollCont > .sapUiForm > .sapUiFormResGrid > .sapUiRGLContainer > .sapUiRGLContainerCont > .sapUiRespGrid").attr("id"));
                    if (oGrid !== undefined) {
                        oGrid.setContainerQuery(false);
                    }
                    
JS;
        $this->addPseudoEventHandler('onAfterRendering', $fixContainerQueryJs);
        
        return '';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5Container::hasButtonBack()
     */
    public function hasButtonBack() : bool
    {
        return true;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractAjaxFacade\Elements\AbstractJqueryElement::buildJsRefresh()
     */
    public function buildJsRefresh(bool $forcePrefillRefresh = false)
    { 
        $prefillJs = '';
        if ($this->needsPrefill()) {
            $prefillJs .= $this->getController()->buildJsMethodCallFromController(self::CONTROLLER_METHOD_PREFILL, $this, 'oView, ' . ($forcePrefillRefresh ? 'true' : 'false'));
        } else {
            // If no real prefill is required, still mark the prefill as pending and back again
            // to trigger listeners to prefill data changes (e.g. stuff added via `$controller->addOnPrefillDataChange()`)
            $prefillJs .= <<<JS

                var oViewModel = oView.getModel("view");
                oViewModel.setProperty('/_prefill/pending', true);
                oViewModel.setProperty('/_prefill/pending', false);
JS;
        }
        return <<<JS

                (function(oView){
                    $prefillJs;
                })({$this->getController()->getView()->buildJsViewGetter($this)});
JS;
    }
    
    /**
     * Returns the id of the sap.uxap.ObjectPageLayout used for dialogs with tabs
     * 
     * NOTE: This method just generates the id and will return it even if the ObjectPageLayout is not used.
     * To find out, if the ObjectPageLayout is used, refer to `ìsObjectPageLayout()`
     * 
     * @return string
     */
    public function getIdOfObjectPageLayout() : string
    {
        return $this->getId() . '_opl';
    }
    
    /**
     * 
     * @param string $scriptJs
     * @return string
     */
    protected function buildJsRegisterOnActionPerformed(string $scriptJs, bool $doNotCallOnUnhandledChanges = true) : string
    {
        if ($this->needsPrefill() === false) {
            return '';
        }
        return parent::buildJsRegisterOnActionPerformed($scriptJs);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5Form::buildJsFloatingToolbar()
     */
    protected function buildJsFloatingToolbar()
    {
        // The Dialog does not need a caption in the toolbar like the Form does
        return $this->getFacade()->getElement($this->getWidget()->getToolbarMain())->buildJsConstructor();
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::buildJsResetter()
     */
    public function buildJsResetter() : string
    {
        return $this->getController()->getView()->buildJsViewGetter($this) 
            . ".getModel().setData({});";
    }

    /**
     * {@inheritDoc}
     * @see UI5Form::buildJsChangesGetter()
     */
    public function buildJsChangesGetter(bool $onlyVisible = false) : string
    {
        // Since getting visible changes will be needed on multiple locations in the dialog, put the typically
        // large logic in a controller method to avoid replication of JS code.
        // TODO perhaps we should put change getter for all containers into controller methods?
        if ($onlyVisible === true) {
            return $this->getController()->buildJsMethodCallFromController(self::CONTROLLER_METHOD_GET_VISIBLE_CHANGES, $this, '');
        }
        return parent::buildJsChangesGetter($onlyVisible);
    }
}