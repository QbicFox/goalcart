# FaraCart — Replace Mission Templates with New MUI Templates

## Objective

Replace the existing FaraCart mission-template system with the six new templates defined in:

`01-FaraCart - UI Exploration Boar.html`

The HTML file will be placed directly inside the FaraCart project folder by the developer/AI agent. Treat that file as the **visual source of truth** for the new designs.

The final implementation must use the project's existing React + MUI architecture and must integrate with the existing FaraCart data, state, business logic, admin panel, and WooCommerce frontend.

Do not keep the old templates.

---

## 1. First: Inspect the Project

Before changing any code:

1. Scan the entire FaraCart project.
2. Locate the current mission-template system.
3. Identify:
   - Existing template components
   - Template registry
   - Template IDs
   - Template selection UI
   - Admin previews
   - Template persistence
   - Template-related CSS
   - Template-related types/interfaces
   - Frontend rendering logic
   - Any backend/API references to template IDs
4. Locate and read:
   - `01-FaraCart - UI Exploration Boar.html`
5. Compare the HTML designs with the current implementation.

Do not start implementation until the existing template architecture is understood.

---

# 2. New Templates

The HTML contains six relevant designs:

| New ID | User-facing name | Source concept |
|---|---|---|
| `template-1` | قالب ۱ | Concept 01 — Classic Progress Card |
| `template-2` | قالب ۲ | Concept 02 — Minimal Inline Cart Mission |
| `template-3` | قالب ۳ | Concept 03 — Circular Progress |
| `template-4` | قالب ۴ | Concept 07 — Product Recommendation + Mission |
| `template-5` | قالب ۵ | Concept 08 — Compact Floating / Sticky Mission |
| `template-6` | قالب ۶ | Concept 09 — Premium / Elegant E-commerce Style |

The only user-facing names must be:

- قالب ۱
- قالب ۲
- قالب ۳
- قالب ۴
- قالب ۵
- قالب ۶

Do not expose the English concept names in the admin UI.

---

# 3. Remove Existing Templates

Completely replace the current template system.

Remove the old templates from:

- Template registry
- Admin template selector
- Admin preview
- Frontend rendering
- Template constants/enums
- Template components
- Unused template CSS
- Old template-specific configuration
- Any other active template references

Do not merely hide the old templates.

After the migration, the user must only be able to select the six new templates.

If old template IDs are persisted in existing missions, inspect the current IDs and implement an appropriate migration/fallback mapping instead of guessing.

---

# 4. Template 1 — قالب ۱

Source:

`Concept 01 — Classic Progress Card`

Implement:

- Mission icon
- Mission label
- Mission title
- Progress percentage
- Horizontal progress bar
- Current amount
- Remaining amount
- CTA button
- Completed state
- Expired state

This should be the most general-purpose template.

The visual structure should closely follow the HTML reference.

---

# 5. Template 2 — قالب ۲

Source:

`Concept 02 — Minimal Inline Cart Mission`

Implement:

- Very compact inline layout
- Mission icon
- Mission title
- Remaining amount
- Compact progress bar
- Compact CTA
- Not-started state

This template is intended to fit naturally inside the WooCommerce cart, between cart content and totals.

Keep its vertical height small.

Do not turn it into a normal large card.

---

# 6. Template 3 — قالب ۳

Source:

`Concept 03 — Circular Progress`

Implement:

- Circular progress
- Percentage inside the circle
- Mission icon
- Mission title
- Mission description
- Current amount
- Remaining amount
- CTA
- Completed circular state

Prefer MUI `CircularProgress` instead of reproducing the raw SVG from the HTML.

Use the existing MUI theme and RTL configuration.

---

# 7. Template 4 — قالب ۴

Source:

`Concept 07 — Product Recommendation + Mission`

Implement:

- Mission progress header
- Progress bar
- Remaining amount
- Recommendation heading
- Recommended products
- Product image/icon
- Product name
- Product price
- Add-to-cart button

Do not hard-code the demonstration products from the HTML.

Use the existing FaraCart/WooCommerce recommendation data and existing add-to-cart functionality.

Do not implement a second recommendation engine.

---

# 8. Template 5 — قالب ۵

Source:

`Concept 08 — Compact Floating / Sticky Mission`

Implement:

- Compact sticky mission bar
- Dark visual style
- Mission icon
- Progress
- Remaining amount
- CTA
- Floating/badge variation where supported by the existing placement system

