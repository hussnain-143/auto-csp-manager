# Auto CSP Manager — Refined Brand Kit

## What was corrected
- Rebuilt the shield as a balanced, symmetrical path with smooth rounded joins and consistent stroke weight.
- Centered the checkmark and corrected its proportions.
- Moved the swoosh behind the shield so it no longer cuts awkwardly across the shield outline or creates confusing intersections.
- Added consistent viewBoxes, padding and scale across logo exports.
- Rebuilt black and white variants with matching high contrast; black is for light backgrounds and white is for dark backgrounds.
- Simplified WordPress menu icons so they remain recognizable at small sizes.

## Palette
- Premium gold: `#D6A72C`
- Deep gold: `#A97912`
- Highlight gold: `#F4D77E`
- Charcoal: `#252525`
- Black: `#111111`
- White: `#FFFFFF`
- Muted: `#667085`
- Soft background: `#F7F7F5`

## Files
- `logos/logo-horizontal.svg` — primary logo
- `logos/logo-stacked.svg` — stacked lockup
- `logos/logo-black.svg` — all-black monochrome lockup
- `logos/logo-white.svg` — all-white monochrome lockup
- `icons/shield-swoosh-gold.svg` — standalone gold mark
- `icons/shield-swoosh-black.svg` — black mark for light backgrounds
- `icons/shield-swoosh-white.svg` — white mark for dark backgrounds
- `icons/app-icon.svg` — square application icon
- `wordpress/menu-icon-black.svg` — WordPress light-sidebar icon
- `wordpress/menu-icon-white.svg` — WordPress dark-sidebar icon
- `wordpress/menu-icon-gold.svg` — gold brand icon
- `banners/wordpress-org-banner.svg` — 1544×500
- `social/social-preview.svg` — 1200×630

## WordPress sidebar guidance
Use `menu-icon-black.svg` for a normal light WordPress admin sidebar. Use `menu-icon-white.svg` only when the sidebar background is dark. If the admin menu icon appears too small at 20px, export at 2x or 3x resolution and let WordPress scale it down. Avoid using a full wordmark in the sidebar.

Example:
```php
add_menu_page(
    'Auto CSP Manager',
    'Auto CSP',
    'manage_options',
    'auto-csp-manager',
    'acsp_render_admin_page',
    plugin_dir_url(__FILE__) . 'assets/brand/menu-icon-black.png',
    80
);
```

## Usage
- Do not stretch or rotate the mark.
- Preserve the relationship between shield, checkmark and swoosh.
- Do not add shadows, gradients, bevels or outlines around the full logo.
- Keep clear space around the logo.
- Use gold as an accent; use charcoal/white for strong text contrast.
