---
name: Culinary Operations System
colors:
  surface: '#f3fbf6'
  surface-dim: '#d3dcd7'
  surface-bright: '#f3fbf6'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#edf6f0'
  surface-container: '#e7f0eb'
  surface-container-high: '#e2eae5'
  surface-container-highest: '#dce5df'
  on-surface: '#151d1a'
  on-surface-variant: '#414846'
  inverse-surface: '#2a322f'
  inverse-on-surface: '#eaf3ee'
  outline: '#727976'
  outline-variant: '#c1c8c5'
  surface-tint: '#48645d'
  primary: '#06241f'
  on-primary: '#ffffff'
  primary-container: '#1e3a34'
  on-primary-container: '#86a49c'
  inverse-primary: '#aecdc4'
  secondary: '#904d00'
  on-secondary: '#ffffff'
  secondary-container: '#fe932c'
  on-secondary-container: '#663500'
  tertiary: '#002617'
  on-tertiary: '#ffffff'
  tertiary-container: '#003e29'
  on-tertiary-container: '#6eab8c'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#cae9e0'
  primary-fixed-dim: '#aecdc4'
  on-primary-fixed: '#02201b'
  on-primary-fixed-variant: '#304c46'
  secondary-fixed: '#ffdcc3'
  secondary-fixed-dim: '#ffb77d'
  on-secondary-fixed: '#2f1500'
  on-secondary-fixed-variant: '#6e3900'
  tertiary-fixed: '#b1f0ce'
  tertiary-fixed-dim: '#95d4b3'
  on-tertiary-fixed: '#002114'
  on-tertiary-fixed-variant: '#0e5138'
  background: '#f3fbf6'
  on-background: '#151d1a'
  surface-variant: '#dce5df'
typography:
  headline-xl:
    fontFamily: Plus Jakarta Sans
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
  headline-xl-mobile:
    fontFamily: Plus Jakarta Sans
    fontSize: 26px
    fontWeight: '700'
    lineHeight: 32px
  headline-lg:
    fontFamily: Plus Jakarta Sans
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
  headline-md:
    fontFamily: Plus Jakarta Sans
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
  headline-sm:
    fontFamily: Plus Jakarta Sans
    fontSize: 18px
    fontWeight: '600'
    lineHeight: 24px
  body-lg:
    fontFamily: Work Sans
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  body-lg-bold:
    fontFamily: Work Sans
    fontSize: 16px
    fontWeight: '600'
    lineHeight: 24px
  body-md:
    fontFamily: Work Sans
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  body-md-bold:
    fontFamily: Work Sans
    fontSize: 14px
    fontWeight: '600'
    lineHeight: 20px
  label-lg:
    fontFamily: Work Sans
    fontSize: 15px
    fontWeight: '600'
    lineHeight: 20px
    letterSpacing: 0.01em
  label-md:
    fontFamily: Work Sans
    fontSize: 13px
    fontWeight: '600'
    lineHeight: 18px
    letterSpacing: 0.02em
  label-sm:
    fontFamily: Work Sans
    fontSize: 11px
    fontWeight: '700'
    lineHeight: 14px
    letterSpacing: 0.04em
  mono-metric:
    fontFamily: Work Sans
    fontSize: 28px
    fontWeight: '700'
    lineHeight: 32px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  gutter: 1rem
  gutter-md: 1.25rem
  gutter-lg: 1.5rem
  margin: 1rem
  margin-md: 1.5rem
  margin-lg: 2rem
  space-xs: 0.25rem
  space-sm: 0.5rem
  space-md: 1rem
  space-lg: 1.5rem
  space-xl: 2rem
  space-2xl: 3rem
---

## Brand & Style

This design system is built specifically for high-velocity culinary hospitality operations. It combines the warm, tactile dignity of craft food service with the uncompromising clarity, speed, and contrast required by commercial counter staff, prep cooks, and store inventory managers.

The style philosophy balances **Modern Hospitality Minimalism** with **Industrial Tactile Ergonomics**:
- **Warm Utility**: Natural, non-glare canvas tones reduce eye fatigue over 10-hour shifts under fluorescent kitchen lighting without feeling sterile or clinical.
- **High-Velocity Usability**: Generous touch targets, high legibility under motion, and instant scannability across POS stations, inventory scales, and heat-lamp expediting zones.
- **Physical Grounding**: Structural framing and measured depth rather than frivolous decorative effects, prioritizing direct situational awareness and decisive operational input.

## Colors

The palette delivers deliberate contrast calibrated for bustling kitchen and counter environments. 

