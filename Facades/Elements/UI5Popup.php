<?php
namespace exface\UI5Facade\Facades\Elements;

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

/**
 * Renders a Popup widget as a sap.m.Popover
 * 
 * @method \exface\Core\Widgets\Popup getWidget()
 *        
 * @author Andrej Kabachnik
 *        
 */
class UI5Popup extends UI5Form
{
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5Form::buildJsConstructor()
     */   
    public function buildJsConstructor($oControllerJs = 'oController') : string
    {
        $widget = $this->getWidget();
        $controller = $this->getController();
        
        // Submit on enter
        $this->registerSubmitOnEnter($oControllerJs);
        
        
        // Finally, instantiate the dialog
        // Make sure to reset the contents of the popup every time it closes.
        // Otherwise values, that had bee inserted would be there again when
        // the popup is opened the next time - in particular if opened in another 
        // context, where not all widgets of the popup explicitly receive values.
        $caption = $this->getCaption();
        $icon = $widget->getIcon() ? 'icon: "' . $this->getIconSrc($widget->getIcon()) . '",' : '';
        $footer = $widget->getHideFooter() === true ? 'null' : $this->buildJsFloatingToolbar();
        return <<<JS

        new sap.m.Popover("{$this->getId()}", {
            {$icon}
            {$this->buildJsPropertyContentHeight()}
            {$this->buildJsPropertyContentWidth()}
            title: {$this->escapeString($caption)},
            showHeader: {$this->escapeBool(trim($caption) !== '' || $icon !== '')},
            placement: sap.m.PlacementType.Auto,
			content : [ {$this->buildJsLayoutConstructor()} ],
            footer: {$footer},
            afterClose: function(oEvent) {
                {$this->buildJsResetter()};
            }
		}).addStyleClass('{$this->buildCssElementClass()}')
        {$this->buildJsPseudoEventHandlers()}
JS;
    }

    /**
     * Builds a content toolbar button that maximizes this entire popup without reparenting its contents.
     *
     * Content-specific scripts run against oTable (the initiating control). Capture and restore scripts
     * share the press handler scope, allowing specialized layouts to retain their normal dimensions.
     * Closing still uses the regular popup reset; minimizing only restores geometry and modal state.
     *
     * @param string $elementId
     * @param string $buttonType
     * @param string $priorityJs
     * @param string $resizeJs
     * @param string $captureJs
     * @param string $restoreJs
     * @return string
     */
    public function buildJsFullscreenButtonForContent(
        string $elementId,
        string $buttonType,
        string $priorityJs,
        string $resizeJs,
        string $captureJs = '',
        string $restoreJs = ''
    ) : string {
        $this->registerCustomCSS(<<<CSS
            .exfPopupFullscreen.sapMPopover {
                position: fixed !important;
                top: 8px !important;
                right: 8px !important;
                bottom: 8px !important;
                left: 8px !important;
                width: auto !important;
                height: auto !important;
                max-width: none !important;
                max-height: none !important;
                margin: 0 !important;
            }
            .exfPopupFullscreen > .sapMPopoverWrapper {
                display: flex;
                flex-direction: column;
                height: 100%;
            }
            .exfPopupFullscreen > .sapMPopoverWrapper > .sapMPopoverCont {
                flex: 1 1 0;
                min-height: 0;
                width: 100% !important;
                height: auto !important;
                max-width: none !important;
                max-height: none !important;
            }
            .exfPopupFullscreen > .sapMPopoverArr {
                display: none;
            }
CSS, '_popup_fullscreen_css');

        $popoverIdJs = $this->escapeString($this->getId());
        $elementIdJs = $this->escapeString($elementId);
        $buttonIdJs = $this->escapeString($elementId . '_fullscreenButton');
        $maximizeJs = $this->escapeString($this->translate('WIDGET.CHART.FULLSCREEN_MAXIMIZE'));
        $minimizeJs = $this->escapeString($this->translate('WIDGET.CHART.FULLSCREEN_MINIMIZE'));
        $controller = $this->getController();
        $controller->addOnHideViewScript(<<<JS

                (function() {
                    var oPopover = sap.ui.getCore().byId({$popoverIdJs});
                    if (oPopover.data('popupFullscreenRestore')) {
                        oPopover.close();
                    }
                })();
JS, true);

        return <<<JS

                    new sap.m.OverflowToolbarButton({
                        id: {$buttonIdJs},
                        type: sap.m.ButtonType.{$buttonType},
                        icon: 'sap-icon://full-screen',
                        text: {$maximizeJs},
                        tooltip: {$maximizeJs},
                        layoutData: new sap.m.OverflowToolbarLayoutData({priority: {$priorityJs}}),
                        press: function(oEvent) {
                            var oButton = oEvent.getSource();
                            var oPopover = sap.ui.getCore().byId({$popoverIdJs});
                            var oTable = sap.ui.getCore().byId({$elementIdJs});
                            var fnRestore = oPopover.data('popupFullscreenRestore');
                            var fnSync = function() {
                                if (oPopover.isOpen() && oTable.getDomRef()) {
                                    {$resizeJs}
                                }
                            };
                            if (fnRestore) {
                                fnRestore();
                                return;
                            }
                            var bModal = oPopover.getModal();
                            var domPopover = oPopover.getDomRef();
                            var domContent = oPopover.getDomRef('cont');
                            var sPopoverStyle = domPopover.style.cssText;
                            var sContentStyle = domContent.style.cssText;
                            var oNormalRect = domPopover.getBoundingClientRect();
                            {$captureJs}
                            var oAnchor = oPopover.data('ganttPopupAnchor');
                            if (oAnchor && !oAnchor.fullscreenSvg) {
                                var domAnchor = oAnchor.node;
                                var oAnchorRect = domAnchor.getBoundingClientRect();
                                // Keep Gantt docking targets alive when the outer chart redraws.
                                var domAnchorSvg = document.createElementNS(domAnchor.namespaceURI, 'svg');
                                domAnchorSvg.style.cssText = 'position:fixed;left:0;top:0;width:100vw;height:100vh;pointer-events:none;';
                                domAnchorSvg.setAttribute('aria-hidden', 'true');
                                document.body.appendChild(domAnchorSvg);
                                domAnchorSvg.appendChild(domAnchor);
                                domAnchor.setAttribute('x', oAnchorRect.left);
                                domAnchor.setAttribute('y', oAnchorRect.top);
                                domAnchor.setAttribute('width', oAnchorRect.width);
                                domAnchor.setAttribute('height', oAnchorRect.height);
                                oAnchor.fullscreenSvg = domAnchorSvg;
                            }
                            fnRestore = function(oCloseEvent) {
                                oPopover.detachBeforeClose(fnRestore);
                                oPopover.data('popupFullscreenRestore', null);
                                oPopover.removeStyleClass('exfPopupFullscreen');
                                oPopover.setModal(bModal);
                                oButton.setIcon('sap-icon://full-screen');
                                oButton.setText({$maximizeJs});
                                oButton.setTooltip({$maximizeJs});
                                domPopover.style.cssText = sPopoverStyle;
                                domContent.style.cssText = sContentStyle;
                                {$restoreJs}
                                if (!oCloseEvent) {
                                    fnSync();
                                    var oRestoredRect = domPopover.getBoundingClientRect();
                                    var iLeft = Math.max(8, Math.min(oNormalRect.left, window.innerWidth - oRestoredRect.width - 8));
                                    var iTop = Math.max(8, Math.min(oNormalRect.top, window.innerHeight - oRestoredRect.height - 8));
                                    domPopover.style.right = '';
                                    domPopover.style.bottom = '';
                                    domPopover.style.left = (iLeft + (window.scrollX || 0)) + 'px';
                                    domPopover.style.top = (iTop + (window.scrollY || 0)) + 'px';
                                    // UI5 has no public reposition API; finish docking before the next paint.
                                    oPopover._repositionOffset();
                                }
                            };
                            oPopover.data('popupFullscreenRestore', fnRestore);
                            oPopover.attachBeforeClose(fnRestore);
                            oPopover.setModal(true);
                            oPopover.addStyleClass('exfPopupFullscreen');
                            oButton.setIcon('sap-icon://exit-full-screen');
                            oButton.setText({$minimizeJs});
                            oButton.setTooltip({$minimizeJs});
                            requestAnimationFrame(fnSync);
                        }
                    }),
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
                $height = '"auto"';
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
                $width = '"auto"'; // This is the size of a P13nDialog used for data configurator
        }
        
