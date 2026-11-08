// Keeps logical selection separate from binding indices removed by collapsing groups.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';

const oScripts = JSON.parse(readFileSync(0, 'utf8'));
const oTools = {
    data: {indexOfRow: (aRows, oRow, sUidCol) => aRows.findIndex(oCandidate => oCandidate[sUidCol] === oRow[sUidCol])}
};

/**
 * Executes the actual handler with native selection event parameters.
 */
function change(oTable, oParameters, sHandler = oTable.handler) {
    new Function('oEvent', 'exfTools', oScripts[sHandler])({
        getSource: () => oTable,
        getParameter: sName => oParameters[sName]
    }, oTools);
}

/**
 * Executes generated UID restoration.
 */
function run(sName, oTable) {
    new Function('oTable', 'exfTools', 'sap', 'setTimeout', oScripts[sName])(
        oTable, oTools, {ui: {getCore: () => ({byId: () => oTable})}},
        (fnRestore, iDelay, oArgument) => fnRestore(oArgument)
    );
}

/**
 * Models native clears before a grouped binding changes its visible indices.
 */
function createTable(aSelected, aPrevious = undefined, sHandler = 'changeSaved') {
    const aRows = [{Id: 1}, {Id: 2}];
    let aEntries = [undefined, aRows[0], undefined, aRows[1]];
    const oSelection = new Set(aSelected);
    const oModel = {getData: () => ({rows: aRows})};
    const oSelectedModel = {
        getProperty: () => oTable.saved,
        setProperty: (sPath, aValue) => { oTable.saved = aValue; }
    };
    const oTable = {
        handler: sHandler,
        __modifyingSelection: false,
        saved: aPrevious ?? aSelected.map(i => aEntries[i]).filter(Boolean),
        changeNotifications: 0,
        getEnableGrouping: () => sHandler !== 'changeResponsive',
        getFixedBottomRowCount: () => 0,
        getBinding: () => ({getLength: () => aEntries.length}),
        getModel: sName => sName === 'selections' ? oSelectedModel : oModel,
        getSelectedIndices: () => [...oSelection].sort((a, b) => a - b),
        getContextByIndex: i => ({getObject: () => aEntries[i]}),
        getSelectedContexts: () => oTable.getSelectedIndices().map(i => oTable.getContextByIndex(i)),
        clearSelection(bUserInteraction = false) {
            const aChangedIndices = this.getSelectedIndices();
            oSelection.clear();
            this.fireRowSelectionChange({rowIndex: -1, rowIndices: aChangedIndices, userInteraction: bUserInteraction});
        },
        addSelectionInterval(iFrom, iTo) {
            for (let i = iFrom; i <= iTo; i++) {
                oSelection.add(i);
            }
            this.fireRowSelectionChange({rowIndex: iFrom, rowIndices: [iFrom], userInteraction: false});
        },
        removeVisibleRow(i) {
            oSelection.delete(i);
            this.fireRowSelectionChange({rowIndex: i, rowIndices: [i], userInteraction: true});
        },
        fireRowSelectionChange(oParameters) {
            change(this, oParameters);
        },
        regroup(bCollapse) {
            this.clearSelection();
            aEntries = bCollapse ? [undefined, undefined, aRows[1]] : [undefined, aRows[0], undefined, aRows[1]];
            run('restore', this);
        },
        collapseAll() {
            this.clearSelection();
            aEntries = [undefined, undefined];
            run('restore', this);
        }
    };
    return oTable;
}

/**
 * Returns business identities rather than synthetic group-header indices.
 */
function ids(aRows) {
    return aRows.map(oRow => oRow.Id).sort((a, b) => a - b);
}

/**
 * Returns selected business rows reachable through the current binding.
 */
function selectedIds(oTable) {
    return ids(oTable.getSelectedIndices().map(i => oTable.getContextByIndex(i).getObject()).filter(Boolean));
}

for (const aIndices of [[1], [1, 3], [0, 1, 2, 3]]) {
    test(`collapse and expand retain the same rows for ${aIndices.length} selected indices`, () => {
        const oTable = createTable(aIndices);
        const aExpected = ids(oTable.saved);
        oTable.regroup(true);
        assert.deepEqual(ids(oTable.saved), aExpected);
        assert.deepEqual(selectedIds(oTable), aExpected.filter(i => i !== 1));
        oTable.regroup(false);
        assert.deepEqual(ids(oTable.saved), aExpected);
        assert.deepEqual(selectedIds(oTable), aExpected);
    });
}

test('changing a visible selection does not discard a selected row in a closed group', () => {
    const oTable = createTable([1, 3]);
    oTable.regroup(true);
    oTable.removeVisibleRow(2);
    assert.deepEqual(ids(oTable.saved), [1]);
    oTable.regroup(false);
    assert.deepEqual(selectedIds(oTable), [1]);
});

test('explicit header deselect-all still clears hidden rows on the current page', () => {
    const oTable = createTable([1, 3]);
    oTable.regroup(true);
    oTable.clearSelection(true);
    assert.deepEqual(oTable.saved, []);
    oTable.regroup(false);
    assert.deepEqual(selectedIds(oTable), []);
});

test('closed-group selections survive even without saved navigation selections', () => {
    const oTable = createTable([1, 3], undefined, 'changeUnsaved');
    oTable.regroup(true);
    assert.deepEqual(ids(oTable.saved), [1, 2]);
    oTable.regroup(false);
    assert.deepEqual(selectedIds(oTable), [1, 2]);
});

test('saved off-page rows survive regrouping', () => {
    const oTable = createTable([1, 3], [{Id: 1}, {Id: 2}, {Id: 99}]);
    oTable.regroup(true);
    assert.deepEqual(ids(oTable.saved), [1, 2, 99]);
    oTable.regroup(false);
    assert.deepEqual(ids(oTable.saved), [1, 2, 99]);
    assert.deepEqual(selectedIds(oTable), [1, 2]);
});

test('header deselect-all clears this page but retains selected rows on other pages', () => {
    const oTable = createTable([1, 3], [{Id: 1}, {Id: 2}, {Id: 99}]);
    oTable.regroup(true);
    oTable.clearSelection(true);
    assert.deepEqual(ids(oTable.saved), [99]);
});

test('selecting only group placeholders does not deselect saved hidden rows', () => {
    const oTable = createTable([1, 3]);
    oTable.collapseAll();
    assert.deepEqual(ids(oTable.saved), [1, 2]);
    oTable.addSelectionInterval(0, 1);
    change(oTable, {rowIndex: -1, rowIndices: [0, 1], userInteraction: true, selectAll: true});
    assert.deepEqual(ids(oTable.saved), [1, 2]);
    oTable.clearSelection(true);
    assert.deepEqual(oTable.saved, []);
});

test('empty restoration notifications retain off-page selections', () => {
    const oTable = createTable([], [{Id: 99}]);
    change(oTable, {rowIndices: [], userInteraction: false});
    assert.deepEqual(ids(oTable.saved), [99]);
    assert.equal(oTable.changeNotifications, 0);
});

test('responsive programmatic clears retain their existing protection', () => {
    const oTable = createTable([], [{Id: 1}], 'changeResponsive');
    change(oTable, {userInteraction: false});
    assert.deepEqual(ids(oTable.saved), [1]);
    assert.equal(oTable.changeNotifications, 0);
});
