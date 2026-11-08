<?php

namespace exface\UI5Facade\Tests\PHPUnit\Unit\Facades\Elements;

use exface\Core\CommonLogic\Workbench;
use exface\Core\CommonLogic\WidgetDimension;
use exface\Core\Widgets\Gantt;
use exface\UI5Facade\Facades\Elements\UI5Gantt;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers split-bar height generation without a facade lifecycle or metamodel.
 */
class UI5GanttSplitBarTest extends TestCase
{
    /**
     * Creates a renderer fixture with the requested split-bar visibility.
     */
    private function createElement(bool $hideSplitBar) : UI5Gantt
    {
        $workbench = new Workbench();
        $widget = $this->createStub(Gantt::class);
        $widget->method('getHeightInRows')->willReturn(1);
        $widget->method('getOrientation')->willReturn(Gantt::ORIENTATION_VERTICAL);
        $widget->method('getHideSplitBar')->willReturn($hideSplitBar);
        $widget->method('getTableSplitSize')->willReturn(new WidgetDimension($workbench));
        $widget->method('getGanttSplitSize')->willReturn(new WidgetDimension($workbench));
        $element = $this->getMockBuilder(UI5Gantt::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getWidget', 'getId', 'getTableRowHeightPx'])
            ->getMock();
        $element->method('getWidget')->willReturn($widget);
        $element->method('getId')->willReturn('gantt');
        $element->method('getTableRowHeightPx')->willReturn(33);
        return $element;
    }

    /**
     * A hidden bar must not add spacing before or after splitter rendering.
     */
    public function testHiddenBarDoesNotReserveHeight() : void
    {
        $element = $this->createElement(true);
        $panelMethod = new ReflectionMethod(UI5Gantt::class, 'buildJsPanelHeight');
        $panelMethod->setAccessible(true);
        $resizeMethod = new ReflectionMethod(UI5Gantt::class, 'buildJsResizeVerticalLayout');
        $resizeMethod->setAccessible(true);

        self::assertStringContainsString(
            'iToolbarHeight + iTableAreaHeight + iGanttAreaHeight + 0',
            $panelMethod->invoke($element)
        );
        self::assertStringContainsString(
            'var iSplitterBarHeight = domSplitterBar ? domSplitterBar.offsetHeight : 0;',
            $resizeMethod->invoke($element, 'oTable')
        );
    }

    /**
     * Visible bars retain their initial height and use measured theme dimensions at runtime.
     */
    public function testVisibleBarRetainsHeight() : void
    {
        $element = $this->createElement(false);
        $panelMethod = new ReflectionMethod(UI5Gantt::class, 'buildJsPanelHeight');
        $panelMethod->setAccessible(true);
        $resizeMethod = new ReflectionMethod(UI5Gantt::class, 'buildJsResizeVerticalLayout');
        $resizeMethod->setAccessible(true);

        self::assertStringContainsString(
            'iToolbarHeight + iTableAreaHeight + iGanttAreaHeight + 16',
            $panelMethod->invoke($element)
        );
        self::assertStringContainsString(
            'var iSplitterBarHeight = domSplitterBar ? domSplitterBar.offsetHeight : 16;',
            $resizeMethod->invoke($element, 'oTable')
        );
    }
}