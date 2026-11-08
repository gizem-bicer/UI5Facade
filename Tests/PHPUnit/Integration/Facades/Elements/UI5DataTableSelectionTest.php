<?php

namespace exface\UI5Facade\Tests\PHPUnit\Integration\Facades\Elements;

use exface\Core\Widgets\DataColumn;
use exface\Core\Widgets\DataTable;
use exface\UI5Facade\Facades\Elements\UI5DataTable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Executes the generated selection scripts in Node without a Workbench or metamodel.
 */
class UI5DataTableSelectionTest extends TestCase
{
    /**
     * Creates a code-generation fixture without initializing the application lifecycle.
     */
    private function createElement(bool $multiSelect, bool $hasUid = true): UI5DataTable
    {
        $column = $this->createStub(DataColumn::class);
        $column->method('getDataColumnName')->willReturn('Id');
        $widget = $this->createStub(DataTable::class);
        $widget->method('getMultiSelect')->willReturn($multiSelect);
        $widget->method('hasUidColumn')->willReturn($hasUid);
        $widget->method('getUidColumn')->willReturn($column);
        $element = $this->getMockBuilder(UI5DataTable::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getWidget', 'isUiTable', 'isMList'])
            ->getMock();
        $element->method('getWidget')->willReturn($widget);
        $element->method('isUiTable')->willReturn(true);
        $element->method('isMList')->willReturn(false);
        return $element;
    }

    /**
     * Covers selection restoration, grouping, footers and selection mutations in real JavaScript.
     */
    public function testGeneratedSelectionScripts(): void
    {
        $multi = $this->createElement(true);
        $single = $this->createElement(false);
        $restore = new ReflectionMethod(UI5DataTable::class, 'buildJsDataLoaderOnLoadedRestoreSelection');
        $restore->setAccessible(true);
        $scripts = [
            'restoreMulti' => $restore->invoke($multi, 'oTable'),
            'restoreNoUid' => $restore->invoke($this->createElement(true, false), 'oTable'),
            'selectMulti' => $multi->buildJsSelectRowByIndex('oTable', '1', false, 'false'),
            'deselectMulti' => $multi->buildJsSelectRowByIndex('oTable', '1', true, 'false'),
            'selectSingle' => $single->buildJsSelectRowByIndex('oTable', '1', false, 'false')
        ];
        $process = proc_open(
            ['node', '--test-reporter=tap', dirname(__DIR__, 3) . '/Support/UI5DataTableSelection.mjs'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process, 'Node must be available for the integration suite.');
        try {
            fwrite($pipes[0], json_encode($scripts, JSON_THROW_ON_ERROR));
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
