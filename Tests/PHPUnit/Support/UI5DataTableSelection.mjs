// Executes PHP-generated selection scripts against a stateful UI5 table boundary.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';

const oScripts = JSON.parse(readFileSync(0, 'utf8'));
const oTools = {
    data: {
        indexOfRow(aRows, oRow, sUidCol) {
            return aRows.findIndex(oCandidate => sUidCol !== null
                ? oCandidate[sUidCol] === oRow[sUidCol]
                : Object.keys(oCandidate).every(sKey => oCandidate[sKey] === oRow[sKey]));
        }
    }
};

/**
 * Models UI5 selection mutations and synchronous selection events.
 */
function createTable(aRows, aPrevious, aSelected = [], oOptions = {}) {
    const oSelection = new Set(aSelected);
    const oTable = {
        calls: {clear: 0, add: [], set: 0, events: 0, notifications: 0, contexts: 0},
        previous: aPrevious,
        __modifyingSelection: false,
        getModel: () => ({
            getProperty: () => aPrevious,
            getData: () => ({rows: aRows})
        }),
        getBinding: () => ({
            getLength: () => aRows.length,
            isGroupHeader: i => (oOptions.groups || []).includes(i),
            getContexts: i => [oTable.getContextByIndex(i)]
        }),
        getFixedBottomRowCount: () => oOptions.footers || 0,
        getContextByIndex(i) {
            this.calls.contexts++;
            return {
                getObject: () => aRows[i],
                getPath: () => oOptions.paths ? oOptions.paths[i] : `/rows/${i}`
            };
        },
        getSelectedIndices: () => [...oSelection].sort((a, b) => a - b),
        clearSelection() {
            this.calls.clear++;
            oSelection.clear();
            this.fireRowSelectionChange({});
        },
        addSelectionInterval(iFrom, iTo) {
            this.calls.add.push([iFrom, iTo]);
            for (let i = iFrom; i <= iTo; i++) {
                oSelection.add(i);
            }
            this.fireRowSelectionChange({});
        },
        removeSelectionInterval(iFrom, iTo) {
            for (let i = iFrom; i <= iTo; i++) {
                oSelection.delete(i);
            }
            this.fireRowSelectionChange({});
        },
        setSelectedIndex(i) {
            this.calls.set++;
            oSelection.clear();
            oSelection.add(i);
            this.fireRowSelectionChange({});
        },
        fireRowSelectionChange(oEvent) {
            this.calls.events++;
            if (!this.__modifyingSelection) {
                this.calls.notifications++;
                this.lastEvent = oEvent;
            }
        }
    };
    return oTable;
}

/**
 * Executes the generated script with immediate deferred restoration.
 */
function runScript(sName, oTable) {
    new Function('oTable', 'exfTools', 'setTimeout', oScripts[sName])(
        oTable, oTools, (fn, iDelay, oArgument) => fn(oArgument)
    );
}

test('all 354 rows restore in one interval and one notification', () => {
    const aRows = Array.from({length: 354}, (oUnused, i) => ({Id: i}));
    const oTable = createTable(aRows, aRows);
    runScript('restoreMulti', oTable);
    assert.equal(oTable.getSelectedIndices().length, 354);
    assert.deepEqual(oTable.calls.add, [[0, 353]]);
    assert.equal(oTable.calls.set, 0);
    assert.equal(oTable.calls.notifications, 1);
    assert.equal(oTable.calls.contexts, 354);
    assert.equal(oTable.lastEvent.userInteraction, false);
});

test('unchanged select-all does not mutate the selection', () => {
    const aRows = Array.from({length: 354}, (oUnused, i) => ({Id: i}));
    const oTable = createTable(aRows, aRows, aRows.map(oRow => oRow.Id));
    runScript('restoreMulti', oTable);
    assert.equal(oTable.calls.clear, 0);
    assert.deepEqual(oTable.calls.add, []);
    assert.equal(oTable.calls.notifications, 1);
});

