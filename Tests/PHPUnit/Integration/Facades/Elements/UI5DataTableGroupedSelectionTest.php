<?php

namespace exface\UI5Facade\Tests\PHPUnit\Integration\Facades\Elements;

use exface\Core\Widgets\DataColumn;
use exface\Core\Widgets\DataTable;
use exface\UI5Facade\Facades\Elements\UI5DataTable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Exercises generated restoration against grouped UI5 selection and header semantics.
 */
class UI5DataTableGroupedSelectionTest extends TestCase
{
    /**
     * Checks full and partial grouped selections without requiring a persisted metamodel.
     */
    public function testGroupedSelectionRestoration(): void
    {
        $column = $this->createStub(DataColumn::class);
        $column->method('getDataColumnName')->willReturn('Id');
        $widget = $this->createStub(DataTable::class);
        $widget->method('getMultiSelect')->willReturn(true);
        $widget->method('hasUidColumn')->willReturn(true);
        $widget->method('getUidColumn')->willReturn($column);
        $element = $this->getMockBuilder(UI5DataTable::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getWidget', 'isUiTable'])
            ->getMock();
        $element->method('getWidget')->willReturn($widget);
        $element->method('isUiTable')->willReturn(true);
        $restore = new ReflectionMethod(UI5DataTable::class, 'buildJsDataLoaderOnLoadedRestoreSelection');
        $script = $restore->invoke($element, 'oTable');
        $process = proc_open(
            ['node', '--test-reporter=tap', dirname(__DIR__, 3) . '/Support/UI5DataTableGroupedSelection.mjs'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process, 'Node must be available for the integration suite.');
        try {
            fwrite($pipes[0], $script);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
        } finally {
            $exitCode = proc_close($process);
        }
        self::assertSame(0, $exitCode, $output . $errors);
        self::assertStringContainsString('# fail 0', $output, $output);
    }
}
