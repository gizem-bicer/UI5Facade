<?php
namespace exface\UI5Facade\Facades\Elements;

use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\WidgetVisibilityDataType;
use exface\Core\Interfaces\Widgets\iShowData;
use exface\Core\Interfaces\Widgets\iUseData;
use exface\Core\Widgets\Dashboard;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Widgets\Card;
use exface\Core\Widgets\Tile;
use exface\UI5Facade\Facades\Elements\UI5DynamicPage;

/**
 * A `Dashboard` is a Widget to display multiple Widgets in an grid-like layout, to let the user have an
 * easy and instant overview about certain data.
 * 
 * This `Dashboard` in UI5 consists of `sap.f.GridContainer`'s, which align the child widgets 
 * in a grid. There might be used multiple instances of `sap.f.Gridcontainer` for one `Dashboard` to support
 * not only the use of units like '%' or 'px' as width parameters, but also the use of an `integer` to define the
 * number of columns one Widget uses.
 * 
 * The children Widgets itself are wrapped in instances of `UI5Card`es.
 * 
 * The `GridContainer` always has the parameters `allowDenseFill` and `snapToRow` set to `true`
 * to automatically optimize the alignment of the Cards. Furthermore the `GridContainer` may use 
 * the css-class `dashboard_gridcontainer_layout`, which sets the minimum and maximum width
 * of its child elements, if the size of those elements is only given in non-percent-values.
 * 
 * @author tmc
 *
 * @method Dashboard getWidget()
 */
class UI5Dashboard extends UI5Container
{
    /**
     * 
     * @var WidgetInterface[][]
     */
    private $widgetsAssignedToContainers = [];

    protected function init()
    {
        parent::init();
        $this->getWidget()->registerFilterLinks();
    }
    
    /**
     * 
     * @param string $oControllerJs
     * @return string
     */
    public function buildJsConstructor($oControllerJs = 'oController') : string
    {   
        $widget = $this->getWidget();

        if ($widget->getLayoutType() === Dashboard::LAYOUT_SPLIT) {
            $splitWidget = $widget->getWidgetFirst();
            $splitWidget->setHideCaption(true);
            // The sap.f.DynamicPage will be added automatically in buildJsPageWrapper()
            $layoutJs = parent::buildJsConstructor($oControllerJs);
        } else {
            $layoutJs = $this->buildJsLayoutConstructor($this->buildJsLayoutGridContainers($oControllerJs));
            if ($this->widgetHasTourGuide($widget) && $this->hasPageWrapper()) {
                $layoutJs = $this->buildJsPageWrapper($layoutJs);
            }
        }
        return $layoutJs;
    }

    /**
     * 
     * @return bool
     */
    protected function isWrappedInDynamicPage() : bool
    {
        return $this->getWidget()->hasFilters();
    }

    /**
     * Builds the tour menu and filter actions for the dashboard header.
     * @return string
     */
    protected function buildJsHeaderButtons() : string
    {
        $buttonsJs = $this->buildJsTourGuideDropdown($this->getWidget(), $this->getController());
        if (! $this->isWrappedInDynamicPage()) {
            return $buttonsJs;
        }
        
        $translator = $this->getWorkbench()->getCoreApp()->getTranslator();
        $buttons = [];
        $btn = WidgetFactory::createFromUxonInParent($this->getWidget()->getConfiguratorWidget()->getFilterTab(), new UxonObject([
            'widget_type' => 'Button',
            'action' => [
                'alias' => 'exface.Core.CustomFacadeScript',
                'script' => $this->buildJsResetter()
            ],
            'show_icon' => false,
            'hint' => $translator->translate('ACTION.RESETWIDGET.NAME'),
            'caption' => $translator->translate('ACTION.RESETWIDGET.NAME'),
            'visibility' => WidgetVisibilityDataType::PROMOTED
        ]));
        $buttons[] = $btn;
        $this->getFacade()->getElement($btn)->setUI5ButtonType('Transparent');
        $btn = WidgetFactory::createFromUxonInParent($this->getWidget()->getConfiguratorWidget()->getFilterTab(), new UxonObject([
            'widget_type' => 'Button',
            'action' => [
                'alias' => 'exface.Core.CustomFacadeScript',
                'script' => $this->buildJsRefresh()
            ],
            'show_icon' => false,
            'hint' => $translator->translate('ACTION.READDATA.NAME'),
            'caption' => $translator->translate('ACTION.READDATA.NAME'),
            'visibility' => WidgetVisibilityDataType::PROMOTED
        ]));
        $buttons[] = $btn;
        foreach ($buttons as $btn) {
            $buttonsJs .= $this->getFacade()->getElement($btn)->buildJsConstructor() . ',';
        }
        return $buttonsJs;
    }
    