test('UID matching survives reordering and changed row values', () => {
    const oTable = createTable(
        [{Id: 3, label: 'new'}, {Id: 1}, {Id: 2}],
        [{Id: 2}, {Id: 3, label: 'old'}],
        [1]
    );
    runScript('restoreMulti', oTable);
    assert.deepEqual(oTable.getSelectedIndices(), [0, 2]);
    assert.deepEqual(oTable.calls.add, [[0, 0], [2, 2]]);
    assert.equal(oTable.calls.notifications, 1);
});

test('group headers and footer rows are not selected', () => {
    const aRows = [{Id: 1}, {Id: 1}, {Id: 2}, {Id: 99}];
    const oTable = createTable(aRows, aRows, [], {groups: [0], footers: 1});
    runScript('restoreMulti', oTable);
    assert.deepEqual(oTable.getSelectedIndices(), [1, 2]);
});

test('nested tree context paths restore by row identity', () => {
    const aRows = [{Id: 1}, {Id: 2}];
    const oTable = createTable(aRows, [aRows[1]], [], {paths: ['/rows/0', '/rows/0/_children/0']});
    runScript('restoreMulti', oTable);
    assert.deepEqual(oTable.getSelectedIndices(), [1]);
});

test('off-page saved rows remain available to the selection handler', () => {
    const aPrevious = [{Id: 1}, {Id: 9}];
    const oTable = createTable([{Id: 1}], aPrevious);
    runScript('restoreMulti', oTable);
    assert.deepEqual(oTable.previous, aPrevious);
    assert.deepEqual(oTable.getSelectedIndices(), [0]);
});

test('undefined or empty selection does not select rows', () => {
    for (const aPrevious of [undefined, []]) {
        const oTable = createTable([{Id: 1}], aPrevious);
        runScript('restoreMulti', oTable);
        assert.deepEqual(oTable.getSelectedIndices(), []);
        assert.equal(oTable.calls.notifications, 0);
    }
});

test('selection without a UID uses row values', () => {
    const oTable = createTable([{name: 'first'}, {name: 'second'}], [{name: 'second'}]);
    runScript('restoreNoUid', oTable);
    assert.deepEqual(oTable.getSelectedIndices(), [1]);
});

test('selection guard is restored when UI5 throws', () => {
    const oTable = createTable([{Id: 1}], [{Id: 1}]);
    oTable.addSelectionInterval = () => { throw new Error('selection failed'); };
    assert.throws(() => runScript('restoreMulti', oTable), /selection failed/);
    assert.equal(oTable.__modifyingSelection, false);
    assert.equal(oTable.calls.notifications, 0);
});

test('multi-select adds or removes only the requested row', () => {
    const oTable = createTable([{Id: 1}, {Id: 2}, {Id: 3}], [], [0, 2]);
    runScript('selectMulti', oTable);
    assert.deepEqual(oTable.getSelectedIndices(), [0, 1, 2]);
    assert.equal(oTable.calls.set, 0);
    assert.deepEqual(oTable.calls.add, [[1, 1]]);
    runScript('selectMulti', oTable);
    assert.equal(oTable.calls.add.length, 1);
    runScript('deselectMulti', oTable);
    assert.deepEqual(oTable.getSelectedIndices(), [0, 2]);
});

test('single-select replaces the previous selection', () => {
    const oTable = createTable([{Id: 1}, {Id: 2}], [], [0]);
    runScript('selectSingle', oTable);
    assert.deepEqual(oTable.getSelectedIndices(), [1]);
    assert.equal(oTable.calls.set, 1);
    assert.deepEqual(oTable.calls.add, []);
});

test('model indices are translated to grouped table indices', () => {
    const oTable = createTable([{Id: 0}, {Id: 1}, {Id: 2}], [], [1], {
        groups: [0], paths: ['/groups/0', '/rows/0', '/rows/1']
    });
    runScript('selectMulti', oTable);
    assert.deepEqual(oTable.getSelectedIndices(), [1, 2]);
});
