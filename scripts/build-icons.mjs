import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');

const SCAN_DIRS = ['.', 'auth', 'user', 'technician', 'admin', 'system', 'assets/js'];
const SKIP = new Set(['node_modules', '.git', 'src', 'uploads']);

function walk(dir, acc = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (SKIP.has(entry.name)) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, acc);
    else if (/\.(php|js)$/.test(entry.name)) acc.push(full);
  }
  return acc;
}

const toPascalCase = (s) => s.replace(/(\w)(\w*)(_|-|\s*)/g, (_, a, b) => a.toUpperCase() + b.toLowerCase());

const used = new Set();
for (const f of SCAN_DIRS.flatMap((d) => walk(path.join(root, d)))) {
  const text = fs.readFileSync(f, 'utf8');
  for (const m of text.matchAll(/data-lucide=["']([^"'<>]+)["']/g)) used.add(m[1]);
  for (const m of text.matchAll(/data-lucide=["'][^"']*<\?php\s+echo\s+\$\w+\s*\?\s*['"]([a-z-]+)['"]/g)) used.add(m[1]);
}
used.add('power');

const aliases = await import(pathToFileURL(path.join(root, 'node_modules/lucide/dist/esm/iconsAndAliases.js')).href);

const entries = {};
const missing = [];
for (const name of [...used].sort()) {
  const key = toPascalCase(name);
  const node = aliases[key];
  if (!node) {
    missing.push(name);
    continue;
  }
  entries[name] = node;
}

if (missing.length) {
  console.error('Unresolved icon names:', missing.join(', '));
  process.exit(1);
}

const body = `/*!
 * lucide v0.475.0 (ISC) - vendored subset
 * Only the ${Object.keys(entries).length} icons used by this app are included.
 * Regenerate with: npm run build:icons
 */
(function (global) {
  var icons = ${JSON.stringify(entries, null, 2)};
  var defaultAttributes = {
    xmlns: 'http://www.w3.org/2000/svg',
    width: 24,
    height: 24,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    'stroke-width': 2,
    'stroke-linecap': 'round',
    'stroke-linejoin': 'round'
  };

  function toPascalCase(string) {
    return string.replace(/(\\w)(\\w*)(_|-|\\s*)/g, function (g0, g1, g2) {
      return g1.toUpperCase() + g2.toLowerCase();
    });
  }

  function createElement(spec) {
    var el = document.createElementNS('http://www.w3.org/2000/svg', spec[0]);
    var attrs = spec[1];
    if (attrs) {
      Object.keys(attrs).forEach(function (name) {
        el.setAttribute(name, String(attrs[name]));
      });
    }
    if (spec[2] && spec[2].length) {
      spec[2].forEach(function (child) {
        el.appendChild(createElement(child));
      });
    }
    return el;
  }

  function replaceElement(element, options) {
    var nameAttr = options.nameAttr;
    var iconName = element.getAttribute(nameAttr);
    if (iconName == null) return;
    var iconNode = icons[toPascalCase(iconName)] || icons[iconName];
    if (!iconNode) {
      if (global.console) console.warn('[lucide] icon "' + iconName + '" not found.');
      return;
    }
    var iconAttrs = {};
    Object.keys(defaultAttributes).forEach(function (k) { iconAttrs[k] = defaultAttributes[k]; });
    iconAttrs[nameAttr] = iconName;
    if (options.attrs) {
      Object.keys(options.attrs).forEach(function (k) { iconAttrs[k] = options.attrs[k]; });
    }
    var seen = {};
    var classes = ['lucide', 'lucide-' + iconName];
    var push = function (v) {
      if (!v) return;
      String(v).split(' ').forEach(function (c) {
        c = c.trim();
        if (c && !seen[c]) { seen[c] = true; classes.push(c); }
      });
    };
    Array.prototype.forEach.call(element.attributes, function (attr) {
      if (attr.name === 'class') return;
      iconAttrs[attr.name] = attr.value;
    });
    push(element.getAttribute('class'));
    if (options.attrs && options.attrs.class) push(options.attrs.class);
    if (classes.length) iconAttrs['class'] = classes.join(' ');
    if (element.parentNode) {
      element.parentNode.replaceChild(createElement(['svg', iconAttrs, iconNode]), element);
    }
  }

  var api = {
    icons: icons,
    createIcons: function (opts) {
      opts = opts || {};
      opts.nameAttr = opts.nameAttr || 'data-lucide';
      var root = opts.root || document;
      var scope = root.querySelectorAll ? root.querySelectorAll('[' + opts.nameAttr + ']') : [];
      Array.prototype.forEach.call(scope, function (el) { replaceElement(el, opts); });
      return api;
    }
  };

  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  global.lucide = api;
})(typeof window !== 'undefined' ? window : this);
`;

const out = path.join(root, 'assets/js/lucide.js');
fs.writeFileSync(out, body);
const kb = (fs.statSync(out).size / 1024).toFixed(1);
console.log(`Wrote assets/js/lucide.js - ${Object.keys(entries).length} icons, ${kb} KB`);
