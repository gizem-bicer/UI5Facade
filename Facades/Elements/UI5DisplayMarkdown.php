<?php

namespace exface\UI5Facade\Facades\Elements;

use exface\Core\Facades\AbstractAjaxFacade\Elements\ToastUIEditorTrait;
use exface\Core\Widgets\DisplayMarkdown;

/**
 * UI5 implementation of the corresponding widget.
 * 
 * @see DisplayMarkdown
 */
class UI5DisplayMarkdown extends UI5Value
{
    use ToastUIEditorTrait;

    /**
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5Text::buildJsConstructorForMainControl()
     */
    public function buildJsConstructorForMainControl($oControllerJs = 'oController')
    {
        $this->registerExternalModules($this->getController());
        $markdownVar = $this->buildJsMarkdownVar();
        
        return <<<JS

        new sap.ui.core.HTML("{$this->getId()}", {
            content: {$this->escapeString("<div style=\"height:{$this->buildCssHeight()}\"> {$this->buildHtmlMarkdownEditor()} </div>")},
            afterRendering: function(oEvent) {
                var oHtml = sap.ui.getCore().byId('{$this->getId()}');
                var oModel = oHtml ? oHtml.getModel() : undefined;
                var sBindingPath = '{$this->getValueBindingPath()}';

                // False on the first render of this control, true on every later re-render
                // (e.g. after a tab switch), because the flag is set on the control at the end.
                var bWasInitialized = oHtml && "_toastUiBinding" in oHtml && oHtml._toastUiBinding;

                // ToastUI's DOM can be missing on first render or wiped by a re-render,
                // so the viewer is (re)created whenever its contents element is absent.
                if (($('#{$this->getId()}').find('.toastui-editor-contents').length === 0)) {
                    {$markdownVar} = {$this->buildJsMarkdownInitViewer()};

                    // A new viewer starts from the widget's initialValue, which is usually empty for
                    // data-bound widgets. Apply the current model value so already-loaded content shows up.
                    if (oModel !== undefined) {
                        var sModelVal = oModel.getProperty(sBindingPath);

                        // On first render the model may still be loading and hold "", which must not
                        // overwrite a non-empty initialValue. On re-renders, "" is the real content.
                        if (sModelVal !== undefined && (bWasInitialized || sModelVal !== '')) {
                            {$this->buildJsValueSetter("sModelVal")}
                        }
                    }
                }

                // The listener below is attached only once, so skip it on re-renders.
                if (bWasInitialized) {
                    return;
                }

                if(oModel !== undefined) {
                    // Property bindings are registered with the model, so attachChange fires on every
                    // model update. A plain sap.ui.model.Binding is never registered and would not fire.
                    var oValueBinding = oModel.bindProperty(sBindingPath);
                    
                    oValueBinding.attachChange(function(oEvent){
                        setTimeout(function(){
                            var sVal = oModel.getProperty(sBindingPath);
                            // Do not update if the model does not have this property
                            if (sVal === undefined) {
                                return;
                            }
                            {$this->buildJsValueSetter("sVal")}
                        }, 0);
                    });
                }
                
                oHtml._toastUiBinding = true;
            }
        })
JS;
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::registerExternalModules()
     */
    public function registerExternalModules(\exface\UI5Facade\Facades\Interfaces\UI5ControllerInterface $controller) : UI5AbstractElement
    {
        $controller->addExternalModule('libs.exface.toastUi', $this->getFacade()->buildUrlToSource('LIBS.TOASTUI.EDITOR.JS'), 'toastui');
        $controller->addExternalCss($this->getFacade()->buildUrlToSource('LIBS.TOASTUI.EDITOR.CSS'));

        if ($this->getWidget()->hasRenderMermaidDiagrams()) {
            $controller->addExternalModule('libs.exface.mermaid', $this->getFacade()->buildUrlToSource('LIBS.MERMAID.JS'), 'mermaid');
        }
        
        return $this;
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5Input::getHeight()
     */
    public function getHeight()
    {
        if ($this->getWidget()->getHeight()->isUndefined()) {
            return (2 * $this->getHeightRelativeUnit()) . 'px';
        }
        return parent::getHeight();
    }
}