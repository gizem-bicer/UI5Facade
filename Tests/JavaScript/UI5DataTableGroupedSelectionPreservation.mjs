// Live regression: group toggles must retain business selections and restore current binding indices.

/**
 * Fails explicitly when the live selection differs from the intended identities.
 */
function check(bCondition, sMessage) {
    if (!bCondition) {
        throw new Error(sMessage);
    }
}

/**
 * Allows deferred selection restoration and row rendering to finish.
 */
function settle() {
    return new Promise(fnResolve => setTimeout(fnResolve, 200));
}

/**
 * Exercises the application's normal search and deferred restoration.
 */
async function reload(oTable, oSearchButton) {
    oSearchButton.firePress();
    const fStart = performance.now();
    while (oTable.getBusy() || oTable.getRows().length === 0) {
        if (performance.now() - fStart > 25000) {
            throw new Error('Search did not finish within 25 seconds.');
        }
        await new Promise(fnResolve => setTimeout(fnResolve, 50));
    }
    await settle();
}

/**
 * Checks single, partial and full selection through native group toggles and search.
 *
 * Requires an initially unselected grouped table with all groups expanded, at least three
 * data rows, synthetic group headers and no fixed footer rows. Does not save business data.
 */
export async function runGroupedSelectionPreservation(oTable, oSearchButton, sUidCol) {
    check(!oTable.getBusy() && oTable.getRows().length > 0, 'Initial rows must be loaded and rendered.');
    check(oTable.getEnableGrouping(), 'The table must have grouping enabled.');
    check(oTable.getFixedBottomRowCount() === 0, 'This browser scenario requires no footer rows.');
    const fnSavedIds = () => oTable.getModel('selections').getProperty('/rows').map(oRow => oRow[sUidCol]).sort();
    const fnDataIndices = () => Array.from({length: oTable.getBinding('rows').getLength()}, (oUnused, i) => i)
        .filter(i => {
            const oContext = oTable.getContextByIndex(i);
            return oContext && oContext.getObject();
        });
    const fnIdsAt = aIndices => aIndices.map(i => oTable.getContextByIndex(i).getObject()[sUidCol]).sort();
    const fnSelectedIds = () => fnIdsAt(oTable.getSelectedIndices().filter(i => {
        const oContext = oTable.getContextByIndex(i);
        return oContext && oContext.getObject();
    }));
    const fnSame = (aLeft, aRight) => JSON.stringify(aLeft) === JSON.stringify(aRight);
    const fnCheckCollapsed = aExpected => {
        const aReachable = fnIdsAt(fnDataIndices());
        check(fnSame(fnSavedIds(), aExpected), 'Collapsing a group must retain every saved identity.');
        check(fnSame(fnSelectedIds(), aExpected.filter(mId => aReachable.includes(mId))),
            'Rows outside the collapsed group must remain visibly selected.');
    };
    const fnClear = () => {
        if (oTable.getSelectedIndices().length === 0 && fnSavedIds().length === 0) {
            return;
        }
        if (oTable.getSelectedIndices().length !== oTable.getBinding('rows').getLength()) {
            oTable._getSelectionPlugin().onHeaderSelectorPress();
        }
        oTable._getSelectionPlugin().onHeaderSelectorPress();
    };
    check(oTable.getSelectedIndices().length === 0 && fnSavedIds().length === 0, 'The initial selection must be empty.');
    const iDataRows = oTable.getModel().getProperty('/rows').length;
    check(iDataRows >= 3 && fnDataIndices().length === iDataRows, 'All data rows must initially be expanded.');
    check(oTable.getBinding('rows').getLength() > iDataRows, 'Synthetic group headers are required.');
    const aResults = [];
    try {
        for (const mCount of [1, 3, 'all']) {
            if (mCount === 'all') {
                oTable._getSelectionPlugin().onHeaderSelectorPress();
            } else {
                fnDataIndices().slice(0, mCount).forEach(i => oTable.addSelectionInterval(i, i));
            }
            const aExpected = fnSavedIds();
            oTable._experimentalGroupingCollapse(oTable.getRows()[0]);
            await settle();
            fnCheckCollapsed(aExpected);
            const oCollapsed = {saved: fnSavedIds().length, visible: fnSelectedIds().length};
            oTable._experimentalGroupingExpand(oTable.getRows()[0]);
            await settle();
            check(fnSame(fnSavedIds(), aExpected) && fnSame(fnSelectedIds(), aExpected),
                'Expanding must restore every previously selected identity.');
            oTable._experimentalGroupingCollapse(oTable.getRows()[0]);
            await settle();
            fnCheckCollapsed(aExpected);
            await reload(oTable, oSearchButton);
            check(fnSame(fnSavedIds(), aExpected) && fnSame(fnSelectedIds(), aExpected),
                'Search after collapse must restore the same selected identities.');
            aResults.push({selection: mCount, collapsed: oCollapsed, afterSearch: fnSavedIds().length});
            fnClear();
            check(fnSavedIds().length === 0 && fnSelectedIds().length === 0, 'Explicit deselect-all must still work.');
        }
        return aResults;
    } finally {
        fnClear();
    }
}
