<?php

namespace exface\UI5Facade\Tests\PHPUnit\Unit\Facades\Elements;

use exface\Core\CommonLogic\Workbench;
use exface\Core\CommonLogic\WidgetDimension;
use exface\Core\Widgets\Gantt;
use exface\UI5Facade\Facades\Elements\UI5Gantt;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers independent vertical pane sizing without a facade lifecycle or metamodel.
 */
class UI5GanttVerticalSizingTest extends TestCase
{
    /**
     * Creates a local renderer fixture retaining the real height generators.
     */
    private function createElement(?string $tableSize = null) : UI5Gantt
    {
        $workbench = new Workbench();
        $widget = $this->createStub(Gantt::class);
        $widget->method('getHeightInRows')->willReturn(1);
        $widget->method('getOrientation')->willReturn(Gantt::ORIENTATION_VERTICAL);
        $widget->method('getTableSplitSize')->willReturn(new WidgetDimension($workbench, $tableSize));
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
     * Automatic panel height must sum the two pane requirements rather than doubling the taller one.
     */
    public function testAutomaticPanelHeightUsesIndependentPaneHeights() : void
    {
        $method = new ReflectionMethod(UI5Gantt::class, 'buildJsPanelHeight');
        $method->setAccessible(true);
        $js = $method->invoke($this->createElement());

        self::assertStringContainsString('iToolbarHeight + iTableAreaHeight + iGanttAreaHeight + 16', $js);
        self::assertStringNotContainsString('Math.max(iTableAreaHeight, iGanttAreaHeight)', $js);
    }

    /**
     * Explicit split dimensions must keep their existing panel height calculation.
     */
    public function testExplicitSplitRetainsPanelHeightCalculation() : void
    {
        $method = new ReflectionMethod(UI5Gantt::class, 'buildJsPanelHeight');
        $method->setAccessible(true);
        $js = $method->invoke($this->createElement('100px'));

        self::assertStringContainsString('2 * Math.max(iTableAreaHeight, iGanttAreaHeight)', $js);
    }

    /**
     * Runtime sizing must retain table scrollbar space without equalizing the two pane heights.
     */
    public function testResizeKeepsTableAndGanttRequirementsSeparate() : void
    {
        $method = new ReflectionMethod(UI5Gantt::class, 'buildJsResizeVerticalLayout');
        $method->setAccessible(true);
        $js = $method->invoke($this->createElement(), 'oTable');

        self::assertStringContainsString('iTableChromeHeight + (1 * iTableRowHeight)', $js);
        self::assertStringContainsString('iTableChromeHeight += iDefaultScrollbarHeight', $js);
        self::assertStringNotContainsString('iPaneAreaHeight', $js);
        self::assertStringContainsString("setProperty('size', sTableAreaHeight, true)", $js);
        self::assertStringContainsString("setProperty('size', sGanttAreaHeight, true)", $js);
    }
}