- **Primary (`#1E3A34`)**: A deep spruce-teal reflecting craft ceramics and focused culinary work. Used for primary workflow drivers, active tab pills, top navigation surfaces, and primary confirmation triggers.
- **Secondary (`#D97706`)**: A toasted golden-amber derived from sizzling garlic, cured proteins, and golden yolks. Reserved for inventory warnings, prep queue delays, low-stock thresholds, and action callouts.
- **Tertiary (`#2D6A4F`)**: Fresh botanical herb-green. Serves as our positive fulfillment anchor for completed tickets, stocked thresholds, and revenue gains.
- **Neutral (`#1C2421`)**: Smoked charcoal-slate providing stark contrast over light cream backgrounds without the harshness of pure black.
- **Canvas & Surface System**:
  - `surface-canvas`: `#FAF8F5` (Warm off-white rice parchment)
  - `surface-card`: `#FFFFFF` (Pure white workstation tile)
  - `surface-subtle`: `#F3EFEA` (Warm neutral counter)
  - `border-neutral`: `#E5E0D8` (Muted ceramic seam)
- **Status & Alerts**:
  - `status-danger`: `#DC2626` (86'd items, order cancellations, stock depletions)
  - `status-success`: `#16A34A` (Fulfillment ready, counts balanced)
  - `status-warning`: `#D97706` (Prep running behind, stock reorder trigger)
  - `status-info`: `#0F5C63` (Order scheduled, modification noted)

### Dark Kitchen Display Mode (KDS)
When switching into the expediter or line-cook mode, the system flips to high-contrast dark values:
- `surface-kds-canvas`: `#121715`
- `surface-kds-ticket`: `#1C2421`
- `surface-kds-border`: `#2E3A35`
- `text-kds-primary`: `#F8FAF9`
- `text-kds-accent`: `#FBBF24`

## Typography

Typography prioritizes legibility at half an arm's distance on POS terminals and kitchen wall mounts.

- **Display & Section Titles (Plus Jakarta Sans)**: Delivers friendly warmth with clean, modern geometry. Keeps operational screens approachable while retaining clear heading separation.
- **Body & Numerical Readouts (Work Sans)**: Neutral, sturdy, and utilitarian. Generous x-height prevents ambiguous reading of critical metrics (e.g., distinguish `8` vs `3` or `1.5 kg` vs `1.8 kg` during swift inventory reconciliations).
- **Tabular Figures**: All monetary amounts, ingredient quantities, and order counters must use tabular figures (`font-variant-numeric: tabular-nums`) to ensure vertical alignment throughout tables and order receipts.

## Layout & Spacing

The system enforces a flexible 12-column layout built for touch-first input, responsive tablet terminals, and widescreen management setups.

- **Grid Architecture**:
  - **Mobile (< 768px)**: 4-column fluid layout with `margin: 1rem` and `gutter: 1rem`. Orders and stock tables convert to stackable card lists.
  - **Tablet & POS Terminals (768px - 1199px)**: 8-column layout with `margin: 1.5rem` and `gutter: 1.25rem`. Split-screen ticket-and-action displays are optimized for two-handed tablet grips.
  - **Desktop / Back-of-House (1200px+)**: 12-column fluid grid locked to a max width of `1680px` with `margin: 2rem` and `gutter: 1.5rem`.
- **Target Ergonomics**:
  - Touch targets for core interactive elements (order bumps, add-to-cart, quantity toggles, category selectors) must never drop below `48px` in height or width, with an ideal default of `52px` on service screens.

## Elevation & Depth

Visual hierarchy uses clean tonal layering paired with warm ambient shadows. This keeps the interface crisp under intense lighting while ensuring overlays and floating summaries remain distinct.

- **Level 0 (Flat Ground)**: `surface-canvas` (`#FAF8F5`). Baseline workspace backdrop.
- **Level 1 (Card & Section Containers)**: White surface (`#FFFFFF`) with a structural border (`1px solid #E5E0D8`) and low-intensity warm shadow:
  - `box-shadow: 0 1px 3px rgba(30, 58, 52, 0.05), 0 1px 2px rgba(30, 58, 52, 0.03);`
- **Level 2 (Active States, Dropdowns & Popovers)**:
  - `box-shadow: 0 4px 6px -1px rgba(30, 58, 52, 0.08), 0 2px 4px -2px rgba(30, 58, 52, 0.04);`
- **Level 3 (Slide-Over Drawers & Floating Action Bars)**:
  - `box-shadow: -4px 0 24px rgba(30, 58, 52, 0.12);`
- **Level 4 (Modals & Critical Confirmation Dialogs)**:
  - `box-shadow: 0 20px 25px -5px rgba(30, 58, 52, 0.15), 0 8px 10px -6px rgba(30, 58, 52, 0.08);`
  - Backdropped by `rgba(28, 36, 33, 0.6)` with a subtle blur (`backdrop-filter: blur(2px)`).

## Shapes

The design system uses balanced curvature (`roundedness: 2`) to deliver approachable warmth without wasting display real estate.

- **Standard Base Radius (`0.5rem` / `8px`)**: Applied to text inputs, table wrappers, order card headers, toast notifications, standard action buttons, and inventory data widgets.
- **Large Container Radius (`1rem` / `16px`)**: Applied to modal shells, persistent sliding drawers, and order category tile cards.
- **Extra Large Radius (`1.5rem` / `24px`)**: Used for quick-filter segmented pills and floating action summaries.
- **Strict Square Exception**: Table rows and nested sub-elements maintain flush interior bounds to avoid broken visual seams.

## Components

### Buttons
- **Touch Standard**: Minimum height `48px` (default `52px` on POS modules). Minimum horizontal padding `1.25rem`.
- **Primary Button**: Deep spruce fill (`#1E3A34`), text `#FFFFFF`, font weight `600`. Hover: `#162C27`. Active: `#0F1E1B`.
- **Secondary Button**: `#FFFFFF` surface with `1.5px` border in `#1E3A34`, text `#1E3A34`.
- **Destructive Button**: Crimson fill (`#DC2626`), text `#FFFFFF`. Used for canceling active tickets, discarding batches, or 86'ing line items.
- **Icon Buttons**: Centered `24px` icon inside a `48px x 48px` container.

### Status Chips & Badges
- **Structure**: Always pair an icon with concise operational text for instant recognition (e.g., `[✓] Complete`, `[!] Low Stock`, `[⧗] In Prep`).
- **Sizes**: Standard (`32px` height) for data tables; Compact (`24px` height) for order line receipts.
- **Pill Styles**:
  - `Paid / In Stock`: Light green background (`#DCFCE7`), deep green text and icon (`#166534`).
  - `Low Stock / Delayed`: Soft amber background (`#FEF3C7`), amber text (`#92400E`).
  - `Out of Stock / Canceled`: Light red background (`#FEE2E2`), crimson text (`#991B1B`).
  - `Pending / Open`: Subtle teal background (`#E6F0EE`), primary teal text (`#1E3A34`).

### Order & Inventory Cards
- **Order Ticket Card**:
  - Fixed-width or flex grid item with white background and `1px solid #E5E0D8`.
  - Header: Ticket number (`headline-sm`), elapsed timer with dynamic urgency color badge, and order type tag (Dine-In, Takeout, Delivery).
  - Body: Itemized rows with quantity callout badges (`x2 Sinangag`, `x1 Tocino, Over-Easy`). Modifiers displayed in indented secondary text.
  - Footer: Direct bump action button spanning full card width.
- **Inventory Stock Card**:
  - Displays ingredient name, category, current unit balance, and reorder trigger point.
  - Progress bar shows depletion level using contextual status colors.

### Data Tables (Inventory & Audit Logs)
- **Header**: `#F3EFEA` background, `label-md` typography, sticky on scroll, height `44px`.
- **Row Styling**: Alternating `#FFFFFF` and `#FAF8F5`, row height minimum `52px` for tap target compliance. Bottom border `1px solid #E5E0D8`.
- **Numeric Columns**: Right-aligned with tabular figures.

### Side Drawers & Modals
- **Side Drawer**: Slides in from the right (`width: 440px` max on desktop, full width on mobile) for order customization, quick stock intake, or recipe breakdown. Header contains sticky title and large dismiss button.
- **Modal Dialog**: Centered modal (`max-width: 520px`) for destructive confirmations (e.g., stock reconciliation discrepancy sign-off).

### Input Fields & Steppers
- **Inputs**: Height `48px`, background `#FFFFFF`, border `1px solid #D1CAC0`, font size `16px` (prevents browser auto-zoom on mobile devices).
- **Steppers**: Dedicated large `+` and `-` steppers (`48px x 48px`) flanking a center numeric readout for quick kitchen quantity modifications.

### Toast Notifications
- **Position**: Bottom-right on desktop; top-center on mobile/POS.
- **Duration & Style**: Auto-dismissing 4-second feedback cards with high-contrast borders and action undo capability (e.g., "Ticket #104 bumped — Undo").