Preserve the compact nature of the source design.

Do not make this behave like a normal large card.

---

# 9. Template 6 — قالب ۶

Source:

`Concept 09 — Premium / Elegant E-commerce Style`

Implement:

- Premium/elegant visual language
- Gold/accent styling
- Mission title
- Mission description
- Elegant progress bar
- Current amount
- Remaining amount
- Product CTA
- Almost-completed state

Keep this template visually distinct.

Do not simplify it into a generic MUI Card.

---

# 10. HTML → MUI

The HTML reference uses Tailwind CSS, Font Awesome, custom CSS and SVG.

Do NOT copy Tailwind classes into React.

Do NOT add Tailwind as a new dependency.

Rebuild the designs using MUI.

Prefer existing MUI components such as:

- `Box`
- `Card`
- `CardContent`
- `Paper`
- `Stack`
- `Typography`
- `LinearProgress`
- `CircularProgress`
- `Button`
- `Chip`
- `Avatar`
- `Badge`
- `Divider`
- `Tooltip`

Use `sx` and the existing project theme.

Use MUI icons when equivalent icons exist.

Examples:

- Truck → `LocalShipping`
- Check → `CheckCircle`
- Clock → `AccessTime`
- Target → `GpsFixed`
- Lightbulb → `Lightbulb`
- Fire → `LocalFireDepartment`
- Chevron → `ChevronLeft`

Do not add unnecessary dependencies.

---

# 11. Preserve Existing Business Logic

Templates are presentation components.

Do not duplicate FaraCart business logic inside individual templates.

Do not independently calculate:

- Mission progress
- Current amount
- Remaining amount
- Target amount
- Cart total
- Completion
- Eligibility
- Product recommendations

Reuse the project's existing logic, hooks, selectors, services and data structures.

If the project already has a mission/template interface, extend it instead of creating a competing architecture.

---

# 12. Dynamic Data

Never hard-code demonstration values from the HTML in production rendering.

Examples of values that must remain dynamic:

- 82%
- 1,650,000 تومان
- 350,000 تومان
- 2,000,000 تومان
- Mission title
- Mission description
- Product names
- Product prices
- Product images

Use the existing FaraCart formatting utilities for prices/currency.

Do not create a second currency formatter if one already exists.

---

# 13. Mission States

Respect the existing FaraCart state model.

Where supported, templates should be able to represent:

- Not started
- In progress
- Almost completed
- Completed
- Expired

Do not force every template to display every state.

Use the visual states from the HTML where applicable.

Examples:

### In Progress
Show:
- Progress
- Remaining amount
- CTA

### Completed
Show:
- Success styling
- Check icon
- Completed message
- 100% progress

### Expired
Show:
- Muted styling
- Expired icon
- Expired label

### Almost Completed
Show:
- Strong emphasis
- Remaining amount
- Encouraging message
- CTA

---

# 14. RTL and Persian

FaraCart is a Persian/RTL product.

All templates must:

- Work correctly in RTL
- Use the existing MUI RTL theme
- Use existing project typography
- Align Persian text correctly
- Position icons correctly
- Handle progress indicators correctly
- Handle Persian numbers/currency correctly
- Work on mobile

Prefer direction-aware layout techniques.

Avoid blindly copying `left`, `right`, `margin-left`, or `margin-right` from the HTML when logical layout is more appropriate.

---

# 15. Responsive Design

Test all templates at:

- 320px
- 375px
- 430px
- Tablet
- Desktop

Do not use fixed widths that break WooCommerce layouts.

Templates may be rendered in:

- Cart
- Mini cart
- Checkout
- Product page
- Floating/sticky areas
- Other configured locations

They must adapt to their available container width.

---

# 16. Admin Template Selection

Find the existing FaraCart admin screen where the template is selected.

Replace the existing options with exactly:

- قالب ۱
- قالب ۲
- قالب ۳
- قالب ۴
- قالب ۵
- قالب ۶

The admin preview must use the actual production template components.

Do not create separate fake preview components.

Use mock data only as input to the real template component.

---

# 17. Preview Data

Create or reuse a small reusable preview/demo data layer if the project needs one.

Example preview values may include:

- 82% progress
- 2,000,000 target
- 1,650,000 current
- 350,000 remaining

These values are allowed only for admin/demo previews.

They must never become fallback production values.

---

# 18. Template Registry

Use one clean template registry.

Conceptually:

