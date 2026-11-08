<?php

namespace exface\UI5Facade\Tests\PHPUnit\Integration\Facades\Elements;

use exface\Core\Widgets\DataColumn;
use exface\Core\Widgets\DataTable;
use exface\UI5Facade\Facades\Elements\UI5DataTable;
use exface\UI5Facade\Facades\Interfaces\UI5ControllerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Executes real selection handlers and restoration with collapsed groups.
 */
class UI5DataTableSelectionChangeTest extends TestCase
{
    /**
     * Creates code-generation fixtures without a Workbench or persisted metamodel.
     */
    private function createElement(bool $saveOnNavigation, bool $uiTable = true): UI5DataTable
    {
        $column = $this->createStub(DataColumn::class);
        $column->method('getDataColumnName')->willReturn('Id');
        $widget = $this->createStub(DataTable::class);
        $widget->method('getMultiSelect')->willReturn(true);
        $widget->method('hasUidColumn')->willReturn(true);
        $widget->method('getUidColumn')->willReturn($column);
        $widget->method('isMultiSelectSavedOnNavigation')->willReturn($saveOnNavigation);
        $controller = $this->createStub(UI5ControllerInterface::class);
        $controller->method('buildJsEventHandler')->willReturn('oTable.changeNotifications++');
        $element = $this->getMockBuilder(UI5DataTable::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getWidget', 'getController', 'getId', 'isUiTable', 'isMList'])
            ->getMock();
        $element->method('getWidget')->willReturn($widget);
        $element->method('getController')->willReturn($controller);
        $element->method('getId')->willReturn('selection-table');
        $element->method('isUiTable')->willReturn($uiTable);
        $element->method('isMList')->willReturn(!$uiTable);
        return $element;
    }

    /**
     * Runs the generated handlers and grouped restoration against native event boundaries.
     */
    public function testGeneratedSelectionChangeScripts(): void
    {
        $element = $this->createElement(true);
        $handler = new ReflectionMethod(UI5DataTable::class, 'buildJsPropertySelectionChange');
        $restore = new ReflectionMethod(UI5DataTable::class, 'buildJsDataLoaderOnLoadedRestoreSelection');
        $scripts = [
            'changeSaved' => $handler->invoke($element, 'oEvent'),
            'changeUnsaved' => $handler->invoke($this->createElement(false), 'oEvent'),
            'changeResponsive' => $handler->invoke($this->createElement(true, false), 'oEvent'),
            'restore' => $restore->invoke($element, 'oTable')
        ];
        $process = proc_open(
            ['node', '--test-reporter=tap', dirname(__DIR__, 3) . '/Support/UI5DataTableSelectionChange.mjs'],
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
