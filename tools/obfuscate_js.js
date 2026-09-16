/**
 * GNCP Academic Portal — Hardened JS Obfuscation Pipeline
 * Usage:
 *   node tools/obfuscate_js.js           # Obfuscate all
 *   node tools/obfuscate_js.js --restore # Restore originals
 *   node tools/obfuscate_js.js --check   # Dry-run check
 */
const JavaScriptObfuscator = require("javascript-obfuscator");
const fs   = require("fs");
const path = require("path");

const ROOT    = path.resolve(__dirname, "..");
const BACKUP  = path.join(__dirname, ".js_originals");
const ARGS    = process.argv.slice(2);
const RESTORE = ARGS.includes("--restore");
const CHECK   = ARGS.includes("--check");

const TARGET_FILES = [
  "assets/js/app.js",
  "assets/js/doc-viewer-app.js",
  "assets/js/gateway-app.js",
  "assets/js/gateway-session.js",
  "shared/academic_constants.js",
  "shared/js/DataCache.js",
  "shared/js/PasswordChangeGuard.js",
  "shared/js/SessionExpirationGuard.js",
  "shared/js/StationPipeline.js",
  "shared/js/components/EmployeeSidebar.js",
  "shared/paymongo/checkout-app.js",
  "stations/assets/js/DataBus.js",
  "stations/it-center/assets/js/app.js",
  "stations/medical-checkup/assets/js/app.js",
  "stations/payment-processing/assets/js/app.js",
  "stations/tlc-helpdesk/assets/js/app.js",
  "registrar/assets/js/controllers/RegistrarController.js",
  "registrar/assets/js/services/RegistrarApiService.js",
  "registrar/assets/js/views/RegistrarView.js",
  "admin/assets/js/components/AdminSidebar.js",
  "admin/assets/js/controllers/AdminController.js",
  "student-portal/assets/js/login-init.js",
  "student-portal/assets/js/controllers/StudentLoginController.js",
  "student-portal/assets/js/controllers/StudentForgotPasswordController.js",
  "student-portal/assets/js/controllers/StudentPortalController.js",
  "student-portal/assets/js/models/StudentModel.js",
  "student-portal/assets/js/services/StudentApiService.js",
  "enrollment-system/assets/js/App.js",
  "enrollment-system/assets/js/TrackerApp.js",
  "enrollment-system/assets/js/models/EnrollmentModel.js",
  "enrollment-system/assets/js/services/ApiService.js",
  "school-website/assets/js/controllers/AppController.js",
  "school-website/assets/js/models/DataModel.js",
  "school-website/assets/js/views/MainView.js",
  "school-website/assets/js/views/PagesView.js",
  "monitoring/assets/js/MonitorApp.js",
];

const OBF_OPTS = {
  compact: true,
  controlFlowFlattening: true,
  controlFlowFlatteningThreshold: 0.5,
  deadCodeInjection: true,
  deadCodeInjectionThreshold: 0.3,
  debugProtection: false,
  disableConsoleOutput: true,
  identifierNamesGenerator: "hexadecimal",
  log: false,
  numbersToExpressions: true,
  renameGlobals: false,
  rotateStringArray: true,
  selfDefending: true,
  shuffleStringArray: true,
  simplify: true,
  splitStrings: true,
  splitStringsChunkLength: 8,
  stringArray: true,
  stringArrayCallsTransform: true,
  stringArrayCallsTransformThreshold: 0.75,
  stringArrayEncoding: ["base64"],
  stringArrayIndexShift: true,
  stringArrayRotate: true,
  stringArrayShuffle: true,
  stringArrayWrappersCount: 2,
  stringArrayWrappersChunkLength: 10,
  stringArrayWrappersParametersMaxCount: 4,
  stringArrayWrappersType: "function",
  stringArrayThreshold: 0.85,
  transformObjectKeys: false,
  unicodeEscapeSequence: false,
  target: "browser",
  sourceMap: false,
};

function fmt(b){ return b<1024?b+"B":(b/1024).toFixed(1)+"KB"; }
function ensureDir(d){ if(!fs.existsSync(d)) fs.mkdirSync(d,{recursive:true}); }

if(RESTORE){
  let n=0;
  for(const rel of TARGET_FILES){
    const bp=path.join(BACKUP,rel), tp=path.join(ROOT,rel);
    if(fs.existsSync(bp)){ ensureDir(path.dirname(tp)); fs.copyFileSync(bp,tp); console.log("  [RESTORED] "+rel); n++; }
    else console.log("  [SKIP] "+rel+" - no backup");
  }
  console.log("\n[+] Restored "+n+" files\n");
  process.exit(0);
}

if(CHECK){
  let tot=0;
  for(const rel of TARGET_FILES){
    const fp=path.join(ROOT,rel);
    if(fs.existsSync(fp)){ const s=fs.statSync(fp).size; tot+=s; console.log("  [OK] "+rel+" ("+fmt(s)+")"); }
    else console.log("  [MISS] "+rel);
  }
  console.log("\nTotal: "+TARGET_FILES.length+" files | "+fmt(tot));
  process.exit(0);
}

console.log("\n========================================================================");
console.log("  GNCP HARDENED JS OBFUSCATION PIPELINE");
console.log("========================================================================");
console.log("  Files   : "+TARGET_FILES.length+" modules");
console.log("  Encoding: base64 strings + control flow flattening + self-defending");
console.log("========================================================================\n");

ensureDir(BACKUP);
let ok=0, err=0, skip=0, totOrig=0, totObf=0;

for(const rel of TARGET_FILES){
  const src=path.join(ROOT,rel), bak=path.join(BACKUP,rel);
  if(!fs.existsSync(src)){ console.log("  [SKIP] "+rel); skip++; continue; }
  const code=fs.readFileSync(src,"utf8");
  const origSz=Buffer.byteLength(code,"utf8");
  totOrig+=origSz;
  ensureDir(path.dirname(bak));
  fs.writeFileSync(bak,code,"utf8");
  try{
    const out=JavaScriptObfuscator.obfuscate(code,OBF_OPTS).getObfuscatedCode();
    const obfSz=Buffer.byteLength(out,"utf8");
    totObf+=obfSz;
    fs.writeFileSync(src,out,"utf8");
    const pct=((obfSz/origSz)*100).toFixed(0);
    console.log("  [OK]  "+rel.padEnd(65)+" "+fmt(origSz).padStart(8)+" -> "+fmt(obfSz).padStart(8)+"  ("+pct+"%)");
    ok++;
  }catch(e){
    console.error("  [ERR] "+rel+" — "+e.message);
    fs.writeFileSync(src,code,"utf8");
    err++;
  }
}

console.log("\n========================================================================");
console.log("  Obfuscated : "+ok+"   Skipped: "+skip+"   Failed: "+err);
console.log("  Original   : "+fmt(totOrig)+"  ->  Obfuscated: "+fmt(totObf)+"  ("+((totObf/totOrig)*100).toFixed(0)+"%)");
console.log("  Backups at : "+BACKUP);
console.log("========================================================================\n");
if(err>0) process.exit(1);
