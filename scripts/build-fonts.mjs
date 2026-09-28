/**
 * Downloads the webfonts used by this app and writes a self-hosted @font-face
 * stylesheet plus the .woff2 files into assets/fonts/.
 *
 * NOTE: this is a *build-time* step and it needs internet once. The generated
 * assets/fonts/* are committed, so the running application never makes an
 * outbound request.
 */
import fs from 'node:fs';
import path from 'node:path';

const dir = path.resolve('assets/fonts');
fs.mkdirSync(dir, { recursive: true });

const UA =
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

const SOURCES = [
  {
    family: 'Space Grotesk',
    slug: 'space-grotesk',
    css: 'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap',
  },
  {
    family: 'Plus Jakarta Sans',
    slug: 'plus-jakarta-sans',
    css: 'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap',
  },
];

const LATIN_RANGE =
  'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, ' +
  'U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD';

const blocks = [];

for (const { family, slug, css: cssUrl } of SOURCES) {
  const res = await fetch(cssUrl, { headers: { 'User-Agent': UA } });
  if (!res.ok) throw new Error(`${res.status} fetching ${cssUrl}`);
  const text = await res.text();

  // Google serves one @font-face per weight per subset. Keep only `latin`.
  const entries = [];
  for (const raw of text.split('@font-face').slice(1)) {
    const block = '@font-face' + raw.split('}')[0] + '}';
    if (!/unicode-range:\s*U\+0000-00FF/.test(block)) continue;
    const weight = block.match(/font-weight:\s*(\d+)/)?.[1];
    const url = block.match(/url\((https:\/\/[^)]+)\)/)?.[1];
    if (weight && url) entries.push({ weight: Number(weight), url });
  }
  if (!entries.length) throw new Error(`no latin @font-face found for ${family}`);

  entries.sort((a, b) => a.weight - b.weight);
  const min = entries[0].weight;
  const max = entries[entries.length - 1].weight;

  // These families ship as variable fonts: every weight resolves to the same
  // file, so store one file and declare the whole range.
  const file = `${slug}-variable.woff2`;
  const fontRes = await fetch(entries[0].url, { headers: { 'User-Agent': UA } });
  if (!fontRes.ok) throw new Error(`${fontRes.status} fetching ${entries[0].url}`);
  const buf = Buffer.from(await fontRes.arrayBuffer());
  fs.writeFileSync(path.join(dir, file), buf);
  console.log(`${file}  ${(buf.length / 1024).toFixed(1)} KB  (weights ${min}-${max})`);

  blocks.push(
    `@font-face {\n` +
      `  font-family: '${family}';\n` +
      `  font-style: normal;\n` +
      `  font-weight: ${min} ${max};\n` +
      `  font-display: swap;\n` +
      `  src: url('${file}') format('woff2-variations');\n` +
      `  unicode-range: ${LATIN_RANGE};\n` +
      `}`
  );
}

const header = `/* Self-hosted webfonts - no external requests.
   Latin subset only, variable weight axes. Regenerate with: npm run build:fonts */

`;

fs.writeFileSync(path.join(dir, 'fonts.css'), header + blocks.join('\n\n') + '\n');

// Drop stale per-weight files from older builds.
for (const f of fs.readdirSync(dir)) {
  if (/-\d+\.woff2$/.test(f)) fs.unlinkSync(path.join(dir, f));
}

console.log('\nWrote assets/fonts/fonts.css');
