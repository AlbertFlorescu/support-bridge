const fs = require('node:fs');
const path = require('node:path');
const source = path.dirname(require.resolve('swagger-ui-dist/package.json'));
const target = path.resolve(__dirname, '../../public/assets/swagger-ui');
fs.mkdirSync(target, { recursive: true });
for (const file of ['swagger-ui-bundle.js', 'swagger-ui.css', 'LICENSE', 'NOTICE']) {
  if (fs.existsSync(path.join(source, file))) fs.copyFileSync(path.join(source, file), path.join(target, file));
}
console.log('Vendored Swagger UI ' + require('swagger-ui-dist/package.json').version);
