<?php

namespace exface\UI5Facade\Tests\PHPUnit\Unit\Facades\Elements;

use exface\Core\Widgets\Dashboard;
use exface\UI5Facade\Facades\Elements\UI5Dashboard;
use exface\UI5Facade\Facades\Interfaces\UI5ControllerInterface;
use exface\UI5Facade\Facades\Interfaces\UI5ViewInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests dashboard tour-menu wiring independently of the metamodel and child rendering.
 */
class UI5DashboardTourGuideTest extends TestCase
{
    /**
     * A tour on the app root requires a header even without common filters.
     */
    public function testAppRootTourRequiresPageWrapper(): void
    {
        $element = $this->createElement(false, true, false, true);
        self::assertTrue($element->hasPageWrapper());
    }

    /**
     * App-root dashboards without tours or filters keep their existing unwrapped layout.
     */
    public function testAppRootWithoutTourDoesNotRequirePageWrapper(): void
    {
        $element = $this->createElement(false, false, false, true);
        self::assertFalse($element->hasPageWrapper());
    }

    /**
     * A nested filterless dashboard does not create an extra page header for its tour.
     */
    public function testNestedTourDoesNotRequirePageWrapper(): void
    {
        $element = $this->createElement(false, true, true, true);
        self::assertFalse($element->hasPageWrapper());
    }

    /**
     * Common filters still require the dynamic-page wrapper.
     */
    public function testFiltersRequirePageWrapper(): void
    {
        $element = $this->createElement(true, false, true, true);
        self::assertTrue($element->hasPageWrapper());
    }

    /**
     * Grid dashboards route their content through the header wrapper when a tour requires it.
     */
    public function testGridTourUsesPageWrapper(): void
    {
        $element = $this->createElement(false, true, false, true, [
            'buildJsLayoutGridContainers', 'buildJsLayoutConstructor', 'buildJsPageWrapper'
        ]);
        $element->method('buildJsLayoutGridContainers')->willReturn('grid');
        $element->method('buildJsLayoutConstructor')->with('grid')->willReturn('scroll');
        $element->expects(self::once())->method('buildJsPageWrapper')->with('scroll')->willReturn('page');

        self::assertSame('page', $element->buildJsConstructor());
    }

    /**
     * Grid dashboards without a required header retain their scroll container.
     */
    public function testGridWithoutTourStaysUnwrapped(): void
    {
        $element = $this->createElement(false, false, false, true, [
            'buildJsLayoutGridContainers', 'buildJsLayoutConstructor', 'buildJsPageWrapper'
        ]);
        $element->method('buildJsLayoutGridContainers')->willReturn('grid');
        $element->method('buildJsLayoutConstructor')->with('grid')->willReturn('scroll');
        $element->expects(self::never())->method('buildJsPageWrapper');

        self::assertSame('scroll', $element->buildJsConstructor());
    }

    /**
     * A grid tour with common filters uses the dynamic-page rendering path.
     */
    public function testFilteredGridTourUsesPageWrapper(): void
    {
        $element = $this->createElement(true, true, true, true, [
            'buildJsLayoutGridContainers', 'buildJsLayoutConstructor', 'buildJsPageWrapper'
        ]);
        $element->method('buildJsLayoutGridContainers')->willReturn('grid');
        $element->method('buildJsLayoutConstructor')->with('grid')->willReturn('scroll');
        $element->expects(self::once())->method('buildJsPageWrapper')->with('scroll')->willReturn('dynamic-page');

        self::assertSame('dynamic-page', $element->buildJsConstructor());
    }

    /**
     * Grid rendering outside the app root stays unchanged when no tour is configured.
     */
    public function testNonAppRootGridWithoutTourStaysUnwrapped(): void
    {
        $element = $this->createElement(false, false, false, false, [
            'buildJsLayoutGridContainers', 'buildJsLayoutConstructor', 'buildJsPageWrapper'
        ]);
        $element->method('buildJsLayoutGridContainers')->willReturn('grid');
        $element->method('buildJsLayoutConstructor')->with('grid')->willReturn('scroll');
        $element->expects(self::never())->method('buildJsPageWrapper');

        self::assertSame('scroll', $element->buildJsConstructor());
    }

    /**
     * Header rendering passes the controller to the shared tour builder for automatic starts.
     */
    public function testHeaderBuildsTourMenuWithController(): void
    {
        $element = $this->createElement(false, true, false, true, [
            'getController', 'buildJsTourGuideDropdown'
        ]);
        $controller = $this->createMock(UI5ControllerInterface::class);
        $element->method('getController')->willReturn($controller);
        $element->expects(self::once())->method('buildJsTourGuideDropdown')
            ->with($element->getWidget(), $controller)->willReturn('tour-menu,');

        $method = new ReflectionMethod(UI5Dashboard::class, 'buildJsHeaderButtons');
        $method->setAccessible(true);
        self::assertSame('tour-menu,', $method->invoke($element));
    }

    /**
     * Creates a local rendering fixture without initializing Workbench or a facade.
     *
     * @param string[] $methods Additional rendering boundaries to replace.
     * @return UI5Dashboard&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createElement(
        bool $hasFilters,
        bool $hasTour,
        bool $hasParent,
        bool $isAppRoot,
        array $methods = []
    ): UI5Dashboard {
        $widget = $this->getMockBuilder(Dashboard::class)->disableOriginalConstructor()
            ->onlyMethods(['hasFilters', 'hasTourGuide', 'hasParent', 'getLayoutType'])->getMock();
        $widget->method('hasFilters')->willReturn($hasFilters);
        $widget->method('hasTourGuide')->willReturn($hasTour);
        $widget->method('hasParent')->willReturn($hasParent);
        $widget->method('getLayoutType')->willReturn(Dashboard::LAYOUT_GRID);

        $view = $this->createMock(UI5ViewInterface::class);
        $view->method('isWebAppRoot')->willReturn($isAppRoot);
        $element = $this->getMockBuilder(UI5Dashboard::class)->disableOriginalConstructor()
            ->onlyMethods(array_merge(['getWidget', 'getView'], $methods))->getMock();
        $element->method('getWidget')->willReturn($widget);
        $element->method('getView')->willReturn($view);
        return $element;
    }
}
