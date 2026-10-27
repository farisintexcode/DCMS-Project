# Nexora interface assets

Palette: navy `#023047`, orange `#FB8500`, white `#FFFFFF`.

- `nexora-logo-light.svg` / `nexora-logo-dark.svg`: transparent full lockups with tight 420:116 viewboxes. Use on light / dark surfaces respectively.
- `nexora-icon-light.svg` / `nexora-icon-dark.svg`: transparent 100:116 icon-only marks for compact navigation. Preserve aspect ratio.
- Matching PNG exports: full lockups at 1680×464 and icon marks at 400×464, including private PDF compatibility.
- `nexora-app.svg`: white rounded-square app source with a centered padded mark.
- `nexora-app-{16,32,48,64,180,192,512}.png`: exact-size white-backed app/browser icons. The application uses 32px favicon, 180px Apple touch icon and 192/512px web manifest entries. `public/favicon.ico` covers the default browser request.

The shared `x-brand` component and centralized CSS select light/dark artwork by surface and theme. The collapsed sidebar and compact headers use icon-only artwork. Print always uses the light version. Keep images at natural aspect ratios; do not apply hue filters, stretch them, or add a large presentation-image canvas.

The original supplied PNGs remain archived in the asset directory; the interface uses the regenerated variants. Regenerate deterministic SVG/PNG/ICO exports with `node scripts/generate-nexora-assets.mjs` using the project's existing Playwright runtime. No external font download or image service is required. The SVG wordmark uses the standard Arial/Helvetica sans-serif family; PNG exports provide fixed rendering when needed.

Browser-icon override: nexora-browser.svg and 16/32/48/64/512px PNG exports use a rounded navy background with the white/orange mark. The HTML favicon and root favicon.ico use this variant; white-backed installed-app assets are retained.

