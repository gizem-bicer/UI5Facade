# Copilot Instructions for the UI5 Facade

This package contains the `exface.UI5Facade` app, which adds a UI facade rendering SAP OpenUI5 apps from widget models of the ExFace no-code platform for business web apps.

## The platform

The platform core is available in `vendor/exface/core`. All [core instructions](../../../exface/core/.github/copilot-instructions.md) apply to this package as well! Read the intro to [UI facades](../../../exface/core/Docs/developer_docs/Facades/UI_facades/index.md) for global principles.

Always follow our [PHP](../../../exface/Core/Docs/developer_docs/code_conventions/PHP/Conventions.md) and [JS](../../../exface/core/Docs/developer_docs/code_conventions/JavaScript/Conventions.md) guidelines when writing code. 

## Testing

Follow the [Core testing instructions](../../../exface/core/.github/instructions/testing.instructions.md)
when implementing or fixing PHP behavior or adding tests. Add or update focused
PHPUnit coverage wherever this does not require mocking Workbench.

Reuse this app's existing unit and integration suites, bootstrap and fixtures.
Keep PHPUnit tests under `Tests/PHPUnit`, alongside other test frameworks' folders.