    /**
     * 
     * @return string
     */
    public function buildJsRefresh() : string
    {
        $searchJs = '';
        foreach ($this->getDataElements() as $el) {
            $searchJs .= PHP_EOL . $el->buildJsRefresh();
        }
        return $searchJs;
    }
    
    /**
     *
     * @return string
     */
    public function buildJsResetter() : string
    {
        $resetJs = "";
        $resetJs .= $this->getConfiguratorElement()->buildJsResetter();

        // if we have tables in the dashboard, reset them too, otherwise column filters etc. would remain
        foreach ($this->getDataElements() as $el) {
            if ($el instanceof UI5DataTable) {
                $resetJs .= PHP_EOL . $el->buildJsResetter();
            }
        }

        $resetJs .= <<<JS
        
setTimeout(function() {
    {$this->buildJsRefresh()}
}(),0);
JS;
        
    return $resetJs;
    }

    protected function getDataElements() : array
    {
        $els = [];
        foreach ($this->getWidget()->getWidgetsRecursive() as $child) {
            if ($child instanceof iShowData || $child instanceof iUseData) {
                $els[] = $this->getFacade()->getElement($child);
            }
        }
        return $els;
    }
    
    protected function getConfiguratorElement() : UI5DashboardConfigurator
    {
        $configuratorWidget = $this->getWidget()->getConfiguratorWidget();
        return $this->getFacade()->getElement($configuratorWidget);
    }
    
    /**
     * This function returns the JS-code for the `Dashboard`. 
     * A dashboard is getting wrapped in a `sap.m.ScrollContainer` to allow scrolling through the elements of
     * a dashboard.
     * 
     * @param string $content
     * @return string
     */
    public function buildJsLayoutConstructor(string $content = null) : string
    {
        
        if ($this->getWidget()->getHeight()->isUndefined() === false){
            $height = "{$this->getWidget()->getHeight()->getValue()}";
        } else {
            $height = "100%";
        }
        
        if ($this->getWidget()->getWidth()->isUndefined() === false){
            $width = "{$this->getWidget()->getWidth()->getValue()}";
        } else {
            $width = "100%";
        }
        
        
        return <<<JS
        new sap.m.ScrollContainer("{$this->getId()}", {
            vertical: true,
            width: "{$width}",
            height: "{$height}",
            content: [
                {$content}
            ]
        })
JS;
    }
    
    /**
     * This function generates the JS-code for the child widgets, which are inserted into the grids.
     * This function only generates the widgets for the grid with the number passed in the parameter `$no`.
     * 
     * Generating the correct width values for the cards is a bit tricky, because this process needs to be adapted
     * to the unit the width of the element is given in. 
     *     
     * If the width is given as an integer, this number is set directly into the card's 
     * `GridContainerItemLayoutData`'s `columns` property. The width of the widget in the card itself
     * is set to 100%. 
     * 
     * If the width is given as an percentage, the `columns` of the `GridContainerItemLayoutData` are getting
     * calulated with the function `getChildrenElementWidthUnitCount()`. The width of the widget in the card
     * is then set to 100%.
     *      
     * If the width is given as a different value, like `px`, it gets an 1 for the `column` property, while
     * the width of the box itself is set to the given value.
     *      
     * @return string
     */
    protected function buildJsLayoutGridContainerItems(string $oControllerJs, array $widgets, bool $containerWidthIsInUnits) : string
    {
        $js = '';
        foreach ($widgets as $widget){
            if (! ($widget instanceof Card) && ! ($widget instanceof Tile)) {
                $card = WidgetFactory::create($this->getWidget()->getPage(), 'Card', $this->getWidget());
                $card->addWidget($widget);
                if ($widget->getHeight()->isUndefined() === false && $widget->getHeight()->isMax() === false) {
                    $card->setHeight($widget->getHeight()->getValue());
                    $widget->setHeight("100%");
                }
                if ($widget->getWidth()->isUndefined() === false && $widget->getWidth()->isMax() === false) {
                    $card->setWidth($widget->getWidth()->getValue());
                    $widget->setWidth("100%");
                }
            } else {
                $card = $widget;
            }
            
            // check whether the whith of the current set of widgets is given as destinct integer or with an unit
            if ($containerWidthIsInUnits === false){
                // calculate the count columns the box will occupy
                $widthUnits = $this->getChildrenElementWidthUnitCount($card);
            } else {
                // check if there is an facade specific value given, like 'px'
                if ($card->getWidth()->isFacadeSpecific()){
                    $widthUnits = 1;
                } elseif ($card->getWidth()->isUndefined() === false) {
                    // take the number of columns straight from the widgets width
                    $widthUnits = $card->getWidth()->getValue();
                    $card->setWidth("100%");
                } 
            }
            
            $element = $this->getFacade()->getElement($card);
            if ($widthUnits && ! ($card instanceof Tile)) {
                $element->setLayoutData("new sap.f.GridContainerItemLayoutData({columns: {$widthUnits}})");
            }
            
            $js .= ($js ? ', ' : '') . $element->buildJsConstructor($oControllerJs);
        }
        
        return $js;
    }
    