```ts
const missionTemplates = [
  {
    id: 'template-1',
    name: 'قالب ۱',
    component: Template1,
  },
  {
    id: 'template-2',
    name: 'قالب ۲',
    component: Template2,
  },
  {
    id: 'template-3',
    name: 'قالب ۳',
    component: Template3,
  },
  {
    id: 'template-4',
    name: 'قالب ۴',
    component: Template4,
  },
  {
    id: 'template-5',
    name: 'قالب ۵',
    component: Template5,
  },
  {
    id: 'template-6',
    name: 'قالب ۶',
    component: Template6,
  },
];
```

Adapt this to the existing project's architecture.

Do not create a duplicate registry if one already exists.

---

# 19. Component Architecture

Follow the existing FaraCart folder conventions.

A possible structure is:

```text
mission-templates/
├── Template1.tsx
├── Template2.tsx
├── Template3.tsx
├── Template4.tsx
├── Template5.tsx
├── Template6.tsx
├── MissionTemplateRenderer.tsx
├── templateRegistry.ts
└── previewData.ts
```

Use the actual project structure if it already has an established convention.

If several templates share UI, create small reusable components such as:

- MissionProgressBar
- MissionStatusBadge
- MissionAmountSummary
- MissionCTA
- RecommendedProductItem

Do not over-abstract the designs.

Each template must remain independently customizable.

---

# 20. Icons and Images

Replace Font Awesome icons with the project's existing icon system where possible.

For WooCommerce products:

- Use real product images.
- Use the existing WooCommerce placeholder when no image exists.
- Do not use the demonstration icons/products from the HTML in production.

---

# 21. Animations

The HTML includes:

- Progress animation
- Shimmer
- Pulse
- Unlock bounce
- Confetti
- Hover movement

Do not reproduce all animations automatically.

Use subtle animations where they improve UX.

At minimum:

- Progress changes should animate smoothly.
- Completed state can have a subtle success transition.
- Product hover should remain subtle.
- Sticky/floating elements must not become distracting.

Respect `prefers-reduced-motion`.

---

# 22. Accessibility

Ensure:

- Buttons have meaningful labels.
- Progress has accessible values/labels.
- Product images have alt text.
- Color is not the only state indicator.
- Keyboard navigation works.
- Focus states are visible.
- Contrast is sufficient.
- Decorative icons are not used as interactive controls.

---

# 23. Existing Mission Data / Persistence

Before changing IDs, inspect whether old template IDs are already stored in:

- WordPress options
- Mission metadata
- Database records
- REST/API responses
- Redux/state
- Local storage

If old IDs exist, implement a safe migration or fallback.

Do not guess old IDs.

If no persisted template IDs exist, simply replace the registry.

---

# 24. Remove Dead Code

After the new templates are implemented:

Search the project for:

- Old template IDs
- Old template names
- Old template components
- Old preview components
- Old template CSS
- Unused imports
- Old registry entries
- Dead selectors/hooks

Remove everything that is no longer required.

The old templates must not remain active or selectable.

---

# 25. Do Not Modify Unrelated Features

Do not unnecessarily change:

- Mission calculations
- Revenue calculations
- Analytics
- Recommendation algorithms
- WooCommerce hooks
- Cart calculations
- Database schema
- API contracts
- Mission creation/editing logic

Only modify them if technically required for template integration.

---

# 26. Visual Fidelity

The HTML file is the visual source of truth.

For each template compare the implementation with the corresponding HTML concept.

Pay attention to:

- Layout
- Spacing
- Card padding
- Border radius
- Typography hierarchy
- Icon sizes
- Progress thickness
- Button dimensions
- Status colors
- Alignment
- Visual density
- Responsive behavior

The mission is not to mechanically convert Tailwind to JSX.

The mission is to recreate the same design using native React + MUI patterns.

---

# 27. Important Rule

Do not do this:

```text
HTML
→ copy classes
→ convert div to Box
→ finish
```

Instead:

```text
HTML design
→ understand visual structure
→ identify reusable patterns
→ map patterns to MUI
→ connect to existing FaraCart data
→ integrate with existing architecture
→ validate visually
```

The final code should look like a properly designed React/MUI implementation.

---

# 28. Validation Checklist

## Templates

- [x] قالب ۱ exists
- [x] قالب ۲ exists
- [x] قالب ۳ exists
- [x] قالب ۴ exists
- [x] قالب ۵ exists
- [x] قالب ۶ exists

