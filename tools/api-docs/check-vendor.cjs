const fs = require('node:fs');
const path = require('node:path');

const project = path.resolve(__dirname, '../..');
const source = path.join(__dirname, 'node_modules/swagger-ui-dist');
const target = path.join(project, 'public/assets/swagger-ui');
const files = ['swagger-ui-bundle.js', 'swagger-ui.css', 'LICENSE', 'NOTICE'];

function regularFile(file) {
  const root = path.parse(file).root;
  let current = root;
  for (const component of file.slice(root.length).split(path.sep)) {
    current = path.join(current, component);
    if (fs.lstatSync(current).isSymbolicLink()) throw new Error('Symbolic links are not valid vendored assets.');
  }
  const info = fs.statSync(file);
  if (!info.isFile() || info.size === 0 || info.size > 8 * 1024 * 1024) throw new Error('A vendored asset is missing, empty or oversized.');
  return fs.readFileSync(file);
}

try {
  const stale = files.filter(file => !regularFile(path.join(source, file)).equals(regularFile(path.join(target, file))));
  if (stale.length) throw new Error(`Vendored Swagger UI differs from the locked package: ${stale.join(', ')}. Run npm ci --ignore-scripts and node vendor.cjs in tools/api-docs, then commit the updated assets.`);
  console.log('All four vendored Swagger UI files match the installed locked dependency.');
} catch (error) {
  console.error(error.code ? 'Cannot verify vendored Swagger UI. Install locked dependencies and regenerate the committed assets.' : error.message);
  process.exitCode = 1;
}
