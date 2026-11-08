# UI Tours

The UI5 facade supports UI tour guides. It uses the [Driver.js](https://driverjs.com/) library to render them.

## Tour guide menus

If a `tour_guide` is defined for a supported widget, a corresponding menu button lets the user select the desired tour. Its location depends on the widget and its page wrapper.

The following widgets will render tour guide menus in UI5:

- `Dashboard` will show a tour menu in its header. Both grid and split layouts support this menu. Dashboards with common filters show it beside the reset and refresh buttons; a page-root dashboard without filters shows it in the page header, including on the app's root page.
- `DataTable`, `DataList`, `DataCards`, `DataMatrix`, `DataTree`, `Gantt`, `FileList`, `PivotTable` and `Scheduler` show the menu in the dynamic-page header. This includes both pivot-table renderers. The menu is available when this header is rendered: normally for page-root data widgets or a data widget filling a dialog. Setting `hide_header` to `true` removes it; setting it to `false` enables it for embedded data widgets.
- `Dialog`, including `DataLookupDialog`, shows the menu in the page header when maximized and in the dialog's button bar when displayed as a modal dialog.
- `Split`, including `SplitHorizontal` and `SplitVertical`, and `Tabs` show the menu in the page header when they are the page-root widget, including on the app's root page.
- `Form` shows the menu in the page header when it is the page-root widget, including on the app's root page. Nested forms do not add a tour menu of their own.
- `Container`, `Panel`, `WidgetGroup`, `Pad`, `Tiles`, `NavTiles`, `Wizard`, `DataCarousel` and `WidgetCarousel` inherit the shared page-header integration. They show the menu when they are the page-root widget and render a page wrapper. Unlike `Dashboard`, `Split`, `Tabs` and `Form`, these controls do not add that wrapper on the app's root page just because a tour is configured.

These menus share the same tour driver and controller integration, including automatic starts and tours triggered by URL parameters or pending tour intents.