        return $width ? 'contentWidth: ' . $width . ',' : '';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractAjaxFacade\Elements\AbstractJqueryElement::getCaption()
     */
    protected function getCaption() : string
    {
        $widget = $this->getWidget();
        if ($widget->getHideCaption() === true) {
            return '';
        }
        $caption = parent::getCaption();
        $objectName = $widget->getMetaObject()->getName();
        $buttonCaption = $widget->hasParent() ? $widget->getParent()->getCaption() : null;
        
        // Append the object name to the caption unless
        // - The dialog has a custom caption (= not qual to the button caption)
        // - The caption is the same as the object name (would look stupid then)
        return $caption === $objectName || $caption !== $buttonCaption ? $caption : $caption . ': ' . $objectName;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5Form::buildCssElementClass()
     */
    public function buildCssElementClass()
    {
        return 'exf-popup' .  ($this->getWidget()->isFilledBySingleWidget() ? ' exf-dialog-filled' : '');
    }
    
    /**
     * Returns the button constructors for the dialog buttons.
     * 
     * @return string
     */
    protected function buildJsDialogButtons(bool $addSpacer = true)
    {
        $toolbarEl = $this->getFacade()->getElement($this->getWidget()->getToolbarMain());
        $js = $toolbarEl->buildJsConstructorsForLeftButtons();
        if ($addSpacer === true) {
            $js .= 'new sap.m.ToolbarSpacer(),';
        }
        $js .= $toolbarEl->buildJsConstructorsForRightButtons();
        return $js;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5Container::hasButtonBack()
     */
    public function hasButtonBack() : bool
    {
        return false;
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
     * @return string
     */
    public function buildJsCloseDialog() : string
    {
        return "sap.ui.getCore().byId('{$this->getId()}').close();";
    }

    /**
     * The validation error of a popup just highlights the bad inputs and tries to focus the first one.
     * 
     * It does not show an error message dialog because that would automatically close the popup.
     * 
     * @see \exface\Core\Facades\AbstractAjaxFacade\Elements\JqueryContainerTrait::buildJsValidationError()
     */
    public function buildJsValidationError()
    {
        foreach ($this->getWidgetsToValidate() as $child) {
            $el = $this->getFacade()->getElement($child);
            $validator = $el->buildJsValidator();
            $output .= '
				if(!' . $validator . ') { ' . $el->buildJsValidationError() . "; if (sFirstInvalidId === undefined) sFirstInvalidId = {$this->escapeString($el->getId())};}";
        }
        return <<<JS
(function(){
    var sFirstInvalidId, oFailedCtrl;
    $output;
    if (sFirstInvalidId && (oFailedCtrl = sap.ui.getCore().byId(sFirstInvalidId))) {
        if (oFailedCtrl.focus !== undefined) {
            oFailedCtrl.focus();
        }
    }
})()
JS;
    }
}