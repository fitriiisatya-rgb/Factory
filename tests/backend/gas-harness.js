"use strict";
const fs = require("fs");
const path = require("path");
const vm = require("vm");
const { makeGasGlobals } = require("./gas-mock.js");

const CODE_NEW = path.join(__dirname, "..", "..", "backend", "Code.gs");
const CODE_LEGACY = path.join(__dirname, "..", "..", "backend", "Code.legacy.gs");

const EXPORT_NAMES = [
  "doGet","doPost","setup","getSS","getVersion_","getAllVersions_","readAllAsObjects_","getOrCreateSheet",
  "SHEET_CEKLIS","SHEET_PO","SHEET_FGPACKING"
];

function loadFrom(file){
  const src = fs.readFileSync(file, "utf8");
  const { globals, state } = makeGasGlobals();
  const sandbox = Object.assign({}, globals, { console });
  vm.createContext(sandbox);
  const tail = "\n;this.__EXPORTS__ = {" + EXPORT_NAMES.map(n=>`${n}: (typeof ${n}!=="undefined"?${n}:undefined)`).join(",") + "};";
  vm.runInContext(src + tail, sandbox, { filename: path.basename(file) });
  const api = sandbox.__EXPORTS__;
  api.__state = state;
  api.doPostJSON = (payload) => JSON.parse(api.doPost({postData:{contents:JSON.stringify(payload)}}).getContent());
  api.doGetJSON = () => JSON.parse(api.doGet({}).getContent());
  // Pastikan semua sheet ada dulu (setup()) supaya installInterleaveHook bisa
  // langsung menunjuk sheet yang benar sebelum request pertama dikirim.
  api.setup();
  return api;
}

function loadNewBackend(){ return loadFrom(CODE_NEW); }
function loadLegacyBackend(){ return loadFrom(CODE_LEGACY); }

module.exports = { loadNewBackend, loadLegacyBackend };
