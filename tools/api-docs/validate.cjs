const parser = require('@apidevtools/swagger-parser');
const path = require('node:path');
const fs = require('node:fs');
const Ajv = require('ajv/dist/2020');
const specification = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../docs/api/openapi.json'), 'utf8'));
parser.validate(specification, { resolve: { external: false } })
  .then(api => {
    console.log(`Validated ${api.openapi}: ${Object.keys(api.paths).length} documented paths.`);
    const fixturesPath = path.resolve(__dirname, '../../var/api-contract-fixtures.json');
    if (!fs.existsSync(fixturesPath)) {
      throw new Error('Run php tests/api-server.php first to generate real HTTP response fixtures.');
    }
    const ajv = new Ajv({ allErrors: true, strict: true });
    const fixtures = JSON.parse(fs.readFileSync(fixturesPath, 'utf8')).fixtures;
    if (!Array.isArray(fixtures) || fixtures.length === 0) throw new Error('No response fixtures were generated.');
    for (const status of [200, 401, 409, 422]) {
      if (!fixtures.some(fixture => fixture.method === 'POST' && fixture.path === '/api/pairs/{id}/approve' && fixture.status === status)) {
        throw new Error(`Missing approval wire fixture for HTTP ${status}. Rerun the current wire suite.`);
      }
    }
    if (!fixtures.some(fixture => fixture.path === '/api/pairs/{id}' && fixture.status === 200 && fixture.body?.pair?.status === 'awaiting_approval')
      || !fixtures.some(fixture => fixture.path === '/api/pairs/{id}' && fixture.status === 200 && fixture.body?.governance?.approved === true)) {
      throw new Error('Wire fixtures must include both a pending plan and a persisted approval.');
    }
    for (const fixture of fixtures) {
      const operation = api.paths[fixture.path]?.[fixture.method.toLowerCase()];
      const response = operation?.responses[String(fixture.status)];
      const mediaType = fixture.mediaType || 'application/json';
      const schema = response?.content?.[mediaType]?.schema;
      if (!schema) throw new Error(`Undocumented ${mediaType} response: ${fixture.method} ${fixture.path} ${fixture.status}`);
      const validate = ajv.compile(schema);
      if (!validate(fixture.body)) {
        throw new Error(`${fixture.method} ${fixture.path} ${fixture.status}: ${ajv.errorsText(validate.errors)}`);
      }
    }
    console.log(`Validated ${fixtures.length} real HTTP response fixtures with JSON Schema 2020-12.`);
  })
  .catch(error => { console.error(error.message); process.exitCode = 1; });