## Old Templates

- [x] Old templates removed from registry
- [x] Old templates removed from admin selector
- [x] Old previews removed
- [x] Old components removed
- [x] Old template CSS removed where unused
- [x] Old IDs are migrated/fallback handled if necessary

## MUI

- [x] All six templates use MUI
- [x] No Tailwind dependency added
- [x] No unnecessary UI dependency added
- [x] Existing MUI theme is respected

## Data

- [x] Mission data is dynamic
- [x] Progress is dynamic
- [x] Amounts are dynamic
- [x] Mission title is dynamic
- [x] Mission status is dynamic
- [x] Product recommendations are dynamic
- [x] No hard-coded demo values in production

## RTL

- [x] RTL works
- [x] Persian typography works
- [x] Currency formatting works
- [x] Icons align correctly
- [x] Progress indicators behave correctly

## Responsive

- [x] 320px
- [x] 375px
- [x] 430px
- [x] Tablet
- [x] Desktop

## States

- [x] Not started where applicable
- [x] In progress where applicable
- [x] Almost completed where applicable
- [x] Completed where applicable
- [x] Expired where applicable

## Integration

- [x] Admin selector works
- [x] Admin preview works
- [x] Template selection persists
- [x] Frontend renderer works
- [x] Existing FaraCart functionality still works

## Quality

- [x] TypeScript/build passes
- [x] ESLint passes where applicable
- [x] No unused imports
- [x] No old template references remain
- [x] No duplicate template system exists

---

# 29. Final Result

After completion, FaraCart must have exactly six selectable mission templates:

1. **قالب ۱** — Classic Progress Card
2. **قالب ۲** — Minimal Inline Cart Mission
3. **قالب ۳** — Circular Progress
4. **قالب ۴** — Product Recommendation + Mission
5. **قالب ۵** — Compact Floating / Sticky Mission
6. **قالب ۶** — Premium / Elegant E-commerce Style

The six templates must be reusable React/MUI components integrated with the existing FaraCart architecture.

The old template system must be fully replaced.

The file:

`01-FaraCart - UI Exploration Boar.html`

must be treated as the design reference, while the existing FaraCart codebase remains the source of truth for business logic, data, architecture, and integration.


# 30. Admin Dashboard — Template Preview Must Be Fully Updated

After implementing the six new templates, the FaraCart admin dashboard must be updated so that the template preview system correctly displays the new templates.

This is a required part of the task, not a separate follow-up task.

Inspect the existing admin dashboard/template-preview implementation and update it to work with the new template architecture.

Requirements:

1. The template selector must show exactly:
   - قالب ۱
   - قالب ۲
   - قالب ۳
   - قالب ۴
   - قالب ۵
   - قالب ۶

2. Selecting each template must immediately render the correct real template component in the preview.

3. The preview must use the same production React/MUI component used on the WooCommerce frontend.

4. Do NOT create a separate HTML/mock implementation for the dashboard preview.

5. Use realistic preview/demo data so that each template demonstrates its actual visual capabilities.

6. Where a template supports multiple states, the dashboard preview should allow the relevant state to be previewed if the existing admin architecture supports state previews.

7. Preview data must never affect real mission data.

8. The preview must correctly work with:
   - RTL
   - Persian text
   - Persian/selected currency formatting
   - Responsive layout
   - MUI theme
   - Template-specific appearance settings

9. When appearance settings are changed in the dashboard, the preview must update immediately without requiring a page refresh.

10. Verify that the preview does not break because of admin-specific CSS or WordPress admin styles.

11. The dashboard preview should represent the actual frontend appearance as closely as possible.

12. Do not duplicate template styling between the frontend and dashboard preview.

The final architecture should conceptually be:

```text
Template configuration
        ↓
Template component
        ↓
 ┌───────────────┐
 ↓               ↓
Admin Preview   Frontend
```

Both must use the same template component and the same appearance configuration.

---

# 31. Per-Template Appearance Settings

Each template must support appropriate visual customization settings from the FaraCart admin dashboard.

Do not assume that all six templates should have exactly the same settings.

Each template should expose only the settings that make sense for its design.

The objective is to give store owners meaningful control over the appearance without destroying the design integrity of each template.

## General Principle

Separate:

### Mission/business settings

From:

### Template/appearance settings

Do not mix visual configuration with mission business logic.

For example:

