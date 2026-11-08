<?php

namespace exface\UI5Facade\Tests\PHPUnit\Unit\Facades\Elements;

use exface\Core\Widgets\Gantt;
use exface\UI5Facade\Facades\Elements\UI5Gantt;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers zoom-aware scrollbar cache generation without a workbench or metamodel.
 */
class UI5GanttLayoutTest extends TestCase
{
    /**
     * Vertical resizing must invalidate stale scrollbar measurements after browser zoom.
     */
    public function testVerticalLayoutCachesScrollbarHeightForCurrentPixelRatio() : void
    {
        $widget = $this->createStub(Gantt::class);
        $widget->method('getHeightInRows')->willReturn(1);
        $widget->method('getOrientation')->willReturn(Gantt::ORIENTATION_VERTICAL);
        $element = $this->getMockBuilder(UI5Gantt::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getWidget', 'getId', 'getTableRowHeightPx'])
            ->getMock();
        $element->method('getWidget')->willReturn($widget);
        $element->method('getId')->willReturn('gantt');
        $element->method('getTableRowHeightPx')->willReturn(33);

        $method = new ReflectionMethod(UI5Gantt::class, 'buildJsResizeVerticalLayout');
        $method->setAccessible(true);
        $js = $method->invoke($element, 'oTable');

        self::assertStringContainsString('! Number.isFinite(iDefaultScrollbarHeight)', $js);
        self::assertStringContainsString(
            '|| oTable._exfDefaultScrollbarPixelRatio !== window.devicePixelRatio',
            $js
        );
        self::assertStringContainsString(
            'iDefaultScrollbarHeight = domScrollbarTest.offsetHeight - domScrollbarTest.clientHeight;',
            $js
        );
        self::assertStringContainsString(
            'oTable._exfDefaultScrollbarPixelRatio = window.devicePixelRatio;',
            $js
        );
        self::assertStringContainsString('domScrollbarTest.remove();', $js);
        self::assertStringContainsString("setProperty('size', sGanttAreaHeight, true)", $js);
    }
}