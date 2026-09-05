// Regression test harness for "amorcakes-manufacturing-v5-slate(2).html".
//
// This does NOT reimplement any app logic. It extracts the app's own inline
// <script> verbatim from the shipped HTML file and runs it under Node with
// stubbed DOM/localStorage/fetch/confirm/XLSX, then exposes the real
// top-level functions/state (D, hitungTarget, poMergeDenganExisting, ...) so
// run-tests.js can call them directly and assert on real behaviour.
"use strict";
const fs = require("fs");
const vm = require("vm");
const path = require("path");

const HTML_PATH = path.join(__dirname, "..", "amorcakes-manufacturing-v5-slate(2).html");
const html = fs.readFileSync(HTML_PATH, "utf8");
const scriptMatch = html.match(/<script(?![^>]*src)[^>]*>([\s\S]*?)<\/script>/);
if(!scriptMatch) throw new Error("Tidak menemukan blok <script> inline di "+HTML_PATH);
const appSrc = scriptMatch[1];

// ---------- localStorage stub ----------
function makeLocalStorage(){
  const store = {};
  return {
    getItem:(k)=> Object.prototype.hasOwnProperty.call(store,k) ? store[k] : null,
    setItem:(k,v)=>{ store[k]=String(v); },
    removeItem:(k)=>{ delete store[k]; },
    clear:()=>{ Object.keys(store).forEach(k=>delete store[k]); }
  };
}

// ---------- generic auto-vivifying fake DOM element ----------
function makeFakeElement(){
  return {
    value:"", textContent:"", innerHTML:"", checked:false,
    style:new Proxy({},{get:()=> "", set:()=>true}),
    classList:{add(){},remove(){},toggle(){},contains(){return false;}},
    dataset:{}, children:[], files:[],
    addEventListener(){}, removeEventListener(){},
    appendChild(){}, setAttribute(){}, removeAttribute(){}, focus(){}, select(){},
    getBoundingClientRect(){ return {top:0,left:0,width:0,height:0}; },
    querySelector(){ return makeFakeElement(); },
    querySelectorAll(){ return []; },
  };
}
function makeDocument(){
  const cache = {};
  return {
    hidden:true,
    activeElement:null,
    getElementById(id){ if(!cache[id]) cache[id]=makeFakeElement(); return cache[id]; },
    querySelector(){ return makeFakeElement(); },
    querySelectorAll(){ return []; },
    addEventListener(){},
    removeEventListener(){},
    createElement(){ return makeFakeElement(); },
  };
}

// Every call builds a FRESH vm context + re-runs the app script, so each test
// group starts from a clean D (same as opening the app fresh with empty
// localStorage) without needing to reimplement any reset logic.
function loadApp(){
  const ctx = {
    console,
    Math, JSON, Date, Number, String, Boolean, Array, Object, RegExp, Promise, Error,
    isNaN, parseFloat, parseInt, structuredClone,
    setTimeout:()=>0, clearTimeout(){},
    setInterval:()=>0, clearInterval(){},
    localStorage: makeLocalStorage(),
    document: makeDocument(),
    navigator: {clipboard:undefined},
    confirm: ()=>true,
    fetch: ()=>Promise.reject(new Error("no-network-in-tests")),
    XLSX: {read(){ throw new Error("XLSX.read not used in this harness — call parsePOAuto directly with row arrays instead of a real file"); },
           utils:{ sheet_to_json(){ return []; } }},
  };
  ctx.window = ctx;
  ctx.pageYOffset = 0;
  ctx.scrollTo = ()=>{};
  ctx.addEventListener = ()=>{};
  ctx.removeEventListener = ()=>{};
  vm.createContext(ctx);

  // Appended in the SAME compilation unit as appSrc so it shares the app's
  // top-level lexical scope (D, hitungTarget, ... are `let`/`const`/function
  // declarations, not properties of the global object).
  const combined = appSrc + `
;(function(){
  globalThis.__API__ = {
    D, SEED, hitungTarget, poMergeDenganExisting, poIdentitasBaris, poAmbilExisting,
    pdSisaTarget, targetUntukDivisi, ceklisKey, fgKey,
    fgMaterializeAll, fgStoreRows, fgGetPacked,
    parsePOAuto, parsePOCsv, parsePOBolu, deteksiFactory, findHeaderRow, findHeaderRowBolu,
    poHitungUnresolved, resolusiProdukPO, poResolusiPetakan, poResolusiBuatBaru, cocokProduk,
    saveD, uid, num, fmt, normNama, normalizeProductKey, divisiProduk, stokGudang,
    SPECIAL_FG, SPECIAL_FG_CIBADAK, SPECIAL_BOLU, DIVISI_BAWAAN,
  };
})();
`;
  const script = new vm.Script(combined, {filename:"app-under-test.js"});
  script.runInContext(ctx);
  const api = ctx.__API__;
  api.__ctx = ctx;
  return api;
}

module.exports = { loadApp };