- Mission target amount → business setting
- Mission condition → business setting
- Mission completion logic → business setting
- Card border radius → appearance setting
- Progress color → appearance setting
- Button color → appearance setting

---

# 32. Common Appearance Settings

Where appropriate, provide a shared set of appearance settings that can be used by multiple templates.

Possible common settings include:

### Colors

- Primary/accent color
- Progress color
- Background color
- Text color
- Secondary text color
- Border color
- Button background color
- Button text color
- Success color

Do not force every color onto every template.

Only expose colors that actually affect the selected template.

### Typography

Where practical:

- Title font size
- Description font size
- Amount font size
- Button font size
- Font weight

Do not expose excessive typography controls that make the UI complicated.

### Shape

Where applicable:

- Border radius
- Progress bar radius
- Button radius
- Card radius

### Spacing

Where useful:

- Card padding
- Element spacing
- Compact/comfortable density

Avoid exposing dozens of low-level spacing controls.

---

# 33. Template-Specific Appearance Settings

Each template should have settings appropriate to its design.

Examples:

## قالب ۱ — Classic Progress Card

Potential settings:

- Accent/progress color
- Card background
- Border color
- Border radius
- Progress bar height
- Button style
- Button color
- Icon background color
- Icon color
- Show percentage
- Show remaining amount
- Card density

---

## قالب ۲ — Minimal Inline Cart Mission

Potential settings:

- Accent color
- Background color
- Progress color
- Border color
- Progress height
- Button style
- Compactness/density
- Show CTA
- Show icon

Because this template is intentionally compact, avoid settings that make it unnecessarily large.

---

## قالب ۳ — Circular Progress

Potential settings:

- Progress color
- Circle size
- Circle thickness
- Background track color
- Percentage text size
- Icon color
- Card background
- Border radius
- Button color

Do not expose settings that would make the circular design visually inconsistent.

---

## قالب ۴ — Product Recommendation + Mission

Potential settings:

- Header background/accent color
- Progress color
- Card background
- Border radius
- Product card border color
- Product card background
- Button color
- Button radius
- Recommendation heading visibility
- Product image size
- Number of recommended products shown, only if this is compatible with the existing recommendation system

Do not turn this into a recommendation-engine configuration screen.

---

## قالب ۵ — Compact Floating / Sticky Mission

Potential settings:

- Bar background color
- Progress color
- Text color
- Icon color
- Button color
- Button text color
- Border radius
- Position if the existing placement system supports configurable position
- Compactness
- Shadow intensity

Be careful that user-selected colors still maintain sufficient contrast.

---

## قالب ۶ — Premium / Elegant E-commerce Style

Potential settings:

- Accent/gold color
- Background color
- Text color
- Secondary text color
- Progress color
- Border color
- Border radius
- Button style
- Button color
- Premium accent intensity

Preserve the premium/elegant character of the template.

Do not allow arbitrary settings to destroy its visual hierarchy.

---

# 34. Appearance Settings Architecture

Do not create six completely unrelated configuration systems.

Create a scalable appearance configuration architecture.

Conceptually:

```ts
interface MissionTemplateAppearance {
  primaryColor?: string;
  backgroundColor?: string;
  textColor?: string;
  secondaryTextColor?: string;
  borderColor?: string;
  progressColor?: string;
  buttonColor?: string;
  borderRadius?: number;
}
```

Then allow template-specific properties where necessary.

Use the project's existing TypeScript architecture and naming conventions.

Do not blindly copy this interface if the project already has a suitable configuration model.

The important requirement is that appearance configuration must be:

- Type-safe
- Persistable
- Template-aware
- Reusable
- Easy to extend with future templates

---

# 35. Appearance Settings UI

Add the appearance controls to the existing FaraCart admin dashboard.

The UI should be easy for a store owner to understand.

Prefer appropriate MUI controls such as:

- Color picker / color input
- Slider
- Switch
- Select
- Radio group
- Toggle button group
- Text field where appropriate

Group settings logically, for example:

```text
تنظیمات ظاهری
├── رنگ‌ها
├── اندازه و فاصله
├── دکمه
├── نوار پیشرفت
└── نمایش
```

Do not expose internal technical property names.

Use clear Persian labels.

Examples:

- رنگ اصلی
- رنگ نوار پیشرفت
- رنگ پس‌زمینه
- رنگ متن
- شعاع گوشه‌ها
- ضخامت نوار پیشرفت
- نمایش درصد پیشرفت
- نمایش دکمه
- اندازه آیکن

