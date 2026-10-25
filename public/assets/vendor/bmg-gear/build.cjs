'use strict';
// node public/assets/vendor/bmg-gear/build.cjs <directory containing node_modules>
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const dependencyRoot = path.resolve(process.argv[2] || '.');
const modules = path.join(dependencyRoot, 'node_modules');
for (const [name, pinned] of [['three', '0.186.1'], ['esbuild', '0.21.5']]) {
  const installed = JSON.parse(fs.readFileSync(path.join(modules, name, 'package.json'), 'utf8')).version;
  if (installed !== pinned) throw Error(`${name}: expected ${pinned}, got ${installed}`);
}
const output = path.resolve(__dirname, '../../js/bmg-gear-renderer.js');
require(path.join(modules, 'esbuild')).buildSync({
  entryPoints: [path.join(__dirname, 'renderer-source.js')],
  outfile: output, bundle: true, format: 'esm', platform: 'browser', target: 'es2020',
  minify: true, legalComments: 'inline', nodePaths: [modules],
  banner: { js: '/* Local BMG illustration. Three.js 0.186.1 (MIT); see ../vendor/bmg-gear/THREE-LICENSE.txt and PROVENANCE.json. */' }
});
const bytes = fs.readFileSync(output);
const provenancePath = path.join(__dirname, 'PROVENANCE.json');
const provenance = JSON.parse(fs.readFileSync(provenancePath, 'utf8'));
provenance.bundle = { file: 'public/assets/js/bmg-gear-renderer.js', bytes: bytes.length,
  sha256: crypto.createHash('sha256').update(bytes).digest('hex') };
fs.writeFileSync(provenancePath, JSON.stringify(provenance, null, 2) + '\n');
console.log(`Built ${bytes.length} bytes (${provenance.bundle.sha256})`);
