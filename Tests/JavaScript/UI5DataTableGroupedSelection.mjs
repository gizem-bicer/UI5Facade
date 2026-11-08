// Browser regression for native grouped-table header state after selection restoration.

/**
 * Fails explicitly if the live table violates the selection contract.
 */
function check(bCondition, sMessage) {
    if (!bCondition) {
        throw new Error(sMessage);
    }
}

/**
 * Waits for the normal search and deferred restoration to finish.
 */
async function reload(oTable, oSearchButton) {
    oSearchButton.firePress();
    const fStart = performance.now();
    while (oTable.getBusy()) {
        if (performance.now() - fStart > 25000) {
            throw new Error('Search did not finish within 25 seconds.');
        }
        await new Promise(fnResolve => setTimeout(fnResolve, 50));
    }
    await new Promise(fnResolve => setTimeout(fnResolve, 100));
}

/**
 * Checks partial/full selection reloads and repeated native header toggles without saving data.
 *
 * Requires an initially unselected grouped sap.ui.table.Table with at least three data rows,
 * synthetic group headers and no fixed footer rows. Clears the test selection on exit.
 */
export async function runGroupedSelectionRegression(oTable, oSearchButton, sUidCol) {
    check(!oTable.getBusy(), 'The initial table must have finished loading.');
    check(oTable.getEnableGrouping(), 'The table must have grouping enabled.');
    check(oTable.getFixedBottomRowCount() === 0, 'This browser scenario requires no footer rows.');
    check(oTable.getSelectedIndices().length === 0, 'The initial table must not have a selection.');
    const aRows = oTable.getModel().getProperty('/rows');
    const iLength = oTable.getBinding('rows').getLength();
    const aDataIndices = [];
    for (let i = 0; i < iLength; i++) {
        const oContext = oTable.getContextByIndex(i);
        if (oContext && oContext.getObject()) {
            aDataIndices.push(i);
        }
    }
    check(aDataIndices.length >= 3 && aDataIndices.length < iLength, 'Data rows and synthetic group headers are required.');
    const fnSavedIds = () => oTable.getModel('selections').getProperty('/rows')
        .map(oRow => oRow[sUidCol]).sort();
    const fnHeaderChecked = () => document.getElementById(oTable.getId() + '-selall').getAttribute('aria-checked');
    const aResults = [];
    try {
        aDataIndices.slice(0, 3).forEach(i => oTable.addSelectionInterval(i, i));
        const aPartialIds = fnSavedIds();
        await reload(oTable, oSearchButton);
        check(oTable.getSelectedIndices().length === 3, 'Partial selection must not include group headers.');
        check(JSON.stringify(fnSavedIds()) === JSON.stringify(aPartialIds), 'Partial selection must retain the same UIDs.');
        check(fnHeaderChecked() === 'false', 'Partial selection must leave the header unchecked.');
        aResults.push({phase: 'partial reload', selected: 3, uids: aPartialIds});

        oTable._getSelectionPlugin().onHeaderSelectorPress();
        check(oTable.getSelectedIndices().length === iLength, 'Native select-all must include synthetic headers.');
        await reload(oTable, oSearchButton);
        check(oTable.getSelectedIndices().length === iLength, 'Full selection must retain synthetic header indices after reload.');
        check(fnHeaderChecked() === 'true', 'The header must remain checked after reload.');
        check(JSON.stringify(fnSavedIds()) === JSON.stringify(aRows.map(oRow => oRow[sUidCol]).sort()),
            'Saved selection must contain all business rows, without group placeholders.');
        aResults.push({phase: 'full reload', selected: iLength, saved: fnSavedIds().length, checked: fnHeaderChecked()});

        for (let i = 0; i < 6; i++) {
            oTable._getSelectionPlugin().onHeaderSelectorPress();
            sap.ui.getCore().applyChanges();
            const bSelected = i % 2 !== 0;
            check(oTable.getSelectedIndices().length === (bSelected ? iLength : 0), `Header click ${i + 1}: incorrect selection.`);
            check(fnSavedIds().length === (bSelected ? aRows.length : 0), `Header click ${i + 1}: incorrect business rows.`);
            check(fnHeaderChecked() === String(bSelected), `Header click ${i + 1}: incorrect checkbox state.`);
            aResults.push({phase: `click ${i + 1}`, selected: oTable.getSelectedIndices().length, saved: fnSavedIds().length});
        }
        return aResults;
    } finally {
        if (fnHeaderChecked() !== 'true') {
            oTable._getSelectionPlugin().onHeaderSelectorPress();
        }
        oTable._getSelectionPlugin().onHeaderSelectorPress();
    }
}
