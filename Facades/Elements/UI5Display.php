<?php
namespace exface\UI5Facade\Facades\Elements;

use exface\Core\Facades\AbstractAjaxFacade\Formatters\JsBooleanFormatter;
use exface\UI5Facade\Facades\Interfaces\UI5BindingFormatterInterface;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\CommonLogic\Constants\Colors;
use exface\Core\Facades\AbstractAjaxFacade\Elements\JsValueScaleTrait;
use exface\Core\Interfaces\Widgets\iHaveColorScale;
use exface\UI5Facade\Facades\Interfaces\UI5ControllerInterface;
use exface\Core\DataTypes\TextDataType;
use exface\Core\Interfaces\Widgets\iCanWrapText;

/**
 * Generates sap.m.Text controls for Display widgets.
 * 
 * @method \exface\Core\Widgets\Display getWidget()
 *
 * @author Andrej Kabachnik
 *        
 */
class UI5Display extends UI5Value
{
    use JsValueScaleTrait;
    
    const ICON_YES_TABLE = "sap-icon://accept";
    const ICON_NO_TABLE = "";
    const ICON_YES_FORM = "sap-icon://message-success";
    const ICON_NO_FORM = "sap-icon://border";

    private $UI5BindingFormatter = null;
    
    private $alignmentProperty = null;
    
    private $onChangeHandlerRegistered = false;
    
    private $wrap = null;
    
