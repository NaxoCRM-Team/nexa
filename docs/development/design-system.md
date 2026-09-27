# Nexa Design System

Version 1.0 is implemented in `client/custom/css/nexa-design-system.css`. Product modules use semantic `--nexa-*` variables and shared classes; raw palette values stay inside the design-system and shell styles.

## Foundations

- Typography uses the system-first Nexa font stack with 12, 14, 16, 20 and 28 pixel roles.
- Spacing follows a 4, 8, 12, 16, 24 and 32 pixel scale.
- Controls are 40 pixels on desktop and 44 pixels on touch layouts.
- Borders use 4 or 6 pixel radii. Dialog elevation is reserved for overlays.
- Focus is always visible, status is never communicated by colour alone, and disabled controls remain labelled.
- Tablet begins below 1024 pixels and the mobile drawer begins below 768 pixels. Font size does not scale with viewport width.

## Components

Use `.nexa-button` and its secondary state for commands, `.nexa-field` with a visible label for inputs and selectors, `.nexa-toolbar` for grouped actions, and `.nexa-table-wrap` for responsive tables. A scrollable table wrapper must have a region label and `tabindex="0"`. Alerts use status-specific variants; empty and loading states keep the surrounding layout stable.

New operational data tables use `client/custom/src/workspace-table.js` unless an existing native record list already provides equivalent behavior. They use a constrained scroll region instead of visible page-number controls, load large datasets incrementally, support column sorting, and let users move and resize columns. Column order and widths are stored per user and table. Every header and body cell carries the same stable `data-column` key; action columns remain fixed and are not draggable.

Dialogs require an accessible name, focus containment, Escape dismissal and focus restoration. The application shell follows the same behavior for its mobile navigation drawer.

## Verification

The deterministic component, login and two-tenant shell fixtures live in `tests/browser/fixtures`. Run:

```powershell
npm install
npx playwright install chromium
npm run test:shell
```

The suite checks axe accessibility rules, keyboard drawer behavior, layout containment and nonblank screenshots at desktop, tablet and mobile sizes. CI retains its screenshots and traces for 14 days.
