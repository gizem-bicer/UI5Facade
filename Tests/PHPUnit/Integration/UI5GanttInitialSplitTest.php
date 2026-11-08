<?php

namespace exface\UI5Facade\Tests\PHPUnit\Integration;

use exface\Core\CommonLogic\Workbench;
use exface\Core\CommonLogic\WidgetDimension;
use exface\Core\Widgets\Gantt;
use exface\UI5Facade\Facades\Elements\UI5Gantt;
use exface\UI5Facade\Facades\Interfaces\UI5ControllerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Executes generated split-fitting JavaScript against isolated layout fixtures.
 */
class UI5GanttInitialSplitTest extends TestCase
{
    /**
     * Generates the real split-fitting code without a metamodel or Workbench mock.
     */
    private function buildFitScript() : string
    {
        $workbench = new Workbench();
        $widget = $this->createStub(Gantt::class);
        $widget->method('getOrientation')->willReturn(Gantt::ORIENTATION_HORIZONTAL);
        $widget->method('getTableSplitSize')->willReturn(new WidgetDimension($workbench));
        $widget->method('getGanttSplitSize')->willReturn(new WidgetDimension($workbench));
        $controller = $this->createStub(UI5ControllerInterface::class);
        $controller->method('buildJsMethodCallFromController')->willReturn('oTable.sync()');
        $element = $this->getMockBuilder(UI5Gantt::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getWidget', 'getController', 'getTableRowHeightPx'])
            ->getMock();
        $element->method('getWidget')->willReturn($widget);
        $element->method('getController')->willReturn($controller);
        $element->method('getTableRowHeightPx')->willReturn(33);
        return (new ReflectionMethod(UI5Gantt::class, 'buildJsFitInitialSplitSize'))->invoke($element, 'oTable');
    }

    /**
     * Supplies unchanged layouts and changes affecting either pane.
     *
     * @return array<string,array>
     */
    public static function splitCases() : array
    {
        return [
            'unchanged split' => [600, ['50%', 'auto'], ['50%', 'auto'], 0],
            'table shrinks to content' => [200, ['50%', 'auto'], ['20.1%', 'auto'], 1],
            'table size changes' => [600, ['60%', 'auto'], ['50%', 'auto'], 1],
            'diagram changes to auto' => [600, ['50%', '40%'], ['50%', 'auto'], 1]
        ];
    }

    /**
     * Unchanged layouts do no work; changed layouts resize and sync once, even on repeated fitting.
     */
    #[DataProvider('splitCases')]
    public function testInitialSplitSkipsOnlyUnchangedLayout(
        int $columnWidth,
        array $initialSizes,
        array $expectedSizes,
        int $expectedUpdates
    ) : void {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            self::markTestSkipped('Node.js is required to execute generated JavaScript.');
        }
        $script = <<<'JS'

let iResizes = 0;
let iSyncs = 0;
let iWrites = 0;
const aFrames = [];
const aLayouts = aSizes.map(sSize => ({
    size: sSize,
    /** Returns the current pane size. */
    getSize() { return this.size; },
    /** Records layout writes and checks that invalidation remains suppressed. */
    setProperty(sName, sValue, bSuppressInvalidation) {
        if (sName !== 'size' || bSuppressInvalidation !== true) {
            throw new Error('Unexpected layout write');
        }
        iWrites++;
        this.size = sValue;
    }
}));
const oSplitter = {
    /** Exposes a fixed-width splitter viewport. */
    getDomRef(sPart) { return sPart ? {offsetWidth: 0} : {clientWidth: 1000}; },
    /** Exposes the two pane layouts. */
    getContentAreas() { return aLayouts.map(oLayout => ({getLayoutData: () => oLayout})); },
    /** Records actual splitter resizing. */
    triggerResize() { iResizes++; }
};
const oColumn = {
    /** Exposes a visible column. */
    getVisible() { return true; },
    /** Exposes its rendered width. */
    getDomRef() { return {getBoundingClientRect: () => ({width: iColumnWidth})}; },
    /** Exposes the optimizer's assigned width. */
    getWidth() { return iColumnWidth + 'px'; }
};
const oTable = {
    _exfGanttColumnsSized: true,
    gantt: {},
    /** Exposes the owning splitter. */
    getParent() { return oSplitter; },
    /** Exposes table dimensions without borders. */
    getDomRef() {
        return {offsetWidth: 500, clientWidth: 500, getBoundingClientRect: () => ({width: 500})};
    },
    /** Exposes the optimized table column. */
    getColumns() { return [oColumn]; },
    /** Supplies zero-width row-header and scrollbar chrome. */
    $() { return {find: () => ({first: () => ({outerWidth: () => 0})})}; },
    /** Records Gantt synchronization. */
    sync() { iSyncs++; }
};
/** Queues callbacks so the generated code's scheduling is exercised. */
global.requestAnimationFrame = function(fnCallback) { aFrames.push(fnCallback); };
fnFit(oTable);
while (aFrames.length) { aFrames.shift()(); }
fnFit(oTable);
while (aFrames.length) { aFrames.shift()(); }
console.log(JSON.stringify({
    applied: oTable._exfGanttInitialSplitSizeApplied,
    sizes: aLayouts.map(oLayout => oLayout.size),
    resizes: iResizes,
    syncs: iSyncs,
    writes: iWrites
}));
JS;
        $process = new Process([$node, '-e',
            'const fnFit = new Function("oTable", ' . json_encode($this->buildFitScript(), JSON_THROW_ON_ERROR) . ');'
            . 'const iColumnWidth = ' . $columnWidth . ';'
            . 'const aSizes = ' . json_encode($initialSizes, JSON_THROW_ON_ERROR) . ';'
            . $script
        ]);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame([
            'applied' => true,
            'sizes' => $expectedSizes,
            'resizes' => $expectedUpdates,
            'syncs' => $expectedUpdates,
            'writes' => $expectedUpdates * 2
        ], json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR));
    }
}