    private $wrapMaxLines = null;
    
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
     * @return bool
     */
    protected function isIcon() : bool
    {
        return $this->getWidget()->getValueDataType() instanceof BooleanDataType;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5Value::buildJsConstructorForMainControl()
     */
    public function buildJsConstructorForMainControl($oControllerJs = 'oController')
    {
        // Register stuff here, that is needed for in-table rendering where buildJsConstructor()
        // is not called
        $this->registerExternalModules($this->getController());
        
        $widget = $this->getWidget();        
        $visible = '';
        if ($this->isIcon()) {
            if ($this->getWidget()->isInTable() === true) {
                $iconYes = self::ICON_YES_TABLE;
                $iconNo = self::ICON_NO_TABLE;
                $icon_width = '"100%"';
            } else {
                $iconYes = self::ICON_YES_FORM;
                $iconNo = self::ICON_NO_FORM;
                $icon_width = "'16px'";
                if ($widget->isHidden() === true) {
                    $visible = 'visible: false,';
                }
            }
            
            // Apply icon changes to formatter.
            $formatter = $this->getValueBindingFormatter()->getJsFormatter();
            if($formatter instanceof JsBooleanFormatter) {
                // the trim here is ugly but we need it, as the value is wrapped in
                // quotation marks again in the JsBooleanFormatter
                $formatter->setHtmlChecked($iconYes);
                $formatter->setHtmlUnchecked($iconNo);
            }
            
            $js = <<<JS

        new sap.ui.core.Icon("{$this->getId()}", {
            width: {$icon_width},
            {$this->buildJsPropertyTooltip()}
            {$visible}
            src: {$this->buildJsValueBinding()}
        })
        .addStyleClass('sapMText')
        {$this->buildJsPseudoEventHandlers()}

JS;
        } elseif($widget instanceof iHaveColorScale && $widget->hasColorScale()) {
            $objStatus = new UI5ObjectStatus($widget, $this->getFacade());
            $objStatus->setTitle('');
            $objStatus->setValueBindingPrefix($this->getValueBindingPrefix());
            $js = $objStatus->buildJsConstructorForMainControl($oControllerJs);
        } else {
            $js = parent::buildJsConstructorForMainControl();
        }

        // TODO #binding store values in real model
        if(! $this->isValueBoundToModel()) {
            $value = $this->escapeJsTextValue($this->getWidget()->getValue());
            $js .= <<<JS

            .setModel(function(){
                var oModel = new sap.ui.model.json.JSONModel();
                oModel.setProperty("/{$this->getWidget()->getDataColumnName()}", "{$value}");
                return oModel;
            }())
JS;
        }
        
        return $js;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5Value::buildJsValueBindingOptions()
     */
    public function buildJsValueBindingOptions()
    {
        $widget = $this->getWidget();
        // Do not use data type formatting on hidden displays (e.g. in hidden data columns). Hidden displays are often
        // used for all sorts of ids and if they are numeric, formatting might break them if digit groups is used.
        // if the Display is an Icon, we still have to use the formatter though
        if ($widget->isHidden() === true && $widget->getHiddenIf() === null && $this->isIcon() === false) {
            return '';
        }
        return $this->getValueBindingFormatter()->buildJsBindingProperties();
    }
    
    /**
     * 
     * @return UI5BindingFormatterInterface
     */
    protected function getValueBindingFormatter()
    {
        // we have to actually cache the formatter, otherwise changes to the JsFormatter inside it won't be kept
        // because getDataTypeFormatterForUI5Bindings always creates a new instance of the JsFormatter
        if ($this->UI5BindingFormatter === null || $this->UI5BindingFormatter->getDataType() !== $this->getWidget()->getValueDataType()) {
            $this->UI5BindingFormatter = $this->getFacade()->getDataTypeFormatterForUI5Bindings($this->getWidget()->getValueDataType());
        }
        return $this->UI5BindingFormatter;
    }
    
    /**
     * Sets the alignment for the content within the display: `"Begin"`, `"End"`, `"Center"`, `"Left"` or `"Right"`.
     * 
     * Accepts a JS snippet as argument: e.g. `"Begin"` or `sap.ui.core.TextAlign.Center`.
     * 
     * @param $propertyValueJs
     * @return UI5Display
     */
    public function setAlignment(string $propertyValueJs) : UI5Display
    {
        $this->alignmentProperty = $propertyValueJs;
        return $this;
    }

    /**
     * 
     * @return string
     */
    protected function buildJsPropertyAlignment() : string
    {
        return $this->alignmentProperty ? 'textAlign: ' . $this->alignmentProperty . ',' : '';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::buildJsProperties()
     */
    public function buildJsProperties()
    {
        return parent::buildJsProperties() . <<<JS
            {$this->buildJsPropertyWidth()}
            {$this->buildJsPropertyHeight()}
            {$this->buildJsPropertyAlignment()}
            {$this->buildJsPropertyWrapping()}
JS;
    }
            
    /**
     * Returns `wrapping: false/true,` with tailing comma and supporting properties like `maxLines` if needed
     * 
     * @return string
     */
    protected function buildJsPropertyWrapping()
    {
        return 'wrapping: ' . ($this->getWrapping() === true ? 'true' : 'false'). ',' . $this->buildJsPropertyMaxLines();
    }
    
    /**
     *
     * @return string
     */
    protected function buildJsPropertyMaxLines() : string
    {
        if ($this->getPropertyMaxLines() === null) {
            return '';
        }
        return "maxLines: {$this->getPropertyMaxLines()},";
    }
    
    /**
     *
     * @return int|NULL
     */
    protected function getPropertyMaxLines() : ?int
    {
        return $this->wrapMaxLines;
    }
    
    /**
     * Explicitly sets the property maxLines of UI5 controls based on sap.m.Text.
     * 
     * MUST be called before the control has been rendered!
     * 
     * Set to `0` to remove the max lines limit.
     * 
     * @param int $value
     * @return UI5Value
     */
    public function setPropertyMaxLines(int $value) : UI5Value
    {
        if ($value === 0) {
            $value = null;
        }
        $this->wrapMaxLines = $value;
        return $this;
    }
    
    /**
     * {@inheritDoc}
     * 
     * If the display is used as cell widget in a DataColumn, the tooltip will
     * contain the value instead of a description, because ui5 tables tend to
     * cut off long values on smaller screens. On the other hande, the description 
     * is already there in the column header.
     * 
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::buildJsPropertyTooltip()
     */
    protected function buildJsPropertyTooltip()
    {
        $widget = $this->getWidget();
        if ($this->getWidget()->isInTable() === true) {
            if ($this->isValueBoundToModel()) {
                $value = $this->buildJsValueBinding('formatter: function(value){return (value === null || value === undefined) ? value : value.toString();},');
            } else {
                $value = $this->buildJsValue();
            }
            
            return 'tooltip: ' . $value .',';
        }

        if ($this->isValueBoundToModel() && $this->getShowValueInTooltip() === true) {
            // If showing values from model, show the full value + description. This makes sure, the value is visible
            // entirely even if the control truncates it because it is too long
            return "tooltip: {$this->buildJsValueBinding("
                formatter: function(value){
                    var sInfo = {$this->escapeString($widget->getHideCaption() ? '' : ($widget->getHint() ? $widget->getHint() : $widget->getCaption()))};
                    var sVal = (value === null || value === undefined) ? '' : value.toString();
                    return sVal + (sInfo  !== '' && sVal !== '' ? ' - ' : '') + sInfo;
                },")},";
        } else {
            return parent::buildJsPropertyTooltip();
        }
    }
    
    protected function getShowValueInTooltip() : bool
    {
        return true;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5Value::buildJsValueSetterMethod()
     */
    public function buildJsValueSetterMethod($value)
    {
        if ($this->isIcon()) {
            if ($this->getWidget()->isInTable() === true) {
                $iconYes = self::ICON_YES_TABLE;
                $iconNo = self::ICON_NO_TABLE;
            } else {
                $iconYes = self::ICON_YES_FORM;
                $iconNo = self::ICON_NO_FORM;
            }
            return "setSrc((function(value) {
                    if (value === '1' || value === 'true' || value === 1 || value === true) return {$this->escapeString($iconYes)};
                    else return {$this->escapeString($iconNo)};
                })($value))";
        }
        return "setText({$value})";
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::buildJsValueSetter()
     */
    public function buildJsValueSetter($valueJs)
    {
        // If we are not bound to the model, we need to do the value formatting by hand here
        // TODO Actually, this is probably not a very good idea as this implies, that the
        // value getter needs to do the opposite conversion. Maybe it would be smarter to
        // always use the value binding and set that via value setter. This would cause
        // quite some refactoring though...
        // #value-binding
        if (! $this->isValueBoundToModel()) {
            $valueJs = $this->getFacade()->getDataTypeFormatter($this->getWidget()->getValueDataType())->buildJsFormatter($valueJs);
        }
        return parent::buildJsValueSetter($valueJs);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::buildJsValueGetterMethod()
     */
    public function buildJsValueGetterMethod()
    {
        return "getText()";
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::buildJsValueGetter()
     */
    public function buildJsValueGetter()
    {
        if ($this->isIcon()) {
            if ($this->getWidget()->isInTable() === true) {
                $iconYes = self::ICON_YES_TABLE;
            } else {
                $iconYes = self::ICON_YES_FORM;
            }
            return <<<JS
                (function(oIcon){
                    return (oIcon.getSrc() === {$this->escapeString($iconYes)});
                })(sap.ui.getCore().byId('{$this->getId()}'))
JS;
        }
        // always return the normalized value, therefor we have to parse it
        $rawValueGetter = parent::buildJsValueGetter();
        return $this->getFacade()->getDataTypeFormatter($this->getWidget()->getValueDataType())->buildJsFormatParser($rawValueGetter);
    }
        
    /**
     * 
     * @return string[]
     */
    protected function getColorSemanticMap() : array
    {
        $semCols = [];
        foreach (Colors::getSemanticColors() as $semCol) {
            switch ($semCol) {
                case Colors::SEMANTIC_ERROR: $ui5Color = 'Error'; break;
                case Colors::SEMANTIC_WARNING: $ui5Color = 'Warning'; break;
                case Colors::SEMANTIC_OK: $ui5Color = 'Success'; break;
                case Colors::SEMANTIC_INFO: $ui5Color = 'Information'; break;
            }
            $semCols[$semCol] = $ui5Color;
        }
        return $semCols;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::addOnChangeScript()
     */
    public function addOnChangeScript($script)
    {
        if ($this->isValueBoundToModel() && $this->onChangeHandlerRegistered === false) {
            $this->addOnBindingChangeScript($this->buildJsValueBindingPropertyName(), $this->getController()->buildJsEventHandler($this, self::EVENT_NAME_CHANGE, false));
            $this->onChangeHandlerRegistered = true;
        }
        return parent::addOnChangeScript($script);
    }
    
    protected function buildJsColorValue() : string
    {
        $widget = $this->getWidget();
        if (! ($widget instanceof iHaveColorScale && $widget->hasColorScale() !== false)) {
            return '';
        }
        
        if (! $this->isValueBoundToModel()) {
            $value = ''; // TODO
        } else {
            $semColsJs = json_encode($this->getColorSemanticMap());
            $bindingOptions = <<<JS
                formatter: function(value){
                    var sColor = {$this->buildJsScaleResolver('value', $widget->getColorScale(), $widget->isColorScaleRangeBased())};
                    var sValueColor;
                    var oCtrl = this;
                    if (sColor.startsWith('~')) {
                        var oColorScale = {$semColsJs};
                        {$this->buildJsColorCssSetter('oCtrl', 'null')}
                        return oColorScale[sColor];
                    } else if (sColor) {
                        {$this->buildJsColorCssSetter('oCtrl', 'sColor')}
                    }
                    return {$this->buildJsColorValueNoColor()};
                }
                
JS;
            $value = $this->buildJsValueBinding($bindingOptions);
        }
        return $value;
    }
    
    /**
     * 
     * @return string
     */
    protected function buildJsColorValueNoColor() : string
    {
        return 'sap.ui.core.ValueState.None';
    }
    
    /**
     * 
     * @param string $oControlJs
     * @param string $sColorJs
     * @return string
     */
    protected function buildJsColorCssSetter(string $oControlJs, string $sColorJs) : string
    {
        return "if ($sColorJs === null) { $oControlJs.$().css('color', null);} else {setTimeout(function(){ $oControlJs.$().css('color', $sColorJs); }, 0)}";
    }
    
    /**
     *
     * @return bool
     */
    protected function getWrapping() : bool
    {
        if ($this->wrap === null) {
            $widget = $this->getWidget();
            if ($widget->isInTable() && $widget->getParent() instanceof iCanWrapText) {
                return ! $widget->getParent()->getNowrap();
            }
            return ($this->getWidget()->getValueDataType() instanceof TextDataType);
        }
        return $this->wrap;
    }
    
    /**
     * 
     * @param bool $value
     * @return UI5Display
     */
    public function setWrapping(bool $value) : UI5Display
    {
        $this->wrap = $value;
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::registerExternalModules()
     */
    public function registerExternalModules(UI5ControllerInterface $controller) : UI5AbstractElement
    {
        $this->getValueBindingFormatter()->registerExternalModules($controller);
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5Value::buildJsValueBindingPropertyName()
     */
    public function buildJsValueBindingPropertyName() : string
    {
        return $this->isIcon() ? 'src' : 'text';
    }
}