#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { MAX_INPUT_BYTES, sanitizeEvidence } = require('./model.cjs');

function digest(value) {
  return crypto.createHash('sha256').update(value, 'utf8').digest('base64');
}

function renderHtml(input) {
  const evidence = sanitizeEvidence(input);
  const css = fs.readFileSync(path.join(__dirname, 'viewer.css'), 'utf8');
  const javascript = fs.readFileSync(path.join(__dirname, 'viewer.js'), 'utf8');
  const template = fs.readFileSync(path.join(__dirname, 'template.html'), 'utf8');
  const json = JSON.stringify(evidence).replaceAll('<', '\\u003c').replaceAll('\u2028', '\\u2028').replaceAll('\u2029', '\\u2029');
  const policy = `default-src 'none'; script-src 'sha256-${digest(javascript)}'; style-src 'sha256-${digest(css)}'; connect-src 'none'; img-src data:; font-src 'none'; object-src 'none'; frame-src 'none'; base-uri 'none'; form-action 'none'`;
  const replacements = { CSP: policy, CSS: css, EVIDENCE: json, JS: javascript };
  return template.replace(/__(CSP|CSS|EVIDENCE|JS)__/gu, (_, key) => replacements[key]);
}

function argumentsFrom(values) {
  if (values.length === 1 && values[0] === '--help') return null;
  if (values.length !== 4) throw new Error('Use --input evidence.json --output replay.html.');
  const result = {};
  for (let index = 0; index < values.length; index += 2) {
    const name = values[index];
    if (!['--input', '--output'].includes(name) || result[name] || !values[index + 1]) throw new Error('Use each required input/output argument once.');
    result[name] = values[index + 1];
  }
  if (!result['--input'] || !result['--output']) throw new Error('Input and output are required.');
  if (path.resolve(result['--input']) === path.resolve(result['--output'])) throw new Error('Input and output must be different files.');
  return result;
}

function main(values) {
  const args = argumentsFrom(values);
  if (args === null) {
    process.stdout.write('Usage: node tools/demo-viewer/render.cjs --input evidence.json --output replay.html\nRenders recorded evidence only; it makes no provider or operator requests.\n');
    return;
  }
  let bytes;
  try {
    const info = fs.statSync(args['--input']);
    if (!info.isFile() || info.size > MAX_INPUT_BYTES) throw new Error();
    bytes = fs.readFileSync(args['--input']);
    if (bytes.length > MAX_INPUT_BYTES) throw new Error();
  } catch {
    throw new Error('Cannot read a regular evidence file within the 2 MiB limit.');
  }
  let input;
  try { input = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes)); }
  catch { throw new Error('Input must contain valid UTF-8 JSON.'); }
  const html = renderHtml(input);
  const output = path.resolve(args['--output']);
  fs.mkdirSync(path.dirname(output), { recursive: true });
  const inputIdentity = fs.realpathSync(args['--input']);
  const outputIdentity = path.join(fs.realpathSync(path.dirname(output)), path.basename(output));
  const equivalent = (left, right) => process.platform === 'win32' ? left.toLowerCase() === right.toLowerCase() : left === right;
  if (equivalent(inputIdentity, outputIdentity)
    || (fs.existsSync(output) && equivalent(inputIdentity, fs.realpathSync(output)))) {
    throw new Error('Input and output must identify different files.');
  }
  const temporary = `${output}.${crypto.randomUUID()}.tmp`;
  try {
    fs.writeFileSync(temporary, html, { encoding: 'utf8', mode: 0o600, flag: 'wx' });
    fs.renameSync(temporary, output);
  } catch {
    throw new Error('Cannot write the replay output.');
  } finally {
    if (fs.existsSync(temporary)) fs.unlinkSync(temporary);
  }
  process.stdout.write(`Rendered ${input.checkpoints.length} recorded checkpoints. No operations were executed.\n`);
}

if (require.main === module) {
  try { main(process.argv.slice(2)); }
  catch (error) { process.stderr.write(`Replay render failed: ${error.message}\n`); process.exitCode = 1; }
}

module.exports = { renderHtml, main };