    /**
     * This function is calculating the amount of columns, the box will need to approximately match the width
     * given in its uxon. There are only 10 columns in the grid for percentage-based items, therefore the 
     * actual percantage of the width the widget will occupy on-screen will be rounded to the closest full 10%.
     * 
     * @param WidgetInterface $widget
     * @return string
     */
    protected function getChildrenElementWidthUnitCount(WidgetInterface $widget) : string
    {

        if ($widget->getWidth()->isMax() || $widget->getWidth()->isUndefined()){
            $width = "100%"; 
        } else {
            $width = $widget->getWidth()->getValue();
        }

        $widget->setWidth("100%");
                
        return round((str_replace('%', '', $width) / 10));
    }
        
    /**
     * Function for adding an widget to an specific container. This information is stored in the local varialble
     * `$widgetsAssignedToContainers`.
     * 
     * @param WidgetInterface $widget
     * @param int $no
     * @return UI5Dashboard
     */
    protected function assignWidgetToContainerNo(WidgetInterface $widget, int $no) : UI5Dashboard
    {
        $this->widgetsAssignedToContainers[$no][] = $widget;
        return $this;
    }
    
    /**
     * This Function decides which widget will get into which container and therefore into which grid.
     * There will be separate grids for widgets, whose width is given as a percantage, and anotherone for those,
     * which are given with an unit or only with an integer. The widgets are being stored in an two-dimensional 
     * array in the class variable `$widgetsAssignedToContainers`.
     * 
     * The Function will go through all widgets, putting the widgets that belong into the same grid, in one array
     * coherently. If a following item belongs into the other table form, it is stored in the next element of the
     * array. 
     * 
     * This needs to be done to support percentage based inputs, while also supporting independently growing and 
     * aligning elements. 
     * 
     * If there is an row of elements, whose with is given as percentages, and the following widgets width is
     * not declared as an percantage, the algorythm tries to put this next widget in that previous container, provided
     * that the previous row's width has not been fully utilized.
     * 
     * @return UI5Dashboard
     */
    protected function assignWidgetsToContainers() : UI5Dashboard
    {
        $childWidgets = $this->getWidget()->getWidgets();
        $counter = 0;
        $modeOfCurrentContainerIsInUnits = null;
        
        foreach ($childWidgets as $widget){
            
            $widthDim = $widget->getWidth();
            // is the width of that widget NOT percentage based?
            $widthIsInUnits = $widthDim->isRelative() || $widthDim->isFacadeSpecific();
            
            // if no width has been defined for that widget it gets the value 1
            if ($widthDim->isUndefined()){
                $widget->setWidth(1);
                $widthIsInUnits = true;    
            }
            
            // check if the newfound widget does not belong into the current grid
            if ($modeOfCurrentContainerIsInUnits !== $widthIsInUnits
                && $modeOfCurrentContainerIsInUnits !== null){ 
                
                    // if the current grid is for precantage-based width items, check if the widget is eligible for 
                    // being added to this row too, to make the row fill out the whole width of the dashboard
                    if ($modeOfCurrentContainerIsInUnits === false){
                        $remainingWidthInRow = $this->getRemainingWidthInContainerNo($counter);
                        if ($remainingWidthInRow !== ''){
                        // if there was space left in the current row: 
                            // set the width of the item
                            $widget->setWidth($remainingWidthInRow);   
                            // set the mode of the next container / grid
                            $modeOfCurrentContainerIsInUnits = $widthIsInUnits;
                            // add the item to the current list, THEN switch to next array
                            $this->assignWidgetToContainerNo($widget, $counter);
                            $counter++;
                            continue;
                        }
                    }
                    
                    //increase the counter to the next array / container for widgets
                    $counter++;
                                  
            }
            // set the mode of the current container to the one of the current widget
            $modeOfCurrentContainerIsInUnits = $widthIsInUnits;
            // add the widget to the container
            $this->assignWidgetToContainerNo($widget, $counter);
        }
        return $this;
    }
    
