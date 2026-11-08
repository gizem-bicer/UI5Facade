// Checks synthetic group indices separately from saved business rows and real tree group headers.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';

const sScript = readFileSync(0, 'utf8');

/**
 * Models the grouped JSON binding, whose native select-all includes synthetic headers.
 */
function createTable(aPrevious, {footers = 0, tree = false, missing = false, selected = []} = {}) {
    const aRows = [undefined, {Id: 1}, undefined, {Id: 2}, {Id: 3}];
    if (footers) {
        aRows.push({Id: 99});
    }
    const oSelection = new Set(selected);
    const oTable = {
        __modifyingSelection: false,
        saved: aPrevious,
        notifications: 0,
        mutations: 0,
        getEnableGrouping: () => !tree,
        getBinding: () => ({
            getLength: () => aRows.length,
            ...(tree ? {isGroupHeader: i => i === 0 || i === 2} : {})
        }),
        getFixedBottomRowCount: () => footers,
        getContextByIndex: i => missing && i === 4 ? undefined : ({
            getObject: () => aRows[i],
            getPath: () => aRows[i] ? `/rows/${i}` : `/sap.ui.table.GroupInfo${i || ''}`
        }),
        getModel: () => ({getProperty: () => oTable.saved}),
        getSelectedIndices: () => [...oSelection].sort((a, b) => a - b),
        clearSelection() {
            this.mutations++;
            oSelection.clear();
            this.fireRowSelectionChange();
        },
        addSelectionInterval(iFrom, iTo) {
            this.mutations++;
            for (let i = iFrom; i <= iTo; i++) {
                oSelection.add(i);
            }
            this.fireRowSelectionChange();
        },
        fireRowSelectionChange() {
            if (!this.__modifyingSelection) {
                this.notifications++;
                this.saved = this.getSelectedIndices().map(i => aRows[i]).filter(Boolean);
            }
        },
        isHeaderChecked: () => oSelection.size === aRows.length - footers,
        pressHeader() {
            if (this.isHeaderChecked()) {
                this.clearSelection();
            } else {
                this.addSelectionInterval(0, aRows.length - footers - 1);
            }
        }
    };
    return oTable;
}

/**
 * Runs the actual generated restore script, including its deferred notification.
 */
function restore(oTable) {
    new Function('oTable', 'exfTools', 'setTimeout', sScript)(
        oTable,
        {data: {indexOfRow: (aRows, oRow) => aRows.findIndex(oCandidate => oCandidate.Id === oRow.Id)}},
        (fnRestore, iDelay, oArgument) => fnRestore(oArgument)
    );
}

test('full grouped selection restores the checked header and clears on the first click', () => {
    const oTable = createTable([{Id: 1}, {Id: 2}, {Id: 3}]);
    restore(oTable);
    assert.deepEqual(oTable.getSelectedIndices(), [0, 1, 2, 3, 4]);
    assert.equal(oTable.isHeaderChecked(), true);
    assert.deepEqual(oTable.saved, [{Id: 1}, {Id: 2}, {Id: 3}]);
    assert.equal(oTable.notifications, 1);
    assert.equal(oTable.mutations, 2);
    for (let i = 0; i < 6; i++) {
        oTable.pressHeader();
        assert.equal(oTable.getSelectedIndices().length, i % 2 === 0 ? 0 : 5);
        assert.equal(oTable.saved.length, i % 2 === 0 ? 0 : 3);
    }
});

test('partial grouped selection restores only the matching business rows', () => {
    const oTable = createTable([{Id: 2}, {Id: 20}]);
    restore(oTable);
    assert.deepEqual(oTable.getSelectedIndices(), [3]);
    assert.equal(oTable.isHeaderChecked(), false);
    assert.deepEqual(oTable.saved, [{Id: 2}]);
});

test('synthetic headers are included only after all selectable data rows match', () => {
    const oTable = createTable([{Id: 1}, {Id: 2}, {Id: 3}, {Id: 99}], {footers: 1});
    restore(oTable);
    assert.deepEqual(oTable.getSelectedIndices(), [0, 1, 2, 3, 4]);
    assert.equal(oTable.isHeaderChecked(), true);
    assert.deepEqual(oTable.saved, [{Id: 1}, {Id: 2}, {Id: 3}]);
});

test('tree binding group headers remain excluded', () => {
    const oTable = createTable([{Id: 1}, {Id: 2}, {Id: 3}], {tree: true});
    restore(oTable);
    assert.deepEqual(oTable.getSelectedIndices(), [1, 3, 4]);
    assert.deepEqual(oTable.saved, [{Id: 1}, {Id: 2}, {Id: 3}]);
});

test('empty saved selection never selects synthetic group headers', () => {
    const oTable = createTable([]);
    restore(oTable);
    assert.deepEqual(oTable.getSelectedIndices(), []);
    assert.equal(oTable.notifications, 0);
});

test('unresolved data contexts do not become selected group headers', () => {
    const oTable = createTable([{Id: 1}, {Id: 2}, {Id: 3}], {missing: true});
    restore(oTable);
    assert.deepEqual(oTable.getSelectedIndices(), [1, 3]);
    assert.equal(oTable.isHeaderChecked(), false);
});

test('an unchanged full grouped selection does not trigger extra mutations', () => {
    const oTable = createTable([{Id: 1}, {Id: 2}, {Id: 3}], {selected: [0, 1, 2, 3, 4]});
    restore(oTable);
    assert.deepEqual(oTable.getSelectedIndices(), [0, 1, 2, 3, 4]);
    assert.equal(oTable.mutations, 0);
    assert.equal(oTable.notifications, 1);
});