Only show settings relevant to the selected template.

---

# 36. Live Preview

The most important requirement for appearance settings:

**Every appearance setting must be reflected immediately in the dashboard preview.**

Expected flow:

```text
User changes appearance setting
        ↓
Configuration state updates
        ↓
Preview receives updated configuration
        ↓
Actual template re-renders
```

Do not require:

- Saving
- Page reload
- Leaving the page
- Reopening the template

just to see a visual change.

Saving should persist the final configuration, but preview must work live before saving.

---

# 37. Persistence

Appearance settings must be persisted using the existing FaraCart configuration/storage architecture.

Before implementing persistence:

1. Find how existing mission/template settings are stored.
2. Reuse that mechanism.
3. Do not introduce an unrelated storage system.

Appearance configuration must remain associated with the selected mission/template appropriately.

If each mission can have its own template, appearance settings must not accidentally become global unless the existing product architecture explicitly defines them as global.

If the project already supports global template defaults, preserve that concept where appropriate.

---

# 38. Default Appearance Configuration

Every template must have sensible default appearance settings matching the HTML reference.

For example:

- قالب ۱ → orange
- قالب ۲ → indigo/blue
- قالب ۳ → indigo/green
- قالب ۴ → blue/indigo
- قالب ۵ → dark/sticky
- قالب ۶ → premium gold

The default configuration must make the template look like the original HTML design before the user customizes anything.

Do not require the user to configure colors manually to reproduce the reference design.

---

# 39. Appearance Configuration Safety

Prevent invalid or harmful visual configurations.

Examples:

- Ensure sufficient text/background contrast where possible.
- Avoid negative spacing values.
- Apply sensible min/max limits to sliders.
- Do not allow enormous font sizes or card dimensions that break the layout.
- Validate color values.
- Keep responsive behavior intact.

The user should have customization freedom without being able to easily destroy the component layout.

---

# 40. Template Settings and Preview Validation

After implementing appearance settings, test each template with:

1. Default settings
2. Custom accent color
3. Custom background color
4. Custom border radius
5. Custom progress color
6. Custom button color
7. Any template-specific settings
8. Different mission states
9. Mobile preview
10. RTL

Verify that no template crashes when optional appearance properties are missing.

Every template must have safe defaults.

---

# 41. Final Admin Dashboard Validation

Before finishing, verify:

- [x] Six new templates appear in the selector
- [x] Old templates do not appear
- [x] Selecting a template renders the correct real component
- [x] Preview uses the production template component
- [x] Preview uses realistic demo data
- [x] Preview works in RTL
- [x] Preview is responsive
- [x] Appearance settings are visible
- [x] Only relevant settings appear for each template
- [x] Appearance changes update preview instantly
- [x] Appearance settings can be saved
- [x] Saved appearance settings are restored when reopening the template
- [x] Template defaults match the HTML reference
- [x] Changing one template's settings does not unexpectedly modify another template
- [x] Existing FaraCart functionality remains intact

---

# 42. Final Architecture Mission

The final system should conceptually follow this architecture:

```text
                    Mission Template
                         │
              ┌──────────┴──────────┐
              │                     │
        Template Data        Appearance Config
              │                     │
              └──────────┬──────────┘
                         ↓
                 Real Template Component
                         │
                ┌────────┴────────┐
                ↓                 ↓
         Admin Live Preview   Frontend
```

There must be a single source of truth for the template UI.

Do not build one implementation for the admin preview and another implementation for the WooCommerce frontend.

---

# 43. Updated Final Result

After completion, FaraCart must have:

### Six templates

1. **قالب ۱** — Classic Progress Card
2. **قالب ۲** — Minimal Inline Cart Mission
3. **قالب ۳** — Circular Progress
4. **قالب ۴** — Product Recommendation + Mission
5. **قالب ۵** — Compact Floating / Sticky Mission
6. **قالب ۶** — Premium / Elegant E-commerce Style

### And for each template

- Production MUI component
- Real frontend rendering
- Admin dashboard preview
- Live preview with demo data
- Default appearance configuration
- Template-specific appearance settings
- Persisted appearance configuration
- Responsive behavior
- RTL support

The admin dashboard preview and the frontend must always use the same production template component.

The HTML file remains the visual source of truth for the default design.

The existing FaraCart codebase remains the source of truth for business logic, data, architecture, and persistence.