    /**
     * This function is responible for generating the right `sap.f.GridContainers`, depending on which form of 
     * width-unit was assigned to the widgets.
     * 
     * The grid for values given with an non-percantage-width have a CSS-class ("dashboard_gridcontainer_layout")
     * assigned, that enables easy, automatic alignment of the elements in the grid.
     * 
     * If the widths are given as an percentage, a grid with 10 equal columns is generated. 
     * 
     * There are other CSS classes assigned to the grids too, to visually hide the fact that there may be more
     * than one grid involved in the dashboard.
     * 
     * @return string
     */
    protected function buildJsLayoutGridContainers(string $oControllerJs) : string
    {
        $this->assignWidgetsToContainers();
        $js = '';
        
        foreach ($this->widgetsAssignedToContainers as $no => $widgetsArray){
            //is true if the width of the widget in this container is given in container units, px, etc. -  false if in %
            $containerWidthIsInUnits = $this->isWidgetContainerWidthInUnits($widgetsArray);
            $containerStyleClass = '';
            
            if ($containerWidthIsInUnits === true) {
                $containerStyleClass .= "dashboard_gridcontainer_layout ";
            }
            
            if ($no == 0){
                $containerStyleClass .= "sapUiTinyMarginTop ";
            }
            
            if ($this->widgetsAssignedToContainers[$no + 1] !== null){
                $containerStyleClass .= "dashboard_gridcontainer_gap_margin_bottom ";
            } else {
                $containerStyleClass .= "sapUiTinyMarginBottom ";
            }
            
            $js .= <<<JS
            new sap.f.GridContainer({
                    width: "auto",
                    allowDenseFill: true,
                    snapToRow: true,
                    layout: [
                        {
                            columnSize: "calc((100% - 9 * 10px) / 10)",
                            rowSize: "20px",
                            gap: "10px"
                        }
                    ],
                    items: [
                        {$this->buildJsLayoutGridContainerItems($oControllerJs, $widgetsArray, $containerWidthIsInUnits)}
                    ]
                }).addStyleClass("{$containerStyleClass} sapUiTinyMarginBeginEnd"),

JS;
            
        }
        
        return $js;
    }
    
    /**
     * returns true, if the container (with the number passed py the attribute) contains widgets,
     * whose width is given non-percentual
     * 
     * @param WidgetInterface[] $widgets
     * @return bool
     */
    protected function isWidgetContainerWidthInUnits(array $widgets) : bool
    {
        return $widgets[0]->getWidth()->isRelative() || $widgets[0]->getWidth()->isFacadeSpecific();
    }
    
    /**
     * This Function returns the remaining percentual width, there is left in the last row of a container. 
     * If there is no space left, the function just returns an empty string ('').
     * 
     * @param int $no
     * @return string
     */
    protected function getRemainingWidthInContainerNo(int $no) : string
    {
        $widthSum = 0;
        
        foreach ($this->widgetsAssignedToContainers[$no] as $widget){
            $width = $widget->getWidth()->getValue();
            $widthVal = str_replace('%', '', $width);
            
            // add percentages
            $widthSum += $widthVal;
            
            switch ($widthSum){
                // if the sum reaches 100, the row is full, therefore no space is left for any other widgets
                case $widthSum == 100:
                    $widthSum = 0;
                    break;
                // if the sum reaches a value over 100, the last widget would already have been on the next row,
                // therefore the following calculations need to be based on it's width
                case $widthSum > 100:
                    $widthSum = $widthVal;
            }
        }
        
        if ($widthSum == 0){
            return '';
        } else {
            return (100-$widthSum) . '%';
        }
    }

    /**
     * {@inheritDoc}
     * @see UI5Container::hasPageWrapper()
     */
    public function hasPageWrapper() : bool
    {
        return $this->isWrappedInDynamicPage()
            || parent::hasPageWrapper()
            || ($this->widgetHasTourGuide($this->getWidget()) && ! $this->getWidget()->hasParent());
    }

    protected function buildJsPageWrapper(string $contentJs, string $footerConstructor = '', string $headerContentJs = '') : string
    {
        if ($this->isWrappedInDynamicPage()) {
            $dynamicPage = new UI5DynamicPage($this->getWidget(), $this->getFacade());
            $dynamicPage->setController($this->getController());
            $dynamicPage->setContentJs($contentJs);
            $dynamicPage->setHeaderToolbarJs($this->buildJsHeaderButtons());
            $dynamicPage->setId($this->getId() . '_page');
            $dynamicPage->addOnResizeScript($this->getOnResizeScript());
            return $dynamicPage->buildJsConstructor();
        } else {
            return parent::buildJsPageWrapper($contentJs, $footerConstructor, $headerContentJs);
        }
    }
}