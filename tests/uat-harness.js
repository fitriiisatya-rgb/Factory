// High-fidelity UAT harness: uses REAL jsdom (real DOM parsing, innerHTML,
// querySelectorAll, dataset, classList, localStorage) instead of a hand-rolled
// element stub, plus the REAL SheetJS (xlsx) library so poProses() itself can
// be driven end-to-end with a real in-memory .xlsx file. Only fetch/confirm
// are stubbed (network + dialogs have no place in Node).
"use strict";
const fs = require("fs");
const path = require("path");
const { JSDOM, VirtualConsole } = require("jsdom");

const HTML_PATH = path.join(__dirname, "..", "amorcakes-manufacturing-v5-slate(2).html");
const html = fs.readFileSync(HTML_PATH, "utf8");
const scriptMatch = html.match(/<script(?![^>]*src)[^>]*>([\s\S]*?)<\/script>/);
if(!scriptMatch) throw new Error("Tidak menemukan blok <script> inline di "+HTML_PATH);
const appSrc = scriptMatch[1];

const XLSX = require("xlsx");

function loadApp({confirmAnswer=true, fetchImpl=null}={}){
  // runScripts:"outside-only" -> the page's own <script> tags never auto-run
  // (so we control exactly when appSrc executes, after stubs are attached),
  // but window.eval() from outside is allowed and runs in the real window.
  const vc = new VirtualConsole();
  vc.forwardTo(console, {omitJSDOMErrors:true}); // keep real console.log/error, drop jsdom's "not implemented" noise (scrollTo, etc.)
  const dom = new JSDOM(html, {url:"http://localhost/amorcakes/", runScripts:"outside-only", pretendToBeVisual:true, virtualConsole:vc});
  const w = dom.window;
  w.scrollTo = () => {};
  if(!w.Element.prototype.scrollIntoView) w.Element.prototype.scrollIntoView = function(){};
  w.XLSX = XLSX;
  w.confirm = () => confirmAnswer;
  w.alert = () => {};
  w.fetch = fetchImpl || (() => Promise.reject(new Error("no-network-in-tests")));
  w.setInterval = () => 0; // don't keep a real 25s polling timer alive across tests
  w.console = console;
  w.structuredClone = w.structuredClone || (v => JSON.parse(JSON.stringify(v)));

  const combined = appSrc + `
;(function(){
  window.__API__ = {
    D, SEED, hitungTarget, poMergeDenganExisting, poIdentitasBaris, poAmbilExisting,
    pdSisaTarget, targetUntukDivisi, ceklisKey, fgKey, skuId,
    fgMaterializeAll, fgStoreRows, fgGetPacked, fgSetPackedField, fgPackedKeyExisting, fgTandaiSiap,
    parsePOAuto, parsePOCsv, parsePOBolu, deteksiFactory,
    poHitungUnresolved, resolusiProdukPO, poResolusiPetakan, poResolusiBuatBaru, poResolusiPilihLain, poResolusiJadiBaru, poResolusiKonfirmasi, cocokProduk,
    saveD, uid, num, fmt, normNama, normalizeProductKey, divisiProduk, stokGudang, mutasiStok, rekapStokHarian,
    SPECIAL_FG, SPECIAL_FG_CIBADAK, SPECIAL_BOLU, DIVISI_BAWAAN,
    poProses, poSimpanPreview, pdSimpan, pdBaca, prodBuildChecklist, pdTutup,
    fgBuildPanel, fgSetPacked, fgSetStatus, fgSimpanProgres,
    kBuildGrid, kBaca, kHitung, kSimpan, kBaru, kTarikDariFG, kTarikDariFGBakery, kProdukPesanan, kSudahKirim,
    kInvoiceBaca, kInvoiceSimpan, kInvoiceHitung, kBukaInvoice, fillInvoiceTokoSelect,
    invoiceNomor, doNomor, fgReadyList, fgReadyListByBakery, dashProdukData, poDeteksiKodeBentrok,
    normalizeStoreName, resolveStore, canonicalStoreName, storeIdentity, storeAliasIndex,
    ensureMasterToko, bootstrapTokoCanonicalDikenal, auditStoreAliases, tokoTipe, cocokToko, normTokoKunci,
    kFulfillmentFlatData, kPesananBelumTerpenuhiPerToko, fillTokoSelects, tokoOptionsCanonical,
    mtkTambahBakery, mtkEditCanonical, mtkTambahAlias, mtkHapusAlias, mtkSetChannel, mtkToggleActive, mtkGabungKe,
    omsetHarianRows, omsetPerBakeryData, omsetTrenHarian, omsetSkuDetailData, omsetRejectPotongRows,
    renderOmset, omsetBukaDrill, hargaDasar, hargaPabrik, mpGet, rupiah, rjBuildGrid, rjSimpan,
    get poPreview(){ return poPreview; },
    get kList(){ return kList; },
    get kFgSource(){ return kFgSource; },
  };
})();
`;
  w.eval(combined);
  const api = w.__API__;
  api.__window = w;
  api.__document = w.document;
  api.$ = (id)=>w.document.getElementById(id);
  return api;
}

module.exports = { loadApp, XLSX };
