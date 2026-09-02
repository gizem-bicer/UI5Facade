<?php
namespace exface\UI5Facade\Facades\Elements;

use exface\Core\Facades\AbstractAjaxFacade\Elements\JqueryContainerTrait;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Interfaces\Widgets\iTakeInput;

/**
 * Generates OpenUI5 inputs
 *
 * @author Andrej Kabachnik
 *        
 */
class UI5InlineGroup extends UI5Value
{
    use JqueryContainerTrait;
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::buildJsConstructor()
     */
    public function buildJsConstructor($oControllerJs = 'oController') : string
    {
        return $this->buildJsLabelWrapper($this->buildJsConstructorForMainControl($oControllerJs) . $this->buildJsAddCssWidgetClasses());
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5Value::buildJsConstructorForMainControl()
     */
    public function buildJsConstructorForMainControl($oControllerJs = 'oController')
    {
        return <<<JS
        new sap.m.HBox("{$this->getId()}", {
            alignItems: "Center",
            items: [
                {$this->buildJsChildrenConstructors()}
            ]
        })
        .addStyleClass('{$this->buildCssElementClass()}')
        {$this->buildJsPseudoEventHandlers()}
JS;
    }
    
    /**
     *
     * @return string
     */
    public function buildJsChildrenConstructors() : string
    {
        $js = '';
        $widget = $this->getWidget();
        $separatorWidgets = $widget->getSeparatorWidgets();
        $stretch = $widget->isStretched();
        // DO NOT use foreach() here - see UI5Container::buildJsChildrenConstructors() for details
        for ($i = 0; $i < $widget->countWidgets(); $i++) {
            $child = $widget->getWidget($i);
            $element = $this->getFacade()->getElement($child);
            if (in_array($child, $separatorWidgets, true) === true) {
                $element->setAlignment("sap.ui.core.TextAlign.Center");
            }

            $element->setLayoutData($this->buildJsChildLayoutConstructor($child, $stretch));

            $child->setWidth('100%');

            $js .= ($js ? ",\n" : '') . $element->buildJsConstructor();
        }
        
        return $js;
    }
    
    /**
     * Function for setting the layoutparameters of the InlineGroup's child widgets.
     * The settings a child gets are depending on the settings in the UXON.
     * The settings are applied by defining an instance of `FlexItemData` for every child widget.
     * 
     * If the width of a child is given, it recieves the following set of attributes:
     * ```
     *      growFactor: 0,
     *      baseSize: [width from UXON]
     * ```
     * If the width is not specified, the settings are as following:
     * ```
     *      growFactor: 1,
     *      baseSize: "0"  
     * ```
     * 
     * If there is no seperator specified, the constructor will append a small margin at the end of each item,
     * exept the last item in line, by setting its style class to UI5's `sapUiTinyMarginEnd`.
     * This is achieved by appending 
     * ```
     *      styleClass: "sapUiTinyMarginEnd"
     * ```
     * to the `FlexItemData`.
     * 
     * @param WidgetInterface $child
     * @return string
     */
    protected function buildJsChildLayoutConstructor(WidgetInterface $child, bool $stretch = true) : string
    {
        //if the width of a child is undefined, it gets the following 
        if ($child->getWidth()->isUndefined()){
            if ($stretch) {
                $props = "growFactor: 1, baseSize: \"0\",";
            }
        } else {
            $props = "growFactor: 0, baseSize: \"{$child->getWidth()->getValue()}\",";
        }
        if (!$this->getWidget()->hasSeperator()){
            $widgets = $this->getWidget()->getWidgets();
            if ($widgets[sizeof($widgets)-1] !== $child){
                $props .= " styleClass: \"sapUiTinyMarginEnd\"";
            }
        }
        
        $string = "new sap.m.FlexItemData({ $props })";
        return $string;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractAjaxFacade\Elements\JqueryContainerTrait::buildJsValidationError()
     */
    public function buildJsValidationError()
    {
        foreach ($this->getWidgetsToValidate() as $child) {
            $el = $this->getFacade()->getElement($child);
            $validator = $el->buildJsValidator();
            $output .= '
				if(!' . $validator . ') { ' . $el->buildJsValidationError() . '; }';
        }
        return $output;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5Value::isRequired()
     */
    protected function isRequired() : bool
    {
        foreach ($this->getWidget()->getWidgets() as $w) {
            if (($w instanceof iTakeInput) && $w->isRequired() === true) {
                return true;
            }
        }
        return false;
    }
    
    /**
     *
     * {@inheritdoc}
     * @see \exface\Core\Facades\AbstractAjaxFacade\Elements\AbstractJqueryElement::buildJsSetDisabled()
     */
    public function buildJsSetDisabled(bool $trueOrFalse) : string
    {
        $js = '';
        foreach ($this->getWidget()->getWidgets() as $child) {
            $el = $this->getFacade()->getElement($child);
            $js .= $el->buildJsSetDisabled($trueOrFalse) . ';';
        }
        return "(function(){ $js })()";
    }
}