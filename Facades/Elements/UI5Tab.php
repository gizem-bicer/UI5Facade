<?php
namespace exface\UI5Facade\Facades\Elements;

use exface\Core\Widgets\Dialog;

/**
 * Renders a sap.m.IconTabFilter or a sap.uxap.ObjectPageSection for a Tab widget
 * 
 * The Tab may be represented by two different controls in UI5 depending on its position
 * in the page structure: 
 * 
 * - Dialogs with Tabs are rendered as `sap.uxap.ObjectPage` where each Tab is a `sap.uxap.ObjectPageSection`.
 * In this case, the Tab is actually rendered by the UI5Dialog class and this class only has
 * some supporting logic. This might be a little misleading, but the ObjectPageLayout is very
 * complex and was thought to be better placed in a single class.
 * - All other Tabs are rendered an `sap.m.IconTabBar` where each Tab is an `sap.m.IconTabFilter`.
 * This is done entirely by this class.
 * 
 * @method \exface\Core\Widgets\Tab getWidget()
 * 
 * @author andrej.kabachnik
 *
 */
class UI5Tab extends UI5Panel
{
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5Panel::buildJsConstructor()
     */
    public function buildJsConstructor($oControllerJs = 'oController') : string
    {
        // Since the tab is allways a child of Tabs, we don't need to check for hasPageWrapper() here
        return $this->buildJsIconTabFilter();
    }
    
    /**
     * Returns true if this Tab is rendered as an ObjectPageSection inside a Dialog
     * 
     * @return bool
     */
    public function isObjectPageSection() : bool
    {
        $tabsWidget = $this->getWidget()->getTabs();
        if ($tabsWidget->hasParent() && ($tabsWidget->getParent() instanceof Dialog)) {
            /* @var $dialogEl UI5Dialog */
            $dialogEl = $this->getFacade()->getElement($tabsWidget->getParent());
            if ($dialogEl->isObjectPageLayout() === true) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 
     * @return string
     */
    protected function buildJsIconTabFilter()
    {
        $caption = json_encode($this->getCaption());
        return <<<JS

    new sap.m.IconTabFilter("{$this->getId()}", {
        text: {$caption},
        content: [
            {$this->buildJsLayoutConstructor()}
        ]
    })
JS;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::registerConditionalProperties()
     */
    public function registerConditionalProperties() : UI5AbstractElement
    {
        parent::registerConditionalProperties();
        $contoller = $this->getController();
        
        // required_if
        if ($activeIf = $this->getWidget()->getActiveIf()) {
            $funcOnTrue = $activeIf->getFunctionOnTrue();
            $funcOnFalse = $activeIf->getFunctionOnFalse();
            $this->registerConditionalPropertyUpdaterOnLinkedElements(
                $activeIf, 
                $this->buildJsSetActive(true) . ';' . ($funcOnTrue !== null ? $this->buildJsCallFunction($funcOnTrue) : ''), 
                $this->buildJsSetActive(false) . ';' . ($funcOnFalse !== null ? $this->buildJsCallFunction($funcOnFalse) : '')
            );
            $js = $this->buildJsConditionalProperty(
                $activeIf,
                $this->buildJsSetActive(true),
                $this->buildJsSetActive(false),
                true
            );
            $contoller
            ->addOnInitScript($js)
            ->addOnPrefillDataChangedScript($js);
        }
        
        return $this;
    }
    
    /**
     *
     * @param bool $trueOrFalse
     * @return string
     */
    public function buildJsSetActive(bool $trueOrFalse) : string
    {
        if ($trueOrFalse === false) {
            return '';
        }
        $widget = $this->getWidget();
        if ($this->isObjectPageSection()) {
            $dialogEl = $this->getFacade()->getElement($widget->getTabs()->getParent());
            $js = "sap.ui.getCore().byId('{$dialogEl->getIdOfObjectPageLayout()}').setSelectedSection('{$this->getId()}')";
        } else {
            $js = "sap.ui.getCore().byId('{$this->getFacade()->getElement($widget->getTabs())->getId()}').setSelectedKey('{$widget->getTabIndex()}');";
        }
        return $js;
    }
}