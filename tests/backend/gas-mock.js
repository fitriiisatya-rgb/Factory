"use strict";
// Minimal, faithful-enough mock of the Google Apps Script APIs that
// backend/Code.gs (and backend/Code.legacy.gs) actually call, so the REAL
// source files can be `eval`'d and exercised under Node without any Google
// account/credentials. Kept purely synchronous — real Apps Script has no
// async versions of SpreadsheetApp/LockService/etc, so this mock doesn't
// either; that keeps behavior faithful rather than accidentally "fixing"
// races the real runtime wouldn't fix on its own.
//
// Concurrency-testing note (see tests/backend/README in the audit report):
// this mock cannot reproduce TRUE OS-level parallel execution (Node is
// single-threaded; two eval'd synchronous calls never actually interleave
// unless something forces a yield). Two techniques are provided instead:
//   1. `installInterleaveHook(sheetName, methodName, onCall)` — fires a
//      callback the Nth time a given sheet method is invoked, so a test can
//      deterministically run a "concurrent" second request in the middle of
//      a first request's read-modify-write sequence. Used to reproduce the
//      legacy no-lock bug exactly, not approximately.
//   2. Sequential calls through the SAME mock spreadsheet, which is valid
//      for testing the NEW code's optimistic-concurrency logic precisely
//      because that logic's job is to make correctness independent of
//      interleaving order — see the concurrency test file for the full
//      rationale.

class MockSheet {
  constructor(name, headers){
    this.name = name;
    // Genuinely-new sheet starts with ZERO rows — Code.gs's getOrCreateSheet
    // always follows insertSheet() with its own appendRow(headers) call, so
    // pre-seeding a header row here would duplicate it (a real empty Google
    // Sheet has no such phantom row either).
    this.rows = headers && headers.length ? [headers.slice()] : [];
    this._hooks = {}; // methodName -> [{remaining, fn}]
  }
  _fireHook(methodName){
    const list = this._hooks[methodName];
    if(!list || !list.length) return;
    const hook = list[0];
    if(hook.remaining > 1){ hook.remaining--; return; }
    list.shift();
    hook.fn();
  }
  _installHook(methodName, callIndex, fn){
    (this._hooks[methodName] ||= []).push({remaining: callIndex, fn});
  }
  getName(){ return this.name; }
  getDataRange(){
    const self = this;
    return { getValues(){ self._fireHook("getDataRange"); return self.rows.map(r=>r.slice()); } };
  }
  appendRow(arr){ this.rows.push(arr.slice()); this._fireHook("appendRow"); }
  getLastRow(){ return this.rows.length; }
  getMaxRows(){ return Math.max(this.rows.length, 1000); }
  setFrozenRows(){ /* no-op */ }
  clearContents(){ this._fireHook("clearContents"); this.rows = []; }
  getRange(row, col, numRows, numCols){
    const self = this;
    return {
      setValues(data){
        for(let i=0;i<data.length;i++){
          const targetRow = row-1+i;
          while(self.rows.length <= targetRow) self.rows.push([]);
          const rowArr = self.rows[targetRow];
          for(let j=0;j<data[i].length;j++){ rowArr[col-1+j] = data[i][j]; }
        }
        self._fireHook("setValues");
      },
      setNumberFormat(){ /* not modeled */ }
    };
  }
}

class MockSpreadsheet {
  constructor(){ this.sheets = {}; }
  getSheetByName(name){ return this.sheets[name] || null; }
  insertSheet(name){ const sh = new MockSheet(name, []); this.sheets[name]=sh; return sh; }
  copy(name){ return {getUrl(){ return "mock://archive/"+encodeURIComponent(name); }}; }
}

function makeGasGlobals(){
  const ss = new MockSpreadsheet();
  const cache = new Map();
  let forcedLockFailures = 0;

  const CacheServiceMock = {
    getScriptCache(){
      return {
        get(k){ return cache.has(k) ? cache.get(k) : null; },
        put(k,v){ cache.set(k, v); }
      };
    }
  };
  let scriptLockHeld = false;
  const LockServiceMock = {
    getScriptLock(){
      let heldByThisHandle = false;
      return {
        tryLock(){
          if(forcedLockFailures > 0){ forcedLockFailures--; return false; }
          // Model REAL mutual exclusion: if another (reentrant, via an
          // interleave hook, or a genuinely separate) caller currently
          // holds the script lock, this call must fail like the real
          // LockService does — it must NOT silently let two "concurrent"
          // requests both proceed, or this mock would hide exactly the
          // race it exists to help prove/disprove.
          if(scriptLockHeld) return false;
          scriptLockHeld = true; heldByThisHandle = true; return true;
        },
        releaseLock(){ if(heldByThisHandle){ scriptLockHeld = false; heldByThisHandle = false; } }
      };
    }
  };
  const SpreadsheetAppMock = {
    getActiveSpreadsheet(){ return ss; },
    getActive(){ return ss; }
  };
  let uuidSeq = 1;
  const UtilitiesMock = {
    formatDate(date, tz, fmt){
      const p = n => String(n).padStart(2,"0");
      return date.getFullYear()+"-"+p(date.getMonth()+1)+"-"+p(date.getDate());
    },
    getUuid(){ return "uuid-"+(uuidSeq++)+"-"+Math.random().toString(36).slice(2,8); }
  };
  const SessionMock = { getScriptTimeZone(){ return "Asia/Jakarta"; } };
  const LoggerMock = { log(){} };
  const ContentServiceMock = {
    MimeType: { JSON: "JSON" },
    createTextOutput(text){
      return { _text:text, setMimeType(){ return this; }, getContent(){ return this._text; } };
    }
  };

  return {
    globals: { SpreadsheetApp:SpreadsheetAppMock, LockService:LockServiceMock, CacheService:CacheServiceMock,
      Utilities:UtilitiesMock, Session:SessionMock, Logger:LoggerMock, ContentService:ContentServiceMock },
    state: {
      ss,
      forceLockTimeoutOnce(){ forcedLockFailures++; },
      installInterleaveHook(sheetName, methodName, callIndex, fn){
        const sh = ss.getSheetByName(sheetName);
        if(!sh) throw new Error("Sheet belum ada: "+sheetName+" (panggil setup()/doGet dulu sebelum pasang hook)");
        sh._installHook(methodName, callIndex, fn);
      }
    }
  };
}
module.exports = { makeGasGlobals };
